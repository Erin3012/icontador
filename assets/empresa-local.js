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

// Solo ayudas de la empresa elegida (localStorage). Antes vivían en offline.js, que bloquea formularios y clics.
