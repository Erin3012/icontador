'use strict';
// Enlaces del SII documentados para consulta manual. No se llaman rutas privadas desde el agente.
const SII = {
  inicio: 'https://homer.sii.cl/',
  rcv: 'https://www4.sii.cl/consdcvinternetui/#/index',
  consultaTerceros: 'https://www.sii.cl/como_se_hace_para/situacion_trib_terceros.html',
};

// El titular informó que el SII autorizó un canal técnico; falta incorporar su especificación.
// Hasta entonces las rutas automáticas permanecen bloqueadas y no se automatiza la interfaz web.
const CANALES_AUTOMATICOS_HABILITADOS = false;

function periodoSii(periodo) {
  const [anio, mes] = String(periodo).split('-');
  return `${mes}-${anio}`;
}

/** RUT como lo acepta el formulario del SII: dígitos y DV, sin puntos ni guion. */
function rutParaFormulario(rut) {
  return String(rut).replace(/[^0-9kK]/g, '').toUpperCase();
}

function requiereCanalAutorizado(tipo) {
  const detalle = tipo === 'empresa'
    ? 'La consulta automática de empresa espera la especificación del canal autorizado del SII.'
    : 'La descarga automática del RCV espera la especificación del canal autorizado del SII.';
  throw new Error(`${detalle} La descarga manual de CSV sigue disponible desde el RCV.`);
}

async function descargarLibro() {
  return requiereCanalAutorizado('rcv');
}
descargarLibro.canalSiiHabilitado = CANALES_AUTOMATICOS_HABILITADOS;

async function consultarEmpresa() {
  return requiereCanalAutorizado('empresa');
}
consultarEmpresa.canalSiiHabilitado = CANALES_AUTOMATICOS_HABILITADOS;

module.exports = { descargarLibro, consultarEmpresa, periodoSii, rutParaFormulario, SII, CANALES_AUTOMATICOS_HABILITADOS };
