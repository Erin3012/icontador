'use strict';
// Pantalla Empresas conectada a api/empresas.php: listar, seleccionar, crear, exportar e importar empresas.
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

function iniciar(){
 const tabla=$('#listEmpresas');if(!tabla)return;
 const cuerpo=tabla.tBodies[0],info=$('#listEmpresas_info'),contenedor=tabla.closest('form')||tabla;
 contenedor.dataset.conta='';
 $('#listEmpresas_paginate')?.setAttribute('hidden','');$('#listEmpresas_length')?.setAttribute('hidden','');
 let empresas=[];

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
  <div class="empresas-importar"><span>¿Tiene una empresa exportada desde otra copia (por ejemplo, desde su equipo)?</span>
   <label class="btn-minimalist btn-minimalist-royal">Importar empresa desde archivo<input type="file" accept=".json,application/json" hidden></label></div>`;
 contenedor.before(panel);
 const crear=$('.empresas-crear',panel),archivo=$('input[type=file]',panel);

 const pintar=async()=>{
  try{empresas=(await api('')).empresas;}catch(e){cuerpo.innerHTML=`<tr><td colspan="6" class="dataTables_empty">${esc(e.message)}</td></tr>`;if(info)info.textContent='';return;}
  const actual=empresaSeleccionada()?.id;
  cuerpo.innerHTML=empresas.length?empresas.map((e,i)=>`<tr class="${i%2?'even':'odd'}${e.id===actual?' empresas-actual':''}" data-id="${e.id}">
   <td class="td-empresa-corta">${esc(e.razon_social)}${e.id===actual?' <span class="empresas-etiqueta">seleccionada</span>':''}</td><td>${esc(e.rut)}</td><td class="texto_izq">${esc(e.regimen)}</td><td>${esc(e.telefono)}</td><td>${esc(e.email)}</td>
   <td class="texto_centrado"><button type="button" class="empresas-accion" data-accion="seleccionar">Seleccionar</button> <a class="empresas-accion" href="${API}?exportar=${e.id}" download>Exportar</a></td></tr>`).join('')
   :'<tr><td colspan="6" class="dataTables_empty">No hay empresas. Use “Crear Empresa” o importe una desde archivo.</td></tr>';
  if(info)info.textContent=`Mostrando ${empresas.length} ${empresas.length===1?'empresa':'empresas'}`;
 };
 cuerpo.addEventListener('click',e=>{const b=e.target.closest('[data-accion=seleccionar]');if(!b)return;
  seleccionar(empresas.find(x=>x.id===Number(b.closest('tr').dataset.id)));});
 // "Crear Empresa" del menú de acciones abre el formulario.
 document.addEventListener('click',e=>{const a=e.target.closest('a.ic-btn-action-nav');if(!a||!/Crear Empresa/.test(a.textContent))return;
  e.preventDefault();e.stopPropagation();crear.hidden=false;crear.razon_social.focus();},true);
 $('.empresas-cancelar',panel).addEventListener('click',()=>{crear.reset();crear.hidden=true;});
 crear.addEventListener('submit',async e=>{e.preventDefault();const b=crear.querySelector('[type=submit]');b.disabled=true;
  const datos=Object.fromEntries(new FormData(crear));datos.plan_ejemplo=crear.plan_ejemplo.checked;
  try{const r=await api('',{method:'POST',body:JSON.stringify(datos)});crear.reset();crear.hidden=true;
   aviso(`Empresa ${r.empresa.razon_social} creada`+(r.cuentas_agregadas?` con ${r.cuentas_agregadas} cuentas de ejemplo.`:'.'));await pintar();}
  catch(err){aviso(err.message);}finally{b.disabled=false;}});
 archivo.addEventListener('change',async()=>{const f=archivo.files[0];if(!f)return;
  try{const r=await api('',{method:'POST',body:await f.text()});const n=r.importado;
   aviso(`Empresa ${r.empresa.razon_social} importada: ${n.cuentas} cuentas, ${n.vouchers} vouchers, ${n.rcv} documentos RCV, ${n.liquidaciones} liquidaciones.`);await pintar();}
  catch(err){aviso(err.message);}finally{archivo.value='';}});
 pintar();
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',iniciar);else iniciar();
})();
