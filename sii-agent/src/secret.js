'use strict';

/** Lee la Clave Tributaria desde un terminal interactivo sin mostrarla en pantalla. */
function solicitarClaveSii(rut, { input = process.stdin, output = process.stdout } = {}) {
  if (!input.isTTY || typeof input.setRawMode !== 'function') {
    throw new Error('Para ingresar la Clave Tributaria, ejecuta el agente desde una terminal interactiva.');
  }

  return new Promise((resolve, reject) => {
    let clave = '';
    const limpiar = () => {
      input.off('data', recibir);
      try { input.setRawMode(false); } catch {}
      input.pause();
    };
    const recibir = (buffer) => {
      for (const caracter of buffer.toString('utf8')) {
        if (caracter === '\u0003') {
          limpiar();
          output.write('\n');
          clave = '';
          reject(new Error('Ingreso de clave cancelado.'));
          return;
        }
        if (caracter === '\r' || caracter === '\n') {
          limpiar();
          output.write('\n');
          if (!clave) reject(new Error('La Clave Tributaria no puede estar vacía.'));
          else resolve(clave);
          clave = '';
          return;
        }
        if (caracter === '\u007f' || caracter === '\b') {
          clave = Array.from(clave).slice(0, -1).join('');
        } else if (caracter >= ' ') {
          clave += caracter;
        }
      }
    };

    output.write(`Clave Tributaria SII para ${rut} (no se mostrará): `);
    input.setRawMode(true);
    input.resume();
    input.on('data', recibir);
  });
}

module.exports = { solicitarClaveSii };
