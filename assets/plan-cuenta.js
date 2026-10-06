'use strict';
// Reemplaza las filas capturadas de #listPlanCuentas por el plan importado en la base local (api/plan-cuentas.php).
const PLAN_CLASES=['','texto_izq','texto_izq','texto_izq','texto_centrado','texto_centrado','texto_izq','texto_centrado'];

function filaPlan(celdas,indice){
 const tr=document.createElement('tr');tr.setAttribute('role','row');tr.className=indice%2?'even':'odd';
 PLAN_CLASES.forEach((clase,i)=>{const td=document.createElement('td');if(clase)td.className=clase;td.textContent=celdas[i]??'';tr.append(td);});
 return tr;
}
function mensajePlan(cuerpo,texto){
 const tr=document.createElement('tr');const td=document.createElement('td');td.colSpan=PLAN_CLASES.length;td.className='dataTables_empty';td.textContent=texto;tr.append(td);cuerpo.replaceChildren(tr);
}
async function cargarPlanCuentas(){
 const tabla=document.getElementById('listPlanCuentas');const cuerpo=tabla?.tBodies[0];if(!cuerpo)return;
 const info=document.getElementById('listPlanCuentas_info');const procesando=document.getElementById('listPlanCuentas_processing');
 const empresa=typeof empresaSeleccionada==='function'?empresaSeleccionada():null;
 const url='../api/plan-cuentas.php'+(empresa?.id?'?empresa_id='+encodeURIComponent(empresa.id):'');
 if(procesando)procesando.style.display='block';
 try{
  const respuesta=await fetch(url,{headers:{Accept:'application/json'}});
  const datos=await respuesta.json();if(!respuesta.ok)throw new Error(datos.error||'Error al cargar el plan de cuentas');
  const filas=datos.filas||[];
  if(filas.length)cuerpo.replaceChildren(...filas.map(filaPlan));else mensajePlan(cuerpo,'La empresa no tiene un plan de cuentas importado.');
  if(info)info.textContent=filas.length?'Mostrando registros del 1 al '+filas.length+' de un total de '+filas.length+' registros':'Mostrando registros del 0 al 0 de un total de 0 registros';
 }catch(error){
  mensajePlan(cuerpo,'No se pudo cargar el plan de cuentas desde la base local. Inicie la copia con npm run start:php.');
  if(info)info.textContent='';
 }finally{if(procesando)procesando.style.display='none';}
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',cargarPlanCuentas);else cargarPlanCuentas();
