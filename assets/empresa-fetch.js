'use strict';
// Vistas independientes (Tesorería, CRM, Activos Fijos, etc.) que usan fetch() directo:
// añade la cabecera X-Empresa-Id a las llamadas a /api/*.php del mismo origen, como hace cabeceraEmpresa() en las demás pantallas.
(function(){
 const original=window.fetch;
 function empresaId(){
  try{const empresa=JSON.parse(localStorage.getItem('icontador.empresaSeleccionada')||'null');return empresa&&empresa.id?String(empresa.id):'';}
  catch{return '';}
 }
 window.fetch=function(entrada,opciones){
  const id=empresaId();if(!id)return original.apply(this,arguments);
  let destino;
  // fetch() acepta una cadena, un objeto URL (propiedad href) o un Request (propiedad url).
  try{destino=new URL(typeof entrada==='string'?entrada:(entrada&&(entrada.href||entrada.url))||'',location.href);}
  catch{return original.apply(this,arguments);}
  if(destino.origin!==location.origin||!/\/api\/[\w-]+\.php$/i.test(destino.pathname))return original.apply(this,arguments);
  const cabeceras=new Headers((opciones&&opciones.headers)||(entrada&&entrada.headers)||undefined);
  if(!cabeceras.has('X-Empresa-Id'))cabeceras.set('X-Empresa-Id',id);
  return original.call(this,entrada,{...(opciones||{}),headers:cabeceras});
 };
})();
