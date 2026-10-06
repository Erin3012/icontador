'use strict';
// Página de inicio: muestra la cuenta con sesión, la empresa elegida y los accesos que correspondan.
const EMPRESA_LOCAL_KEY='icontador.empresaSeleccionada';

function empresaSeleccionada(){
 try{return JSON.parse(localStorage.getItem(EMPRESA_LOCAL_KEY)||'null');}catch{return null;}
}
function mostrarEmpresa(){
 const empresa=empresaSeleccionada();
 const nombre=document.getElementById('empresa-nombre'),boton=document.getElementById('empresa-cambiar');
 if(empresa?.razon_social){nombre.textContent=empresa.razon_social;boton.textContent='Cambiar empresa';}
 else document.querySelector('.empresa').classList.add('sin-empresa');
}
async function mostrarUsuario(){
 try{
  const respuesta=await fetch('api/usuario.php',{headers:{Accept:'application/json'}});
  if(respuesta.status===401){location.href='auth/login.php?volver=%2Findex.html';return;}
  if(!respuesta.ok)return;
  const usuario=await respuesta.json();
  const primerNombre=String(usuario.nombre||'').trim().split(/\s+/)[0];
  if(primerNombre)document.getElementById('saludo').textContent='Hola, '+primerNombre;
  document.getElementById('usuario-nombre').textContent=usuario.nombre||usuario.email||'';
  if(usuario.admin)for(const id of ['enlace-usuarios','tarjeta-usuarios'])document.getElementById(id).hidden=false;
 }catch{}
}
// El módulo de sugerencias puede no estar instalado todavía: solo se muestra si la pantalla existe.
async function mostrarSugerencias(){
 try{const respuesta=await fetch('views/sugerencias.html',{method:'HEAD'});if(respuesta.ok)document.getElementById('tarjeta-sugerencias').hidden=false;}catch{}
}
mostrarEmpresa();mostrarUsuario();mostrarSugerencias();
