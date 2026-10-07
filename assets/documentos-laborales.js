'use strict';
/* Documentos laborales: declaración jurada Ley 21.389, acreditación de pagos previsionales, planilla de remuneraciones
   y formulario de documentación laboral y previsional. Se llenan con la empresa seleccionada y las liquidaciones del mes
   (api/liquidaciones.php); los datos que no están en la base (representante, establecimiento, RBD) se recuerdan por empresa. */
const DL_EMPRESA_KEY='icontador.empresaSeleccionada';
const DL_DATOS_KEY='icontador.documentosLaborales';
const MESES=['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
const dl={empresa:null,liquidaciones:[],doc:'jurada'};
const $=id=>document.getElementById(id);

function el(tag,props={},...hijos){const e=document.createElement(tag);for(const [k,v] of Object.entries(props)){if(k==='class')e.className=v;else if(k==='text')e.textContent=v;else e.setAttribute(k,v);}e.append(...hijos.filter(h=>h!=null&&h!==false));return e;}
function leerJson(clave,defecto){try{return JSON.parse(localStorage.getItem(clave)||'null')??defecto;}catch{return defecto;}}
function mensaje(texto){const m=$('dl-mensaje');m.textContent=texto;m.hidden=!texto;}
const clp=n=>'$'+Math.round(+n||0).toLocaleString('es-CL');
const entero=v=>Math.round(+String(v||'').replace(/[^\d-]/g,'')||0);
const rutConPuntos=r=>String(r||'').replace(/^(\d+)-/,(m,n)=>Number(n).toLocaleString('es-CL')+'-');
const hoyIso=()=>{const d=new Date();return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;};
const fechaLarga=iso=>{const [a,m,d]=String(iso||'').split('-');return a&&m&&d?`${+d} de ${MESES[+m-1]} de ${a}`:'';};
const mesTexto=p=>{const [a,m]=String(p||'').split('-');return a&&m?`${MESES[+m-1].toUpperCase()} ${a}`:'';};
const linea=(valor,ancho='dl-linea')=>el('span',{class:ancho,text:valor||' '});

/* ---------- Datos ---------- */
function datosEmpresa(){const todos=leerJson(DL_DATOS_KEY,{});return todos[dl.empresa?.id||0]||{};}
function guardarDato(campo,valor){const todos=leerJson(DL_DATOS_KEY,{});const id=dl.empresa?.id||0;todos[id]={...todos[id],[campo]:valor};try{localStorage.setItem(DL_DATOS_KEY,JSON.stringify(todos));}catch{}}
async function api(ruta){
 const cab={};if(dl.empresa?.id)cab['X-Empresa-Id']=String(dl.empresa.id);
 const r=await fetch('../api/'+ruta,{headers:cab,credentials:'same-origin'});
 const datos=await r.json().catch(()=>{throw new Error('La API PHP no respondió. Inicie la copia con npm run start:php.');});
 if(!r.ok)throw new Error(datos.error||'No se pudieron leer los datos.');
 return datos;
}
async function cargarEmpresa(){
 dl.empresa=leerJson(DL_EMPRESA_KEY,null);
 try{const {empresas=[]}=await api('empresas.php');const e=empresas.find(x=>x.id===dl.empresa?.id);if(e)dl.empresa=e;}catch{}
 const e=dl.empresa;
 $('dl-empresa').textContent=e?[e.razon_social,e.rut&&'RUT '+rutConPuntos(e.rut)].filter(Boolean).join(' · '):'No hay empresa seleccionada: elígela en el inicio con Cambiar Empresa.';
 const datos=datosEmpresa();
 document.querySelectorAll('[data-guardar]').forEach(i=>{i.value=datos[i.dataset.guardar]??(i.id==='dl-establecimiento'?e?.razon_social||'':'');});
}
async function cargarLiquidaciones(){
 const periodo=$('dl-periodo').value;dl.liquidaciones=[];mensaje('');
 if(periodo){
  try{dl.liquidaciones=(await api('liquidaciones.php?periodo='+encodeURIComponent(periodo))).liquidaciones||[];
   if(!dl.liquidaciones.length)mensaje('No hay liquidaciones guardadas para '+mesTexto(periodo).toLowerCase()+'. Guárdalas desde la Calculadora de Remuneraciones; mientras tanto puedes llenar los datos a mano.');}
  catch(err){mensaje(err.message);}
 }
 const lista=$('dl-trabajadores');lista.replaceChildren(...dl.liquidaciones.map(l=>el('option',{value:l.trabajador})));
 pintar();
}

/* ---------- Totales del mes ---------- */
function aportesEmpleador(l){const e=l.detalle?.empleador;return e?Object.values(e).reduce((s,v)=>s+(+v||0),0):Math.max(0,(l.costoEmpresa||0)-(l.totalHaberes||0));}
function totales(){
 const t={trabajadores:dl.liquidaciones.length,imponible:0,haberes:0,afp:0,salud:0,afc:0,impuesto:0,descuentos:0,liquido:0,empleador:0,afcEmpleador:0,mutual:0,sis:0,afps:{},saludes:{}};
 for(const l of dl.liquidaciones){
  for(const k of ['imponible','afp','salud','afc','impuesto','liquido'])t[k]+=l[k]||0;
  t.haberes+=l.totalHaberes||0;t.descuentos+=l.totalDescuentos||0;t.empleador+=aportesEmpleador(l);
  const emp=l.detalle?.empleador||{};t.afcEmpleador+=+emp.afc||0;t.mutual+=+emp.mutual||0;t.sis+=+emp.sis||0;
  const afp='AFP '+(l.detalle?.entrada?.afp||'sin indicar'),salud=l.detalle?.entrada?.salud==='isapre'?'Isapre':'Fonasa';
  const sumar=(grupo,nombre,imp,monto)=>{const g=grupo[nombre]||={n:0,imponible:0,monto:0};g.n++;g.imponible+=imp;g.monto+=monto;};
  sumar(t.afps,afp,l.imponible||0,(l.afp||0)+(+emp.sis||0)+(+emp.cuentaIndividual||0)+(+emp.rentabilidadProtegida||0)+(+emp.expectativaVida||0));
  sumar(t.saludes,salud,l.imponible||0,l.salud||0);
 }
 t.cotizaciones=t.afp+t.salud+t.afc+t.empleador;
 return t;
}

/* ---------- Hojas ---------- */
function encabezado(titulo,subtitulo){
 const e=dl.empresa||{};
 return el('header',{class:'dl-hoja-encabezado'},
  el('div',{class:'dl-hoja-empresa'},el('strong',{text:e.razon_social||''}),e.rut?el('span',{text:'RUT '+rutConPuntos(e.rut)}):null,e.giro?el('span',{text:e.giro}):null),
  el('h2',{text:titulo}),subtitulo?el('p',{class:'dl-hoja-sub',text:subtitulo}):null);
}
function firma(texto='Nombre y firma Empleador / Representante Legal'){return el('div',{class:'dl-firma'},el('span',{class:'dl-firma-linea'}),el('span',{text:texto}));}
const repNombre=()=>$('dl-rep-nombre').value.trim(),repRut=()=>rutConPuntos($('dl-rep-rut').value.trim());

function hojaJurada(trabajador,rut){
 const e=dl.empresa||{},notificado=document.querySelector('input[name=dj-notificado]:checked').value==='si';
 const caja=(marcado,texto)=>el('tr',{},el('td',{class:'dl-x',text:marcado?'X':''}),el('td',{text:texto}));
 return el('article',{class:'dl-hoja'},
  el('h2',{class:'dl-centro',text:'DECLARACIÓN JURADA'}),
  el('p',{class:'dl-centro dl-negrita',text:'(Artículo 7° DFL N°1, de 30.05.2000, del Ministerio de Justicia, modificado por la Ley N°21.389)'}),
  el('p',{class:'dl-justificado'},'Don(ña) ',linea(repNombre()),', cédula de identidad N° ',linea(repRut(),'dl-linea-corta'),', en representación del empleador ',linea(e.razon_social),', RUT ',linea(rutConPuntos(e.rut),'dl-linea-corta'),', respecto del trabajador(a) ',linea(trabajador),', cédula de identidad N° ',linea(rutConPuntos(rut),'dl-linea-corta'),
   ', conforme a lo dispuesto en el artículo 7° del DFL N°1, de 30.05.2000, del Ministerio de Justicia, modificado por la Ley N°21.389, que crea el Registro Nacional de Deudores de Pensiones de Alimentos y modifica diversos cuerpos legales para perfeccionar el sistema de pago de las pensiones de alimentos (entre ellos la Ley N°14.908 sobre Abandono de Familia y Pago de Pensiones Alimenticias), declara juramentadamente lo siguiente:'),
  el('p',{class:'dl-negrita',text:'(Marque con una X la alternativa que corresponda)'}),
  el('p',{},el('b',{text:'PRIMERO: '}),'Que,'),
  el('table',{class:'dl-tabla dl-opcion'},el('tbody',{},
   caja(notificado,'He sido notificado/a por el Tribunal competente de la obligación de practicar retención judicial de las remuneraciones/indemnizaciones del/de la trabajador/a antes individualizado/a, correspondiente a los alimentos decretados o aprobados judicialmente.'),
   caja(!notificado,'No he sido notificado/a por Tribunal alguno respecto a la obligación de practicar retención judicial de las remuneraciones/indemnizaciones del trabajador/a antes señalado.'))),
  el('p',{class:'dl-justificado'},el('b',{text:'SEGUNDO: '}),'Que, habiendo sido notificado de que debo efectuar las retenciones correspondientes a alimentos decretados o aprobados judicialmente en contra del/de la trabajador/a antes individualizado/a, exhibo los siguientes antecedentes a efectos de acreditar que he efectuado el descuento, la retención y el pago del monto correspondiente de las indemnizaciones que constan en el finiquito, en la cuenta bancaria ordenada por el Tribunal competente:'),
  el('ol',{class:'dl-justificado'},
   el('li',{text:'Oficio o resolución notificada por el Tribunal competente donde consta el monto o porcentaje a retener para efectuar el pago de la pensión alimenticia.'}),
   el('li',{text:'Comprobante que acredita el descuento, la retención y pago de los montos en la cuenta bancaria ordenada por el tribunal conforme a los incisos 4°, 5° y 6°, todos del artículo 13 de la Ley N°14.908.'}),
   el('li',{text:'Los 3 últimos comprobantes de pago de remuneraciones del/de la trabajador/a antes individualizado/a.'})),
  el('p',{class:'dl-justificado'},el('b',{text:'TERCERO: '}),'Que, tengo conocimiento cabal que en caso de comprobarse falsedad en la presente declaración, incurro en las penas del artículo 210 del Código Penal.'),
  $('dj-fecha').value?el('p',{class:'dl-derecha',text:($('dl-comuna').value.trim()?$('dl-comuna').value.trim()+', ':'')+fechaLarga($('dj-fecha').value)}):null,
  firma());
}
function pintarJurada(todos=false){
 const hojas=todos&&dl.liquidaciones.length?dl.liquidaciones.map(l=>hojaJurada(l.trabajador,'')):[hojaJurada($('dj-trabajador').value.trim(),$('dj-rut').value.trim())];
 $('dj-hojas').replaceChildren(...hojas);
}
function pintarAcreditacion(){
 const t=totales(),pagadasTexto=$('ac-pagadas').value.trim(),pagadas=pagadasTexto?entero(pagadasTexto):t.cotizaciones;
 const fila=(monto,texto)=>el('tr',{},el('td',{class:'dl-monto',text:monto}),el('td',{text:texto}));
 $('ac-hoja').replaceChildren(el('article',{class:'dl-hoja'},
  el('h2',{class:'dl-centro',text:'DECLARACIÓN DE ACREDITACIÓN DE PAGOS PREVISIONALES Y PAGO DE REMUNERACIONES'}),
  el('table',{class:'dl-tabla dl-datos'},el('tbody',{},
   el('tr',{},el('th',{text:'Rol base de datos'}),el('td',{text:$('dl-rbd').value.trim()}),el('th',{text:'Año'}),el('td',{text:($('dl-periodo').value||'').slice(0,4)})),
   el('tr',{},el('th',{text:'Nombre establecimiento'}),el('td',{colspan:'3',text:$('dl-establecimiento').value.trim()})),
   el('tr',{},el('th',{text:'Comuna'}),el('td',{colspan:'3',text:$('dl-comuna').value.trim()})))),
  el('p',{class:'dl-justificado'},'Yo ',linea(repNombre()),' RUT ',linea(repRut(),'dl-linea-corta'),', sostenedor o representante legal de ',linea($('dl-establecimiento').value.trim()||dl.empresa?.razon_social),
   ', certifico que, de acuerdo a detalle adjunto, se ha realizado el pago de las cotizaciones previsionales y el pago de las remuneraciones del personal, en conformidad con las planillas de remuneraciones correspondientes al mes de ',el('b',{text:mesTexto($('dl-periodo').value)}),'.'),
  el('table',{class:'dl-tabla dl-resumen'},el('tbody',{},
   fila(String(t.trabajadores),'Número de trabajadores, según planilla o libro de remuneraciones'),
   fila(clp(t.imponible),'Renta imponible total del mes'),
   fila(clp(t.cotizaciones),'Total de cotizaciones a pagar, según planilla o libro de remuneraciones'),
   fila(clp(pagadas),'Total de cotizaciones efectivamente pagadas'),
   fila(clp(Math.max(0,t.cotizaciones-pagadas)),'Total de cotizaciones pendientes de pago'))),
  el('p',{class:'dl-justificado dl-chico',text:'La información consignada por el sostenedor y/o representante legal que suscribe se realiza bajo el apercibimiento de lo contemplado en el Art. 204 del Código Penal. Asimismo, la documentación de respaldo de la presente declaración en sus originales se encuentra a disposición para la revisión de los organismos competentes.'}),
  el('table',{class:'dl-tabla dl-datos'},el('tbody',{},
   el('tr',{},el('th',{text:'Nombre del sostenedor o representante legal'}),el('td',{text:repNombre()})),
   el('tr',{},el('th',{text:'Firma y timbre'}),el('td',{class:'dl-alto'})),
   el('tr',{},el('th',{text:'Fecha de la declaración'}),el('td',{text:fechaLarga($('ac-fecha').value)}))))));
}
const COLUMNAS_PLANILLA=[['Trabajador','trabajador'],['Sueldo base','sueldoBase'],['Gratificación','gratificacion'],['Total imponible','imponible'],['No imponibles',l=>(l.totalHaberes||0)-(l.imponible||0)],['Total haberes','totalHaberes'],['AFP','afp'],['Salud','salud'],['Seg. cesantía','afc'],['Impuesto renta','impuesto'],['Otros descuentos',l=>(l.totalDescuentos||0)-(l.afp||0)-(l.salud||0)-(l.afc||0)-(l.impuesto||0)],['Total descuentos','totalDescuentos'],['Líquido a pagar','liquido']];
const valorColumna=(l,c)=>typeof c[1]==='function'?c[1](l):l[c[1]];
function pintarPlanilla(){
 const filas=dl.liquidaciones.map(l=>el('tr',{},...COLUMNAS_PLANILLA.map((c,i)=>el('td',{class:i?'dl-monto':'',text:i?clp(valorColumna(l,c)):l.trabajador}))));
 const total=el('tr',{class:'dl-total'},...COLUMNAS_PLANILLA.map((c,i)=>el('td',{class:i?'dl-monto':'',text:i?clp(dl.liquidaciones.reduce((s,l)=>s+(+valorColumna(l,c)||0),0)):'TOTALES'})));
 $('pl-hoja').replaceChildren(el('article',{class:'dl-hoja dl-horizontal'},
  encabezado('PLANILLA DE REMUNERACIONES','Mes: '+mesTexto($('dl-periodo').value)+($('dl-establecimiento').value.trim()?' · Establecimiento: '+$('dl-establecimiento').value.trim():'')),
  el('table',{class:'dl-tabla dl-planilla'},el('thead',{},el('tr',{},...COLUMNAS_PLANILLA.map(c=>el('th',{text:c[0]})))),
   el('tbody',{},...(filas.length?filas:[el('tr',{},el('td',{colspan:String(COLUMNAS_PLANILLA.length),class:'dl-centro',text:'No hay liquidaciones guardadas para este mes.'}))]),filas.length?total:null)),
  el('p',{text:'Fecha: '+fechaLarga(hoyIso())}),firma('Nombre, firma y timbre sostenedor')));
}
function descargarCsv(){
 const celda=v=>{const s=String(v??'');return /[;"\n]/.test(s)?'"'+s.replace(/"/g,'""')+'"':s;};
 const lineas=[COLUMNAS_PLANILLA.map(c=>c[0]).join(';'),...dl.liquidaciones.map(l=>COLUMNAS_PLANILLA.map((c,i)=>celda(i?Math.round(+valorColumna(l,c)||0):l.trabajador)).join(';'))];
 const a=el('a',{download:'planilla-remuneraciones-'+($('dl-periodo').value||'mes')+'.csv'});
 a.href=URL.createObjectURL(new Blob(['﻿'+lineas.join('\r\n')],{type:'text/csv;charset=utf-8'}));document.body.append(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(a.href),1000);
}
function pintarFormulario(){
 const t=totales(),docs=[...document.querySelectorAll('input[name=fo-doc]:checked')].map(i=>i.value);
 const grupo=(titulo,g)=>el('table',{class:'dl-tabla'},el('thead',{},el('tr',{},el('th',{text:titulo}),el('th',{text:'N° trabajadores'}),el('th',{text:'Imponible'}),el('th',{text:'Monto'}))),
  el('tbody',{},...Object.entries(g).map(([n,v])=>el('tr',{},el('td',{text:n}),el('td',{class:'dl-monto',text:String(v.n)}),el('td',{class:'dl-monto',text:clp(v.imponible)}),el('td',{class:'dl-monto',text:clp(v.monto)}))),
   el('tr',{class:'dl-total'},el('td',{text:'TOTALES'}),el('td',{class:'dl-monto',text:String(Object.values(g).reduce((s,v)=>s+v.n,0))}),el('td',{class:'dl-monto',text:clp(Object.values(g).reduce((s,v)=>s+v.imponible,0))}),el('td',{class:'dl-monto',text:clp(Object.values(g).reduce((s,v)=>s+v.monto,0))}))));
 $('fo-hoja').replaceChildren(el('article',{class:'dl-hoja'},
  encabezado('FORMULARIO DE PRESENTACIÓN DE DOCUMENTACIÓN LABORAL Y PREVISIONAL'),
  el('table',{class:'dl-tabla dl-datos'},el('tbody',{},
   el('tr',{},el('th',{text:'1.- Remuneración del mes de'}),el('td',{text:mesTexto($('dl-periodo').value)})),
   el('tr',{},el('th',{text:'Establecimiento'}),el('td',{text:$('dl-establecimiento').value.trim()})),
   el('tr',{},el('th',{text:'Comuna'}),el('td',{text:$('dl-comuna').value.trim()})),
   el('tr',{},el('th',{text:'Sostenedor'}),el('td',{text:repNombre()})),
   el('tr',{},el('th',{text:'R.U.T.'}),el('td',{text:rutConPuntos(dl.empresa?.rut)})),
   el('tr',{},el('th',{text:'Total funcionarios'}),el('td',{text:`${t.trabajadores}  (Docentes: ${$('fo-docentes').value||'—'} · No docentes: ${$('fo-no-docentes').value||'—'})`})))),
  el('h3',{text:'2.- Documentos que acompaña'}),
  el('ul',{},...(docs.length?docs.map(d=>el('li',{text:d})):[el('li',{text:'—'})])),
  el('h3',{text:'3.- Detalle de cotizaciones previsionales y de salud'}),
  grupo('Institución previsional (AFP, incluye SIS)',t.afps),grupo('Institución de salud',t.saludes),
  el('table',{class:'dl-tabla'},el('tbody',{},
   el('tr',{},el('th',{text:'Seguro de cesantía (trabajador + empleador)'}),el('td',{class:'dl-monto',text:clp(t.afc+t.afcEmpleador)})),
   el('tr',{},el('th',{text:'Mutual de seguridad'}),el('td',{class:'dl-monto',text:clp(t.mutual)})),
   el('tr',{},el('th',{text:'Retención impuesto único'}),el('td',{class:'dl-monto',text:clp(t.impuesto)})),
   el('tr',{class:'dl-total'},el('th',{text:'Total cotizaciones'}),el('td',{class:'dl-monto',text:clp(t.cotizaciones)})))),
  el('p',{class:'dl-justificado',text:'El sostenedor que suscribe declara que los datos contenidos en este formulario corresponden a todo el personal que labora en el establecimiento educacional a su cargo.'}),
  el('p',{text:'Fecha presentación: '+fechaLarga(hoyIso())}),firma('Nombre, firma sostenedor y/o representante legal')));
}
function pintar(){({jurada:()=>pintarJurada(),acreditacion:pintarAcreditacion,planilla:pintarPlanilla,formulario:pintarFormulario})[dl.doc]();}
function mostrarDoc(doc){
 dl.doc=doc;
 document.querySelectorAll('.dl-pestanas button').forEach(b=>b.classList.toggle('activo',b.dataset.doc===doc));
 document.querySelectorAll('.dl-doc').forEach(s=>{s.hidden=s.id!=='doc-'+doc;});
 pintar();
}

/* ---------- Inicio ---------- */
async function iniciar(){
 const hoy=new Date(),anterior=new Date(hoy.getFullYear(),hoy.getMonth()-1,1);
 $('dl-periodo').value=`${anterior.getFullYear()}-${String(anterior.getMonth()+1).padStart(2,'0')}`;
 $('dj-fecha').value=$('ac-fecha').value=hoyIso();
 if(document.referrer&&new URL(document.referrer).origin===location.origin)$('dl-volver').addEventListener('click',e=>{e.preventDefault();history.back();});
 document.querySelector('.dl-pestanas').addEventListener('click',e=>{const b=e.target.closest('button[data-doc]');if(b)mostrarDoc(b.dataset.doc);});
 document.addEventListener('input',e=>{const i=e.target;if(i.dataset?.guardar)guardarDato(i.dataset.guardar,i.value.trim());if(i.id==='dl-periodo')cargarLiquidaciones();else pintar();});
 document.addEventListener('change',e=>{if(e.target.matches('input[type=radio],input[type=checkbox]'))pintar();});
 document.addEventListener('click',e=>{const b=e.target.closest('[data-imprimir]');if(b){pintar();window.print();}});
 $('dj-todos').addEventListener('click',()=>{if(!dl.liquidaciones.length){mensaje('No hay liquidaciones del mes: escribe el trabajador y usa Imprimir / PDF.');return;}pintarJurada(true);window.print();pintarJurada();});
 $('pl-csv').addEventListener('click',descargarCsv);
 await cargarEmpresa();
 await cargarLiquidaciones();
}
iniciar();
