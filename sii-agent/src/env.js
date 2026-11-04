'use strict';
// Lee un archivo .env sencillo (CLAVE=valor, una por línea). No reemplaza variables que ya existen en el entorno.
const fs = require('node:fs');

function cargarEnv(ruta) {
  const valores = {};
  if (!fs.existsSync(ruta)) return valores;
  for (const linea of fs.readFileSync(ruta, 'utf8').split(/\r?\n/)) {
    if (/^\s*(#|$)/.test(linea)) continue;
    const m = /^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$/.exec(linea);
    if (!m) continue;
    let valor = m[2];
    if ((valor.startsWith('"') && valor.endsWith('"')) || (valor.startsWith("'") && valor.endsWith("'"))) valor = valor.slice(1, -1);
    valores[m[1]] = valor;
  }
  return valores;
}

/** Cambia claves de un .env sin tocar las demás: un valor reemplaza o agrega, null la quita. */
function actualizarEnv(ruta, cambios) {
  const lineas = fs.existsSync(ruta) ? fs.readFileSync(ruta, 'utf8').split(/\r?\n/) : [];
  const pendientes = { ...cambios };
  const nuevas = [];
  for (const linea of lineas) {
    const m = /^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=/.exec(linea);
    if (!m || !Object.prototype.hasOwnProperty.call(pendientes, m[1])) {
      nuevas.push(linea);
      continue;
    }
    const valor = pendientes[m[1]];
    delete pendientes[m[1]];
    if (valor !== null) nuevas.push(`${m[1]}=${valor}`);
  }
  for (const [clave, valor] of Object.entries(pendientes)) {
    if (valor !== null) nuevas.push(`${clave}=${valor}`);
  }
  while (nuevas.length && nuevas[nuevas.length - 1] === '') nuevas.pop();
  fs.writeFileSync(ruta, nuevas.join('\n') + '\n', { mode: 0o600 });
}

module.exports = { cargarEnv, actualizarEnv };
