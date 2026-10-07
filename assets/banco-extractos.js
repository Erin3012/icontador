'use strict';
// Pantalla Banco (views/banco-extractos.html): carga de la cartola desde la plantilla CSV, conciliación con vouchers y reporte de saldos (api/banco.php).
(function(){
 const API='../api/banco.php';
 const $=id=>document.getElementById(id);
 const dinero=n=>(n<0?'-':'')+'$'+Math.abs(n).toLocaleString('es-CL');
 const fecha=f=>f?f.split('-').reverse().join('-'):'';
 const estado={extracto:null,comprobante:null,extractos:[],comprobantes:[]};

 async function api(consulta,opciones={}){
  let respuesta;
  try{respuesta=await fetch(API+consulta,{...opciones,headers:{Accept:'application/json',...(opciones.body?{'Content-Type':'application/json'}:{}),...(typeof cabeceraEmpresa==='function'?cabeceraEmpresa():{})}});}
  catch{throw new Error('No se pudo conectar con el servidor.');}
  const datos=await respuesta.json().catch(()=>null);
  if(!datos)throw new Error('El servidor no respondió (error '+respuesta.status+').');
  if(!respuesta.ok)throw Object.assign(new Error((datos.errores||[datos.error||'Error '+respuesta.status]).join(' ')),{errores:datos.errores});
  return datos;
 }
 function mensaje(id,texto,ok,lista){
  const caja=$(id);caja.hidden=!texto;caja.className='ban-mensaje'+(ok?' ok':'');caja.replaceChildren(texto||'');
  if(lista&&lista.length>1){const ul=document.createElement('ul');for(const l of lista){const li=document.createElement('li');li.textContent=l;ul.append(li);}caja.replaceChildren(texto,ul);}
 }
 function celda(tr,texto,clase){const td=document.createElement('td');if(clase)td.className=clase;td.textContent=texto;tr.append(td);return td;}
 function vacio(cuerpo,columnas,texto){const tr=document.createElement('tr');const td=celda(tr,texto,'ban-vacio');td.colSpan=columnas;cuerpo.replaceChildren(tr);}
 function boton(texto,clase,accion){const b=document.createElement('button');b.type='button';b.className=clase;b.textContent=texto;b.addEventListener('click',accion);return b;}
 function radio(nombre,valor,etiqueta){const r=document.createElement('input');r.type='radio';r.name=nombre;r.value=valor;r.setAttribute('aria-label',etiqueta);return r;}
 function filtros(){return Object.fromEntries([...new FormData($('ban-filtros')).entries()].filter(([,v])=>v!==''));}
 const consulta=(vista,datos)=>'?'+new URLSearchParams({vista,...datos});

 // ---- 1. Cargar la cartola ----
 $('ban-importar').addEventListener('submit',async e=>{
  e.preventDefault();
  const archivo=$('ban-csv').files[0];
  if(!archivo)return mensaje('ban-importar-msg','Seleccione el archivo CSV completado.');
  if(archivo.size>5*1024*1024)return mensaje('ban-importar-msg','El archivo supera los 5 MB.');
  const b=$('ban-subir');b.disabled=true;mensaje('ban-importar-msg','Importando…',true);
  try{
   // Excel en Windows guarda el CSV en Windows-1252; si el archivo no es UTF-8 válido se lee con esa codificación.
   const bytes=new Uint8Array(await archivo.arrayBuffer());
   let texto;try{texto=new TextDecoder('utf-8',{fatal:true}).decode(bytes);}catch{texto=new TextDecoder('windows-1252').decode(bytes);}
   const r=await api('?accion=importar',{method:'POST',body:JSON.stringify({csv:texto})});
   mensaje('ban-importar-msg',`Se importaron ${r.importados} movimientos del ${fecha(r.desde)} al ${fecha(r.hasta)}.`+(r.omitidos?` ${r.omitidos} ya estaban cargados y no se repitieron.`:''),true);
   $('ban-importar').reset();cargarConciliacion();
  }catch(err){mensaje('ban-importar-msg',err.errores&&err.errores.length>1?'El archivo no se importó. Corrija estas líneas y vuelva a subirlo:':'No se importó: '+err.message,false,err.errores);}
  finally{b.disabled=false;}
 });

 // ---- 2. Conciliación ----
 function pintarExtractos(){
  const cuerpo=$('ban-extractos').tBodies[0];
  $('ban-ext-total').textContent=estado.extractos.length?`(${estado.extractos.length})`:'';
  if(!estado.extractos.length)return vacio(cuerpo,7,'No hay movimientos con estos filtros.');
  cuerpo.replaceChildren(...estado.extractos.map(m=>{
   const tr=document.createElement('tr');tr.dataset.id=m.id;
   const td=document.createElement('td');
   if(m.estado==='pendiente'){const r=radio('ban-ext',m.id,'Elegir movimiento '+m.descripcion);r.checked=estado.extracto===m.id;td.append(r);}
   tr.append(td);
   celda(tr,fecha(m.fecha));celda(tr,m.descripcion);celda(tr,m.referencia);celda(tr,dinero(m.monto),'num'+(m.monto<0?' neg':''));
   const est=celda(tr,'');const chip=document.createElement('span');chip.className='ban-chip '+m.estado;
   chip.textContent=m.estado==='pendiente'?'Pendiente':'Conciliado';est.append(chip);
   if(m.comprobante){const v=document.createElement('small');v.textContent=` ${m.comprobante.tipoNombre} N° ${m.comprobante.numero}`;est.append(v);}
   else if(m.estado==='conciliado'){const v=document.createElement('small');v.textContent=' sin voucher';est.append(v);}
   const acciones=celda(tr,'');
   acciones.append(m.estado==='conciliado'?boton('Desconciliar','ban-mini',()=>accion(`?accion=desconciliar&id=${m.id}`,{method:'POST',body:'{}'},'Movimiento devuelto a pendientes.'))
    :boton('Eliminar','ban-mini ban-peligro',()=>{if(confirm(`¿Eliminar el movimiento "${m.descripcion}" por ${dinero(m.monto)}?`))accion(`?id=${m.id}`,{method:'DELETE'},'Movimiento eliminado.');}));
   return tr;
  }));
 }
 function pintarComprobantes(){
  const cuerpo=$('ban-comprobantes').tBodies[0];
  $('ban-comp-total').textContent=estado.comprobantes.length?`(${estado.comprobantes.length})`:'';
  if(!estado.comprobantes.length)return vacio(cuerpo,5,'No hay vouchers sin conciliar con estos filtros.');
  const elegido=estado.extractos.find(m=>m.id===estado.extracto);
  cuerpo.replaceChildren(...estado.comprobantes.map(v=>{
   const tr=document.createElement('tr');
   if(elegido&&Math.abs(elegido.monto)===v.total)tr.className='coincide';
   const td=document.createElement('td');const r=radio('ban-comp',v.id,`Elegir voucher ${v.tipoNombre} ${v.numero}`);r.checked=estado.comprobante===v.id;td.append(r);tr.append(td);
   celda(tr,fecha(v.fecha));celda(tr,`${v.tipoNombre} N° ${v.numero}`);celda(tr,v.glosa);celda(tr,dinero(v.monto),'num'+(v.monto<0?' neg':''));
   return tr;
  }));
 }
 function pintarSeleccion(){
  const m=estado.extractos.find(x=>x.id===estado.extracto),v=estado.comprobantes.find(x=>x.id===estado.comprobante);
  $('ban-conciliar').disabled=!m;
  $('ban-seleccion').textContent=!m?'Marque un movimiento pendiente.':`${m.descripcion} (${dinero(m.monto)})`+(v?` con ${v.tipoNombre} N° ${v.numero} (${dinero(v.monto)})`+(Math.abs(m.monto)!==v.total?' · los montos no coinciden':''):' · sin voucher');
 }
 async function cargarConciliacion(){
  const f=filtros();
  try{
   const [ext,comp]=await Promise.all([api(consulta('extractos',f)),api(consulta('comprobantes',{...f,estado:''}))]);
   estado.extractos=ext.extractos;estado.comprobantes=comp.comprobantes;
   if(!estado.extractos.some(m=>m.id===estado.extracto&&m.estado==='pendiente'))estado.extracto=null;
   if(!estado.comprobantes.some(v=>v.id===estado.comprobante))estado.comprobante=null;
   mensaje('ban-conciliar-msg','');
  }catch(err){estado.extractos=[];estado.comprobantes=[];mensaje('ban-conciliar-msg',err.message);}
  pintarExtractos();pintarComprobantes();pintarSeleccion();
 }
 async function accion(ruta,opciones,ok){
  try{await api(ruta,opciones);await cargarConciliacion();mensaje('ban-conciliar-msg',ok,true);}
  catch(err){mensaje('ban-conciliar-msg',err.message);}
 }
 $('ban-extractos').addEventListener('change',e=>{if(e.target.name==='ban-ext'){estado.extracto=Number(e.target.value);pintarComprobantes();pintarSeleccion();}});
 $('ban-comprobantes').addEventListener('change',e=>{if(e.target.name==='ban-comp'){estado.comprobante=Number(e.target.value);pintarSeleccion();}});
 $('ban-filtros').addEventListener('submit',e=>{e.preventDefault();cargarConciliacion();});
 $('ban-filtros').estado.addEventListener('change',cargarConciliacion);
 $('ban-limpiar').addEventListener('click',()=>{$('ban-filtros').reset();cargarConciliacion();});
 $('ban-conciliar').addEventListener('click',()=>{
  const m=estado.extractos.find(x=>x.id===estado.extracto);if(!m)return;
  if(!estado.comprobante&&!confirm(`¿Conciliar "${m.descripcion}" sin voucher?`))return;
  accion(`?accion=conciliar&id=${m.id}`,{method:'POST',body:JSON.stringify({comprobante:estado.comprobante})},'Movimiento conciliado.');
 });

 // ---- 3. Reporte de saldos ----
 function resumen(titulo,valor,clase){const d=document.createElement('div');d.className='ban-saldo '+(clase||'');const s=document.createElement('span');s.textContent=titulo;const b=document.createElement('strong');b.textContent=dinero(valor);d.append(s,b);return d;}
 $('ban-reporte-form').addEventListener('submit',async e=>{
  e.preventDefault();
  const f=Object.fromEntries([...new FormData(e.target).entries()].filter(([,v])=>v!==''));
  try{
   const r=await api(consulta('reporte',f));mensaje('ban-reporte-msg','');
   const periodo=document.createElement('p');periodo.className='ban-periodo';
   periodo.textContent=`${document.getElementById('ban-empresa').textContent||'Empresa'} · Período: ${r.desde?fecha(r.desde):'inicio'} al ${r.hasta?fecha(r.hasta):'hoy'} · ${r.movimientos.length} movimientos (${r.conciliados} conciliados, ${r.pendientes} pendientes)`;
   const saldos=document.createElement('div');saldos.className='ban-saldos';
   saldos.append(resumen('Saldo inicial',r.saldoInicial),resumen('Abonos',r.abonos,'pos'),resumen('Cargos',r.cargos,'neg'),resumen('Saldo final',r.saldoFinal,'final'));
   const tabla=document.createElement('table');tabla.className='ban-tabla';
   tabla.innerHTML='<thead><tr><th scope="col">Fecha</th><th scope="col">Descripción</th><th scope="col">Referencia</th><th scope="col" class="num">Monto</th><th scope="col" class="num">Saldo</th><th scope="col">Estado</th></tr></thead><tbody></tbody>';
   const cuerpo=tabla.tBodies[0];
   const ini=document.createElement('tr');ini.className='ban-total';celda(ini,'');celda(ini,'Saldo inicial');celda(ini,'');celda(ini,'');celda(ini,dinero(r.saldoInicial),'num');celda(ini,'');cuerpo.append(ini);
   for(const m of r.movimientos){const tr=document.createElement('tr');celda(tr,fecha(m.fecha));celda(tr,m.descripcion);celda(tr,m.referencia);celda(tr,dinero(m.monto),'num'+(m.monto<0?' neg':''));celda(tr,dinero(m.saldo),'num');celda(tr,m.estado==='conciliado'?'Conciliado':'Pendiente');cuerpo.append(tr);}
   const fin=document.createElement('tr');fin.className='ban-total';celda(fin,'');celda(fin,'Saldo final');celda(fin,'');celda(fin,dinero(r.abonos+r.cargos),'num');celda(fin,dinero(r.saldoFinal),'num');celda(fin,'');cuerpo.append(fin);
   $('ban-reporte').replaceChildren(periodo,saldos,tabla);$('ban-imprimir').hidden=false;
  }catch(err){mensaje('ban-reporte-msg',err.message);}
 });
 $('ban-imprimir').addEventListener('click',()=>window.print());

 const empresa=typeof empresaSeleccionada==='function'?empresaSeleccionada():null;
 $('ban-empresa').textContent=empresa?.razon_social||'';
 cargarConciliacion();
})();
