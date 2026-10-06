'use strict';
// Pantalla Empresas conectada a api/empresas.php: listar, seleccionar y administrar empresas.
(function(){
const API='../api/empresas.php';
const $=(s,r=document)=>r.querySelector(s);
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
async function api(consulta,opciones={}){
 let respuesta;
 try{respuesta=await fetch(API+(consulta||''),{...opciones,headers:{Accept:'application/json','Content-Type':'application/json'}});}
 catch{throw new Error('No se pudo conectar con la API PHP. Inicie la copia con npm run start:php.');}
 const datos=await respuesta.json().catch(()=>null);
 if(!datos)throw new Error('La API PHP no respondió. Inicie la copia con npm run start:php.');
 if(!respuesta.ok)throw new Error((datos.errores||[datos.error||'Error '+respuesta.status]).join(' '));
 return datos;
}
function aviso(texto){document.querySelector('.offline-message')?.remove();const e=document.createElement('div');e.className='offline-message';e.setAttribute('role','status');e.textContent=texto;document.body.append(e);setTimeout(()=>e.remove(),5000);}
function seleccionar(empresa){localStorage.setItem(EMPRESA_LOCAL_KEY,JSON.stringify(empresa));location.href='panel.html';}
function modal(titulo,contenido){
 document.querySelector('.empresas-modal-overlay')?.remove();const overlay=document.createElement('div');overlay.className='empresas-modal-overlay';overlay.dataset.conta='';
 const caja=document.createElement('section');caja.className='empresas-modal';caja.setAttribute('role','dialog');caja.setAttribute('aria-modal','true');
 caja.innerHTML=`<header><h2>${esc(titulo)}</h2><button type="button" class="empresas-modal-cerrar" aria-label="Cerrar">×</button></header><div class="empresas-modal-contenido" data-conta="">${contenido}</div>`;
 document.body.append(overlay);overlay.append(caja);const cerrar=()=>overlay.remove();caja.querySelector('.empresas-modal-cerrar').addEventListener('click',cerrar);overlay.addEventListener('click',e=>{if(e.target===overlay)cerrar();});return{overlay,caja,cerrar};
}

function iniciar(){
 const tabla=$('#listEmpresas');if(!tabla)return;
 const cuerpo=tabla.tBodies[0],info=$('#listEmpresas_info'),contenedor=tabla.closest('form')||tabla;
 contenedor.dataset.conta='';
 $('#listEmpresas_paginate')?.setAttribute('hidden','');$('#listEmpresas_length')?.setAttribute('hidden','');
 let empresas=[],puedeAdministrar=false;
 let busqueda='';
 const filtro=$('#estEmpr');
 if(filtro){filtro.innerHTML='<option value="activo">Activas</option><option value="inactivo">Inactivas</option><option value="todas">Todas</option>';filtro.value='activo';}

 const panel=document.createElement('div');panel.className='empresas-panel';panel.dataset.conta='';
 panel.innerHTML=`<form class="empresas-crear" hidden>
   <h3>Crear empresa</h3>
   <div class="empresas-campos">
    <label>Razón social *<input name="razon_social" class="form-control input-minimalist" required maxlength="191"></label>
    <label>RUT<input name="rut" class="form-control input-minimalist" maxlength="12" placeholder="76.123.456-7"></label>
    <label>Giro<input name="giro" class="form-control input-minimalist" maxlength="120"></label>
    <label>Régimen tributario<select name="regimen" class="form-control input-minimalist"><option value="">Seleccione</option><option>Pro Pyme General (14 D N°3)</option><option>Pro Pyme Transparente (14 D N°8)</option><option>Régimen General (14 A)</option><option>Renta presunta</option><option>Sin fines de lucro</option></select></label>
    <label>Teléfono<input name="telefono" class="form-control input-minimalist" maxlength="30"></label>
    <label>E-mail<input name="email" type="email" class="form-control input-minimalist" maxlength="120"></label>
   </div>
   <label class="empresas-check"><input type="checkbox" name="plan_ejemplo" checked> Cargar el plan de cuentas de ejemplo (63 cuentas)</label>
   <div class="empresas-botones"><button type="submit" class="btn-minimalist btn-minimalist-royal">Crear empresa</button> <button type="button" class="btn-minimalist empresas-cancelar">Cancelar</button></div>
  </form>
  `;
 contenedor.before(panel);
 const crear=$('.empresas-crear',panel);

 const renderizar=()=>{
  const actual=empresaSeleccionada()?.id,estado=filtro?.value||'activo';
  const visibles=empresas.filter(e=>{
   const coincideEstado=estado==='todas'||(estado==='inactivo'?(e.estado||'activo')==='inactivo':(e.estado||'activo')==='activo');
   return coincideEstado&&(!busqueda||[e.razon_social,e.rut,e.regimen,e.telefono,e.email].some(v=>String(v||'').toLowerCase().includes(busqueda)));
  });
  cuerpo.innerHTML=visibles.length?visibles.map((e,i)=>{
   const inactiva=(e.estado||'activo')==='inactivo';
   const editarBtn='<a href="#" class="js_cargarEditarEmpresa puntero btn-accion-empresa" data-accion="editar" title="Editar" aria-label="Editar"><span class="btn-action-glow btn-glow-celeste"><i class="fa fa-pencil" aria-hidden="true"></i></span></a>';
   const verBtn='<a href="#" class="js_verEmpresa puntero btn-accion-empresa" data-accion="ver" title="Ver" aria-label="Ver"><span class="btn-action-glow btn-glow-cafe-cl"><i class="fa fa-eye" aria-hidden="true"></i></span></a>';
   const estadoBtn=`<a href="#" class="js_cargarEliminarEmpresa puntero btn-accion-empresa" data-accion="estado" title="${inactiva?'Activar':'Desactivar'}" aria-label="${inactiva?'Activar':'Desactivar'}"><span class="btn-action-glow ${inactiva?'btn-glow-rojo':'btn-glow-verde'}"><i class="fa ${inactiva?'fa-times':'fa-check'}" aria-hidden="true"></i></span></a>`;
   return `<tr class="${i%2?'even':'odd'}${e.id===actual?' empresas-actual':''}${inactiva?' empresas-inactiva':''}" data-id="${e.id}">
    <td class="td-empresa-corta empresas-ingresar" title="Doble clic para ingresar a esta empresa" style="cursor:pointer">${esc(e.razon_social)}${e.id===actual?' <span class="empresas-etiqueta">seleccionada</span>':''}${inactiva?' <span class="empresas-etiqueta empresas-etiqueta-inactiva">inactiva</span>':''}</td><td>${esc(e.rut)}</td><td class="texto_izq">${esc(e.regimen)}</td><td>${esc(e.telefono)}</td><td>${esc(e.email)}</td>
    <td class="empresas-acciones-celda" style="box-sizing:content-box;width:146.075px !important;min-width:146.075px !important;max-width:146.075px !important;text-align:center !important;white-space:nowrap !important">${editarBtn}${verBtn}${estadoBtn}</td></tr>`;
  }).join(''):'<tr><td colspan="6" class="dataTables_empty">No hay empresas en este filtro.</td></tr>';
  if(info)info.textContent=`Mostrando ${visibles.length} de ${empresas.length} ${empresas.length===1?'empresa':'empresas'}`;
 };
 const pintar=async()=>{
  try{const datos=await api('');empresas=datos.empresas||[];puedeAdministrar=Boolean(datos.puede_administrar);renderizar();}
  catch(e){cuerpo.innerHTML=`<tr><td colspan="6" class="dataTables_empty">${esc(e.message)}</td></tr>`;if(info)info.textContent='';}
 };
 filtro?.addEventListener('change',renderizar);
 $('#listEmpresas_filter input')?.addEventListener('input',e=>{busqueda=e.target.value.trim().toLowerCase();renderizar();});
 cuerpo.addEventListener('dblclick',e=>{
  const nombre=e.target.closest('.empresas-ingresar');if(!nombre)return;
  const fila=nombre.closest('tr[data-id]');if(!fila)return;
  const empresa=empresas.find(x=>x.id===Number(fila.dataset.id));if(!empresa)return;
  if(empresa.estado==='inactivo'){aviso('Activa esta empresa antes de ingresar.');return;}
  seleccionar(empresa);
 });
 cuerpo.addEventListener('click',async e=>{
  const b=e.target.closest('[data-accion]');if(!b)return;
  e.preventDefault();
  const empresa=empresas.find(x=>x.id===Number(b.closest('tr').dataset.id));if(!empresa)return;
  if(b.dataset.accion==='ver'){
   const datos=[['Razón social',empresa.razon_social],['RUT',empresa.rut],['Giro',empresa.giro],['Régimen tributario',empresa.regimen],['Teléfono',empresa.telefono],['E-mail',empresa.email],['Estado',empresa.estado==='inactivo'?'Inactiva':'Activa'],['Vistas importadas',empresa.vistas],['Registros importados',empresa.registros],['Cuentas',empresa.cuentas],['Vouchers',empresa.vouchers]];
   const vista=modal('Ver empresa',`<dl class="empresas-detalle">${datos.map(([k,v])=>`<div><dt>${esc(k)}</dt><dd>${esc(v||'—')}</dd></div>`).join('')}</dl>`);vista.caja.querySelector('.empresas-modal-cerrar').focus();return;
  }
  if(!puedeAdministrar){aviso('Solo un administrador puede modificar empresas.');return;}
  if(b.dataset.accion==='editar'){
   const regimenes=['Pro Pyme General (14 D N°3)','Pro Pyme Transparente (14 D N°8)','Régimen General (14 A)','Renta presunta','Sin fines de lucro'];if(empresa.regimen&&!regimenes.includes(empresa.regimen))regimenes.unshift(empresa.regimen);
   const edicion=modal('Editar empresa',`<form class="empresas-editar">
    <label>Razón social *<input name="razon_social" required maxlength="191" value="${esc(empresa.razon_social)}"></label>
    <label>RUT<input name="rut" maxlength="12" value="${esc(empresa.rut)}"></label>
    <label>Giro<input name="giro" maxlength="120" value="${esc(empresa.giro)}"></label>
    <label>Régimen tributario<select name="regimen">${regimenes.map(r=>`<option ${r===empresa.regimen?'selected':''}>${esc(r)}</option>`).join('')}<option value="" ${!empresa.regimen?'selected':''}>Sin especificar</option></select></label>
    <label>Teléfono<input name="telefono" maxlength="30" value="${esc(empresa.telefono)}"></label>
    <label>E-mail<input name="email" type="email" maxlength="120" value="${esc(empresa.email)}"></label>
    <div class="empresas-modal-botones"><button class="btn-minimalist btn-minimalist-royal" type="submit">Guardar cambios</button></div>
   </form>`);
   const form=edicion.caja.querySelector('form');form.addEventListener('submit',async ev=>{ev.preventDefault();const guardar=form.querySelector('[type=submit]');guardar.disabled=true;
    try{const datos=Object.fromEntries(new FormData(form));const r=await api(`?id=${empresa.id}`,{method:'PUT',body:JSON.stringify(datos)});const actual=empresaSeleccionada();if(actual?.id===empresa.id)localStorage.setItem(EMPRESA_LOCAL_KEY,JSON.stringify(r.empresa));edicion.cerrar();aviso('Cambios guardados.');await pintar();}
    catch(err){aviso(err.message);}finally{guardar.disabled=false;}
   });form.querySelector('[name=razon_social]').focus();return;
  }
  if(b.dataset.accion==='estado'){
   const inactiva=empresa.estado==='inactivo',nuevo=inactiva?'activo':'inactivo';
   if(!window.confirm(`${inactiva?'¿Activar':'¿Desactivar'} ${empresa.razon_social}? ${inactiva?'':'La empresa dejará de aparecer en el filtro Activas.'}`))return;
   b.disabled=true;try{await api(`?id=${empresa.id}`,{method:'PATCH',body:JSON.stringify({estado:nuevo})});if(nuevo==='inactivo'&&empresaSeleccionada()?.id===empresa.id)localStorage.removeItem(EMPRESA_LOCAL_KEY);aviso(`Empresa ${inactiva?'activada':'desactivada'}.`);await pintar();}catch(err){aviso(err.message);}finally{b.disabled=false;}
  }
 });
 // "Crear Empresa" del menú de acciones abre el formulario.
 document.addEventListener('click',e=>{const a=e.target.closest('a.ic-btn-action-nav');if(!a||!/Crear Empresa/.test(a.textContent))return;
  e.preventDefault();e.stopPropagation();crear.hidden=false;crear.razon_social.focus();},true);
 $('.empresas-cancelar',panel).addEventListener('click',()=>{crear.reset();crear.hidden=true;});
 crear.addEventListener('submit',async e=>{e.preventDefault();const b=crear.querySelector('[type=submit]');b.disabled=true;
  const datos=Object.fromEntries(new FormData(crear));datos.plan_ejemplo=crear.plan_ejemplo.checked;
  try{const r=await api('',{method:'POST',body:JSON.stringify(datos)});crear.reset();crear.hidden=true;
   aviso(`Empresa ${r.empresa.razon_social} creada`+(r.cuentas_agregadas?` con ${r.cuentas_agregadas} cuentas de ejemplo.`:'.'));await pintar();}
  catch(err){aviso(err.message);}finally{b.disabled=false;}});
 pintar();
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',iniciar);else iniciar();
})();
