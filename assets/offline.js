'use strict';
const EMPRESA_LOCAL_KEY='icontador.empresaSeleccionada';

function empresaSeleccionada(){
 try{return JSON.parse(localStorage.getItem(EMPRESA_LOCAL_KEY)||'null');}catch{return null;}
}
// Las APIs PHP guardan y leen los datos de la empresa indicada en esta cabecera.
function cabeceraEmpresa(){
 const empresa=empresaSeleccionada();return empresa?.id?{'X-Empresa-Id':String(empresa.id)}:{};
}
function actualizarCabeceraEmpresa(){
 const empresa=empresaSeleccionada();if(!empresa)return;
 document.querySelectorAll('a.link_blanco').forEach(enlace=>{
  if(/USUARIO TITULAR:/i.test(enlace.textContent))enlace.textContent='USUARIO TITULAR: USUARIO LOCAL · '+empresa.razon_social;
 });
}
function crearSelectorEmpresa(empresas){
 document.querySelector('.empresa-local-overlay')?.remove();
 const overlay=document.createElement('div');overlay.className='empresa-local-overlay';
 const dialog=document.createElement('section');dialog.className='empresa-local-dialog';dialog.setAttribute('role','dialog');dialog.setAttribute('aria-modal','true');dialog.setAttribute('aria-labelledby','empresa-local-titulo');
 const titulo=document.createElement('h2');titulo.id='empresa-local-titulo';titulo.textContent='Seleccionar empresa';
 const ayuda=document.createElement('p');ayuda.textContent='Elige la empresa con la que deseas trabajar en esta copia local.';
 const lista=document.createElement('div');lista.className='empresa-local-lista';
 empresas.forEach(empresa=>{
  const boton=document.createElement('button');boton.type='button';boton.className='empresa-local-opcion';
  const nombre=document.createElement('strong');nombre.textContent=empresa.razon_social;
  const detalle=document.createElement('span');detalle.textContent=empresa.vistas+' vistas · '+empresa.registros+' registros importados';
  boton.append(nombre,detalle);boton.addEventListener('click',()=>{
   localStorage.setItem(EMPRESA_LOCAL_KEY,JSON.stringify(empresa));
   window.location.href='panel.html';
  });lista.append(boton);
 });
 if(!empresas.length){const vacio=document.createElement('p');vacio.className='empresa-local-vacio';vacio.textContent='No hay empresas importadas en la base local.';lista.append(vacio);}
 const cerrar=document.createElement('button');cerrar.type='button';cerrar.className='empresa-local-cerrar';cerrar.textContent='Cerrar';cerrar.addEventListener('click',()=>overlay.remove());
 dialog.append(titulo,ayuda,lista,cerrar);overlay.append(dialog);document.body.append(overlay);
 dialog.querySelector('button')?.focus();
}
async function mostrarSelectorEmpresa(){
 try{
  const respuesta=await fetch('../api/empresas.php',{headers:{Accept:'application/json'}});
  const datos=await respuesta.json();if(!respuesta.ok)throw new Error((datos.errores||[]).join(' ')||datos.error||'Error al cargar empresas');
  crearSelectorEmpresa(datos.empresas||[]);
 }catch(error){notify(error.message||'No se pudo cargar la empresa local.');}
}
// Página de inicio: nombre de la cuenta con sesión en la cabecera, fecha del día y pestañas de avisos.
async function mostrarUsuarioInicio(){
 try{
  const respuesta=await fetch('../api/usuario.php',{headers:{Accept:'application/json'}});
  if(!respuesta.ok)return;
  const usuario=await respuesta.json(),empresa=empresaSeleccionada();
  document.querySelectorAll('a.link_blanco').forEach(enlace=>{
   if(/USUARIO TITULAR:/i.test(enlace.textContent))enlace.textContent='USUARIO TITULAR: '+String(usuario.nombre||usuario.email||'').toUpperCase()+(empresa?' · '+empresa.razon_social:'');
  });
 }catch{}
}
function iniciarPaginaInicio(){
 const fecha=document.getElementById('fecha-hoy');
 if(fecha){const texto=new Date().toLocaleDateString('es-CL',{day:'2-digit',month:'long',year:'numeric'}).replace(/ de /g,' ');fecha.textContent=texto.replace(/(^|\s)\p{Ll}/u,l=>l.toUpperCase());}
 document.querySelectorAll('.tabs a[data-tab]').forEach(pestana=>pestana.addEventListener('click',event=>{
  event.preventDefault();event.stopPropagation();
  document.querySelectorAll('.tabs a[data-tab]').forEach(otra=>otra.classList.toggle('active',otra===pestana));
  document.querySelectorAll('.secciones > article').forEach(articulo=>{articulo.style.display=articulo.id===pestana.dataset.tab?'block':'none';});
 }));
 mostrarUsuarioInicio();
}
function iniciarEmpresaLocal(){
 actualizarCabeceraEmpresa();
 const esInicio=/\/inicio-cuenta\.html$/i.test(location.pathname);
 if(esInicio)iniciarPaginaInicio();
 if(esInicio&&(new URLSearchParams(location.search).has('seleccionar')||!empresaSeleccionada()))mostrarSelectorEmpresa();
}
// Las zonas marcadas con data-conta tienen su propio comportamiento en contabilidad.js.
document.addEventListener('submit',event=>{if(event.target.closest('[data-conta]'))return;event.preventDefault();notify();});
document.addEventListener('click',event=>{
 if(event.target.closest('[data-conta]'))return;
 const accordion=event.target.closest('.ui-accordion-header[aria-controls]');if(accordion){event.preventDefault();const panel=document.getElementById(accordion.getAttribute('aria-controls'));if(panel){const open=accordion.getAttribute('aria-expanded')!=='true';accordion.setAttribute('aria-expanded',String(open));panel.setAttribute('aria-hidden',String(!open));panel.style.display=open?'block':'none';}return;}
 const a=event.target.closest('a');
 if(a&&(a.dataset.localAction==='js_CmbrEmprSlcnd'||a.closest('#CmbrEmprSlcnd'))){event.preventDefault();window.location.href='inicio-cuenta.html?seleccionar=1';return;}
 if(a?.dataset.offlinePanel){event.preventDefault();const tab=a.closest('[role=tab]'),panel=document.getElementById(a.dataset.offlinePanel);const group=tab?.closest('.ui-tabs');group?.querySelectorAll(':scope > [role=tabpanel]').forEach(p=>{p.style.display=p===panel?'block':'none';p.setAttribute('aria-hidden',String(p!==panel));});group?.querySelectorAll('[role=tab]').forEach(t=>{t.classList.toggle('ui-tabs-active',t===tab);t.setAttribute('aria-selected',String(t===tab));});return;}
 if(a&&a.getAttribute('href')==='#'&&(a.classList.contains('ic-trigger-link')||a.parentElement?.querySelector(':scope > ul'))){event.preventDefault();const menu=a.parentElement.querySelector('ul');if(menu){menu.hidden=!menu.hidden;menu.style.display=menu.hidden?'none':'block';return;}}
 if(a&&a.getAttribute('href')!=='#')return;
 const control=event.target.closest('button,a,input[type=submit]');
 if(control){event.preventDefault();const label=control.textContent.trim();if(/^(Cerrar|Close)(\s*✕)?$/.test(label)){control.closest('.modal,.ui-dialog')?.remove();document.querySelectorAll('.modal-backdrop').forEach(e=>e.remove());document.body.classList.remove('modal-open');return;}notify();}
});
document.addEventListener('input',event=>{if(event.target.type!=='search')return;const section=event.target.closest('.dataTables_wrapper');section?.querySelectorAll('tbody tr').forEach(row=>{row.hidden=!row.textContent.toLowerCase().includes(event.target.value.toLowerCase());});});
function notify(){document.querySelector('.offline-message')?.remove();const e=document.createElement('div');e.className='offline-message';e.setAttribute('role','status');e.textContent='Vista de referencia: esta operación requiere el backend y está desactivada.';document.body.append(e);setTimeout(()=>e.remove(),4000);}
// Botón fijo para reportar un problema o dejar una sugerencia desde cualquier pantalla (views/sugerencias.html).
function agregarBotonSugerencias(){
 if(document.querySelector('.sug-flotante'))return;
 const pagina=location.pathname.split('/').pop()||'';
 const enlace=document.createElement('a');enlace.className='sug-flotante';enlace.textContent='Reportar problema';
 enlace.href='sugerencias.html?'+new URLSearchParams({origen:pagina.replace(/\.html$/,''),desde:pagina});
 document.body.append(enlace);
}
function iniciarPagina(){iniciarEmpresaLocal();agregarBotonSugerencias();}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',iniciarPagina);else iniciarPagina();
