'use strict';
// Plan de cuentas de la empresa seleccionada (api/plan-cuentas.php): listar, cargar el plan de ejemplo, agregar, editar y eliminar cuentas.
const PLAN_CLASES=['','texto_izq','texto_izq','texto_izq','texto_centrado','texto_centrado','texto_izq','texto_centrado'];
const PLAN_TIPOS={activo:'Activo',pasivo:'Pasivo y patrimonio',perdida:'Pérdidas',ganancia:'Ganancias',otra:'Otra'};

async function apiPlan(consulta,opciones={}){
 let respuesta;
 try{respuesta=await fetch('../api/plan-cuentas.php'+(consulta||''),{...opciones,headers:{Accept:'application/json','Content-Type':'application/json',...(typeof cabeceraEmpresa==='function'?cabeceraEmpresa():{})}});}
 catch{throw new Error('No se pudo conectar con la API PHP. Inicie la copia con npm run start:php.');}
 const datos=await respuesta.json().catch(()=>null);
 if(!datos)throw new Error('La API PHP no respondió. Inicie la copia con npm run start:php.');
 if(!respuesta.ok)throw new Error((datos.errores||[datos.error||'Error '+respuesta.status]).join(' '));
 return datos;
}
// Usa las columnas importadas de iContador cuando existen; si no, arma la fila con el código y el nombre.
function filaPlan(cuenta,indice){
 const celdas=cuenta.detalle?cuenta.detalle.slice(0,7):[PLAN_TIPOS[cuenta.clase]||'','',cuenta.codigo+' '+cuenta.nombre,'','','',''];
 const tr=document.createElement('tr');tr.setAttribute('role','row');tr.className=indice%2?'even':'odd';tr.dataset.codigo=cuenta.codigo;tr.dataset.nombre=cuenta.nombre;
 PLAN_CLASES.forEach((clase,i)=>{const td=document.createElement('td');if(clase)td.className=clase;
  if(i===7){const ed=document.createElement('button');ed.type='button';ed.className='plan-editar';ed.textContent='Editar';ed.setAttribute('aria-label','Editar cuenta '+cuenta.codigo);
   const b=document.createElement('button');b.type='button';b.className='plan-eliminar';b.textContent='Eliminar';b.setAttribute('aria-label','Eliminar cuenta '+cuenta.codigo);td.append(ed,' ',b);}
  else td.textContent=celdas[i]??'';tr.append(td);});
 return tr;
}
function mensajePlan(cuerpo,texto){
 const tr=document.createElement('tr');const td=document.createElement('td');td.colSpan=PLAN_CLASES.length;td.className='dataTables_empty';td.textContent=texto;tr.append(td);cuerpo.replaceChildren(tr);
}
function avisoPlan(texto){document.querySelector('.offline-message')?.remove();const e=document.createElement('div');e.className='offline-message';e.setAttribute('role','status');e.textContent=texto;document.body.append(e);setTimeout(()=>e.remove(),4000);}

function barraPlan(tabla,recargar){
 const barra=document.createElement('form');barra.className='plan-barra';barra.dataset.conta='';
 barra.innerHTML=`<button type="button" class="btn-minimalist btn-minimalist-royal plan-ejemplo">Cargar plan de ejemplo</button>
  <span class="plan-separador">o agregue una cuenta:</span>
  <label>Código <input name="codigo" class="form-control input-minimalist" required maxlength="20" placeholder="1.1.20"></label>
  <label>Nombre <input name="nombre" class="form-control input-minimalist" required maxlength="120" placeholder="Nombre de la cuenta"></label>
  <button type="submit" class="btn-minimalist btn-minimalist-royal plan-guardar">+ Agregar cuenta</button>
  <button type="button" class="btn-minimalist plan-cancelar" hidden>Cancelar</button>`;
 (tabla.closest('.dataTables_wrapper')||tabla).before(barra);
 barra.querySelector('.plan-ejemplo').addEventListener('click',async e=>{const b=e.currentTarget;b.disabled=true;
  try{const r=await apiPlan('',{method:'POST',body:JSON.stringify({ejemplo:true})});avisoPlan(r.agregadas?`Se agregaron ${r.agregadas} cuentas del plan de ejemplo.`:'La empresa ya tiene todas las cuentas del plan de ejemplo.');recargar();}
  catch(err){avisoPlan(err.message);}finally{b.disabled=false;}});
 // Con dataset.editando el mismo formulario guarda los cambios de una cuenta existente.
 const separador=barra.querySelector('.plan-separador'),guardar=barra.querySelector('.plan-guardar'),cancelar=barra.querySelector('.plan-cancelar');
 barra.editar=(codigo,nombre)=>{barra.dataset.editando=codigo;barra.codigo.value=codigo;barra.nombre.value=nombre;separador.textContent=`Editando la cuenta ${codigo}:`;guardar.textContent='Guardar cambios';cancelar.hidden=false;barra.scrollIntoView({block:'center'});barra.nombre.focus();};
 const terminar=()=>{delete barra.dataset.editando;barra.reset();separador.textContent='o agregue una cuenta:';guardar.textContent='+ Agregar cuenta';cancelar.hidden=true;};
 barra.terminar=terminar;cancelar.addEventListener('click',terminar);
 barra.addEventListener('submit',async e=>{e.preventDefault();const editando=barra.dataset.editando;const cuerpo=JSON.stringify({codigo:barra.codigo.value,nombre:barra.nombre.value});
  try{
   if(editando){const c=await apiPlan('?codigo='+encodeURIComponent(editando),{method:'PUT',body:cuerpo});avisoPlan(`Cuenta ${c.codigo} actualizada.`);}
   else{const c=await apiPlan('',{method:'POST',body:cuerpo});avisoPlan(`Cuenta ${c.codigo} agregada.`);}
   terminar();recargar();
  }catch(err){avisoPlan(err.message);}});
 return barra;
}

async function cargarPlanCuentas(){
 const tabla=document.getElementById('listPlanCuentas');const cuerpo=tabla?.tBodies[0];if(!cuerpo)return;
 tabla.dataset.conta='';
 const info=document.getElementById('listPlanCuentas_info');const procesando=document.getElementById('listPlanCuentas_processing');
 const pintar=async()=>{
  if(procesando)procesando.style.display='block';
  try{
   const cuentas=(await apiPlan('')).cuentas||[];
   if(cuentas.length)cuerpo.replaceChildren(...cuentas.map(filaPlan));else mensajePlan(cuerpo,'Esta empresa aún no tiene plan de cuentas. Use “Cargar plan de ejemplo” o agregue las cuentas una a una.');
   if(info)info.textContent=`Mostrando ${cuentas.length} cuentas`;
  }catch(error){mensajePlan(cuerpo,error.message);if(info)info.textContent='';}
  finally{if(procesando)procesando.style.display='none';}
 };
 const barra=barraPlan(tabla,pintar);
 // "Agregar Cuenta" del menú usa el formulario de esta página.
 document.addEventListener('click',e=>{const enlace=e.target.closest('a[data-local-action="js_cargASCUENTQT"]');if(!enlace)return;e.preventDefault();e.stopPropagation();barra.terminar();barra.codigo.focus();},true);
 cuerpo.addEventListener('click',e=>{const b=e.target.closest('.plan-editar');if(!b)return;const fila=b.closest('tr');barra.editar(fila.dataset.codigo,fila.dataset.nombre);});
 cuerpo.addEventListener('click',async e=>{const b=e.target.closest('.plan-eliminar');if(!b)return;const codigo=b.closest('tr').dataset.codigo;
  if(!confirm(`¿Eliminar la cuenta ${codigo}?`))return;
  try{await apiPlan('?codigo='+encodeURIComponent(codigo),{method:'DELETE'});avisoPlan(`Cuenta ${codigo} eliminada.`);pintar();}catch(err){avisoPlan(err.message);}});
 document.getElementById('listPlanCuentas_paginate')?.setAttribute('hidden','');document.getElementById('listPlanCuentas_length')?.setAttribute('hidden','');
 pintar();
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',cargarPlanCuentas);else cargarPlanCuentas();
