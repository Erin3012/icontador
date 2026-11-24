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

async function actualizarCabeceraUsuario(){
 const enlaces=[...document.querySelectorAll('a.link_blanco')].filter(enlace=>/USUARIO TITULAR:/i.test(enlace.textContent));
 if(!enlaces.length)return;
 enlaces.forEach(enlace=>enlace.textContent='USUARIO TITULAR: cargando…');
 try{
  const [respuestaUsuario,respuestaEmpresas]=await Promise.all([
   fetch('../api/usuario.php',{headers:{Accept:'application/json'},credentials:'same-origin',cache:'no-store'}),
   fetch('../api/empresas.php?vista=todas',{headers:{Accept:'application/json'},credentials:'same-origin',cache:'no-store'}).catch(()=>null)
  ]);
  if(!respuestaUsuario.ok)throw new Error('sesion');
  const usuario=await respuestaUsuario.json();let razon='';
  if(respuestaEmpresas?.ok){const datos=await respuestaEmpresas.json(),seleccionada=empresaSeleccionada();const empresa=(datos.empresas||[]).find(e=>String(e.id)===String(seleccionada?.id));if(empresa&&!/empresa\s+demo/i.test(String(empresa.razon_social||'')))razon=String(empresa.razon_social||'').trim();}
  let identidad=String(usuario.nombre||'').trim();if(!identidad||/^usuario\s+demo$/i.test(identidad))identidad=String(usuario.email||'Usuario').trim();
  const texto='USUARIO TITULAR: '+identidad.toLocaleUpperCase('es-CL')+(razon?' · '+razon.toLocaleUpperCase('es-CL'):'');
  enlaces.forEach(enlace=>enlace.textContent=texto);
 }catch{
  enlaces.forEach(enlace=>enlace.textContent='SESIÓN NO DISPONIBLE');
 }
}

if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',actualizarCabeceraUsuario,{once:true});
else actualizarCabeceraUsuario();

// Solo ayudas de la empresa elegida (localStorage). Antes vivían en offline.js, que bloquea formularios y clics.
