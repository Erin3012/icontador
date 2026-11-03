'use strict';
// Agente de sincronización del RCV con el SII. Revisa Cifrax, descarga los libros pedidos y los devuelve.
//   node src/index.js            -> corre en bucle (cada SII_INTERVALO_SEG segundos)
//   node src/index.js --una-vez  -> revisa una vez y termina
const fs = require('node:fs');
const path = require('node:path');
const { cargarEnv } = require('./env');
const { crearServidor } = require('./api');
const { descargarLibro, consultarEmpresa, explorarSii } = require('./sii');
const { parseRcv } = require('../../assets/rcv-import.js');

/** "76.000.000-0" -> "76000000-0" (la forma en que se busca la clave). */
function normalizarRut(rut) {
  const limpio = String(rut || '').replace(/[^0-9kK]/g, '').toUpperCase();
  return limpio.length < 2 ? '' : `${limpio.slice(0, -1)}-${limpio.slice(-1)}`;
}

function leerClaves(texto) {
  if (!texto) return {};
  let datos;
  try {
    datos = JSON.parse(texto);
  } catch {
    throw new Error('SII_CLAVES no es JSON válido. Revisa sii-agent/.env.');
  }
  const claves = {};
  for (const [rut, clave] of Object.entries(datos)) claves[normalizarRut(rut)] = String(clave);
  return claves;
}

/** Consulta de datos para crear una empresa: usa la clave del RUT y devuelve razón social y giro. */
async function procesarConsultaEmpresa({ servidor, tarea, claves, consultar, log = console.log }) {
  const rut = normalizarRut(tarea.rut);
  const clave = rut && claves[rut];
  if (!clave) {
    const mensaje = rut ? `Falta la clave del SII para el RUT ${rut} en sii-agent/.env (SII_CLAVES).` : 'El RUT pedido no es válido.';
    await servidor.fallar(tarea.id, mensaje).catch(() => {});
    return { ok: false, mensaje };
  }
  try {
    const datos = await consultar({ rut, clave });
    await servidor.resultadoEmpresa(tarea.id, datos);
    log(`Consulta ${tarea.id}: datos de ${rut} enviados.`);
    return { ok: true, datos };
  } catch (e) {
    const mensaje = `No se pudieron traer los datos del SII: ${e.message}`;
    log(`Consulta ${tarea.id}: ${mensaje}`);
    await servidor.fallar(tarea.id, mensaje).catch(() => {});
    return { ok: false, mensaje };
  }
}

/**
 * Ejecuta una tarea: descarga el libro, lo valida con el mismo lector del navegador (assets/rcv-import.js)
 * y lo envía a Cifrax. Los fallos locales se informan a la tarea; los que rechaza el servidor ya quedan registrados allí.
 */
async function procesarTarea({ servidor, tarea, claves, descargar, consultar = consultarEmpresa, carpeta, conservar, log = console.log }) {
  if (tarea.tipo === 'datos_empresa') return procesarConsultaEmpresa({ servidor, tarea, claves, consultar, log });
  const rut = normalizarRut(tarea.rut);
  if (!rut) return servidor.fallar(tarea.id, 'La empresa no tiene RUT en Cifrax.');
  const clave = claves[rut];
  if (!clave) return servidor.fallar(tarea.id, `Falta la clave del SII para el RUT ${rut} en sii-agent/.env (SII_CLAVES).`);

  let archivo = null;
  try {
    archivo = await descargar({ rut, clave, periodo: tarea.periodo, libro: tarea.libro, carpeta });
  } catch (e) {
    const mensaje = `No se pudo descargar el RCV del SII: ${e.message}`;
    log(`Tarea ${tarea.id}: ${mensaje}`);
    await servidor.fallar(tarea.id, mensaje).catch(() => {});
    return { ok: false, mensaje };
  }
  try {
    const parsed = parseRcv(fs.readFileSync(archivo, 'utf8'), path.basename(archivo));
    if (parsed.kind !== tarea.libro) throw new Error(`El archivo descargado es de ${parsed.kind}, no de ${tarea.libro}.`);
    if (parsed.period !== tarea.periodo) throw new Error(`El archivo es del período ${parsed.period || 'desconocido'}, no del ${tarea.periodo}.`);
    if (!parsed.docs.length) throw new Error('El archivo del SII no trae documentos.');
    const datos = { period: parsed.period, kind: parsed.kind, fileName: parsed.fileName, rutEmpresa: parsed.rutEmpresa, docs: parsed.docs };
    try {
      const { guardados } = await servidor.importar(tarea.id, datos);
      log(`Tarea ${tarea.id}: ${guardados} documentos de ${tarea.libro} guardados (${tarea.periodo}).`);
      return { ok: true, guardados };
    } catch (e) {
      // El servidor ya dejó la tarea en error con su motivo (por ejemplo, RUT distinto).
      log(`Tarea ${tarea.id}: el servidor rechazó el archivo: ${e.message}`);
      return { ok: false, mensaje: e.message };
    }
  } catch (e) {
    // Archivo que no es el libro pedido o que no se puede leer: se informa a la tarea.
    log(`Tarea ${tarea.id}: ${e.message}`);
    await servidor.fallar(tarea.id, e.message).catch(() => {});
    return { ok: false, mensaje: e.message };
  } finally {
    if (!conservar) fs.rmSync(archivo, { force: true });
  }
}

function configurar(env) {
  const url = env.SII_URL_SERVIDOR;
  const token = env.SII_TOKEN_AGENTE;
  if (!url || !token) throw new Error('Faltan SII_URL_SERVIDOR y SII_TOKEN_AGENTE en sii-agent/.env.');
  return {
    url,
    token,
    claves: leerClaves(env.SII_CLAVES),
    headless: String(env.SII_HEADLESS ?? 'true').toLowerCase() !== 'false',
    intervaloMs: Math.max(15, Number(env.SII_INTERVALO_SEG || 60)) * 1000,
    carpeta: path.resolve(__dirname, '..', env.SII_CARPETA || 'descargas'),
    conservar: String(env.SII_CONSERVAR ?? 'false').toLowerCase() === 'true',
  };
}

/** Una vuelta: reclama las tareas pendientes y las procesa una por una. */
async function revisar({ servidor, config, descargar = descargarLibro, consultar = consultarEmpresa, log }) {
  const tareas = await servidor.pendientes();
  for (const tarea of tareas) {
    await procesarTarea({
      servidor, tarea, claves: config.claves, carpeta: config.carpeta, conservar: config.conservar, log,
      descargar: (opciones) => descargar({ ...opciones, headless: config.headless }),
      consultar: (opciones) => consultar({ ...opciones, headless: config.headless }),
    });
  }
  return tareas.length;
}

async function main() {
  const env = { ...cargarEnv(path.resolve(__dirname, '..', '.env')), ...process.env };
  const log = (texto) => console.log(`[${new Date().toISOString()}] ${texto}`);
  if (process.argv.includes('--explorar')) {
    // Modo exploración: no necesita el servidor. Usa la primera clave de SII_CLAVES, o el RUT que se pase después de --explorar.
    const claves = leerClaves(env.SII_CLAVES);
    const idx = process.argv.indexOf('--explorar');
    const pedido = process.argv[idx + 1] && !process.argv[idx + 1].startsWith('--') ? normalizarRut(process.argv[idx + 1]) : '';
    const rut = pedido || Object.keys(claves)[0];
    if (!rut || !claves[rut]) throw new Error('Falta la clave del RUT en SII_CLAVES (sii-agent/.env).');
    const headless = String(env.SII_HEADLESS ?? 'true').toLowerCase() !== 'false';
    const destino = await explorarSii({ rut, clave: claves[rut], carpeta: path.resolve(__dirname, '..', env.SII_CARPETA || 'descargas'), headless, esperar: process.argv.includes('--esperar') });
    log(`Exploración guardada en ${destino}. Este archivo queda solo en tu PC.`);
    return;
  }
  if (process.argv.includes('--descargar')) {
    // Prueba de descarga sin Cifrax: node src/index.js --descargar AAAA-MM compras|ventas [RUT]
    const idx = process.argv.indexOf('--descargar');
    const [periodo, libro, pedidoRut] = process.argv.slice(idx + 1);
    if (!/^20\d{2}-(0[1-9]|1[0-2])$/.test(periodo || '') || !['compras', 'ventas'].includes(libro)) {
      throw new Error('Uso: npm run descargar -- AAAA-MM compras|ventas [RUT]');
    }
    const claves = leerClaves(env.SII_CLAVES);
    const rut = pedidoRut ? normalizarRut(pedidoRut) : Object.keys(claves)[0];
    if (!rut || !claves[rut]) throw new Error('Falta la clave del RUT en SII_CLAVES (sii-agent/.env).');
    const headless = String(env.SII_HEADLESS ?? 'true').toLowerCase() !== 'false';
    const carpeta = path.resolve(__dirname, '..', env.SII_CARPETA || 'descargas');
    const archivo = await descargarLibro({ rut, clave: claves[rut], periodo, libro, carpeta, headless });
    const parsed = parseRcv(fs.readFileSync(archivo, 'utf8'), path.basename(archivo));
    log(`Descarga OK: ${parsed.docs.length} documentos de ${parsed.kind} (${parsed.period}). Archivo: ${archivo}`);
    return;
  }
  const config = configurar(env);
  const servidor = crearServidor({ url: config.url, token: config.token });
  if (process.argv.includes('--una-vez')) {
    const n = await revisar({ servidor, config, log });
    log(n ? `${n} tarea(s) revisadas.` : 'No hay tareas pendientes.');
    return;
  }
  log(`Agente SII activo. Revisando ${config.url} cada ${config.intervaloMs / 1000} s.`);
  for (;;) {
    try {
      await revisar({ servidor, config, log });
    } catch (e) {
      log(`No se pudo revisar Cifrax: ${e.message}`);
    }
    await new Promise((r) => setTimeout(r, config.intervaloMs));
  }
}

module.exports = { procesarTarea, normalizarRut, leerClaves, configurar, revisar, main };

if (require.main === module) {
  main().catch((e) => {
    console.error(e.message);
    process.exit(1);
  });
}
