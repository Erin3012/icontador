'use strict';

// Prueba manual local del flujo SII. No usa el agente central ni importa datos.
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const readline = require('node:readline');
const { descargarLibro } = require('../src/sii');

function preguntar(texto) {
  const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
  return new Promise((resolve) => rl.question(texto, (respuesta) => {
    rl.close();
    resolve(respuesta.trim());
  }));
}

function preguntarSecreto(texto) {
  if (!process.stdin.isTTY || typeof process.stdin.setRawMode !== 'function') {
    throw new Error('Ejecuta esta prueba desde una terminal interactiva de WSL para ocultar la clave.');
  }
  return new Promise((resolve, reject) => {
    const entrada = process.stdin;
    const salida = process.stdout;
    let valor = '';
    salida.write(texto);
    entrada.setRawMode(true);
    entrada.resume();
    const limpiar = () => {
      entrada.removeListener('data', recibir);
      entrada.setRawMode(false);
      entrada.pause();
      salida.write('\n');
    };
    const recibir = (datos) => {
      for (const byte of datos) {
        if (byte === 3) {
          limpiar();
          reject(new Error('Prueba interrumpida.'));
          return;
        }
        if (byte === 13 || byte === 10) {
          limpiar();
          resolve(valor);
          return;
        }
        if (byte === 127 || byte === 8) valor = valor.slice(0, -1);
        else if (byte >= 32) valor += String.fromCharCode(byte);
      }
    };
    entrada.on('data', recibir);
  });
}

async function main() {
  if (process.platform !== 'linux') throw new Error('Esta prueba está pensada para ejecutarse dentro de WSL/Linux.');
  const rut = await preguntar('RUT del contribuyente: ');
  const periodo = await preguntar('Período (AAAA-MM, por ejemplo 2026-10): ');
  if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(periodo)) throw new Error('Período inválido; usa el formato AAAA-MM.');
  const libroIngresado = (await preguntar('Libro a consultar (compras/ventas, defecto compras): ')).toLowerCase();
  const libro = libroIngresado || 'compras';
  if (!['compras', 'ventas'].includes(libro)) throw new Error('Libro inválido; usa compras o ventas.');
  let clave = await preguntarSecreto('Clave Tributaria (entrada oculta): ');
  if (!rut || !clave) throw new Error('RUT y clave son obligatorios.');

  const carpeta = path.resolve(process.env.SII_DIAGNOSTICO_DIR || path.join(os.homedir(), 'icontador-sii-diagnostico'));
  fs.mkdirSync(carpeta, { recursive: true, mode: 0o700 });
  const rutaLog = path.join(carpeta, `prueba-${libro}-${periodo}.log`);
  const logAnterior = console.log;
  const errorAnterior = console.error;
  const registrar = (salida, args) => {
    const mensaje = args.map((x) => x instanceof Error ? x.message : String(x)).join(' ')
      .split(clave).join('[CLAVE OCULTA]');
    try { fs.appendFileSync(rutaLog, `${new Date().toISOString()} ${mensaje}\n`, { mode: 0o600 }); } catch {}
    salida(...args);
  };
  console.log = (...args) => registrar(logAnterior, args);
  console.error = (...args) => registrar(errorAnterior, args);
  console.log(`Diagnóstico local: ${carpeta}`);
  console.log(`Registro de pasos: ${rutaLog}`);
  console.log(`Chromium se abrirá visible. La prueba consultará solo RCV de ${libro} y no importará datos.`);
  try {
    const archivo = await descargarLibro({
      rut, clave, periodo, libro, carpeta, headless: false,
      onProgress: (etapa) => console.log(`[flujo] ${etapa}`),
      onFailure: async (jpeg, page) => {
        const captura = path.join(carpeta, `fallo-${libro}-${periodo}-${Date.now()}.jpg`);
        fs.writeFileSync(captura, jpeg, { mode: 0o600 });
        console.log(`Captura de la pantalla RCV al fallar: ${captura}`);
        const capas = await page.evaluate(() => [...document.querySelectorAll('.modal-backdrop, .modal, [role="dialog"]')]
          .map((el) => {
            const estilo = getComputedStyle(el);
            return {
              tag: el.tagName, id: el.id, clase: String(el.className || ''),
              visible: estilo.display !== 'none' && estilo.visibility !== 'hidden' && Number(estilo.opacity || 1) > 0,
              ariaHidden: el.getAttribute('aria-hidden'),
              texto: String(el.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 240),
            };
          })).catch(() => []);
        console.log(`Capas y diálogos en pantalla: ${JSON.stringify(capas)}`);
      },
    });
    if (archivo?.sinDocumentos) {
      console.log(`RESULTADO: el SII confirma que el período ${periodo} no tiene documentos de ${libro}.`);
    } else {
      try { fs.chmodSync(archivo, 0o600); } catch {}
      console.log(`Descarga de ${libro} completada: ${archivo}`);
      console.log('El CSV quedó solo en este equipo. Revísalo y elimina la carpeta de diagnóstico cuando termines.');
    }
  } catch (error) {
    console.error(`ERROR: ${error.message}`);
    throw error;
  } finally {
    // Eliminar la referencia en memoria cuanto antes; nunca se escribe en archivos ni argumentos.
    clave = '';
    console.log = logAnterior;
    console.error = errorAnterior;
  }
}

main().catch((error) => {
  console.error(`Prueba visible detenida: ${error.message}`);
  process.exitCode = 1;
});
