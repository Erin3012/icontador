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
  const nh=headers.map(norm);
  if(nh.includes('total documentos')&&nh.includes('monto neto')&&nh.includes('monto iva')&&nh.includes('monto total')){
   const ix={tipo:nh.findIndex(h=>h==='tipo documento'),documentos:nh.indexOf('total documentos'),exento:nh.indexOf('monto exento'),neto:nh.indexOf('monto neto'),iva:nh.indexOf('monto iva'),total:nh.indexOf('monto total')};
   const resumen=[];
   for(const line of lines.slice(1)){const c=splitLine(line,sep),raw=c[ix.tipo]||'',m=/\((\d+)\)/.exec(raw);if(!raw||!m)continue;resumen.push({tipo:Number(m[1]),tipoNombre:raw.replace(/\s*\(\d+\)\s*$/,'').trim()+' ('+m[1]+')',documentos:amount(c[ix.documentos]),exento:ix.exento<0?0:amount(c[ix.exento]),neto:amount(c[ix.neto]),iva:amount(c[ix.iva]),total:amount(c[ix.total])});}
   if(!resumen.length)throw new Error('El archivo resumen no contiene filas válidas.');
   return {kind:'ventas',resumen,fileName:fileName||'',rutEmpresa:rutFromName(fileName),period:periodFromName(fileName),docs:[]};
  }
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
  const resumenes=parsed.filter(p=>p.kind==='ventas').flatMap(p=>p.resumen||[]);
  const tc=totals(compras,COMPRAS_FIELDS),tv=totals(ventas,VENTAS_FIELDS);
  for(const r of resumenes){tv.documentos+=r.documentos;for(const f of ['exento','neto','iva','total'])tv[f]+=r[f]||0;}
  // Débito: IVA de ventas menos el IVA retenido por el comprador (cambio de sujeto). Crédito: IVA recuperable de compras.
  const debito=tv.iva-tv.ivaRetenido,credito=tc.iva,diferencia=debito-credito;
  const periods=[...new Set(parsed.map(p=>p.period).filter(Boolean))].sort();
  return {
   period:periods.length?periods[periods.length-1]:null,periods,
   compras:{docs:compras,totales:tc,resumen:summaryByType(compras,COMPRAS_FIELDS),otrosImpuestos:summaryByCode(compras,'otroImpCodigo','otros',OTROS_IMP),ivaNoRecuperable:summaryByCode(compras,'ivaNoRecCodigo','ivaNoRec',IVA_NO_REC)},
   ventas:{docs:ventas,totales:tv,resumen:summaryByType(ventas,VENTAS_FIELDS).concat(resumenes.map(r=>({...r,tipoNombre:r.tipoNombre,agregado:true}))),resumenes,otrosImpuestos:summaryByCode(ventas,'otroImpCodigo','otros',OTROS_IMP)},
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
  const t=totals(book.docs,isC?COMPRAS_FIELDS:VENTAS_FIELDS);const foot=['Totales del detalle ('+t.documentos+' documentos)','','','','',...['exento','neto','iva',extra.key,'otros','total'].map(f=>money(t[f]))];
  const sumHead=[{label:'Tipo de documento'},{label:'Documentos',num:1},{label:'Exento',num:1},{label:'Neto',num:1},{label:isC?'IVA recuperable':'IVA',num:1},{label:'Total',num:1}];
  const sumRows=book.resumen.map(r=>({cells:[r.tipo+' · '+r.tipoNombre,r.documentos,money(r.exento),money(r.neto),money(r.iva),money(r.total)]}));
  const section=el('section',{class:'rcv-book','data-book':kind},el('h3',null,isC?'Libro de Compras':'Libro de Ventas'));
  if(!book.docs.length&&kind==='ventas'&&book.resumenes?.length){section.append(el('p',{class:'rcv-note'},'El SII entregó un resumen mensual agregado. Incluye '+book.resumenes.reduce((n,r)=>n+r.documentos,0)+' comprobantes sin detalle individual; se conserva como resumen y no crea vouchers.'));section.append(el('h4',null,'Resumen mensual por tipo'),table(sumHead,book.resumen.map(r=>({cells:[r.tipo+' · '+r.tipoNombre,r.documentos,money(r.exento),money(r.neto),money(r.iva),money(r.total)]}))));return section;}
  if(!book.docs.length){section.append(el('p',{class:'rcv-empty'},'No se cargó un archivo de '+kind+'.'));return section;}
  if(kind==='ventas'&&book.resumenes?.length)section.append(el('p',{class:'rcv-note'},'El resumen mensual agregado del tipo 48 se suma a los totales, pero no contiene detalle individual ni genera vouchers.'));
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
 const state={files:[],summaries:[],server:false,periods:[],period:null};
 async function request(method,query,body){
  const res=await fetch(API+(query||''),{method,headers:{...(body?{'Content-Type':'application/json'}:{}),...(typeof cabeceraEmpresa==='function'?cabeceraEmpresa():{})},body:body?JSON.stringify(body):undefined});
  const data=await res.json().catch(()=>({}));if(!res.ok)throw new Error(data.error||('Error '+res.status+' del servidor.'));return data;
 }
 function setMsg(text){document.getElementById('rcv-import-msg').textContent=text||'';}
 function render(){
  const out=document.getElementById('rcv-import-result');out.replaceChildren();
  const all=[...state.files,...state.summaries];
  const welcome=document.querySelector('#contenido_rcv .welcome-royal-container')?.closest('.container-fluid');if(welcome)welcome.style.display=all.length?'none':'';
  if(!all.length){out.append(el('p',{class:'rcv-empty'},state.period?'No hay libros RCV importados para este período.':'Selecciona un período para consultar sus libros.'));return;}
  const books=buildBooks(all);
  if(books.periods.length>1)setMsg('Los archivos cargados son de períodos distintos ('+books.periods.map(formatPeriod).join(', ')+'). El IVA suma todos los documentos.');
  out.append(ivaSection(books),bookSection('compras',books.compras),bookSection('ventas',books.ventas));
 }
 async function openPeriod(period,expand=false){
  state.period=period||null;
  renderCentralPeriods();
  const data=period?await request('GET','?periodo='+encodeURIComponent(period)):{libros:[],resumenes:[]};
  state.files=data.libros||[];state.summaries=data.resumenes||[];
  const detalle=document.getElementById('rcv-import');if(detalle&&expand)detalle.open=true;
  render();
 }
 async function refreshPeriods(){state.periods=(await request('GET')).periodos;renderCentralPeriods();}
 function renderCentralPeriods(){
  const select=document.getElementById('rcv-cent-periodo');if(!select)return;
  const previous=select.value||state.period||'';
  const union=new Map();for(const p of state.periods)union.set(p.period,{period:p.period,compras:Number(p.compras||0),ventas:Number(p.ventas||0)});for(const p of sii.centralPeriodos||[]){const item=union.get(p.periodo)||{period:p.periodo,compras:0,ventas:0};item.honorarios_recibidas=Number(p.libros?.honorarios_recibidas?.esperados||0);item.honorarios_emitidas=Number(p.libros?.honorarios_emitidas?.esperados||0);union.set(p.periodo,item);}
  const periodos=[...union.values()].filter(p=>p.compras||p.ventas||p.honorarios_recibidas||p.honorarios_emitidas).sort((a,b)=>b.period.localeCompare(a.period));
  select.replaceChildren(el('option',{value:''},periodos.length?'Selecciona un período importado':'Sin períodos importados'));
  for(const p of periodos){const libros=[];const disponibles=[];if(p.compras){libros.push(`${p.compras} compras`);disponibles.push('compras');}if(p.ventas){libros.push(`${p.ventas} ventas`);disponibles.push('ventas');}for(const libro of ['recibidas','emitidas']){const n=p['honorarios_'+libro]||0;if(n){libros.push(`${n} honorarios ${libro}`);disponibles.push('honorarios_'+libro);}}const estados=sii.centralEstados[p.period]||{};const hechos=disponibles.filter(libro=>estados[libro]?.estado==='contabilizado').length;const estado=hechos?` · ${hechos===disponibles.length?'Contabilizado':'Parcialmente contabilizado'}`:'';select.append(el('option',{value:p.period},`${formatPeriod(p.period)} · ${libros.join(' · ')}${estado}`));}
  if(periodos.some(p=>p.period===previous))select.value=previous;
 }
 function showBook(){const select=document.getElementById('rcv_filtro_tipoRCV');const kind=select&&select.selectedIndex===1?'ventas':'compras';document.querySelector('#rcv-import-result .rcv-book[data-book="'+kind+'"]')?.scrollIntoView({behavior:'smooth',block:'start'});}
 async function readFile(file){const buf=await file.arrayBuffer();try{return new TextDecoder('utf-8',{fatal:true}).decode(buf);}catch{return new TextDecoder('windows-1252').decode(buf);}}
 async function load(files){
  setMsg('');const errors=[],parsedFiles=[];
  for(const file of files){try{parsedFiles.push(parseRcv(await readFile(file),file.name));}catch(e){errors.push(file.name+': '+e.message);}}
  if(state.server){
   let saved=null;
   for(const parsed of parsedFiles){
    if(!parsed.period){errors.push(parsed.fileName+': no se pudo determinar el período.');continue;}
    try{await request('POST','',{kind:parsed.kind,period:parsed.period,fileName:parsed.fileName,rutEmpresa:parsed.rutEmpresa,...(parsed.resumen?{resumen:parsed.resumen}:{docs:parsed.docs})});saved=parsed.period;}catch(e){errors.push(parsed.fileName+': '+e.message);}
   }
   try{await refreshPeriods();if(saved)await openPeriod(saved);else render();}catch(e){errors.push(e.message);}
  }else{
   for(const parsed of parsedFiles){if(parsed.resumen){state.summaries=state.summaries.filter(f=>f.period!==parsed.period);state.summaries.push(parsed);}else{state.files=state.files.filter(f=>!(f.kind===parsed.kind&&f.period===parsed.period));state.files.push(parsed);}}
   render();
  }
  if(errors.length)setMsg(errors.join(' '));
 }
 async function clearAll(){
  if(!state.server){state.files=[];state.summaries=[];setMsg('');render();return;}
  if(!state.period||!window.confirm('¿Borrar de la base de datos los libros de '+formatPeriod(state.period)+'?'))return;
  try{await request('DELETE','?periodo='+encodeURIComponent(state.period));await refreshPeriods();await openPeriod(null);setMsg('');}catch(e){setMsg(e.message);}
 }
 async function connect(){
  try{await refreshPeriods();state.server=true;if(state.periods.length)await openPeriod(state.periods[0].period);else render();
   const sync=document.getElementById('rcv-sii');if(sync)sync.hidden=false;refreshSii().catch(e=>setSiiMsg(e.message));}
  catch(e){state.server=false;setMsg('No se pudieron cargar los libros guardados de RCV: '+e.message);render();}
 }
 // Sincronización con el SII: la web encola la descarga y el agente del PC (sii-agent/) la ejecuta y devuelve el libro.
 const SII_API='../api/sii.php';
 const CENTRAL_API='../api/rcv-centralizacion.php';
 const sii={tareas:[],timer:null,servicioDisponible:false,administrador:false,clave:false,cifrado:false,csrf:'',actividadId:0,capturaId:0,capturaUrl:'',centralEstados:{},centralPeriodos:[]};
 const ESTADO_SII={pendiente:'pendiente',en_proceso:'en proceso',cancelando:'cancelando',reencolando:'reiniciando',cancelada:'cancelada',completada:'completada',error:'error'};
  const ETAPAS_SII={iniciada:'Solicitud tomada por el agente',autenticando:'Iniciando sesión en el SII',sesion_lista:'Sesión del SII lista',abriendo_rcv:'Abriendo Registro de Compras y Ventas',abriendo_honorarios:'Abriendo Boletas de Honorarios',seleccionando_empresa:'Seleccionando empresa',configurando_periodo:'Configurando período',seleccionando_libro:'Seleccionando libro',consultando:'Consultando el SII',descargando:'Descargando el informe',validando:'Validando el archivo y sus datos',importando:'Guardando documentos en qlc.cl',completada:'Sincronización completada',error:'La tarea terminó con error',cancelada:'Tarea cancelada'};
 function setSiiMsg(text){const m=document.getElementById('rcv-sii-msg');if(m)m.textContent=text||'';}
 async function siiRequest(method,body,query=''){
  if(body&&!sii.csrf){const token=await fetch(SII_API+'?csrf=1',{headers:typeof cabeceraEmpresa==='function'?cabeceraEmpresa():{}});const data=await token.json().catch(()=>({}));if(!token.ok||!data.csrf)throw new Error(data.error||'No se pudo validar la sesión.');sii.csrf=data.csrf;}
  const res=await fetch(SII_API+query,{method,headers:{...(body?{'Content-Type':'application/json','X-CSRF-Token':sii.csrf}:{}),...(typeof cabeceraEmpresa==='function'?cabeceraEmpresa():{})},body:body?JSON.stringify(body):undefined});
  const data=await res.json().catch(()=>({}));if(!res.ok)throw new Error(data.error||('Error '+res.status+' del servidor.'));return data;
 }
 async function centralRequest(method,body,query=''){
  if(body&&!sii.csrf){const token=await fetch(SII_API+'?csrf=1',{headers:typeof cabeceraEmpresa==='function'?cabeceraEmpresa():{}});const data=await token.json().catch(()=>({}));if(!token.ok||!data.csrf)throw new Error(data.error||'No se pudo validar la sesión.');sii.csrf=data.csrf;}
  const res=await fetch(CENTRAL_API+query,{method,headers:{...(body?{'Content-Type':'application/json','X-CSRF-Token':sii.csrf}:{}),...(typeof cabeceraEmpresa==='function'?cabeceraEmpresa():{})},body:body?JSON.stringify(body):undefined});
  const data=await res.json().catch(()=>({}));if(!res.ok)throw new Error(data.error||('Error '+res.status+' del servidor.'));return data;
 }
 const CENTRAL_FIELDS=[['cuenta_compra_default','Compras · neto y exento Del Giro'],['cuenta_iva_credito','Compras · IVA recuperable'],['cuenta_iva_uso_comun','Compras · IVA de uso común'],['cuenta_iva_no_rec','Compras · IVA no recuperable'],['cuenta_otros_compra','Compras · otros impuestos/sin crédito'],['cuenta_proveedor_default','Compras · proveedores por pagar (predeterminada)'],['cuenta_venta_default','Ventas · neto y exento'],['cuenta_iva_debito','Ventas · IVA débito'],['cuenta_retencion_venta','Ventas · IVA retenido por recuperar'],['cuenta_otros_venta','Ventas · otros impuestos'],['cuenta_cliente','Ventas · clientes por cobrar'],['cuenta_redondeo','Ajustes de redondeo (resumen 48)'],['cuenta_hon_recibidas','Honorarios recibidos · gasto bruto'],['cuenta_hon_retencion_pagar','Honorarios recibidos · retención por pagar'],['cuenta_hon_proveedor_pagar','Honorarios recibidos · líquido por pagar'],['cuenta_hon_emitidas','Honorarios emitidos · ingreso bruto'],['cuenta_hon_retencion_recuperar','Honorarios emitidos · retención por recuperar'],['cuenta_hon_cliente','Honorarios emitidos · líquido por cobrar']];
 let centralConfig=null;
 function accountSelect(cuentas,value,label){const s=el('select',{class:'rcv-select'});s.append(el('option',{value:''},'Seleccione una cuenta'));for(const c of cuentas)s.append(el('option',{value:c.codigo},c.codigo+' · '+c.nombre));s.value=value||'';s.setAttribute('aria-label',label);return s;}
 function voucherEditor(voucher,title,role,cuentas){
  const editor=el('section',{class:'rcv-cent-voucher-editor','data-voucher-role':role},el('h5',null,title));
  const tipo=el('select',{class:'rcv-select','data-voucher-field':'tipo'},el('option',{value:'E'},'Egreso'),el('option',{value:'I'},'Ingreso'),el('option',{value:'T'},'Traspaso'));tipo.value=voucher.tipo;
  const fecha=el('input',{type:'date',class:'rcv-select','data-voucher-field':'fecha',value:voucher.fecha||''});
  const registro=el('select',{class:'rcv-select','data-voucher-field':'registro'},el('option',{value:'Tributario'},'Tributario'),el('option',{value:'IFRS'},'IFRS'),el('option',{value:'Ambos'},'Ambos'));registro.value=voucher.registro||'Tributario';
  const glosa=el('input',{type:'text',maxlength:'500',class:'rcv-select','data-voucher-field':'glosa',value:voucher.glosa||''});
  const campos=el('div',{class:'rcv-cent-voucher-fields'},...[["Tipo de voucher",tipo],["Fecha",fecha],["Registro",registro],["Glosa",glosa]].map(([label,input])=>el('label',{class:'rcv-cent-field'},label,input)));
  const lineas=el('div',{class:'rcv-cent-voucher-lines'});
  const encabezado=el('div',{class:'rcv-cent-line rcv-cent-line-head'},el('span',null,'Cuenta'),el('span',null,'Glosa'),el('span',null,'Debe'),el('span',null,'Haber'),el('span',null,''));lineas.append(encabezado);
  let indiceLinea=0;const agregarLinea=(linea={})=>{let opciones=cuentas;if(role==='pago'&&indiceLinea===1){const categoria=document.getElementById('rcv-cent-pago-tipo')?.value||'banco';opciones=centralConfig?.cuentas_pago?.[categoria]||[];}const row=el('div',{class:'rcv-cent-line'},accountSelect(opciones,linea.cuenta,'Cuenta contable'),el('input',{type:'text',maxlength:'200',class:'rcv-select','aria-label':'Glosa de la línea',value:linea.glosa||''}),el('input',{type:'number',min:'0',step:'1',inputmode:'numeric',class:'rcv-select','aria-label':'Debe',value:linea.debe||''}),el('input',{type:'number',min:'0',step:'1',inputmode:'numeric',class:'rcv-select','aria-label':'Haber',value:linea.haber||''}),el('button',{type:'button',class:'rcv-btn-secondary rcv-cent-remove-line','aria-label':'Quitar línea'},'×'));row.classList.add('rcv-cent-line-edit');lineas.append(row);indiceLinea++;};
  (voucher.lineas||[]).forEach(agregarLinea);
  const totales=el('div',{class:'rcv-cent-voucher-totals'}),refrescar=()=>{let debe=0,haber=0;for(const row of lineas.querySelectorAll('.rcv-cent-line-edit')){debe+=Number(row.children[2].value||0);haber+=Number(row.children[3].value||0);}totales.textContent=`Debe ${money(debe)} · Haber ${money(haber)} · ${debe===haber&&debe>0?'Cuadrado':`Diferencia ${money(Math.abs(debe-haber))}`}`;totales.classList.toggle('is-balanced',debe===haber&&debe>0);};
  const add=el('button',{type:'button',class:'rcv-btn-secondary rcv-cent-add-line'},'+ Agregar línea');lineas.addEventListener('click',e=>{if(e.target.closest('.rcv-cent-add-line')){agregarLinea({});refrescar();}else if(e.target.closest('.rcv-cent-remove-line')){if(lineas.querySelectorAll('.rcv-cent-line-edit').length>2)e.target.closest('.rcv-cent-line-edit').remove();refrescar();}});lineas.addEventListener('input',e=>{const row=e.target.closest('.rcv-cent-line-edit');if(row&&e.target===row.children[2]&&e.target.value)row.children[3].value='';if(row&&e.target===row.children[3]&&e.target.value)row.children[2].value='';refrescar();});lineas.addEventListener('change',refrescar);
  editor.append(campos,lineas,add,totales);refrescar();return editor;
 }
 function leerVoucherEditor(editor){return{tipo:editor.querySelector('[data-voucher-field="tipo"]').value,fecha:editor.querySelector('[data-voucher-field="fecha"]').value,registro:editor.querySelector('[data-voucher-field="registro"]').value,glosa:editor.querySelector('[data-voucher-field="glosa"]').value.trim(),lineas:[...editor.querySelectorAll('.rcv-cent-line-edit')].map(row=>({cuenta:row.children[0].value,glosa:row.children[1].value,debe:row.children[2].value||0,haber:row.children[3].value||0}))};}
 function voucherLinesPreview(voucher,title,cuentas,role){
  const nombres=new Map((cuentas||[]).map(c=>[String(c.codigo),String(c.nombre)])),lineas=voucher.lineas||[],debe=lineas.reduce((s,l)=>s+Number(l.debe||0),0),haber=lineas.reduce((s,l)=>s+Number(l.haber||0),0);
  const rows=lineas.map(l=>({cells:[`${l.cuenta} · ${nombres.get(String(l.cuenta))||'Cuenta'}`,money(l.debe),money(l.haber)]}));
  return el('div',{class:'rcv-cent-entry-preview','data-voucher-role':role},el('h6',null,title),table([{label:'Cuenta contable'},{label:'Debe',num:1},{label:'Haber',num:1}],rows),el('p',{class:'rcv-cent-entry-totals'},`Total Debe ${money(debe)} · Total Haber ${money(haber)} · ${debe===haber?'Cuadrado':'Descuadrado'}`));
 }
 function renderPaymentOptions(){
  const tipo=document.getElementById('rcv-cent-pago-tipo'),cuenta=document.getElementById('rcv-cent-pago-cuenta'),check=document.getElementById('rcv-cent-pago-incluir'),wrap=document.getElementById('rcv-cent-pago-opciones');if(!tipo||!cuenta||!centralConfig)return;
  const previo=cuenta.value,grupo=centralConfig.cuentas_pago||{banco:[],caja:[]};
  tipo.replaceChildren(el('option',{value:'banco'},'Banco'),el('option',{value:'caja'},'Caja'));
  const preferido=(grupo.banco||[]).length?'banco':'caja',actual=tipo.value;tipo.value=(actual&&(grupo[actual]||[]).length)?actual:preferido;
  const poblar=()=>{const anterior=cuenta.value||previo;cuenta.replaceChildren(el('option',{value:''},'Seleccione una cuenta'));for(const c of grupo[tipo.value]||[])cuenta.append(el('option',{value:c.codigo},c.codigo+' · '+c.nombre));if([...cuenta.options].some(o=>o.value===anterior))cuenta.value=anterior;else if(cuenta.options.length>1)cuenta.selectedIndex=1;
   for(const editor of document.querySelectorAll('.rcv-cent-voucher-editor[data-voucher-role="pago"]')){const row=editor.querySelectorAll('.rcv-cent-line-edit')[1],select=row?.children[0];if(select){const account=accountSelect(grupo[tipo.value]||[],'','Cuenta Caja o Banco');select.replaceWith(account);}}
  };
  poblar();tipo.onchange=()=>{poblar();invalidarVistaCentral();};if(check&&wrap)wrap.hidden=!check.checked;
 }
 function invalidarVistaCentral(){const panel=document.getElementById('rcv-cent-preview');if(!panel)return;panel.dataset.serverReady='false';const msg=document.getElementById('rcv-cent-preview-msg');if(msg)msg.textContent='Cambiaste la selección de pagos o la cuenta. Actualiza la vista previa antes de confirmar.';const confirmar=document.getElementById('rcv-cent-confirmar');if(confirmar)confirmar.disabled=true;}
 function centralScope(){const periodo=document.getElementById('rcv-cent-periodo')?.value||'',libros=[...document.querySelectorAll('#rcv-sii-form input[name=libro]:checked')].map(i=>i.value),honorarios=[...document.querySelectorAll('#rcv-sii-form input[name=honorarios]:checked')].map(i=>i.value),registro=document.getElementById('rcv-cent-registro')?.value||'Tributario';return{desde:periodo,hasta:periodo,libros,honorarios,registro};}
 async function abrirConfigCentral(){
  const modal=document.getElementById('rcv-cent-modal'),body=document.getElementById('rcv-cent-config');body.replaceChildren(el('p',null,'Cargando plan de cuentas y proveedores…'));modal.hidden=false;
  try{centralConfig=await centralRequest('GET');renderConfigCentral();renderPaymentOptions();}catch(e){body.replaceChildren(el('p',{class:'rcv-msg'},e.message));}
 }
 async function abrirVistaCentralizacion(){
  const modal=document.getElementById('rcv-cent-modal');modal.hidden=false;
  try{if(!centralConfig)centralConfig=await centralRequest('GET');renderPaymentOptions();await previsualizarCentral();}
  catch(e){setCentralMsg(e.message);}
 }
 function renderConfigCentral(){
  const body=document.getElementById('rcv-cent-config');body.replaceChildren();const cuentas=centralConfig.cuentas||[],config=centralConfig.config||{};
  body.append(el('p',{class:'rcv-note'},'Se precargan cuentas sugeridas según el plan de esta empresa. Revisa las asignaciones y cambia cualquier cuenta antes de guardar.'));
  let seccionHonorarios=false;for(const [key,label] of CENTRAL_FIELDS){if(key.startsWith('cuenta_hon_')&&!seccionHonorarios){body.append(el('h4',null,'Boletas de honorarios'));body.append(el('p',{class:'rcv-note'},'Recibidas: gasto bruto y retención por pagar; emitidas: ingreso bruto y retención por recuperar.'));seccionHonorarios=true;}const row=el('label',{class:'rcv-cent-field'},label);row.append(accountSelect(cuentas,config[key],label));row.dataset.key=key;body.append(row);}
  body.append(el('h4',null,'Cuenta de gasto según naturaleza de compra'));
  for(const op of centralConfig.operaciones||[]){if(op.key==='del giro')continue;const row=el('label',{class:'rcv-cent-field'},op.nombre);const cuentasOperacion=config.cuentas_operacion||{};const cuenta=Object.prototype.hasOwnProperty.call(cuentasOperacion,op.key)?cuentasOperacion[op.key]:config.cuenta_compra_default;const sel=accountSelect(cuentas,cuenta,op.nombre);sel.dataset.operation=op.key;row.append(sel);body.append(row);}
  body.append(el('h4',null,'Cuenta por pagar de proveedores'));
  body.append(el('p',{class:'rcv-note'},'Los proveedores nuevos usan la cuenta predeterminada. Cambiar una asignación la reutiliza en centralizaciones futuras.'));
  if(!(centralConfig.proveedores||[]).length)body.append(el('p',{class:'rcv-empty'},'Los proveedores aparecerán aquí después de importar RCV de compras.'));
  for(const p of centralConfig.proveedores||[]){const row=el('label',{class:'rcv-cent-field'},(p.nombre||'Proveedor sin razón social')+' · RUT '+p.rut);const sel=accountSelect(cuentas,p.cuenta,'Cuenta por pagar '+p.rut);sel.options[0].textContent='Usar cuenta predeterminada ('+(config.cuenta_proveedor_default||'sin definir')+')';row.append(sel);row.dataset.rut=p.rut;body.append(row);}
 }
 async function guardarConfigCentral(){
  const body=document.getElementById('rcv-cent-config'),config={};for(const row of body.querySelectorAll('[data-key]'))config[row.dataset.key]=row.querySelector('select').value;
  config.cuentas_operacion={};for(const s of body.querySelectorAll('[data-operation]'))config.cuentas_operacion[s.dataset.operation]=s.value;
  const proveedores=[...body.querySelectorAll('[data-rut]')].map(row=>({rut:row.dataset.rut,cuenta:row.querySelector('select').value}));
  try{centralConfig=await centralRequest('PUT',{config,proveedores});setCentralMsg('Configuración guardada para esta empresa.');document.getElementById('rcv-cent-modal').hidden=true;}catch(e){setCentralMsg(e.message);}
 }
 function validarVistaCentral(){
  const panel=document.getElementById('rcv-cent-preview'),msg=document.getElementById('rcv-cent-preview-msg'),confirm=document.getElementById('rcv-cent-confirmar');if(!panel||!confirm)return false;
  const ready=panel.dataset.serverReady==='true';confirm.disabled=!ready;
  if(msg)msg.textContent=ready?'Vista previa validada. Revisa las cuentas y los montos antes de confirmar.':'Actualiza la vista previa después de cambiar la selección de pagos.';
  return ready;
 }
 async function previsualizarCentral(){
  const scope=centralScope();if(!scope.desde||(!state.periods.some(p=>p.period===scope.desde)&&!(sii.centralPeriodos||[]).some(p=>p.periodo===scope.desde))){setCentralMsg('Selecciona un período importado en la lista.');return;}if(!scope.libros.length&&!scope.honorarios.length){setCentralMsg('Selecciona al menos un libro: Compras, Ventas u Honorarios.');return;}
  try{
   if(!centralConfig)centralConfig=await centralRequest('GET');renderPaymentOptions();
   const panel=document.getElementById('rcv-cent-preview'),registrarPagos=Boolean(document.getElementById('rcv-cent-pago-incluir')?.checked),tipoCuentaPago=document.getElementById('rcv-cent-pago-tipo')?.value||'banco',cuentaPago=document.getElementById('rcv-cent-pago-cuenta')?.value||'';
   const pagosSourceKeys=registrarPagos?[...panel.querySelectorAll('.rcv-cent-pago-select:checked')].map(i=>i.value):[];
   if(pagosSourceKeys.length&&!cuentaPago)throw new Error('Selecciona una cuenta de Caja o Banco para los documentos que marcarás como pagados.');
   const r=await centralRequest('POST',{...scope,accion:'previsualizar',registrar_pagos:registrarPagos,pagos_source_keys:pagosSourceKeys,tipo_cuenta_pago:tipoCuentaPago,cuenta_pago:cuentaPago});panel.replaceChildren();
   const totalVouchers=Number(r.vouchers_totales??r.vouchers_nuevos),titulo=`Vista previa · ${totalVouchers} vouchers${registrarPagos?` (${r.vouchers_nuevos} de centralización y ${r.pagos_nuevos} de pago)`:''}`;
   panel.append(el('h4',null,titulo),el('p',null,`Período ${formatPeriod(r.desde)} · ${r.registro} · Compras ${money(r.totales.compras)} · Ventas ${money(r.totales.ventas)} · Honorarios recibidas ${money(r.totales.honorarios_recibidas||0)} · Honorarios emitidas ${money(r.totales.honorarios_emitidas||0)}`));
   if(registrarPagos)panel.append(el('p',{class:'rcv-note'},pagosSourceKeys.length?`Se conciliarán ${pagosSourceKeys.length} documento(s) desde ${tipoCuentaPago==='caja'?'Caja':'Banco'} · ${cuentaPago}.`:'Marca en la lista inferior únicamente los documentos cuyo pago vas a conciliar. No se generará ningún pago hasta que marques registros.'));
   if(r.resumenes_48_seleccionados)panel.append(el('p',{class:'rcv-note'},`Incluye ${r.resumenes_48_seleccionados} asiento(s) mensual(es) para resumen(es) de pago electrónico tipo 48.`));
   if(r.periodos_sin_datos?.length){const faltantes=r.periodos_sin_datos.slice(0,20).map(x=>formatPeriod(x.periodo)+' '+x.libro).join(', ');panel.append(el('p',{class:'rcv-note'},`Sin documentos importados (${r.periodos_sin_datos.length} combinaciones): ${faltantes}${r.periodos_sin_datos.length>20?'…':''}`));}
   panel.append(el('p',{class:'rcv-note'},`${r.ya_centralizados} documentos ya contabilizados${registrarPagos?`; ${r.pagos_nuevos} pagos seleccionados, ${r.pagos_ya_centralizados||0} ya conciliados`:' (se omiten para evitar duplicados)'}, ${r.cambiados} cambiados (revisión), ${r.no_incluir} “No Incluir” omitidos.`));
   const nombreLibro=x=>x.tipo==='honorarios'?(x.libro==='honorarios_emitidas'?'Honorarios emitidas':'Honorarios recibidas'):(x.libro==='compras'?'Compras':'Ventas');
   if(r.cambiados_lista?.length)panel.append(el('p',{class:'rcv-msg'},'Cambios detectados para revisión: '+r.cambiados_lista.slice(0,20).map(x=>nombreLibro(x)+' '+x.folio+' · '+x.rut).join(', ')+(r.cambiados_lista.length>20?'…':'')));
   if(r.errores?.length)panel.append(el('div',{class:'rcv-cent-errors'},...r.errores.slice(0,20).map(x=>el('p',null,`${formatPeriod(x.periodo)} ${nombreLibro(x)} · folio ${x.folio}: ${x.mensaje}`))));
   const ajustes=(r.items||[]).filter(x=>Number(x.ajuste_redondeo||0)!==0);if(ajustes.length)panel.append(el('p',{class:'rcv-note'},`El resumen 48 presenta una diferencia que se contabilizará en la cuenta de redondeo configurada.`));
   if(registrarPagos){
    const opciones=(r.pagos_disponibles||[]).map(p=>{const marcado=pagosSourceKeys.includes(p.source_key),check=el('input',{type:'checkbox',class:'rcv-cent-pago-select',value:p.source_key});check.checked=marcado;check.disabled=Boolean(p.pago_ya_centralizado);return el('label',{class:'rcv-cent-pay-option'},check,el('span',{class:'rcv-cent-pay-kind'},p.libro==='compras'?'Compra':'Venta'),el('span',{class:'rcv-cent-pay-folio'},p.folio),el('span',{class:'rcv-cent-pay-party'},p.contraparte||'Sin contraparte'),el('strong',{class:'rcv-cent-pay-total'},money(p.total)),el('span',{class:'rcv-cent-pay-status'},p.pago_ya_centralizado?'Pago ya conciliado':p.centralizado?'Voucher ya contabilizado · pago pendiente':'Pendiente de contabilizar'));});
    const lista=el('div',{class:'rcv-cent-payment-list'},...(opciones.length?opciones:[el('p',{class:'rcv-note'},'No hay documentos disponibles para conciliar pagos en este período.')]));
    panel.append(el('details',{class:'rcv-cent-payment-picker',open:''},el('summary',null,`Seleccionar documentos para pago · ${opciones.length} disponibles`),el('p',{class:'rcv-note'},'Selecciona solo las facturas que vas a conciliar. La vista previa genera un voucher de pago adicional por cada registro marcado.'),lista));
   }
   const cuentas=centralConfig.cuentas||[],asientos=[];
   for(const item of r.items||[]){
    const clase=item.tipo==='honorarios'?(item.libro==='honorarios_emitidas'?'Honorario emitido':'Honorario recibido'):(item.libro==='compras'?'Compra':'Venta');const titulo=`${formatPeriod(item.periodo)} · ${clase} · ${item.folio} · ${item.contraparte||''}`;
    const doc=el('article',{class:'rcv-cent-document','data-source-key':item.source_key,'data-label':titulo},el('header',{class:'rcv-cent-document-head'},el('h5',null,`${item.solo_pago?'Pago pendiente · ':''}${titulo}`),el('strong',null,money(item.total))));
    if(item.solo_pago)doc.append(el('p',{class:'rcv-note'},`Voucher de centralización existente N° ${item.voucher_existente_numero}. Se agregará únicamente el pago.`));
    else doc.append(voucherLinesPreview(item.voucher,'Asiento de centralización',cuentas,'principal'));
    if(item.pago_voucher)doc.append(voucherLinesPreview(item.pago_voucher,'Asiento de pago',cuentas,'pago'));
    asientos.push(doc);
   }
   if(asientos.length){panel.append(el('h4',null,'Asientos por documento'),el('p',{class:'rcv-note'},'Vista de solo lectura: cada asiento muestra las cuentas involucradas y sus montos en Debe y Haber.'),...asientos);}
   panel.append(el('p',{id:'rcv-cent-preview-msg',class:'rcv-msg',role:'status'}));const seleccionCompleta=pagosSourceKeys.every(key=>(r.items||[]).some(item=>item.source_key===key&&item.pago_voucher));panel.dataset.serverReady=String(!r.errores?.length&&totalVouchers>0&&seleccionCompleta);panel.dataset.registrarPagos=String(registrarPagos);panel.dataset.pagosSourceKeys=JSON.stringify(pagosSourceKeys);panel.dataset.tipoCuentaPago=tipoCuentaPago;panel.dataset.cuentaPago=cuentaPago;panel.dataset.scope=JSON.stringify(scope);
   const confirm=document.getElementById('rcv-cent-confirmar');confirm.textContent=`Confirmar y crear ${totalVouchers} vouchers`;panel.onchange=e=>{if(e.target.matches('.rcv-cent-pago-select'))invalidarVistaCentral();};document.getElementById('rcv-cent-modal').hidden=false;validarVistaCentral();setCentralMsg('');
  }catch(e){setCentralMsg(e.message);}
 }
 async function confirmarCentral(){const panel=document.getElementById('rcv-cent-preview');if(!validarVistaCentral())return;const scope=JSON.parse(panel.dataset.scope),registrarPagos=panel.dataset.registrarPagos==='true',pagosSourceKeys=JSON.parse(panel.dataset.pagosSourceKeys||'[]');const button=document.getElementById('rcv-cent-confirmar');button.disabled=true;
  try{const r=await centralRequest('POST',{...scope,accion:'centralizar',registrar_pagos:registrarPagos,pagos_source_keys:pagosSourceKeys,tipo_cuenta_pago:panel.dataset.tipoCuentaPago,cuenta_pago:panel.dataset.cuentaPago});panel.append(el('p',{class:'rcv-sii-completada'},`Se crearon ${r.vouchers_creados??r.creados} vouchers (${r.creados} de centralización y ${r.pagos_creados||0} de pago); ${r.resumenes_48_centralizados||0} resumen(es) 48, ${r.ya_centralizados} ya estaban contabilizados, ${r.cambiados} requieren revisión y ${r.no_incluir} fueron omitidos.`));button.textContent='Centralización completada';button.disabled=true;panel.dataset.serverReady='false';setCentralMsg(`Centralización finalizada: ${r.vouchers_creados??r.creados} vouchers creados.`);await refreshCentralEstados();renderSii();}
  catch(e){button.disabled=false;setCentralMsg(e.message);}
 }
 function setCentralMsg(text){const m=document.getElementById('rcv-cent-msg');if(m)m.textContent=text||'';}
 async function refreshCentralEstados(){try{const r=await centralRequest('GET',null,'?estados_periodos=1');sii.centralEstados={};sii.centralPeriodos=r.periodos||[];for(const p of sii.centralPeriodos)sii.centralEstados[p.periodo]=p.libros||{};renderCentralPeriods();}catch(e){setCentralMsg('No se pudo actualizar el estado de contabilización: '+e.message);}}
 function centralizarPeriodoDesdeFila(periodo,libros){
  const selector=document.getElementById('rcv-cent-periodo');if(selector&&[...selector.options].some(o=>o.value===periodo))selector.value=periodo;
  document.querySelectorAll('#rcv-sii-form input[name=libro]').forEach(i=>{i.checked=libros.includes(i.value);});
  previsualizarCentral();
 }
 function renderSii(){
  const list=document.getElementById('rcv-sii-tareas');if(!list)return;
   const grupos=new Map();for(const t of sii.tareas){if(!grupos.has(t.periodo))grupos.set(t.periodo,{});const key=(t.tipo||'rcv')+':'+t.libro;const actual=grupos.get(t.periodo)[key];if(!actual||Number(t.id)>Number(actual.id))grupos.get(t.periodo)[key]=t;}
  const filas=[...grupos.entries()].sort((a,b)=>b[0].localeCompare(a[0])).map(([periodo,tareas])=>{
    const tareasCompletas=Object.values(tareas).filter(t=>t.estado==='completada');const librosCompletos=tareasCompletas.filter(t=>(t.tipo||'rcv')==='rcv').map(t=>t.libro);const honorariosCompletos=tareasCompletas.filter(t=>t.tipo==='honorarios').map(t=>'honorarios_'+t.libro);
    const detalle=Object.values(tareas).map(t=>(t.tipo==='honorarios'?'Honorarios '+t.libro:(t.libro==='compras'?'Compras':'Ventas'))+' '+(ESTADO_SII[t.estado]||t.estado)).join(' · ');
   const estados=sii.centralEstados[periodo]||{};const cuenta=[...librosCompletos,...honorariosCompletos].map(libro=>{const e=estados[libro],nombre=libro==='compras'?'Compras':libro==='ventas'?'Ventas':libro==='honorarios_recibidas'?'Honorarios recibidas':'Honorarios emitidas';return nombre+' '+(e?.estado==='contabilizado'?'contabilizado':e?.estado==='parcial'?'parcialmente contabilizado':e?.estado==='revision'?'requiere revisión':'pendiente');}).join(' · ');
   const errores=Object.values(tareas).some(t=>t.estado==='error');
   const centralizados=[...librosCompletos,...honorariosCompletos];const contabilizado=centralizados.length>0&&centralizados.every(l=>estados[l]?.estado==='contabilizado');
   const completado=centralizados.length>0;
   const claseEstado=errores?'rcv-periodo-error':contabilizado?'rcv-periodo-contabilizado':completado?'rcv-periodo-completado':'';
   const li=el('li',{class:claseEstado},`SII · ${formatPeriod(periodo)} · ${detalle}${centralizados.length?' · Contabilización: '+cuenta:''}`);
   return li;
  });
  list.replaceChildren(...(filas.length?filas:[el('li',null,'Sin sincronizaciones todavía.')]));
  const claveEstado=document.getElementById('rcv-sii-clave-estado');
  if(claveEstado)claveEstado.textContent=!sii.cifrado?'El servidor no tiene disponible el cifrado seguro.':(sii.clave?'Clave configurada en la ficha de empresa.':'Falta configurar la Clave Tributaria en la ficha de empresa.');
  const sync=document.querySelector('#rcv-sii-form [type="submit"]');
  if(sync)sync.disabled=!sii.servicioDisponible||!sii.clave||!sii.cifrado;
  if(!sii.servicioDisponible)setSiiMsg('La sincronización no está disponible temporalmente. Inténtalo más tarde.');
  else if(!sii.clave)setSiiMsg('Configura la Clave Tributaria desde Empresas → Editar empresa para solicitar la sincronización.');
  else if(!sii.cifrado)setSiiMsg('La sincronización no está disponible temporalmente. Inténtalo más tarde.');
 }
 async function refreshVpsLogs(){
  const panel=document.getElementById('rcv-vps-terminal');if(!panel)return;
  const estado=document.getElementById('rcv-vps-status'),salida=document.getElementById('rcv-vps-output');
  try{
   const r=await siiRequest('GET',null,'?logs=1');
   estado.textContent=r.agente?.conectado?'VPS conectado · actividad del agente actualizada':'VPS desconectado · mostrando los últimos eventos recibidos';
    const lines=(r.eventos||[]).map(ev=>{const hora=String(ev.creado_en||'').replace('T',' ').slice(0,19);const periodo=ev.periodo?formatPeriod(ev.periodo):'consulta';const libro=['compras','ventas'].includes(ev.libro)?ev.libro:['recibidas','emitidas'].includes(ev.libro)?'honorarios '+ev.libro:'';const detalle=ev.etapa==='completada'&&/^\d{1,9}$/.test(String(ev.detalle||''))?` · ${ev.detalle} registros`:'';return `[${hora}] tarea ${ev.tarea_id} · ${[libro,periodo].filter(Boolean).join(' ')} · ${ETAPAS_SII[ev.etapa]||'Actualización del agente'}${detalle}`;});
   salida.textContent=lines.length?lines.join('\n'):'Aún no hay eventos de tareas RCV.';
   salida.scrollTop=salida.scrollHeight;
  }catch(e){estado.textContent=e.message||'No se pudieron cargar los logs del agente.';}
 }
 async function refreshActividad(){
  const lista=document.getElementById('rcv-sii-actividad'),titulo=document.getElementById('rcv-sii-actividad-titulo'),captura=document.getElementById('rcv-sii-captura');if(!lista)return;
  const tarea=sii.tareas.find(t=>t.estado==='en_proceso'||t.estado==='cancelando'||t.estado==='reencolando')||sii.tareas[0];
  if(!tarea){sii.actividadId=0;titulo.textContent='El agente aún no ha procesado solicitudes.';lista.replaceChildren();captura?.replaceChildren();return;}
   titulo.textContent=`${tarea.tipo==='honorarios'?'Honorarios '+tarea.libro:(tarea.libro==='compras'?'Compras':tarea.libro==='ventas'?'Ventas':'Consulta')} · ${formatPeriod(tarea.periodo)} · ${ESTADO_SII[tarea.estado]||tarea.estado}`;
  if(tarea.id!==sii.capturaId){if(sii.capturaUrl)URL.revokeObjectURL(sii.capturaUrl);sii.capturaId=tarea.id;sii.capturaUrl='';captura?.replaceChildren();}
  if(tarea.id!==sii.actividadId){sii.actividadId=tarea.id;lista.replaceChildren(el('li',null,tarea.estado==='pendiente'?'En espera; el registro aparecerá cuando el agente inicie esta tarea.':'Cargando actividad…'));}
  if(!['en_proceso','cancelando','reencolando','completada','error','cancelada'].includes(tarea.estado))return;
  try{
   const r=await siiRequest('GET',null,'?eventos='+encodeURIComponent(tarea.id));
   const items=(r.eventos||[]).map(ev=>{const hora=String(ev.creado_en||'').slice(11,19);const mensaje=ETAPAS_SII[ev.etapa]||'Actualización del agente';const detalle=ev.etapa==='completada'&&/^\d+$/.test(ev.detalle||'')?` · ${ev.detalle} documentos` : '';return el('li',null,el('time',null,hora),el('span',null,mensaje+detalle));});
   lista.replaceChildren(...(items.length?items:[el('li',null,tarea.estado==='en_proceso'?'Conectado; esperando la siguiente actualización…':'No hay eventos de actividad para esta tarea.')]));
   if(tarea.estado==='error'&&!sii.capturaUrl){const res=await fetch(SII_API+'?captura='+encodeURIComponent(tarea.id),{headers:typeof cabeceraEmpresa==='function'?cabeceraEmpresa():{}}).catch(()=>null);if(res?.ok){const blob=await res.blob();if(blob.type==='image/jpeg'&&sii.capturaId===tarea.id)sii.capturaUrl=URL.createObjectURL(blob);}}
   captura?.replaceChildren();if(tarea.estado==='error'&&sii.capturaUrl&&captura){const img=el('img',{src:sii.capturaUrl,alt:'Captura del módulo RCV al ocurrir el error',loading:'lazy'});captura.append(el('details',{class:'rcv-agent-capture'},el('summary',null,'Ver captura del SII'),img,el('p',{class:'rcv-note'},'Captura temporal; solo tú puedes verla y vence en 48 horas.')));}
  }catch{lista.replaceChildren(el('li',null,'No se pudo cargar el registro de actividad.'));}
 }
 async function refreshSii(){
  clearTimeout(sii.timer);
  const anteriores=new Map(sii.tareas.map(t=>[t.id,t.estado]));
  const datos=await siiRequest('GET',null,'?panel=1');
  sii.tareas=datos.tareas;sii.administrador=Boolean(datos.administrador);sii.clave=Boolean(datos.clave_configurada);sii.cifrado=Boolean(datos.cifrado_disponible);sii.servicioDisponible=Boolean(datos.sincronizacion_disponible);
  await refreshCentralEstados();
  renderSii();
  await refreshActividad();
  await refreshVpsLogs();
  const nuevas=sii.tareas.filter(t=>t.estado==='completada'&&anteriores.has(t.id)&&anteriores.get(t.id)!=='completada');
  if(nuevas.length){try{await refreshPeriods();await openPeriod(nuevas[0].periodo,true);}catch(e){setMsg(e.message);}}
  sii.timer=setTimeout(()=>refreshSii().catch(e=>setSiiMsg(e.message)),sii.tareas.some(t=>t.estado==='en_proceso'||t.estado==='cancelando'||t.estado==='reencolando')?4000:15000);
 }
 async function sincronizar(e){
  e.preventDefault();
  const desde=document.getElementById('rcv-sii-desde').value;
  const hasta=document.getElementById('rcv-sii-hasta').value;
   const libros=[...document.querySelectorAll('#rcv-sii-form input[name=libro]:checked')].map(i=>i.value);
   const honorarios=[...document.querySelectorAll('#rcv-sii-form input[name=honorarios]:checked')].map(i=>i.value);
  if(!desde||!hasta){setSiiMsg('Elija el primer y el último mes del rango.');return;}
  const [anioDesde,mesDesde]=desde.split('-').map(Number),[anioHasta,mesHasta]=hasta.split('-').map(Number);
  const cantidad=(anioHasta-anioDesde)*12+mesHasta-mesDesde+1;
  if(cantidad<1){setSiiMsg('El mes “Desde” debe ser anterior o igual al mes “Hasta”.');return;}
  if(cantidad>60){setSiiMsg('El rango máximo por solicitud es de 60 meses. Divide períodos más extensos en varios rangos.');return;}
   if(!libros.length&&!honorarios.length){setSiiMsg('Elija libros RCV o boletas de honorarios.');return;}
   const totalSolicitudes=cantidad*(libros.length+honorarios.length);
   if(cantidad>1&&!window.confirm(`Se solicitarán hasta ${totalSolicitudes} descargas para ${cantidad} meses. ¿Continuar?`))return;
  try{
     const r=await siiRequest('POST',{desde,hasta,libros,honorarios});
    const partes=[];
    if(r.creadas.length)partes.push(`Se solicitaron ${r.creadas.length} descargas para ${r.periodos} ${r.periodos===1?'mes':'meses'}.`);
    if(r.ya_en_cola.length)partes.push(`${r.ya_en_cola.length} ${r.ya_en_cola.length===1?'descarga ya estaba':'descargas ya estaban'} en cola.`);
    if(!partes.length)partes.push('No había descargas nuevas para encolar.');
    setSiiMsg(partes.join(' '));
    await refreshSii();
  }catch(err){setSiiMsg(err.message);}
 }
 function buildSii(){
  const hoy=new Date();const cerrado=new Date(hoy.getFullYear(),hoy.getMonth()-1,1);const mes=cerrado.getFullYear()+'-'+String(cerrado.getMonth()+1).padStart(2,'0');
   const check=(valor,texto,name='libro',checked=name==='libro')=>el('label',{class:'rcv-sii-check'},el('input',{type:'checkbox',name,value:valor,...(checked?{checked:''}:{})}),' '+texto);
  return el('div',{class:'rcv-import rcv-sii',id:'rcv-sii',hidden:''},
   el('h3',null,'Sincronizar con el SII'),
    el('p',null,'Descarga desde el SII los libros RCV y los informes mensuales de boletas de honorarios de esta empresa. Los libros marcados también se incluirán al previsualizar la centralización del período. La Clave Tributaria se guarda de forma segura y nunca se vuelve a mostrar.'),
   el('section',{id:'rcv-vps-terminal',class:'rcv-vps-terminal','aria-label':'Registro operativo del agente VPS'},el('div',{class:'rcv-vps-head'},el('h4',null,'Logs del agente VPS'),el('span',{id:'rcv-vps-status',role:'status'},'Conectando…')),el('pre',{id:'rcv-vps-output','aria-live':'polite'},'Esperando eventos del agente…'),el('p',{class:'rcv-note'},'Registro de actividad RCV de solo lectura. No muestra credenciales ni permite ejecutar comandos.')),
   el('div',{class:'rcv-sii-vinculo'},
    el('h4',null,'Clave Tributaria de esta empresa'),
    el('p',{id:'rcv-sii-clave-estado',class:'rcv-msg',role:'status'},'Consultando…'),
    el('a',{href:'empresas.html'},'Administrar clave desde Empresas → Editar empresa')),
   el('form',{id:'rcv-sii-form',class:'rcv-sii-form'},
    el('label',null,'Desde ',el('input',{type:'month',id:'rcv-sii-desde',class:'rcv-select',value:mes,'aria-label':'Primer mes del rango'})),
    el('label',null,'Hasta ',el('input',{type:'month',id:'rcv-sii-hasta',class:'rcv-select',value:mes,'aria-label':'Último mes del rango'})),
     check('compras','Compras'),check('ventas','Ventas'),check('recibidas','Honorarios recibidas','honorarios',false),check('emitidas','Honorarios emitidas','honorarios',false),
    el('label',null,'Período a centralizar ',el('select',{id:'rcv-cent-periodo',class:'rcv-select','aria-label':'Período RCV completado a centralizar'},el('option',{value:''},'Cargando períodos…'))),
    el('label',null,'Registro ',el('select',{id:'rcv-cent-registro',class:'rcv-select'},el('option',{value:'Tributario'},'Tributario'),el('option',{value:'IFRS'},'IFRS'),el('option',{value:'Ambos'},'Ambos'))),
    el('span',{class:'rcv-note'},'Selecciona los libros RCV y/o de honorarios que quieres centralizar en este período. La vista previa mostrará cada asiento antes de confirmar.'),
    el('button',{type:'submit',class:'rcv-btn',disabled:''},'Sincronizar rango'),
    el('button',{type:'button',id:'rcv-cent-config-btn',class:'rcv-btn-secondary'},'Configurar centralización'),
    el('button',{type:'button',id:'rcv-cent-run-btn',class:'rcv-btn'},'Previsualizar centralización')),
   el('p',{id:'rcv-sii-msg',class:'rcv-msg',role:'status'}),
   el('p',{id:'rcv-cent-msg',class:'rcv-msg',role:'status'}),
   el('ul',{id:'rcv-sii-tareas',class:'rcv-files'}),
   el('section',{class:'rcv-agent-log','aria-live':'polite'},el('h4',null,'Actividad de la tarea seleccionada'),el('p',{id:'rcv-sii-actividad-titulo',class:'rcv-note'},'Consultando…'),el('ol',{id:'rcv-sii-actividad'}),el('div',{id:'rcv-sii-captura'})),
   el('div',{id:'rcv-cent-modal',class:'rcv-cent-modal',hidden:''},el('div',{class:'rcv-cent-dialog',role:'dialog','aria-modal':'true','aria-labelledby':'rcv-cent-title'},
    el('div',{class:'rcv-cent-head'},el('h3',{id:'rcv-cent-title'},'Centralización de RCV'),el('button',{type:'button',id:'rcv-cent-close','aria-label':'Cerrar'},'×')),
    el('div',{id:'rcv-cent-config',class:'rcv-cent-config'}),
    el('div',{class:'rcv-cent-payment'},el('h4',null,'Conciliar pagos'),el('p',{class:'rcv-note'},'El RCV no informa qué documentos están pagados. Activa la selección y marca únicamente los registros cuyo pago vas a conciliar; cada selección crea un voucher de pago adicional. En comprobantes tipo 48 se concilia el resumen mensual completo.'),el('label',{class:'rcv-cent-payment-check'},el('input',{type:'checkbox',id:'rcv-cent-pago-incluir'}),' Habilitar selección de documentos para pago'),el('div',{id:'rcv-cent-pago-opciones',class:'rcv-cent-payment-options',hidden:''},el('label',{class:'rcv-cent-field'},'Cuenta de pago ',el('select',{id:'rcv-cent-pago-tipo',class:'rcv-select'},el('option',{value:'banco'},'Banco'),el('option',{value:'caja'},'Caja'))),el('label',{class:'rcv-cent-field'},'Cuenta contable ',el('select',{id:'rcv-cent-pago-cuenta',class:'rcv-select'},el('option',{value:''},'Seleccione una cuenta'))))),
    el('div',{class:'rcv-cent-actions'},el('button',{type:'button',id:'rcv-cent-save',class:'rcv-btn'},'Guardar configuración'),el('button',{type:'button',id:'rcv-cent-preview-btn',class:'rcv-btn-secondary'},'Actualizar vista previa')),
    el('div',{id:'rcv-cent-preview',class:'rcv-cent-preview'}),
    el('div',{class:'rcv-cent-actions'},el('button',{type:'button',id:'rcv-cent-confirmar',class:'rcv-btn',disabled:''},'Confirmar y crear vouchers')))));
 }

 function init(){
  const host=document.getElementById('contenido_rcv');if(!host)return;
  const panel=el('details',{class:'rcv-import rcv-detail',id:'rcv-import'},
   el('summary',null,'Detalle del período'),
   el('p',{id:'rcv-import-msg',class:'rcv-msg',role:'status'}),
   el('div',{id:'rcv-import-result'},el('p',{class:'rcv-empty'},'Selecciona un período para consultar sus libros.')));
  host.prepend(panel);
  const sync=buildSii();host.prepend(sync);
  document.getElementById('rcv-sii-form').addEventListener('submit',sincronizar);
  document.getElementById('rcv-cent-config-btn').addEventListener('click',abrirConfigCentral);
  document.getElementById('rcv-cent-run-btn').addEventListener('click',abrirVistaCentralizacion);
  document.getElementById('rcv-cent-close').addEventListener('click',()=>{document.getElementById('rcv-cent-modal').hidden=true;});
  document.getElementById('rcv-cent-save').addEventListener('click',guardarConfigCentral);
  document.getElementById('rcv-cent-preview-btn').addEventListener('click',previsualizarCentral);
  document.getElementById('rcv-cent-confirmar').addEventListener('click',confirmarCentral);
  document.getElementById('rcv-cent-pago-incluir').addEventListener('change',e=>{document.getElementById('rcv-cent-pago-opciones').hidden=!e.target.checked;invalidarVistaCentral();});
  for(const id of ['rcv-cent-pago-tipo','rcv-cent-pago-cuenta'])document.getElementById(id).addEventListener('change',invalidarVistaCentral);
  document.getElementById('rcv-cent-periodo').addEventListener('change',e=>{const periodo=e.target.value;document.getElementById('rcv-import').open=Boolean(periodo);openPeriod(periodo).catch(e=>setMsg(e.message));});
  // Con libros cargados, la lupa de "Tipo RCV" lleva al libro elegido en vez del aviso de operación desactivada.
  document.getElementById('btn_rcv_filtro')?.addEventListener('click',e=>{if(!state.files.length)return;e.preventDefault();e.stopPropagation();showBook();});
  connect();
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})(typeof window!=='undefined'?window:globalThis);
