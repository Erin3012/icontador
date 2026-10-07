'use strict';
/* Importación de boletas de honorarios desde el informe mensual del SII (informeMensualREC.xls).
   El SII entrega ese "xls" como una tabla HTML en ISO-8859-1: aquí se lee la tabla, se guardan las boletas
   en la base de datos (api/honorarios.php) y se muestra el libro del período con la retención a declarar. */
(function(root){
 const MONTHS=['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
 const ENTITIES={nbsp:' ',amp:'&',lt:'<',gt:'>',quot:'"',apos:"'",deg:'°',ordm:'º',ordf:'ª',aacute:'á',eacute:'é',iacute:'í',oacute:'ó',uacute:'ú',Aacute:'Á',Eacute:'É',Iacute:'Í',Oacute:'Ó',Uacute:'Ú',ntilde:'ñ',Ntilde:'Ñ',uuml:'ü',Uuml:'Ü'};
 const decode=s=>s.replace(/&(#x[0-9a-f]+|#\d+|[a-z]+);/gi,(m,e)=>e[0]==='#'?String.fromCodePoint(e[1].toLowerCase()==='x'?parseInt(e.slice(2),16):parseInt(e.slice(1),10)):(ENTITIES[e]??m));
 const cellText=html=>decode(html.replace(/<br\s*\/?>|<p\b[^>]*>/gi,' ').replace(/<[^>]*>/g,'')).replace(/\s+/g,' ').trim();
 const norm=s=>String(s||'').normalize('NFD').replace(/[̀-ͯ]/g,'').toLowerCase().replace(/[^a-z0-9]+/g,' ').trim();
 function rows(html){return [...html.matchAll(/<tr\b[^>]*>([\s\S]*?)(?=<tr\b|<\/table>|$)/gi)].map(m=>[...m[1].matchAll(/<t[dh]\b[^>]*>([\s\S]*?)<\/t[dh]>/gi)].map(c=>cellText(c[1])));}
 function amount(v){v=String(v||'').replace(/[$\s]/g,'');if(!v)return 0;v=v.replace(/\./g,'').replace(',','.');const n=Number(v);return Number.isFinite(n)?Math.round(n):0;}
 const COLUMNS={numero:['n','nro','numero'],fecha:['fecha'],estado:['estado'],fechaAnulacion:['fecha anulacion'],rut:['rut'],nombre:['nombre o razon social'],socProf:['soc prof'],bruto:['brutos','bruto'],retenido:['retenido','retencion'],pagado:['pagado','liquido']};

 function parseInforme(text,fileName){
  text=String(text).replace(/^﻿/,'');
  if(!/<table/i.test(text))throw new Error('No se reconoce el archivo como informe mensual de boletas de honorarios del SII.');
  const all=rows(text);
  const h=all.findIndex(r=>{const n=r.map(norm);return n.includes('brutos')&&n.includes('retenido')&&n.includes('rut');});
  if(h<0)throw new Error('No se reconoce el archivo como informe mensual de boletas de honorarios del SII.');
  const header=all[h].map(norm),index={};
  for(const [k,names] of Object.entries(COLUMNS))index[k]=header.findIndex(c=>names.includes(c));
  // La fila de grupos sobre los encabezados dice si las boletas son de un Emisor (recibidas) o de un Receptor (emitidas).
  const groups=(all[h-1]||[]).map(norm);
  const kind=groups.includes('receptor')?'emitidas':'recibidas';
  const info=all.slice(0,h).flat().join(' ');
  const rut=(/RUT\s*:\s*([\d.]{7,10}-[\dkK])/i.exec(info)||[])[1]||'';
  const pm=/mes\s+(\d{1,2})\s+del\s+a(?:ñ|n)o\s+(20\d{2})/i.exec(info);
  const period=pm?pm[2]+'-'+pm[1].padStart(2,'0'):null;
  const boletas=[];
  for(const r of all.slice(h+1)){
   const get=k=>index[k]>=0?r[index[k]]||'':'';
   if(r.length<header.length||!/^\d+$/.test(get('numero')))continue;
   boletas.push({numero:get('numero'),fecha:get('fecha'),estado:get('estado').toUpperCase(),fechaAnulacion:get('fechaAnulacion'),rut:get('rut'),nombre:get('nombre'),socProf:/^s/i.test(get('socProf')),bruto:amount(get('bruto')),retenido:amount(get('retenido')),pagado:amount(get('pagado'))});
  }
  return {kind,fileName:fileName||'',rutEmpresa:rut.replace(/\./g,'').toUpperCase(),period:period||periodFromDocs(boletas),boletas,totales:totals(boletas)};
 }
 function periodFromDocs(list){const count={};for(const b of list){const m=/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(b.fecha);if(m){const k=m[3]+'-'+m[2].padStart(2,'0');count[k]=(count[k]||0)+1;}}return Object.keys(count).sort((a,b)=>count[b]-count[a])[0]||null;}
 const anulada=b=>/NUL/i.test(b.estado);
 // Igual que el informe del SII: las boletas anuladas no suman.
 function totals(list){const t={boletas:list.length,anuladas:0,bruto:0,retenido:0,pagado:0};for(const b of list){if(anulada(b)){t.anuladas++;continue;}t.bruto+=b.bruto;t.retenido+=b.retenido;t.pagado+=b.pagado;}return t;}
 const formatPeriod=p=>{if(!p)return 'sin período';const [y,m]=p.split('-');return MONTHS[Number(m)-1]+' '+y;};

 const api={parseInforme,totals,formatPeriod};
 if(typeof module!=='undefined'&&module.exports){module.exports=api;return;}
 root.HonorariosImport=api;

 // ---- Interfaz en la pantalla Honorarios ----
 const money=n=>(n<0?'-':'')+'$'+Math.abs(n).toLocaleString('es-CL');
 function el(tag,attrs,...children){const e=document.createElement(tag);for(const [k,v] of Object.entries(attrs||{})){if(k==='class')e.className=v;else e.setAttribute(k,v);}for(const c of children)if(c!=null)e.append(c instanceof Node?c:document.createTextNode(String(c)));return e;}
 const API='../api/honorarios.php';
 const state={libros:[],server:false,periods:[],period:null};
 async function request(method,query,body){
  const res=await fetch(API+(query||''),{method,headers:{...(body?{'Content-Type':'application/json'}:{}),...(typeof cabeceraEmpresa==='function'?cabeceraEmpresa():{})},body:body?JSON.stringify(body):undefined});
  const data=await res.json().catch(()=>({}));if(!res.ok)throw new Error(data.error||('Error '+res.status+' del servidor.'));return data;
 }
 const $=id=>document.getElementById(id);
 function setMsg(text){$('hon-import-msg').textContent=text||'';}
 function bookSection(libro){
  const recibidas=libro.kind==='recibidas',t=libro.totales||totals(libro.boletas);
  const head=['N°','Fecha','Estado',recibidas?'RUT emisor':'RUT receptor','Nombre o razón social','Soc. prof.','Bruto','Retenido','Pagado'];
  const num=i=>i>=6?'num':'';
  const table=el('table',{class:'rcv-table'},el('thead',null,el('tr',null,...head.map((h,i)=>el('th',{scope:'col',class:num(i)},h)))));
  const body=el('tbody');
  for(const b of libro.boletas)body.append(el('tr',anulada(b)?{class:'rcv-nc'}:null,...[b.numero,b.fecha,b.estado+(b.fechaAnulacion?' '+b.fechaAnulacion:''),b.rut,b.nombre,b.socProf?'Sí':'No',money(b.bruto),money(b.retenido),money(b.pagado)].map((c,i)=>el('td',{class:num(i)},c))));
  table.append(body,el('tfoot',null,el('tr',null,...['Totales ('+(t.boletas-t.anuladas)+' vigentes'+(t.anuladas?', '+t.anuladas+' anuladas':'')+')','','','','','',money(t.bruto),money(t.retenido),money(t.pagado)].map((c,i)=>el('td',{class:num(i)},c)))));
  const card=(label,value,cls)=>el('div',{class:'rcv-card '+(cls||'')},el('span',null,label),el('strong',null,value));
  return el('section',{class:'rcv-book'},el('h3',null,'Boletas de honorarios '+libro.kind+' · '+formatPeriod(libro.period)),
   el('div',{class:'rcv-cards'},card('Honorarios brutos',money(t.bruto)),card('Retención',money(t.retenido),recibidas?'rcv-pay':''),card('Líquido pagado',money(t.pagado))),
   recibidas?el('p',{class:'rcv-note'},'La retención de las boletas recibidas se declara y paga en el F29 del mes siguiente (código 151).'):null,
   el('div',{class:'rcv-table-wrap'},table));
 }
 function render(){
  const out=$('hon-import-result');out.replaceChildren(...state.libros.map(bookSection));
  const clear=$('hon-import-clear');clear.hidden=!state.libros.length;clear.textContent=state.server?'Borrar período guardado':'Quitar archivos';
  const select=$('hon-import-periods');select.hidden=!state.server||!state.periods.length;
  select.replaceChildren(el('option',{value:''},'Períodos guardados'),...state.periods.map(p=>{const o=el('option',{value:p.period},formatPeriod(p.period)+' · '+p.recibidas+' recibidas'+(p.emitidas?' · '+p.emitidas+' emitidas':''));if(p.period===state.period)o.selected=true;return o;}));
 }
 async function openPeriod(period){state.period=period||null;state.libros=period?(await request('GET','?periodo='+encodeURIComponent(period))).libros:[];render();}
 async function refreshPeriods(){state.periods=(await request('GET')).periodos;}
 async function readFile(file){const buf=await file.arrayBuffer();try{return new TextDecoder('utf-8',{fatal:true}).decode(buf);}catch{return new TextDecoder('windows-1252').decode(buf);}}
 async function load(files){
  setMsg('');const errors=[],parsed=[];
  for(const file of files){try{parsed.push(parseInforme(await readFile(file),file.name));}catch(e){errors.push(file.name+': '+e.message);}}
  if(state.server){
   let saved=null;
   for(const p of parsed){
    if(!p.period){errors.push(p.fileName+': no se pudo determinar el período.');continue;}
    try{await request('POST','',{kind:p.kind,period:p.period,fileName:p.fileName,rutEmpresa:p.rutEmpresa,boletas:p.boletas});saved=p.period;}catch(e){errors.push(p.fileName+': '+e.message);}
   }
   try{await refreshPeriods();if(saved)await openPeriod(saved);else render();}catch(e){errors.push(e.message);}
  }else{
   for(const p of parsed){state.libros=state.libros.filter(l=>!(l.kind===p.kind&&l.period===p.period));state.libros.push(p);}
   render();
  }
  if(errors.length)setMsg(errors.join(' '));
 }
 async function clearAll(){
  if(!state.server){state.libros=[];setMsg('');render();return;}
  if(!state.period||!window.confirm('¿Borrar de la base de datos las boletas de honorarios de '+formatPeriod(state.period)+'?'))return;
  try{await request('DELETE','?periodo='+encodeURIComponent(state.period));await refreshPeriods();await openPeriod(null);setMsg('');}catch(e){setMsg(e.message);}
 }
 async function connect(){
  const note=$('hon-import-storage');
  try{await refreshPeriods();state.server=true;note.textContent='Las boletas importadas se guardan en la base de datos de Cifrax, por empresa y período.';if(state.periods.length)await openPeriod(state.periods[0].period);else render();}
  catch(e){state.server=false;note.textContent=/sesión|empresa/i.test(e.message)?e.message:'Sin servidor PHP: el informe se muestra en este navegador y no se guarda. Inicia la copia con "npm run start:php" para guardarlo.';render();}
 }
 function init(){
  const anchor=document.getElementById('bhonfiltro_form')?.closest('.box_datos_new');if(!anchor)return;
  const input=el('input',{type:'file',id:'hon-import-input',accept:'.xls,.html,.htm',multiple:''});
  const clear=el('button',{type:'button',id:'hon-import-clear',class:'rcv-btn-secondary'},'Quitar archivos');clear.hidden=true;
  const periods=el('select',{id:'hon-import-periods',class:'rcv-select','aria-label':'Períodos guardados'});periods.hidden=true;
  const panel=el('div',{class:'rcv-import col-lg-12 col-md-12 col-sm-12 col-xs-12',id:'hon-import'},
   el('h3',null,'Importar boletas de honorarios desde el SII'),
   el('p',null,'En sii.cl, Boletas de honorarios electrónicas, Contribuyente receptor, Consultar boletas recibidas, elige el mes y descarga el informe mensual (archivo informeMensualREC.xls). Selecciónalo aquí; puedes cargar varios meses a la vez.'),
   el('p',{id:'hon-import-storage',class:'rcv-note'}),
   el('div',{class:'rcv-import-actions'},el('label',{class:'rcv-btn',for:'hon-import-input'},'Seleccionar informe del SII'),input,periods,clear),
   el('p',{id:'hon-import-msg',class:'rcv-msg',role:'status'}),el('div',{id:'hon-import-result'}));
  anchor.before(panel);
  input.addEventListener('change',()=>{load([...input.files]);input.value='';});
  periods.addEventListener('change',()=>{openPeriod(periods.value).catch(e=>setMsg(e.message));});
  clear.addEventListener('click',e=>{e.stopPropagation();clearAll();});
  connect();
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})(typeof window!=='undefined'?window:globalThis);
