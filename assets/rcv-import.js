'use strict';
/* Importación local del Registro de Compras y Ventas (RCV) descargado desde el SII.
   Lee los CSV de detalle de compras y de ventas, arma ambos libros y calcula el IVA del período.
   Todo ocurre en el navegador: los archivos no se envían a ningún servidor. */
(function(root){
 const DOC_TYPES={29:'Factura de inicio',30:'Factura',32:'Factura de venta exenta',33:'Factura electrónica',34:'Factura no afecta o exenta electrónica',35:'Boleta',38:'Boleta exenta',39:'Boleta electrónica',40:'Liquidación factura',41:'Boleta exenta electrónica',43:'Liquidación factura electrónica',45:'Factura de compra',46:'Factura de compra electrónica',48:'Comprobante de pago electrónico',55:'Nota de débito',56:'Nota de débito electrónica',60:'Nota de crédito',61:'Nota de crédito electrónica',101:'Factura de exportación',104:'Nota de débito de exportación',106:'Nota de crédito de exportación',110:'Factura de exportación electrónica',111:'Nota de débito de exportación electrónica',112:'Nota de crédito de exportación electrónica',914:'Declaración de ingreso (DIN)'};
 const CREDIT_NOTES=new Set([60,61,106,112]);
 // Códigos de "otros impuestos" del SII más comunes (los demás se muestran solo con su código).
 const OTROS_IMP={14:'IVA de margen de comercialización',15:'IVA retenido total',17:'IVA anticipado faenamiento carne',18:'IVA anticipado carne',19:'IVA anticipado harina',23:'Impuesto adicional art. 37 letras a, b, c',24:'Licores, piscos, destilados',25:'Vinos',26:'Cervezas y bebidas alcohólicas',27:'Bebidas analcohólicas y minerales',271:'Bebidas analcohólicas con elevado contenido de azúcar',28:'Impuesto específico diésel',35:'Impuesto específico gasolinas'};
 // Códigos de IVA no recuperable del RCV de compras.
 const IVA_NO_REC={1:'Compras destinadas a operaciones no gravadas o exentas',2:'Facturas registradas fuera de plazo',3:'Gastos rechazados',4:'Entregas gratuitas (premios, bonificaciones) recibidas',9:'Otros'};
 const MONTHS=['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

 const norm=s=>String(s||'').normalize('NFD').replace(/[̀-ͯ]/g,'').toLowerCase().replace(/[^a-z0-9]+/g,' ').trim();
 // Columnas por libro: nombre interno -> encabezados posibles del CSV del SII (normalizados).
 const COLUMNS={
  compras:{tipo:['tipo doc'],tipoOperacion:['tipo compra'],rut:['rut proveedor'],razon:['razon social'],folio:['folio'],fecha:['fecha docto'],fechaRecepcion:['fecha recepcion'],exento:['monto exento'],neto:['monto neto'],iva:['monto iva recuperable'],ivaNoRec:['monto iva no recuperable'],ivaNoRecCodigo:['codigo iva no rec'],ivaUsoComun:['iva uso comun'],netoActivoFijo:['monto neto activo fijo'],ivaActivoFijo:['iva activo fijo'],impSinCredito:['impto sin derecho a credito'],otroImpCodigo:['codigo otro impuesto'],otros:['valor otro impuesto'],otroImpTasa:['tasa otro impuesto'],total:['monto total']},
  ventas:{tipo:['tipo doc'],tipoOperacion:['tipo venta'],rut:['rut cliente'],razon:['razon social'],folio:['folio'],fecha:['fecha docto'],fechaRecepcion:['fecha recepcion'],exento:['monto exento'],neto:['monto neto'],iva:['monto iva'],ivaRetenido:['iva retenido total'],refTipo:['tipo docto referencia'],refFolio:['folio docto referencia'],otroImpCodigo:['codigo otro imp','codigo otro impuesto'],otros:['valor otro imp','valor otro impuesto'],otroImpTasa:['tasa otro imp','tasa otro impuesto'],total:['monto total']}
 };
 // Archivos de compras del RCV que no son el registro (no dan crédito fiscal).
 const COMPRAS_FUERA={PENDIENTE:'pendientes',NO_INCLUIR:'no incluidos',RECLAMADO:'reclamados'};

 function splitLine(line,sep){const out=[];let cur='',quoted=false;for(let i=0;i<line.length;i++){const c=line[i];if(quoted){if(c==='"'&&line[i+1]==='"'){cur+='"';i++;}else if(c==='"')quoted=false;else cur+=c;}else if(c==='"')quoted=true;else if(c===sep){out.push(cur);cur='';}else cur+=c;}out.push(cur);return out.map(v=>v.trim());}
 function amount(v){v=String(v||'').trim();if(!v)return 0;if(/^-?\d{1,3}(\.\d{3})+(,\d+)?$/.test(v))v=v.replace(/\./g,'').replace(',','.');else v=v.replace(',','.');const n=Number(v);return Number.isFinite(n)?Math.round(n):0;}
 function detectKind(headers){const h=headers.map(norm);if(h.includes('rut proveedor')||h.includes('tipo compra'))return 'compras';if(h.includes('rut cliente')||h.includes('tipo venta'))return 'ventas';return null;}
 function rutFromName(name){const m=/RCV_[A-Z_]+?_(\d{7,8}-[\dkK])_/i.exec(String(name||''));return m?m[1].toUpperCase():'';}
 function periodFromName(name){const m=/(20\d{2})(0[1-9]|1[0-2])(?!\d)/.exec(String(name||''));return m?m[1]+'-'+m[2]:null;}

 function parseRcv(text,fileName){
  text=String(text).replace(/^﻿/,'');
  if(/<table/i.test(text)&&/honorarios/i.test(text))throw new Error('Es el informe de boletas de honorarios: impórtalo en el módulo Honorarios.');
  const fuera=/RCV_COMPRA_(PENDIENTE|NO_INCLUIR|RECLAMADO)/i.exec(String(fileName||''));
  if(fuera)throw new Error('Trae los documentos '+COMPRAS_FUERA[fuera[1].toUpperCase()]+' del RCV, que no forman parte del Libro de Compras. Importa el archivo RCV_COMPRA_REGISTRO.');
  const lines=text.split(/\r?\n/).filter(l=>l.trim());
  if(!lines.length)throw new Error('El archivo está vacío.');
  const sep=(lines[0].match(/;/g)||[]).length>=(lines[0].match(/,/g)||[]).length?';':',';
  const headers=splitLine(lines[0],sep);const kind=detectKind(headers);
  if(!kind)throw new Error('No se reconoce el archivo como detalle de compras o ventas del RCV del SII.');
  const index={};const normHeaders=headers.map(norm);
  for(const [key,names] of Object.entries(COLUMNS[kind])){index[key]=normHeaders.findIndex(h=>names.includes(h));}
  for(const key of ['tipo','folio','neto','iva','total'])if(index[key]<0)throw new Error('Falta la columna "'+COLUMNS[kind][key][0]+'" en '+(fileName||'el archivo')+'.');
  const docs=[];
  for(const line of lines.slice(1)){
   const cells=splitLine(line,sep);const get=k=>index[k]>=0?cells[index[k]]||'':'';
   const tipo=parseInt(get('tipo'),10);if(!tipo)continue;
   const doc={tipo,tipoNombre:DOC_TYPES[tipo]||('Documento '+tipo),tipoOperacion:get('tipoOperacion'),rut:get('rut'),razon:get('razon'),folio:get('folio'),fecha:get('fecha'),exento:amount(get('exento')),neto:amount(get('neto')),iva:amount(get('iva')),otros:amount(get('otros')),total:amount(get('total')),signo:CREDIT_NOTES.has(tipo)?-1:1};
   doc.fechaRecepcion=get('fechaRecepcion');doc.otroImpCodigo=get('otroImpCodigo');doc.otroImpTasa=get('otroImpTasa');
   if(kind==='compras'){doc.ivaNoRec=amount(get('ivaNoRec'));doc.ivaNoRecCodigo=get('ivaNoRecCodigo');doc.ivaUsoComun=amount(get('ivaUsoComun'));doc.netoActivoFijo=amount(get('netoActivoFijo'));doc.ivaActivoFijo=amount(get('ivaActivoFijo'));doc.impSinCredito=amount(get('impSinCredito'));}
   else{doc.ivaRetenido=amount(get('ivaRetenido'));doc.refTipo=get('refTipo');doc.refFolio=get('refFolio');}
   docs.push(doc);
  }
  return {kind,fileName:fileName||'',rutEmpresa:rutFromName(fileName),period:periodFromName(fileName)||periodFromDocs(docs),docs};
 }
 function periodFromDocs(docs){const count={};for(const d of docs){const m=/^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$/.exec(d.fecha)||null;const k=m?m[3]+'-'+m[2].padStart(2,'0'):(/^(\d{4})-(\d{2})/.exec(d.fecha)||[])[0];if(k)count[k]=(count[k]||0)+1;}return Object.keys(count).sort((a,b)=>count[b]-count[a])[0]||null;}

 // Totales con signo: las notas de crédito restan.
 function totals(docs,fields){const t={documentos:docs.length};for(const f of fields)t[f]=docs.reduce((s,d)=>s+d.signo*(d[f]||0),0);return t;}
 function summaryByType(docs,fields){const groups=new Map();for(const d of docs){if(!groups.has(d.tipo))groups.set(d.tipo,[]);groups.get(d.tipo).push(d);}return [...groups.entries()].sort((a,b)=>a[0]-b[0]).map(([tipo,list])=>({tipo,tipoNombre:list[0].tipoNombre,...totals(list,fields)}));}

 // Suma con signo agrupada por un código (otro impuesto o IVA no recuperable); omite documentos sin código o sin monto.
 function summaryByCode(docs,codeField,amountField,names){const groups=new Map();for(const d of docs){const code=String(d[codeField]||'').trim();if(!code||!d[amountField])continue;const g=groups.get(code)||{codigo:code,nombre:names[code]||'Código '+code,documentos:0,monto:0};g.documentos++;g.monto+=d.signo*d[amountField];groups.set(code,g);}return [...groups.values()].sort((a,b)=>Number(a.codigo)-Number(b.codigo));}
 const COMPRAS_FIELDS=['exento','neto','iva','ivaNoRec','ivaUsoComun','ivaActivoFijo','impSinCredito','otros','total'];
 const VENTAS_FIELDS=['exento','neto','iva','ivaRetenido','otros','total'];
 function buildBooks(parsed){
  // Los documentos leídos desde la base de datos no traen nombre de tipo ni signo: se recalculan aquí.
  const prep=d=>({...d,tipoNombre:DOC_TYPES[d.tipo]||('Documento '+d.tipo),signo:CREDIT_NOTES.has(d.tipo)?-1:1});
  const compras=parsed.filter(p=>p.kind==='compras').flatMap(p=>p.docs.map(prep)),ventas=parsed.filter(p=>p.kind==='ventas').flatMap(p=>p.docs.map(prep));
  const tc=totals(compras,COMPRAS_FIELDS),tv=totals(ventas,VENTAS_FIELDS);
  // Débito: IVA de ventas menos el IVA retenido por el comprador (cambio de sujeto). Crédito: IVA recuperable de compras.
  const debito=tv.iva-tv.ivaRetenido,credito=tc.iva,diferencia=debito-credito;
  const periods=[...new Set(parsed.map(p=>p.period).filter(Boolean))].sort();
  return {
   period:periods.length?periods[periods.length-1]:null,periods,
   compras:{docs:compras,totales:tc,resumen:summaryByType(compras,COMPRAS_FIELDS),otrosImpuestos:summaryByCode(compras,'otroImpCodigo','otros',OTROS_IMP),ivaNoRecuperable:summaryByCode(compras,'ivaNoRecCodigo','ivaNoRec',IVA_NO_REC)},
   ventas:{docs:ventas,totales:tv,resumen:summaryByType(ventas,VENTAS_FIELDS),otrosImpuestos:summaryByCode(ventas,'otroImpCodigo','otros',OTROS_IMP)},
   iva:{debito,credito,ivaPagar:Math.max(diferencia,0),remanente:Math.max(-diferencia,0),ivaNoRecuperable:tc.ivaNoRec,ivaUsoComun:tc.ivaUsoComun,ivaRetenido:tv.ivaRetenido,ivaActivoFijo:tc.ivaActivoFijo}
  };
 }
 const formatPeriod=p=>{if(!p)return 'sin período';const [y,m]=p.split('-');return MONTHS[Number(m)-1]+' '+y;};

 const api={parseRcv,buildBooks,formatPeriod,DOC_TYPES,OTROS_IMP,IVA_NO_REC};
 if(typeof module!=='undefined'&&module.exports){module.exports=api;return;}
 root.RcvImport=api;

 // ---- Interfaz en la pantalla RCV ----
 const money=n=>(n<0?'-':'')+'$'+Math.abs(n).toLocaleString('es-CL');
 function el(tag,attrs,...children){const e=document.createElement(tag);for(const [k,v] of Object.entries(attrs||{})){if(k==='class')e.className=v;else e.setAttribute(k,v);}for(const c of children)if(c!=null)e.append(c instanceof Node?c:document.createTextNode(String(c)));return e;}
 function table(head,rows,foot){const t=el('table',{class:'rcv-table'});t.append(el('thead',null,el('tr',null,...head.map(h=>el('th',{scope:'col',class:h.num?'num':''},h.label)))));const body=el('tbody');for(const r of rows)body.append(el('tr',r.cls?{class:r.cls}:null,...r.cells.map((c,i)=>el('td',{class:head[i].num?'num':''},c))));t.append(body);if(foot)t.append(el('tfoot',null,el('tr',null,...foot.map((c,i)=>el('td',{class:head[i].num?'num':''},c)))));return el('div',{class:'rcv-table-wrap'},t);}
 function bookSection(kind,book){
  const isC=kind==='compras';const extra=isC?{key:'ivaNoRec',label:'IVA no rec.'}:{key:'ivaRetenido',label:'IVA retenido'};
  const head=[{label:'Tipo'},{label:'Folio'},{label:'Fecha'},{label:isC?'RUT proveedor':'RUT cliente'},{label:'Razón social'},{label:'Exento',num:1},{label:'Neto',num:1},{label:isC?'IVA recuperable':'IVA',num:1},{label:extra.label,num:1},{label:'Otros imp.',num:1},{label:'Total',num:1}];
  const rows=book.docs.map(d=>({cls:d.signo<0?'rcv-nc':'',cells:[d.tipo+' · '+d.tipoNombre,d.folio,d.fecha,d.rut,d.razon,...['exento','neto','iva',extra.key,'otros','total'].map(f=>money(d.signo*(d[f]||0)))]}));
  const t=book.totales;const foot=['Totales ('+t.documentos+' documentos)','','','','',...['exento','neto','iva',extra.key,'otros','total'].map(f=>money(t[f]))];
  const sumHead=[{label:'Tipo de documento'},{label:'Documentos',num:1},{label:'Exento',num:1},{label:'Neto',num:1},{label:isC?'IVA recuperable':'IVA',num:1},{label:'Total',num:1}];
  const sumRows=book.resumen.map(r=>({cells:[r.tipo+' · '+r.tipoNombre,r.documentos,money(r.exento),money(r.neto),money(r.iva),money(r.total)]}));
  const section=el('section',{class:'rcv-book','data-book':kind},el('h3',null,isC?'Libro de Compras':'Libro de Ventas'));
  if(!book.docs.length){section.append(el('p',{class:'rcv-empty'},'No se cargó un archivo de '+kind+'.'));return section;}
  section.append(el('h4',null,'Resumen por tipo de documento'),table(sumHead,sumRows));
  const codeHead=[{label:'Código'},{label:'Descripción'},{label:'Documentos',num:1},{label:'Monto',num:1}],codeRows=list=>list.map(r=>({cells:[r.codigo,r.nombre,r.documentos,money(r.monto)]}));
  if(book.otrosImpuestos.length)section.append(el('h4',null,'Otros impuestos por código'),table(codeHead,codeRows(book.otrosImpuestos)));
  if(isC&&book.ivaNoRecuperable.length)section.append(el('h4',null,'IVA no recuperable por código'),table(codeHead,codeRows(book.ivaNoRecuperable)));
  section.append(el('h4',null,'Detalle'),table(head,rows,foot));return section;
 }
 function ivaSection(books){
  const i=books.iva;const result=i.ivaPagar>0?['IVA a pagar',money(i.ivaPagar),'rcv-pay']:['Remanente de crédito fiscal',money(i.remanente),'rcv-credit'];
  const card=(label,value,cls)=>el('div',{class:'rcv-card '+(cls||'')},el('span',null,label),el('strong',null,value));
  const notes=[];if(i.ivaRetenido)notes.push('El débito descuenta '+money(i.ivaRetenido)+' de IVA retenido por los compradores.');if(i.ivaUsoComun)notes.push('Hay '+money(i.ivaUsoComun)+' de IVA de uso común: el crédito usa la columna IVA recuperable del SII; revisa la proporcionalidad.');if(i.ivaNoRecuperable)notes.push(money(i.ivaNoRecuperable)+' de IVA no recuperable no se usa como crédito.');if(i.ivaActivoFijo)notes.push('El crédito incluye '+money(i.ivaActivoFijo)+' de IVA por compras de activo fijo.');
  return el('section',{class:'rcv-iva'},el('h3',null,'IVA del período · '+formatPeriod(books.period)),el('div',{class:'rcv-cards'},card('Débito fiscal (ventas)',money(i.debito)),card('Crédito fiscal (compras)',money(i.credito)),card(result[0],result[1],result[2])),...notes.map(n=>el('p',{class:'rcv-note'},n)));
 }

 // Con el servidor PHP (api/rcv.php) los libros se guardan en la base de datos; sin él, solo se muestran en este navegador.
 const API='../api/rcv.php';
 const state={files:[],server:false,periods:[],period:null};
 async function request(method,query,body){
  const res=await fetch(API+(query||''),{method,headers:{...(body?{'Content-Type':'application/json'}:{}),...(typeof cabeceraEmpresa==='function'?cabeceraEmpresa():{})},body:body?JSON.stringify(body):undefined});
  const data=await res.json().catch(()=>({}));if(!res.ok)throw new Error(data.error||('Error '+res.status+' del servidor.'));return data;
 }
 function setMsg(text){document.getElementById('rcv-import-msg').textContent=text||'';}
 function render(){
  const out=document.getElementById('rcv-import-result');out.replaceChildren();
  const list=document.getElementById('rcv-import-files');list.replaceChildren(...state.files.map(f=>el('li',null,(f.kind==='compras'?'Compras':'Ventas')+' · '+formatPeriod(f.period)+' · '+f.docs.length+' documentos · '+f.fileName)));
  const clear=document.getElementById('rcv-import-clear');clear.hidden=!state.files.length;clear.textContent=state.server?'Borrar período guardado':'Quitar archivos';
  const select=document.getElementById('rcv-import-periods');select.hidden=!state.server||!state.periods.length;
  select.replaceChildren(el('option',{value:''},'Períodos guardados'),...state.periods.map(p=>{const o=el('option',{value:p.period},formatPeriod(p.period)+' · '+p.compras+' compras · '+p.ventas+' ventas');if(p.period===state.period)o.selected=true;return o;}));
  const welcome=document.querySelector('#contenido_rcv .welcome-royal-container')?.closest('.container-fluid');if(welcome)welcome.style.display=state.files.length?'none':'';
  if(!state.files.length)return;
  const books=buildBooks(state.files);
  if(books.periods.length>1)setMsg('Los archivos cargados son de períodos distintos ('+books.periods.map(formatPeriod).join(', ')+'). El IVA suma todos los documentos.');
  out.append(ivaSection(books),bookSection('compras',books.compras),bookSection('ventas',books.ventas));
 }
 async function openPeriod(period){
  state.period=period||null;
  state.files=period?(await request('GET','?periodo='+encodeURIComponent(period))).libros:[];
  render();
 }
 async function refreshPeriods(){state.periods=(await request('GET')).periodos;}
 function showBook(){const select=document.getElementById('rcv_filtro_tipoRCV');const kind=select&&select.selectedIndex===1?'ventas':'compras';document.querySelector('#rcv-import-result .rcv-book[data-book="'+kind+'"]')?.scrollIntoView({behavior:'smooth',block:'start'});}
 async function readFile(file){const buf=await file.arrayBuffer();try{return new TextDecoder('utf-8',{fatal:true}).decode(buf);}catch{return new TextDecoder('windows-1252').decode(buf);}}
 async function load(files){
  setMsg('');const errors=[],parsedFiles=[];
  for(const file of files){try{parsedFiles.push(parseRcv(await readFile(file),file.name));}catch(e){errors.push(file.name+': '+e.message);}}
  if(state.server){
   let saved=null;
   for(const parsed of parsedFiles){
    if(!parsed.period){errors.push(parsed.fileName+': no se pudo determinar el período.');continue;}
    try{await request('POST','',{kind:parsed.kind,period:parsed.period,fileName:parsed.fileName,rutEmpresa:parsed.rutEmpresa,docs:parsed.docs});saved=parsed.period;}catch(e){errors.push(parsed.fileName+': '+e.message);}
   }
   try{await refreshPeriods();if(saved)await openPeriod(saved);else render();}catch(e){errors.push(e.message);}
  }else{
   for(const parsed of parsedFiles){state.files=state.files.filter(f=>!(f.kind===parsed.kind&&f.period===parsed.period));state.files.push(parsed);}
   render();
  }
  if(errors.length)setMsg(errors.join(' '));
 }
 async function clearAll(){
  if(!state.server){state.files=[];setMsg('');render();return;}
  if(!state.period||!window.confirm('¿Borrar de la base de datos los libros de '+formatPeriod(state.period)+'?'))return;
  try{await request('DELETE','?periodo='+encodeURIComponent(state.period));await refreshPeriods();await openPeriod(null);setMsg('');}catch(e){setMsg(e.message);}
 }
 async function connect(){
  const note=document.getElementById('rcv-import-storage');
  try{await refreshPeriods();state.server=true;note.textContent='Los libros importados se guardan en la base de datos de Cifrax.';if(state.periods.length)await openPeriod(state.periods[0].period);else render();}
  catch{state.server=false;note.textContent='Sin servidor PHP: los archivos se procesan en este navegador y no se guardan. Inicia la copia con "npm run start:php" para guardarlos.';render();}
 }
 function init(){
  const host=document.getElementById('contenido_rcv');if(!host)return;
  const input=el('input',{type:'file',id:'rcv-import-input',accept:'.csv,text/csv',multiple:''});
  const clear=el('button',{type:'button',id:'rcv-import-clear',class:'rcv-btn-secondary'},'Quitar archivos');clear.hidden=true;
  const periods=el('select',{id:'rcv-import-periods',class:'rcv-select','aria-label':'Períodos guardados'});periods.hidden=true;
  const panel=el('div',{class:'rcv-import',id:'rcv-import'},
   el('h3',null,'Importar RCV desde archivo del SII'),
   el('p',null,'En sii.cl, Registro de Compras y Ventas, descarga el detalle de compras y el de ventas del mes (botón "Descargar Detalles") y selecciona ambos CSV aquí.'),
   el('p',{id:'rcv-import-storage',class:'rcv-note'}),
   el('div',{class:'rcv-import-actions'},el('label',{class:'rcv-btn',for:'rcv-import-input'},'Seleccionar CSV de compras y ventas'),input,periods,clear),
   el('ul',{id:'rcv-import-files',class:'rcv-files'}),el('p',{id:'rcv-import-msg',class:'rcv-msg',role:'status'}),el('div',{id:'rcv-import-result'}));
  host.prepend(panel);
  input.addEventListener('change',()=>{load([...input.files]);input.value='';});
  periods.addEventListener('change',()=>{openPeriod(periods.value).catch(e=>setMsg(e.message));});
  clear.addEventListener('click',e=>{e.stopPropagation();clearAll();});
  // Con libros cargados, la lupa de "Tipo RCV" lleva al libro elegido en vez del aviso de operación desactivada.
  document.getElementById('btn_rcv_filtro')?.addEventListener('click',e=>{if(!state.files.length)return;e.preventDefault();e.stopPropagation();showBook();});
  connect();
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})(typeof window!=='undefined'?window:globalThis);
