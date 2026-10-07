'use strict';
// Pantallas de Voucher, Libro Diario, Libro Mayor, Balance General, Estado de Resultado y Libros de Compras y Ventas
// conectadas a la API PHP (api/*.php).
// La base de datos y la validación definitiva están en PHP; aquí solo se muestran totales en vivo.
// Las funciones puras de la primera parte se prueban con `npm test`.
(function(){
const TIPOS={I:'Ingreso',E:'Egreso',T:'Traspaso'};
const REGISTROS=['Ambos','IFRS','Tributario'];

const monto=v=>{const n=Number(v||0);return Number.isFinite(n)?Math.round(n):0;};
function totales(v){let debe=0,haber=0;for(const l of v.lineas||[]){debe+=monto(l.debe);haber+=monto(l.haber);}return {debe,haber,diferencia:debe-haber};}
function formato(n){return Math.round(n).toLocaleString('es-CL');}
function fechaCorta(f){const [a,m,d]=String(f).split('-');return `${d}/${m}/${a}`;}
function csv(filas){return '﻿'+filas.map(f=>f.map(c=>{const s=String(c??'');return /[;"\r\n]/.test(s)?'"'+s.replace(/"/g,'""')+'"':s;}).join(';')).join('\r\n');}
function filasDiario(libro){
 const filas=[['Fecha','Tipo','N°','Glosa voucher','Código','Cuenta','Glosa línea','Debe','Haber']];
 for(const a of libro.asientos)for(const l of a.lineas)filas.push([fechaCorta(a.fecha),a.tipoNombre,a.numero,a.glosa,l.cuenta,l.nombre,l.glosa,l.debe,l.haber]);
 filas.push(['','','','','','','Total',libro.debe,libro.haber]);return filas;
}
function filasMayor(mayor){
 const filas=[['Código','Cuenta','Fecha','Tipo','N°','Glosa','Debe','Haber','Saldo']];
 for(const c of mayor){filas.push([c.codigo,c.nombre,'','','','Saldo anterior','','',c.saldoAnterior]);for(const m of c.movimientos)filas.push([c.codigo,c.nombre,fechaCorta(m.fecha),m.tipoNombre,m.numero,m.glosa,m.debe,m.haber,m.saldo]);filas.push([c.codigo,c.nombre,'','','','Total',c.debe,c.haber,c.saldo]);}
 return filas;
}
function filasBalance(b){
 const filas=[['Código','Cuenta','Débitos','Créditos','Saldo deudor','Saldo acreedor','Activo','Pasivo','Pérdidas','Ganancias']];
 for(const c of b.cuentas)filas.push([c.codigo,c.nombre,c.debitos,c.creditos,c.deudor,c.acreedor,c.activo,c.pasivo,c.perdida,c.ganancia]);
 const t=b.totales,a=b.ajuste,s=b.sumasIguales;
 filas.push(['','Sumas',t.debitos,t.creditos,t.deudor,t.acreedor,t.activo,t.pasivo,t.perdida,t.ganancia]);
 filas.push(['',b.resultado>=0?'Utilidad del ejercicio':'Pérdida del ejercicio','','','','',a.activo,a.pasivo,a.perdida,a.ganancia]);
 filas.push(['','Sumas iguales','','','','',s.activo,s.pasivo,s.perdida,s.ganancia]);return filas;
}
function filasResultado(r){
 const filas=[['Código','Cuenta','Monto']];
 filas.push(['','Ingresos','']);for(const c of r.ingresos)filas.push([c.codigo,c.nombre,c.monto]);filas.push(['','Total ingresos',r.totalIngresos]);
 filas.push(['','Costos y gastos','']);for(const c of r.gastos)filas.push([c.codigo,c.nombre,c.monto]);filas.push(['','Total costos y gastos',r.totalGastos]);
 filas.push(['',r.resultado>=0?'Utilidad del ejercicio':'Pérdida del ejercicio',r.resultado]);return filas;
}
// Columnas propias de cada libro del RCV además de exento, neto e IVA.
const RCV_EXTRA={compras:[['ivaNoRec','IVA no recuperable'],['ivaUsoComun','IVA uso común']],ventas:[['ivaRetenido','IVA retenido']]};
const DOCS_SII={30:'Factura',32:'Factura exenta',33:'Factura electrónica',34:'Factura exenta electrónica',35:'Boleta',38:'Boleta exenta',39:'Boleta electrónica',41:'Boleta exenta electrónica',45:'Factura de compra',46:'Factura de compra electrónica',48:'Comprobante de pago electrónico',55:'Nota de débito',56:'Nota de débito electrónica',60:'Nota de crédito',61:'Nota de crédito electrónica',101:'Factura de exportación',110:'Factura de exportación electrónica',111:'Nota de débito de exportación electrónica',112:'Nota de crédito de exportación electrónica',914:'Declaración de ingreso (DIN)'};
const nombreDoc=t=>DOCS_SII[t]||'Documento '+t;
function filasLibroRcv(libro){
 const extra=RCV_EXTRA[libro.libro]||[],firmado=(d,c)=>d.signo*(d[c]||0)||0;
 const filas=[['Período','Fecha','Tipo','Folio','RUT',libro.libro==='compras'?'Proveedor':'Cliente','Exento','Neto','IVA',...extra.map(e=>e[1]),'Otros impuestos','Total']];
 for(const d of libro.docs)filas.push([d.periodo,d.fecha,nombreDoc(d.tipo),d.folio,d.rut,d.razon,firmado(d,'exento'),firmado(d,'neto'),firmado(d,'iva'),...extra.map(e=>firmado(d,e[0])),firmado(d,'otros'),firmado(d,'total')]);
 const t=libro.totales;filas.push(['','',`Total: ${t.documentos} ${t.documentos===1?'documento':'documentos'}`,'','','',t.exento,t.neto,t.iva,...extra.map(e=>t[e[0]]),t.otros,t.total]);return filas;
}

function filasLibroHonorarios(libro){
 const filas=[['Período','Fecha','N°','Estado','RUT','Nombre o razón social','Soc. prof.','Bruto','Retenido','Pagado']];
 for(const b of libro.boletas)filas.push([b.periodo,b.fecha,b.numero,b.estado,b.rut,b.nombre,b.socProf?'Sí':'No',b.bruto,b.retenido,b.pagado]);
 const t=libro.totales,vig=t.boletas-t.anuladas;filas.push(['','',`Total: ${vig} ${vig===1?'boleta vigente':'boletas vigentes'}`+(t.anuladas?` (${t.anuladas} anuladas no suman)`:''),'','','','',t.bruto,t.retenido,t.pagado]);return filas;
}

function filasLibroInventario(datos){
 const filas=[['Categoría','Código','Descripción','Cantidad','Valor Unitario','Valor Total']];
 if(datos.inventario&&datos.inventario.categorias){
  for(const cat of datos.inventario.categorias){
   for(const item of cat.items){
    filas.push([cat.nombre,item.codigo,item.descripcion,item.cantidad,item.valor_unitario,item.cantidad*item.valor_unitario]);
   }
   filas.push(['SUBTOTAL '+cat.nombre,'','',cat.cantidad,'',cat.valor_total]);
  }
  const inv=datos.inventario;filas.push(['','','TOTAL INVENTARIO',inv.total_cantidad,'',inv.total_valor]);
 }
 filas.push(['','','','','','']);
 filas.push(['BALANCE GENERAL (al cierre del período)','','','','','']);
 if(datos.balance&&datos.balance.cuentas){
  for(const c of datos.balance.cuentas)filas.push([c.codigo,c.nombre,'',c.debitos,c.creditos,'']);
  const b=datos.balance.totales;filas.push(['TOTALES','','',b.debitos,b.creditos,'']);
 }
 return filas;
}

// ---------- Excel (.xlsx) ----------
// Libro de una hoja escrito a mano (ZIP sin compresión + SpreadsheetML), sin librerías externas.
// Arriba van las líneas de cabecera (empresa, título, período); los números quedan como números con formato #.##0.
const CRC_TABLA=(()=>{const t=new Uint32Array(256);for(let n=0;n<256;n++){let c=n;for(let k=0;k<8;k++)c=c&1?0xEDB88320^(c>>>1):c>>>1;t[n]=c>>>0;}return t;})();
function crc32(bytes){let c=0xFFFFFFFF;for(const b of bytes)c=CRC_TABLA[(c^b)&0xFF]^(c>>>8);return (c^0xFFFFFFFF)>>>0;}
function zip(archivos){
 const cod=new TextEncoder(),partes=[],central=[];let offset=0;
 const u16=n=>[n&255,(n>>>8)&255],u32=n=>[n&255,(n>>>8)&255,(n>>>16)&255,(n>>>24)&255];
 for(const [nombre,texto] of archivos){
  const n=cod.encode(nombre),d=cod.encode(texto),crc=crc32(d);
  // Versión 2.0, bit 11 = nombres UTF-8, método 0 (sin compresión), fecha 1/1/1980.
  const comun=[...u16(20),...u16(0x0800),...u16(0),...u16(0),...u16(0x21),...u32(crc),...u32(d.length),...u32(d.length),...u16(n.length),...u16(0)];
  const local=Uint8Array.from([...u32(0x04034b50),...comun]);
  partes.push(local,n,d);
  central.push(Uint8Array.from([...u32(0x02014b50),...u16(20),...comun,...u16(0),...u16(0),...u16(0),...u32(0),...u32(offset)]),n);
  offset+=local.length+n.length+d.length;
 }
 const tamCentral=central.reduce((t,p)=>t+p.length,0);
 const fin=Uint8Array.from([...u32(0x06054b50),...u16(0),...u16(0),...u16(archivos.length),...u16(archivos.length),...u32(tamCentral),...u32(offset),...u16(0)]);
 const todo=[...partes,...central,fin],salida=new Uint8Array(todo.reduce((t,p)=>t+p.length,0));
 let i=0;for(const p of todo){salida.set(p,i);i+=p.length;}
 return salida;
}
const xml=v=>String(v??'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])).replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F]/g,'');
function columna(i){let s='';for(i++;i;i=Math.floor((i-1)/26))s=String.fromCharCode(65+(i-1)%26)+s;return s;}
// Estilos: 0 normal, 1 negrita, 2 número, 3 número en negrita, 4 título, 5 encabezado de tabla.
function xlsx({hoja='Reporte',cabecera=[],filas,destacar=()=>false}){
 const [titulos,...cuerpo]=filas,ancho=titulos.map(t=>String(t).length);
 const celda=(v,f,c,estilo)=>{const ref=columna(c)+f;
  if(typeof v==='number'&&Number.isFinite(v))return `<c r="${ref}" s="${estilo&1?3:2}"><v>${v}</v></c>`;
  if(v===''||v==null)return estilo?`<c r="${ref}" s="${estilo}"/>`:'';
  return `<c r="${ref}" t="inlineStr"${estilo?` s="${estilo}"`:''}><is><t xml:space="preserve">${xml(v)}</t></is></c>`;};
 const filasXml=[];let f=0;
 cabecera.forEach((linea,i)=>{f++;filasXml.push(`<row r="${f}">${celda(linea,f,0,i===0?4:1)}</row>`);});
 if(cabecera.length)f++;
 f++;const filaTitulos=f;filasXml.push(`<row r="${f}">${titulos.map((t,c)=>celda(t,f,c,5)).join('')}</row>`);
 cuerpo.forEach((fila,j)=>{f++;const neg=destacar(fila,j,cuerpo.length)?1:0;
  fila.forEach((v,c)=>{const largo=typeof v==='number'?formato(v).length+1:String(v??'').length;if(largo>(ancho[c]||0))ancho[c]=largo;});
  filasXml.push(`<row r="${f}">${fila.map((v,c)=>celda(v,f,c,neg)).join('')}</row>`);});
 const cols=ancho.map((a,c)=>`<col min="${c+1}" max="${c+1}" width="${Math.min(Math.max(a+2,8),60)}" customWidth="1"/>`).join('');
 const nombre=String(hoja).replace(/[\[\]:*?\/\\]/g,' ').slice(0,31)||'Reporte';
 const hojaXml=`<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><sheetViews><sheetView workbookViewId="0"><pane ySplit="${filaTitulos}" topLeftCell="A${filaTitulos+1}" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>${cols}</cols><sheetData>${filasXml.join('')}</sheetData><pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>`;
 const estilos=`<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="3"><font><sz val="10"/><name val="Arial"/></font><font><b/><sz val="10"/><name val="Arial"/></font><font><b/><sz val="14"/><name val="Arial"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFDCE6F1"/></patternFill></fill></fills><borders count="2"><border/><border><bottom style="thin"><color rgb="FF7F7F7F"/></bottom></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="6"><xf xfId="0"/><xf xfId="0" fontId="1" applyFont="1"/><xf xfId="0" numFmtId="3" applyNumberFormat="1"/><xf xfId="0" numFmtId="3" fontId="1" applyNumberFormat="1" applyFont="1"/><xf xfId="0" fontId="2" applyFont="1"/><xf xfId="0" fontId="1" fillId="2" borderId="1" applyFont="1" applyFill="1" applyBorder="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>`;
 return zip([
  ['[Content_Types].xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>'],
  ['_rels/.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>'],
  ['xl/workbook.xml',`<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="${xml(nombre)}" sheetId="1" r:id="rId1"/></sheets></workbook>`],
  ['xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>'],
  ['xl/worksheets/sheet1.xml',hojaXml],
  ['xl/styles.xml',estilos],
 ]);
}

const core={TIPOS,REGISTROS,monto,totales,formato,fechaCorta,csv,filasDiario,filasMayor,filasBalance,filasResultado,filasLibroRcv,filasLibroHonorarios,nombreDoc,crc32,zip,xlsx};
if(typeof module!=='undefined'&&module.exports){module.exports=core;return;}

// ---------- Navegador ----------
const API='../api/';
class ErrorApi extends Error{constructor(errores){super(errores.join(' '));this.errores=errores;}}
async function api(ruta,opciones={}){
 let respuesta;
 try{respuesta=await fetch(API+ruta,{...opciones,headers:{'Content-Type':'application/json',...(typeof cabeceraEmpresa==='function'?cabeceraEmpresa():{}),...opciones.headers}});}
 catch{throw new ErrorApi(['No se pudo conectar con la API PHP. Inicie el servidor con "npm run start:php" o use Apache/XAMPP.']);}
 const datos=await respuesta.json().catch(()=>null);
 if(!datos)throw new ErrorApi(['La API PHP no respondió. Inicie el servidor con "npm run start:php" o use Apache/XAMPP (el servidor de "npm start" no ejecuta PHP).']);
 if(!respuesta.ok)throw new ErrorApi(datos.errores||(datos.error?[datos.error]:['Error '+respuesta.status]));
 return datos;
}
const consulta=o=>new URLSearchParams(Object.entries(o).filter(([,v])=>v!==undefined&&v!=='')).toString();
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const hoy=()=>{const d=new Date();return new Date(d.getTime()-d.getTimezoneOffset()*60000).toISOString().slice(0,10);};
const $=(s,r=document)=>r.querySelector(s);
const alerta=errores=>`<div class="alert alert-danger conta-errores" role="alert"><b>No se pudo completar la operación:</b><ul>${errores.map(x=>`<li>${esc(x)}</li>`).join('')}</ul></div>`;
function aviso(texto){document.querySelector('.offline-message')?.remove();const e=document.createElement('div');e.className='offline-message conta-aviso';e.setAttribute('role','status');e.textContent=texto;document.body.append(e);setTimeout(()=>e.remove(),4000);}
function aFecha(input){if(!input)return;input.type='date';input.removeAttribute('maxlength');input.classList.remove('hasDatepicker');}
function descargar(nombre,contenido,tipo='text/csv;charset=utf-8'){const url=URL.createObjectURL(new Blob([contenido],{type:tipo}));const a=document.createElement('a');a.href=url;a.download=nombre;document.body.append(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);}
const opcionesPlan=(plan,sel)=>plan.map(c=>`<option value="${esc(c.codigo)}"${c.codigo===sel?' selected':''}>${esc(c.codigo)} · ${esc(c.nombre)}</option>`).join('');


// ---------- Impresión / PDF ----------
// El informe se imprime desde un contenedor propio, hijo directo de <body>, con los datos de la empresa arriba y la firma al pie.
// Así no hereda la posición de la pantalla (antes el PDF salía con un gran espacio en blanco arriba).
const FIRMAS={ambos:['Representante legal','Contador'],representante:['Representante legal'],contador:['Contador'],ninguna:[]};
function prepararFirma(form){
 const como=$('#firComo',form),ubica=$('#UbicaFirma',form);
 if(como)como.innerHTML='<option value="ambos">Representante legal y contador</option><option value="representante">Representante legal</option><option value="contador">Contador</option><option value="ninguna">Sin firma</option>';
 if(ubica)ubica.innerHTML='<option value="centro">Centrada</option><option value="izquierda">A la izquierda</option><option value="derecha">A la derecha</option>';
 return ()=>({firmas:FIRMAS[como?.value||'ambos']||FIRMAS.ambos,ubicacion:ubica?.value||'centro'});
}
let empresaImpresion=null;
async function cargarEmpresaImpresion(){
 const local=typeof empresaSeleccionada==='function'?empresaSeleccionada():null;
 empresaImpresion=local;
 try{const datos=await api('empresas.php');const e=(datos.empresas||[]).find(x=>x.id===local?.id);if(e)empresaImpresion=e;}catch{}
 return empresaImpresion;
}
const rutConPuntos=r=>String(r||'').replace(/^(\d+)-/,(m,n)=>Number(n).toLocaleString('es-CL')+'-');
function lineaEmpresa(e){return e?[e.razon_social,e.rut&&'RUT '+rutConPuntos(e.rut)].filter(Boolean).join(' · '):'';}
// Excel con la empresa, el título y el período arriba, igual que el PDF.
function descargarExcel({titulo,periodo,archivo,filas,destacar}){
 const e=empresaImpresion||{};
 const cabecera=[titulo,e.razon_social,e.rut&&'RUT '+rutConPuntos(e.rut),periodo,'Emitido el '+fechaCorta(hoy())].filter(Boolean);
 descargar(archivo+'.xlsx',xlsx({hoja:titulo.replace(/\s*\(.*\)$/,''),cabecera,filas,destacar}),'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
}
function imprimirInforme({titulo,periodo,salida,horizontal=false,firma={firmas:FIRMAS.ambos,ubicacion:'centro'}}){
 document.getElementById('conta-impresion')?.remove();
 const e=empresaImpresion||{},hoyTexto=fechaCorta(hoy());
 const datos=[['Razón social',e.razon_social],['RUT',rutConPuntos(e.rut)],['Giro',e.giro],['Régimen',e.regimen],['Teléfono',e.telefono],['Correo',e.email]].filter(([,v])=>v);
 const cont=document.createElement('div');cont.id='conta-impresion';
 const contenido=salida.cloneNode(true);contenido.removeAttribute('id');contenido.querySelectorAll('.conta-titulo,.conta-periodo,.conta-empresa').forEach(n=>n.remove());
 cont.innerHTML=`<header class="imp-cabecera"><div class="imp-empresa">${datos.length?datos.map(([k,v])=>`<div><span>${k}:</span> ${esc(v)}</div>`).join(''):'<div>Empresa sin datos registrados</div>'}</div>
  <div class="imp-emision">Emitido el ${hoyTexto}</div></header>
  <h1 class="imp-titulo">${esc(titulo)}</h1><p class="imp-periodo">${esc(periodo)}</p>
  <div class="imp-cuerpo"></div>
  ${firma.firmas.length?`<footer class="imp-firmas imp-firmas-${firma.ubicacion}">${firma.firmas.map(f=>`<div class="imp-firma"><div class="imp-linea"></div><div>${esc(f)}</div>${f==='Representante legal'&&e.razon_social?`<div class="imp-sub">${esc(e.razon_social)}</div>`:''}</div>`).join('')}</footer>`:''}`;
 $('.imp-cuerpo',cont).append(...contenido.childNodes);
 document.body.append(cont);
 let pagina=document.getElementById('conta-pagina');if(!pagina){pagina=document.createElement('style');pagina.id='conta-pagina';document.head.append(pagina);}
 pagina.textContent=`@page{size:A4 ${horizontal?'landscape':'portrait'};margin:12mm 10mm}`;
 const titulo0=document.title;document.title=[titulo,e.razon_social].filter(Boolean).join(' - ');
 window.addEventListener('afterprint',()=>{document.title=titulo0;},{once:true});
 window.print();
}

async function pantallaCrear(){
 const dialogo=$('.ui-dialog'),form=$('#ingCabVoucher_form');if(!dialogo||!form)return;
 dialogo.dataset.conta='';dialogo.classList.add('conta-dialogo');
 const caja=$('#ms-error-voucher'),id=new URLSearchParams(location.search).get('id');
 const tv=$('#tv'),fecha=$('#v_fecha'),glosa=$('#v_glosa'),radios=[...form.querySelectorAll('input[name=t_vou_ifrstrib]')],numero=form.querySelector('.label-minimalist:nth-child(2)');
 tv.innerHTML='<option value="">Seleccione</option>'+Object.entries(TIPOS).map(([k,n])=>`<option value="${k}">${n}</option>`).join('');
 radios.forEach((r,i)=>{r.value=REGISTROS[i];});
 aFecha(fecha);
 let plan=[],existente=null;
 try{[plan,existente]=await Promise.all([api('cuentas.php'),id?api('vouchers.php?id='+encodeURIComponent(id)):null]);}
 catch(e){caja.innerHTML=alerta(e.errores||[e.message]);if(!plan.length)return;}
 if(!plan.length){caja.innerHTML=alerta(['Esta empresa aún no tiene plan de cuentas. Cárguelo en Plan de Cuenta (“Cargar plan de ejemplo”) antes de crear vouchers.']);return;}
 const v=existente||{tipo:'',fecha:hoy(),registro:'Ambos',glosa:'',lineas:[{},{}]};
 tv.value=v.tipo;fecha.value=v.fecha;glosa.value=v.glosa;(radios.find(r=>r.value===v.registro)||radios[0]).checked=true;
 if(existente){$('#ui-id-1').textContent=`Editar Voucher · ${TIPOS[v.tipo]} N° ${v.numero}`;numero.textContent=`${TIPOS[v.tipo]} N° ${v.numero}`;}
 const crear=[...dialogo.querySelectorAll('.ui-dialog-buttonset button')].find(b=>b.textContent.trim()==='Crear');if(existente&&crear)crear.textContent='Guardar';

 const detalle=document.createElement('div');detalle.className='col-md-12 conta-detalle';
 detalle.innerHTML=`<div class="card-moderna"><div class="card-moderna-header acento-azul"><h4 class="card-moderna-title"><i class="fa fa-list"></i> Detalle</h4></div><div class="card-moderna-body">
  <table class="conta-tabla"><thead><tr><th>Cuenta</th><th>Glosa</th><th class="num">Debe</th><th class="num">Haber</th><th></th></tr></thead><tbody></tbody>
  <tfoot><tr><td colspan="2"><button type="button" class="btn-minimalist btn-minimalist-royal conta-agregar">+ Agregar línea</button></td><td class="num conta-tdebe"></td><td class="num conta-thaber"></td><td></td></tr>
  <tr><td colspan="5" class="conta-estado" aria-live="polite"></td></tr></tfoot></table></div></div>`;
 form.after(detalle);
 const cuerpo=$('tbody',detalle);
 const fila=(l={})=>{const tr=document.createElement('tr');tr.innerHTML=`<td><select class="form-control input-minimalist conta-cuenta" aria-label="Cuenta"><option value="">Seleccione cuenta</option>${opcionesPlan(plan,l.cuenta)}</select></td>
  <td><input type="text" class="form-control input-minimalist conta-glosa" aria-label="Glosa de la línea" maxlength="200"></td>
  <td><input type="number" min="0" step="1" inputmode="numeric" class="form-control input-minimalist num conta-debe" aria-label="Debe"></td>
  <td><input type="number" min="0" step="1" inputmode="numeric" class="form-control input-minimalist num conta-haber" aria-label="Haber"></td>
  <td><button type="button" class="conta-quitar" title="Quitar línea" aria-label="Quitar línea">✕</button></td>`;
  $('.conta-glosa',tr).value=l.glosa||'';$('.conta-debe',tr).value=l.debe||'';$('.conta-haber',tr).value=l.haber||'';cuerpo.append(tr);};
 v.lineas.forEach(fila);
 const leerFormulario=()=>({tipo:tv.value,fecha:fecha.value,registro:radios.find(r=>r.checked)?.value,glosa:glosa.value.trim(),
  lineas:[...cuerpo.rows].map(tr=>({cuenta:$('.conta-cuenta',tr).value,glosa:$('.conta-glosa',tr).value,debe:$('.conta-debe',tr).value||0,haber:$('.conta-haber',tr).value||0}))});
 const refrescar=()=>{const t=totales(leerFormulario()),estado=$('.conta-estado',detalle);
  $('.conta-tdebe',detalle).textContent=formato(t.debe);$('.conta-thaber',detalle).textContent=formato(t.haber);
  estado.className='conta-estado '+(t.diferencia||!t.debe?'conta-mal':'conta-bien');
  estado.textContent=!t.debe&&!t.haber?'Ingrese los montos del voucher.':t.diferencia?`Descuadrado: diferencia de ${formato(Math.abs(t.diferencia))} en el ${t.diferencia>0?'Debe':'Haber'}.`:'Cuadrado: Debe = Haber.';};
 detalle.addEventListener('input',e=>{const tr=e.target.closest('tr');if(e.target.classList.contains('conta-debe')&&e.target.value)$('.conta-haber',tr).value='';if(e.target.classList.contains('conta-haber')&&e.target.value)$('.conta-debe',tr).value='';refrescar();});
 detalle.addEventListener('change',refrescar);form.addEventListener('submit',e=>e.preventDefault());
 let enviando=false;
 dialogo.addEventListener('click',async e=>{const b=e.target.closest('button');if(!b)return;
  if(b.classList.contains('conta-agregar')){fila({glosa:glosa.value.trim()});refrescar();$('tr:last-child .conta-cuenta',cuerpo).focus();return;}
  if(b.classList.contains('conta-quitar')){b.closest('tr').remove();refrescar();return;}
  const texto=b.textContent.trim();
  if(/Cerrar|Close/.test(texto)){location.href='voucher.html';return;}
  if((texto==='Crear'||texto==='Guardar')&&!enviando){enviando=true;b.disabled=true;
   try{const guardado=await api(existente?'vouchers.php?id='+existente.id:'vouchers.php',{method:existente?'PUT':'POST',body:JSON.stringify(leerFormulario())});
    location.href='voucher.html?guardado='+guardado.id;}
   catch(err){caja.innerHTML=alerta(err.errores||[err.message]);caja.scrollIntoView({block:'nearest'});}
   finally{enviando=false;b.disabled=false;}}
 });
 refrescar();
}

function pantallaLista(){
 const tabla=$('#ListVoucher');if(!tabla)return;
 const form=$('#bvoufiltro_form'),des=$('#fdes'),has=$('#fhas'),num=$('#nCOMPR'),cont=$('#liVOUCH');
 form.dataset.conta='';cont.dataset.conta='';[des,has].forEach(aFecha);
 const cuerpo=tabla.tBodies[0],info=$('#ListVoucher_info');
 const vacio=texto=>`<tr class="odd"><td valign="top" colspan="8" class="dataTables_empty"><b>${texto}</b></td></tr>`;
 let pedido=0;
 const pintar=async()=>{const yo=++pedido,filtrado=des.value||has.value||num.value.trim();
  let lista;
  try{lista=await api('vouchers.php?'+consulta({desde:des.value,hasta:has.value,numero:num.value.trim()}));}
  catch(e){cuerpo.innerHTML=vacio(esc(e.message));info.textContent='';return;}
  if(yo!==pedido)return;
  cuerpo.innerHTML=lista.length?lista.map((v,i)=>`<tr class="${i%2?'even':'odd'}" data-id="${v.id}"><td>${v.numero}</td><td>${esc(v.tipoNombre)}</td><td>${fechaCorta(v.fecha)}</td><td class="texto_centrado">${esc(v.registro)}</td><td class="texto_der">${formato(v.debe)}</td><td class="texto_der">${formato(v.haber)}</td><td>${esc(v.glosa)}</td>
   <td class="texto_centrado"><a href="voucher-crear.html?id=${v.id}" class="conta-accion">Editar</a> <button type="button" class="conta-accion conta-eliminar" data-nombre="${esc(v.tipoNombre)} N° ${v.numero} del ${fechaCorta(v.fecha)}">Eliminar</button></td></tr>`).join('')
   :vacio(filtrado?'Ningún voucher coincide con el filtro':'Aún no hay vouchers. Use “+ Voucher” para crear el primero.');
  info.textContent=`Mostrando ${lista.length} vouchers`;
  const guardado=new URLSearchParams(location.search).get('guardado');
  tabla.querySelector(`tr[data-id="${CSS.escape(guardado||'')}"]`)?.classList.add('conta-resaltado');
 };
 form.addEventListener('submit',e=>{e.preventDefault();pintar();});
 form.addEventListener('input',pintar);
 form.addEventListener('click',e=>{if(e.target.closest('#bt-search-project-budget')){e.preventDefault();pintar();}});
 cont.addEventListener('click',async e=>{const b=e.target.closest('.conta-eliminar');if(!b)return;
  if(!confirm(`¿Eliminar el voucher ${b.dataset.nombre}?`))return;
  try{await api('vouchers.php?id='+b.closest('tr').dataset.id,{method:'DELETE'});aviso('Voucher eliminado.');pintar();}catch(err){aviso(err.message);}});
 // La paginación remota del original no aplica: se muestran todos los registros filtrados.
 $('#ListVoucher_paginate')?.setAttribute('hidden','');$('#ListVoucher_length')?.setAttribute('hidden','');
 pintar();
 if(new URLSearchParams(location.search).get('guardado'))aviso('Voucher guardado.');
}

async function pantallaReporte(tipo){
 const form=$('#reporteLibroDiario_form');if(!form)return;
 form.dataset.conta='';
 const des=$('#fdesld'),has=$('#fhasld'),reg=$('#idTpCONTAB');
 [des,has].forEach(aFecha);
 reg.innerHTML='<option value="Tributario">Tributario</option><option value="IFRS">IFRS</option>';
 const titulo=tipo==='mayor'?'Reporte Libro Mayor':'Reporte Libro Diario';
 if(tipo==='mayor'){$('#Btnrep5')?.classList.remove('bton_rpt_activo');$('#Btnrep6')?.classList.add('bton_rpt_activo');}
 const h3=form.querySelector('legend h3');if(h3)h3.innerHTML=`<i class="fa fa-file-text-o" aria-hidden="true"></i> ${titulo}`;
 const salida=document.createElement('div');salida.id='conta-reporte';salida.className='col-lg-12 col-md-12 col-sm-12 col-xs-12 conta-reporte';salida.dataset.conta='';
 form.parentElement.after(salida);
 let cuenta;
 if(tipo==='mayor'){const fila=document.createElement('div');fila.className='col-lg-12 col-md-12 col-sm-12 col-xs-12 padding_0px margin_bottom_5px';
  fila.innerHTML=`<label class="col-lg-3 col-md-3 col-sm-3 col-xs-5 padding_0px margin_top_10px" for="conta-cuenta">Cuenta</label><div class="col-lg-6 col-md-6 col-sm-6 col-xs-7"><select id="conta-cuenta" class="form-control"><option value="">Todas las cuentas con movimiento</option></select></div>`;
  has.closest('.margin_bottom_5px').after(fila);cuenta=$('#conta-cuenta');
  api('cuentas.php').then(plan=>cuenta.insertAdjacentHTML('beforeend',opcionesPlan(plan))).catch(()=>{});}
 const filtros=()=>({libro:tipo,desde:des.value,hasta:has.value,registro:reg.value,cuenta:cuenta?.value});
 const firma=prepararFirma(form);cargarEmpresaImpresion().then(()=>pintar());
 const periodo=f=>`${f.desde?fechaCorta(f.desde):'inicio'} al ${f.hasta?fechaCorta(f.hasta):'hoy'} · Contabilidad ${f.registro}`;
 const vacio=()=>`<p class="conta-vacio">No hay vouchers en este período. <a href="voucher-crear.html">Crear un voucher</a></p>`;
 const diario=libro=>libro.asientos.length?`<table class="conta-tabla conta-libro"><thead><tr><th>Fecha</th><th>Comprobante</th><th>Código</th><th>Cuenta</th><th>Glosa</th><th class="num">Debe</th><th class="num">Haber</th></tr></thead><tbody>${libro.asientos.map(a=>
   `<tr class="conta-asiento"><td>${fechaCorta(a.fecha)}</td><td>${esc(a.tipoNombre)} N° ${a.numero}</td><td colspan="5">${esc(a.glosa)}</td></tr>`+a.lineas.map(l=>`<tr><td></td><td></td><td>${esc(l.cuenta)}</td><td>${esc(l.nombre)}</td><td>${esc(l.glosa)}</td><td class="num">${l.debe?formato(l.debe):''}</td><td class="num">${l.haber?formato(l.haber):''}</td></tr>`).join('')
   +`<tr class="conta-subtotal"><td colspan="5"></td><td class="num">${formato(a.debe)}</td><td class="num">${formato(a.haber)}</td></tr>`).join('')}</tbody>
   <tfoot><tr><td colspan="5">Totales</td><td class="num">${formato(libro.debe)}</td><td class="num">${formato(libro.haber)}</td></tr></tfoot></table>`:vacio();
 const mayor=cuentas=>cuentas.length?cuentas.map(c=>`<table class="conta-tabla conta-libro"><caption>${esc(c.codigo)} · ${esc(c.nombre)}</caption><thead><tr><th>Fecha</th><th>Comprobante</th><th>Glosa</th><th class="num">Debe</th><th class="num">Haber</th><th class="num">Saldo</th></tr></thead><tbody>
   <tr class="conta-asiento"><td colspan="5">Saldo anterior</td><td class="num">${formato(c.saldoAnterior)}</td></tr>${c.movimientos.map(m=>`<tr><td>${fechaCorta(m.fecha)}</td><td>${esc(m.tipoNombre)} N° ${m.numero}</td><td>${esc(m.glosa)}</td><td class="num">${m.debe?formato(m.debe):''}</td><td class="num">${m.haber?formato(m.haber):''}</td><td class="num">${formato(m.saldo)}</td></tr>`).join('')}</tbody>
   <tfoot><tr><td colspan="3">Totales · saldo ${c.saldo>=0?'deudor':'acreedor'}</td><td class="num">${formato(c.debe)}</td><td class="num">${formato(c.haber)}</td><td class="num">${formato(c.saldo)}</td></tr></tfoot></table>`).join(''):vacio();
 let pedido=0;
 const pintar=async()=>{const yo=++pedido,f=filtros();
  try{const datos=await api('libros.php?'+consulta(f));if(yo!==pedido)return;
   salida.innerHTML=`<h3 class="conta-titulo">${tipo==='mayor'?'Libro Mayor':'Libro Diario'}</h3><p class="conta-empresa">${esc(lineaEmpresa(empresaImpresion))}</p><p class="conta-periodo">${esc(periodo(f))}</p>`+(tipo==='mayor'?mayor(datos):diario(datos));}
  catch(e){if(yo===pedido)salida.innerHTML=alerta(e.errores||[e.message]);}
 };
 const nombre=tipo==='mayor'?'Libro Mayor':'Libro Diario';
 const destacar=tipo==='mayor'?f=>f[5]==='Total'||f[5]==='Saldo anterior':f=>f[6]==='Total';
 const exportar=async formato=>{try{const f=filtros(),datos=await api('libros.php?'+consulta(f)),filas=tipo==='mayor'?filasMayor(datos):filasDiario(datos);
  if(formato==='xlsx')descargarExcel({titulo:nombre,periodo:periodo(f),archivo:`libro-${tipo}`,filas,destacar});else descargar(`libro-${tipo}.csv`,csv(filas));}catch(e){aviso(e.message);}};
 form.addEventListener('change',pintar);
 form.addEventListener('submit',e=>e.preventDefault());
 form.addEventListener('click',e=>{const b=e.target.closest('button');if(!b)return;e.preventDefault();
  const texto=b.textContent;
  if(/PDF/.test(texto))imprimirInforme({titulo:nombre,periodo:periodo(filtros()),salida,firma:firma()});else if(/CSV/.test(texto))exportar('csv');else if(/EXCEL/.test(texto))exportar('xlsx');});
 pintar();
}

// Balance General, Estado de Resultado y Libros de Compras y Ventas comparten el mismo esquema de filtros y salida.
const INFORMES={
 'reportes-libro-balance.html':{form:'#reporteBalance_form',des:'#fdeslb',has:'#fhaslb',titulo:'Balance General',archivo:'balance-general',horizontal:true,
  ruta:f=>'libros.php?'+consulta({libro:'balance',desde:f.desde,hasta:f.hasta,registro:f.registro}),filas:filasBalance,vacio:b=>!b.cuentas.length},
 'reportes-resultados.html':{form:'#reporteEerr_form',des:'#fdeser',has:'#fhaser',titulo:'Estado de Resultado',archivo:'estado-resultado',
  ruta:f=>'libros.php?'+consulta({libro:'resultado',desde:f.desde,hasta:f.hasta,registro:f.registro}),filas:filasResultado,vacio:r=>!r.ingresos.length&&!r.gastos.length},
 'reportes-libro-compras.html':{form:'#reporteLibroCompra_form',des:'#fdeslc',has:'#fhaslc',titulo:'Libro de Compras',archivo:'libro-compras',horizontal:true,rcv:'compras'},
 'reportes-libro-ventas.html':{form:'#reporteLibroVenta_form',des:'#fdes',has:'#fhas',titulo:'Libro de Ventas',archivo:'libro-ventas',horizontal:true,rcv:'ventas'},
 // La captura de esta pantalla reutiliza el formulario del Libro de Ventas.
 'reportes-libro-honorarios.html':{form:'#reporteLibroVenta_form',des:'#fdes',has:'#fhas',titulo:'Libro de Honorarios (boletas recibidas)',archivo:'libro-honorarios',horizontal:true,honorarios:'recibidas'},
 'reportes-libro-inventario.html':{form:'#reporteInventBalan_form',des:'#fdeslib',has:'#fhaslib',titulo:'Libro de Inventario y Balance',archivo:'libro-inventario-balance',
  ruta:f=>'libros.php?'+consulta({libro:'inventario',desde:f.desde,hasta:f.hasta}),filas:filasLibroInventario,vacio:i=>!i.inventario||!i.inventario.categorias||i.inventario.categorias.length===0},
};
function tablaFilas(filas,{destacar=()=>false}={}){
 const [cab,...cuerpo]=filas,num=v=>typeof v==='number';
 return `<table class="conta-tabla conta-libro"><thead><tr>${cab.map((c,i)=>`<th${cuerpo.some(f=>num(f[i]))?' class="num"':''}>${esc(c)}</th>`).join('')}</tr></thead><tbody>${cuerpo.map((f,j)=>
  `<tr${destacar(f,j,cuerpo.length)?' class="conta-asiento"':''}>${f.map(v=>num(v)?`<td class="num">${formato(v)}</td>`:`<td>${esc(v)}</td>`).join('')}</tr>`).join('')}</tbody></table>`;
}
function pantallaInforme(cfg){
 const form=$(cfg.form);if(!form)return;
 form.dataset.conta='';
 const des=$(cfg.des,form),has=$(cfg.has,form),reg=$('#idTpCONTAB',form),tipo=cfg.rcv?$('#tDocto',form):null,sii=cfg.rcv||cfg.honorarios;
 if(cfg.honorarios)$('#tDocto',form)?.closest('.col-lg-12')?.remove();
 [des,has].forEach(aFecha);
 if(reg)reg.innerHTML='<option value="Tributario">Tributario</option><option value="IFRS">IFRS</option>';
 if(tipo){tipo.nextElementSibling?.classList.contains('select2')&&tipo.nextElementSibling.remove();
  tipo.classList.remove('select2-hidden-accessible');tipo.removeAttribute('aria-hidden');tipo.removeAttribute('tabindex');
  tipo.innerHTML='<option value="">Todos</option>'+Object.keys(DOCS_SII).map(t=>`<option value="${t}">${t} · ${esc(DOCS_SII[t])}</option>`).join('');}
 const salida=document.createElement('div');salida.id='conta-reporte';salida.className='col-lg-12 col-md-12 col-sm-12 col-xs-12 conta-reporte';salida.dataset.conta='';
 form.parentElement.after(salida);
 const filtros=()=>({desde:des.value,hasta:has.value,registro:reg?.value,tipo:tipo?.value});
 const firma=prepararFirma(form);cargarEmpresaImpresion().then(()=>pintar());
 const ruta=f=>cfg.rcv?'rcv.php?'+consulta({libro:cfg.rcv,desde:f.desde,hasta:f.hasta,tipo:f.tipo}):cfg.honorarios?'honorarios.php?'+consulta({libro:cfg.honorarios,desde:f.desde,hasta:f.hasta}):cfg.ruta(f);
 const filas=cfg.rcv?filasLibroRcv:cfg.honorarios?filasLibroHonorarios:cfg.filas;
 const leer=f=>api(ruta(f));
 const periodo=f=>`${f.desde?fechaCorta(f.desde):'inicio'} al ${f.hasta?fechaCorta(f.hasta):'hoy'}`+(sii?'':` · Contabilidad ${f.registro}`);
 const vacio=cfg.honorarios?'<p class="conta-vacio">No hay boletas de honorarios en este período. <a href="honorarios.html">Importar el informe del SII</a></p>':cfg.rcv?'<p class="conta-vacio">No hay documentos del RCV en este período. <a href="rcv.html">Importar el RCV</a></p>':'<p class="conta-vacio">No hay vouchers en este período. <a href="voucher-crear.html">Crear un voucher</a></p>';
 // Las filas de totales (sin código ni período) se destacan.
 const destacar=f=>f[0]==='';
 let pedido=0;
 const pintar=async()=>{const yo=++pedido,f=filtros();
  try{const datos=await leer(f);if(yo!==pedido)return;
   const sinDatos=cfg.rcv?!datos.docs.length:cfg.honorarios?!datos.boletas.length:cfg.vacio(datos);
   salida.innerHTML=`<h3 class="conta-titulo">${cfg.titulo}</h3><p class="conta-empresa">${esc(lineaEmpresa(empresaImpresion))}</p><p class="conta-periodo">${esc(periodo(f))}</p>`+(sinDatos?vacio:tablaFilas(filas(datos),{destacar}));}
  catch(e){if(yo===pedido)salida.innerHTML=alerta(e.errores||[e.message]);}
 };
 const exportar=async formato=>{try{const f=filtros(),tabla=filas(await leer(f));
  if(formato==='xlsx')descargarExcel({titulo:cfg.titulo,periodo:periodo(f),archivo:cfg.archivo,filas:tabla,destacar});else descargar(cfg.archivo+'.csv',csv(tabla));}catch(e){aviso(e.message);}};
 form.addEventListener('change',pintar);
 form.addEventListener('submit',e=>e.preventDefault());
 form.addEventListener('click',e=>{const b=e.target.closest('button,a');if(!b)return;e.preventDefault();
  if(b.classList.contains('dropdown-toggle')){const menu=b.parentElement.querySelector('.dropdown-menu');if(menu)menu.style.display=menu.style.display==='block'?'none':'block';return;}
  const menu=b.closest('.dropdown-menu');if(menu)menu.style.display='none';
  const texto=b.textContent;
  if(/PDF/.test(texto))imprimirInforme({titulo:cfg.titulo,periodo:periodo(filtros()),salida,horizontal:Boolean(cfg.horizontal),firma:firma()});else if(/CSV/.test(texto))exportar('csv');else if(/EXCEL/.test(texto))exportar('xlsx');else if(/Ver/.test(texto))pintar();
  else aviso('Este formato del SII aún no está disponible; use PDF, Excel o CSV.');});
 pintar();
}

function iniciar(){
 const pagina=location.pathname.split('/').pop();
 if(pagina==='voucher-crear.html')pantallaCrear();
 else if(pagina==='voucher.html')pantallaLista();
 else if(pagina==='reportes-libro-diario.html')pantallaReporte('diario');
 else if(pagina==='reportes-libro-mayor.html')pantallaReporte('mayor');
 else if(INFORMES[pagina])pantallaInforme(INFORMES[pagina]);
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',iniciar);else iniciar();
})();
