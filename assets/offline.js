'use strict';
const EMPRESA_LOCAL_KEY='icontador.empresaSeleccionada';
const EMPRESA_LOCAL_STATE_KEY='icontador.empresas.local';

function estadoEmpresasLocal(){
 try{return JSON.parse(localStorage.getItem(EMPRESA_LOCAL_STATE_KEY)||'{}');}catch{return {};}
}
function guardarEstadoEmpresasLocal(estado){localStorage.setItem(EMPRESA_LOCAL_STATE_KEY,JSON.stringify(estado));}

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
 const estado=estadoEmpresasLocal();
 const filtro=document.createElement('select');filtro.className='empresa-local-filtro';filtro.setAttribute('aria-label','Filtrar empresas');
 [['activas','Empresas activas'],['inactivas','Empresas desactivadas localmente'],['todas','Todas las empresas']].forEach(([v,t])=>{const o=document.createElement('option');o.value=v;o.textContent=t;filtro.append(o);});
 const renderLista=()=>{
  lista.replaceChildren();
  const visibles=empresas.filter(e=>{const inactiva=Boolean(estado[e.id]?.inactiva);return filtro.value==='todas'||(filtro.value==='inactivas'?inactiva:!inactiva);});
  visibles.forEach(empresa=>{
   const ficha=estado[empresa.id]||{};const fila=document.createElement('article');fila.className='empresa-local-fila'+(ficha.inactiva?' es-inactiva':'');
   const elegir=document.createElement('button');elegir.type='button';elegir.className='empresa-local-opcion';
   const nombre=document.createElement('strong');nombre.textContent=ficha.nombre||empresa.razon_social;
   const detalle=document.createElement('span');detalle.textContent=empresa.vistas+' vistas · '+empresa.registros+' registros importados'+(ficha.inactiva?' · Desactivada en este navegador':'');
   elegir.append(nombre,detalle);elegir.disabled=Boolean(ficha.inactiva);elegir.setAttribute('aria-label','Seleccionar '+nombre.textContent);elegir.addEventListener('click',()=>{
    localStorage.setItem(EMPRESA_LOCAL_KEY,JSON.stringify({...empresa,razon_social:nombre.textContent}));
    window.location.href='panel.html';
   });
   const acciones=document.createElement('div');acciones.className='empresa-local-acciones';
   const ver=crearBotonEmpresa('Ver','fa-eye',()=>mostrarDetalleEmpresa(empresa,ficha));
   const editar=crearBotonEmpresa('Editar','fa-pencil',()=>editarEmpresaLocal(empresa,ficha,()=>{renderLista();}));
   const activar=crearBotonEmpresa(ficha.inactiva?'Activar':'Desactivar',ficha.inactiva?'fa-check':'fa-ban',()=>{
    const nuevo=estadoEmpresasLocal();nuevo[empresa.id]={...nuevo[empresa.id],inactiva:!ficha.inactiva};guardarEstadoEmpresasLocal(nuevo);Object.assign(estado,nuevo);renderLista();
   });
   acciones.append(ver,editar,activar);fila.append(elegir,acciones);lista.append(fila);
  });
  if(!visibles.length){const vacio=document.createElement('p');vacio.className='empresa-local-vacio';vacio.textContent=empresas.length?'No hay empresas en este filtro.':'No hay empresas disponibles en la base local.';lista.append(vacio);}
 };
 filtro.value='activas';filtro.addEventListener('change',renderLista);renderLista();
 const cerrar=document.createElement('button');cerrar.type='button';cerrar.className='empresa-local-cerrar';cerrar.textContent='Cerrar';cerrar.addEventListener('click',()=>overlay.remove());
 dialog.append(titulo,ayuda,filtro,lista,cerrar);overlay.append(dialog);document.body.append(overlay);
 dialog.querySelector('.empresa-local-opcion:not(:disabled)')?.focus();
}
function crearBotonEmpresa(etiqueta,icono,accion){
 const boton=document.createElement('button');boton.type='button';boton.className='empresa-local-accion';boton.title=etiqueta;boton.setAttribute('aria-label',etiqueta);
 const i=document.createElement('i');i.className='fa '+icono;i.setAttribute('aria-hidden','true');const texto=document.createElement('span');texto.textContent=etiqueta;boton.append(i,texto);boton.addEventListener('click',accion);return boton;
}
function mostrarDetalleEmpresa(empresa,ficha){
 const modal=crearDialogoEmpresa('Ver empresa');
 const datos=[['Razón social',ficha.nombre||empresa.razon_social],['Vistas importadas',String(empresa.vistas)],['Registros importados',String(empresa.registros)],['Estado local',ficha.inactiva?'Desactivada':'Activa']];
 datos.forEach(([label,valor])=>{const p=document.createElement('p');p.className='empresa-local-dato';const b=document.createElement('strong');b.textContent=label+': ';p.append(b,document.createTextNode(valor));modal.contenido.append(p);});
 modal.abrir();
}
function editarEmpresaLocal(empresa,ficha,alGuardar){
 const modal=crearDialogoEmpresa('Editar empresa');const label=document.createElement('label');label.textContent='Nombre visible en esta copia local';
 const input=document.createElement('input');input.type='text';input.maxLength=191;input.required=true;input.value=ficha.nombre||empresa.razon_social;input.className='empresa-local-campo';label.append(input);
 const nota=document.createElement('p');nota.className='empresa-local-nota';nota.textContent='Este cambio solo modifica el nombre mostrado en este navegador; no altera los datos contables ni la base importada.';
 const guardar=document.createElement('button');guardar.type='button';guardar.className='empresa-local-cerrar';guardar.textContent='Guardar nombre local';guardar.addEventListener('click',()=>{
  const nombre=input.value.trim();if(!nombre){input.focus();return;}const estado=estadoEmpresasLocal();estado[empresa.id]={...estado[empresa.id],nombre};guardarEstadoEmpresasLocal(estado);modal.cerrar();alGuardar();
 });
 modal.contenido.append(label,nota,guardar);modal.abrir();input.focus();input.select?.();
}
function crearDialogoEmpresa(titulo){
 const overlay=document.createElement('div');overlay.className='empresa-local-modal';overlay.setAttribute('role','presentation');const caja=document.createElement('section');caja.className='empresa-local-modal-caja';caja.setAttribute('role','dialog');caja.setAttribute('aria-modal','true');
 const encabezado=document.createElement('div');encabezado.className='empresa-local-modal-encabezado';const h=document.createElement('h3');h.textContent=titulo;const x=document.createElement('button');x.type='button';x.className='empresa-local-modal-x';x.setAttribute('aria-label','Cerrar');x.textContent='×';encabezado.append(h,x);
 const contenido=document.createElement('div');contenido.className='empresa-local-modal-contenido';const cerrar=()=>overlay.remove();x.addEventListener('click',cerrar);overlay.addEventListener('click',e=>{if(e.target===overlay)cerrar();});caja.append(encabezado,contenido);overlay.append(caja);
 return{contenido,abrir(){document.querySelector('.empresa-local-modal')?.remove();document.body.append(overlay);x.focus();},cerrar};
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
// Logo de Cifrax al inicio de la barra superior de cada pantalla.
function agregarMarca(){
 const barra=document.querySelector(".navbar-inverse .navbar-header");
 if(!barra||document.querySelector(".cifrax-marca"))return;
 const enlace=document.createElement("a");enlace.className="cifrax-marca";enlace.href="inicio-cuenta.html";enlace.setAttribute("aria-label","Cifrax, inicio");
 const logo=document.createElement("img");logo.src="../assets/cifrax-logo-blanco.png";logo.alt="Cifrax";
 enlace.append(logo);barra.prepend(enlace);
}
// Botón Cambiar Empresa: algunas pantallas (Empresas) lo traen oculto; siempre se muestra en la barra.
function mostrarCambiarEmpresa(){const li=document.getElementById('CmbrEmprSlcnd');if(li&&li.style.display==='none')li.style.display='';}
// Campos de fecha: el calendario del original (jQuery UI) no viene en la copia; se usa el calendario del navegador.
function activarCalendarios(){
 document.querySelectorAll('input.hasDatepicker').forEach(input=>{
  const m=/^(d{2})[-/](d{2})[-/](d{4})$/.exec(input.value.trim());
  input.type='date';input.removeAttribute('maxlength');input.classList.remove('hasDatepicker');
  if(m)input.value=`${m[3]}-${m[2]}-${m[1]}`;
 });
}
// Botón Volver: regresa a la pantalla anterior de la app; si se llegó desde fuera (o se abrió directo), va al inicio.
function vieneDeLaApp(){
 try{return !!document.referrer&&new URL(document.referrer).origin===location.origin&&history.length>1;}catch{return false;}
}
function agregarBotonVolver(){
 if(document.querySelector('.cifrax-volver'))return;
 const esInicio=/\/inicio-cuenta\.html$/i.test(location.pathname);
 if(esInicio&&!vieneDeLaApp())return;
 const enlace=document.createElement('a');enlace.className='cifrax-volver';enlace.href='inicio-cuenta.html';enlace.title='Volver a la pantalla anterior';
 const icono=document.createElement('span');icono.className='glyphicon glyphicon-arrow-left';icono.setAttribute('aria-hidden','true');
 enlace.append(icono,document.createTextNode(' Volver'));
 enlace.addEventListener('click',event=>{if(!vieneDeLaApp())return;event.preventDefault();history.back();});
 const barra=document.querySelector('.navbar-inverse .navbar-header');
 if(barra){const marca=barra.querySelector('.cifrax-marca');marca?marca.after(enlace):barra.prepend(enlace);}
 else{const aviso=document.querySelector('.offline-banner');if(aviso){enlace.classList.add('cifrax-volver-aviso');aviso.prepend(enlace);}else{enlace.classList.add('cifrax-volver-flotante');document.body.append(enlace);}}
}
function iniciarPagina(){agregarMarca();mostrarCambiarEmpresa();activarCalendarios();agregarBotonVolver();iniciarEmpresaLocal();agregarBotonSugerencias();}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',iniciarPagina);else iniciarPagina();
