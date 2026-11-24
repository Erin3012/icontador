(() => {
 'use strict';
 const API='../api/sii.php?logs=1';
 const ETAPAS={iniciada:'Solicitud tomada por el agente',autenticando:'Iniciando sesión en el SII',sesion_lista:'Sesión del SII lista',abriendo_rcv:'Abriendo Registro de Compras y Ventas',abriendo_honorarios:'Abriendo Boletas de Honorarios',seleccionando_empresa:'Seleccionando empresa',configurando_periodo:'Configurando período',seleccionando_libro:'Seleccionando libro',consultando:'Consultando el SII',descargando:'Descargando el informe',validando:'Validando el archivo y sus datos',importando:'Guardando documentos en qlc.cl',completada:'Sincronización completada',error:'La tarea terminó con error',cancelada:'Tarea cancelada'};
 let timer=0,observador=null,ocupado=false;

 function crearPanel(host){
  let panel=document.getElementById('rcv-vps-terminal');
  if(!panel){
   panel=document.createElement('section');panel.id='rcv-vps-terminal';panel.className='rcv-vps-terminal';panel.setAttribute('aria-label','Registro operativo del agente VPS');
   const cabecera=document.createElement('div');cabecera.className='rcv-vps-head';
   const titulo=document.createElement('h4');titulo.textContent='Logs del agente VPS';
   const estado=document.createElement('span');estado.id='rcv-vps-status';estado.setAttribute('role','status');estado.textContent='Conectando…';
   cabecera.append(titulo,estado);
   const salida=document.createElement('pre');salida.id='rcv-vps-output';salida.setAttribute('aria-live','polite');salida.textContent='Esperando eventos del agente…';
   const nota=document.createElement('p');nota.className='rcv-note';nota.textContent='Registro de actividad RCV de solo lectura. No muestra credenciales ni permite ejecutar comandos.';
   panel.append(cabecera,salida,nota);
  }
  panel.hidden=false;
  // The legacy RCV view has several broad visibility rules. Keep this
  // read-only panel rendered even when those rules are refreshed at runtime.
  panel.style.setProperty('display','block','important');
  panel.style.setProperty('visibility','visible','important');
  panel.style.setProperty('opacity','1','important');
  panel.style.setProperty('min-height','96px','important');
  panel.style.setProperty('position','relative','important');
  panel.style.setProperty('z-index','1','important');
  const intro=host.querySelector(':scope > p');
  if(intro?.nextSibling!==panel)host.insertBefore(panel,intro?.nextSibling||host.firstChild);
  if(!panel.dataset.vpsLogsMounted){
   panel.dataset.vpsLogsMounted='1';
   new MutationObserver(()=>{if(panel.hidden)panel.hidden=false;}).observe(panel,{attributes:true,attributeFilter:['hidden']});
  }
  return panel;
 }

 function formatoPeriodo(valor){
  if(!/^\d{4}-\d{2}$/.test(String(valor||'')))return 'consulta';
  const [a,m]=valor.split('-');const mes=['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'][Number(m)-1];
  return mes?`${mes} ${a}`:'consulta';
 }
 async function actualizar(panel){
  if(ocupado)return;ocupado=true;
  const estado=panel.querySelector('#rcv-vps-status'),salida=panel.querySelector('#rcv-vps-output');
  try{
   const respuesta=await fetch(API,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
   if(!respuesta.ok){estado.textContent=`No se pudieron consultar los logs (HTTP ${respuesta.status}).`;return;}
   const datos=await respuesta.json();
   estado.textContent=datos.agente?.conectado?'Agente VPS conectado · actualizado ahora':'Agente VPS desconectado · mostrando últimos eventos recibidos';
   const eventos=Array.isArray(datos.eventos)?datos.eventos:[];
   const lineas=eventos.map(e=>{
    const hora=String(e.creado_en||'').replace('T',' ').slice(0,19);
     const libro=e.tipo==='honorarios'?'Honorarios '+e.libro:(e.libro==='compras'?'compras':e.libro==='ventas'?'ventas':'');
    const tarea=Number(e.tarea_id)||0;
    const etapa=ETAPAS[e.etapa]||'Actualización del agente';
     const n=e.etapa==='completada'&&/^\d{1,9}$/.test(String(e.detalle||''))?` · ${e.detalle} registros`:'';
    return `[${hora}] tarea ${tarea} · ${[libro,formatoPeriodo(e.periodo)].filter(Boolean).join(' ')} · ${etapa}${n}`;
   });
   salida.textContent=lineas.length?lineas.join('\n'):'Aún no hay eventos de tareas RCV.';
   salida.scrollTop=salida.scrollHeight;
  }catch{estado.textContent='No se pudieron consultar los logs del agente.';}
  finally{ocupado=false;}
 }
 function instalar(){
  const host=document.getElementById('rcv-sii');
  if(!host)return false;
  const panel=crearPanel(host);actualizar(panel).catch(()=>{});
  clearInterval(timer);timer=setInterval(()=>actualizar(panel),10000);
  return true;
 }
 function iniciar(){
  if(instalar())return;
  observador=new MutationObserver(()=>{if(instalar()){observador?.disconnect();observador=null;}});
  if(document.body)observador.observe(document.body,{childList:true,subtree:true});
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',iniciar,{once:true});else iniciar();
})();
