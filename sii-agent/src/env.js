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

module.exports = { cargarEnv };
