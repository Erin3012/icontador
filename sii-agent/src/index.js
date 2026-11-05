'use strict';
// Agente de sincronización del RCV con el SII. Revisa Cifrax, descarga los libros pedidos y los devuelve.
//   node src/index.js            -> corre en bucle (cada SII_INTERVALO_SEG segundos)
//   node src/index.js --una-vez  -> revisa una vez y termina
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { cargarEnv, actualizarEnv } = require('./env');
const { crearServidor, emparejarAgente } = require('./api');
const { descargarLibro, consultarEmpresa } = require('./sii');
const { solicitarClaveSii } = require('./secret');
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
async function procesarConsultaEmpresa({ servidor, tarea, claves, solicitarClave = solicitarClaveSii, consultar = consultarEmpresa, log = console.log }) {
  const rut = normalizarRut(tarea.rut);
  if (!rut) {
    const mensaje = 'El RUT pedido no es válido.';
    await servidor.fallar(tarea.id, mensaje).catch(() => {});
    return { ok: false, mensaje };
  }
  if (consultar.canalSiiHabilitado === false) {
    const mensaje = 'La consulta automática de empresa está deshabilitada hasta validar un canal autorizado por el SII.';
    await servidor.fallar(tarea.id, mensaje).catch(() => {});
    return { ok: false, mensaje };
  }
  let clave = '';
  try {
    clave = claves !== undefined ? String(claves[rut] || '') : await solicitarClave(rut);
    if (!clave) throw new Error('No se ingresó la Clave Tributaria.');
    const datos = await consultar({ rut, clave });
    await servidor.resultadoEmpresa(tarea.id, datos);
    log(`Consulta ${tarea.id}: datos de ${rut} enviados.`);
    return { ok: true, datos };
  } catch (e) {
    const detalle = String(e?.message || 'Error desconocido.');
    const mensaje = `No se pudieron traer los datos del SII: ${clave ? detalle.split(clave).join('[oculta]') : detalle}`;
    log(`Consulta ${tarea.id}: ${mensaje}`);
    await servidor.fallar(tarea.id, mensaje).catch(() => {});
    return { ok: false, mensaje };
  } finally {
    clave = '';
  }
}

/**
 * Ejecuta una tarea: descarga el libro, lo valida con el mismo lector del navegador (assets/rcv-import.js)
 * y lo envía a Cifrax. Los fallos locales se informan a la tarea; los que rechaza el servidor ya quedan registrados allí.
 */
async function procesarTarea({ servidor, tarea, claves, solicitarClave = solicitarClaveSii, descargar, consultar = consultarEmpresa, carpeta, conservar, log = console.log }) {
  if (tarea.tipo === 'datos_empresa') return procesarConsultaEmpresa({ servidor, tarea, claves, solicitarClave, consultar, log });
  const rut = normalizarRut(tarea.rut);
  if (!rut) return servidor.fallar(tarea.id, 'La empresa no tiene RUT en Cifrax.');
  if (descargar.canalSiiHabilitado === false) {
    return servidor.fallar(tarea.id, 'La descarga automática del RCV está deshabilitada hasta validar un canal autorizado por el SII.');
  }

  let archivo = null;
  let clave = '';
  try {
    clave = claves !== undefined ? String(claves[rut] || '') : await solicitarClave(rut);
    if (!clave) throw new Error('No se ingresó la Clave Tributaria.');
    archivo = await descargar({ rut, clave, periodo: tarea.periodo, libro: tarea.libro, carpeta });
    clave = '';
  } catch (e) {
    const detalle = String(e?.message || 'Error desconocido.');
    const mensaje = `No se pudo descargar el RCV del SII: ${clave ? detalle.split(clave).join('[oculta]') : detalle}`;
    clave = '';
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
  if (env.SII_CLAVES) throw new Error('Elimina SII_CLAVES de sii-agent/.env; el agente ahora pide la clave en la terminal sin guardarla.');
  return {
    url,
    token,
    headless: String(env.SII_HEADLESS ?? 'true').toLowerCase() !== 'false',
    intervaloMs: Math.max(15, Number(env.SII_INTERVALO_SEG || 60)) * 1000,
    carpeta: path.resolve(__dirname, '..', env.SII_CARPETA || 'descargas'),
    conservar: String(env.SII_CONSERVAR ?? 'false').toLowerCase() === 'true',
  };
}

/** Una vuelta: reclama las tareas pendientes y las procesa una por una. */
async function revisar({ servidor, config, descargar = descargarLibro, consultar = consultarEmpresa, solicitarClave = solicitarClaveSii, log }) {
  if (descargar.canalSiiHabilitado === false && consultar.canalSiiHabilitado === false) {
    log('El agente no consulta el SII hasta configurar el canal autorizado.');
    return 0;
  }
  const tareas = await servidor.pendientes();
  const conHeadless = (fn) => {
    const envuelta = (opciones) => fn({ ...opciones, headless: config.headless });
    if (fn.canalSiiHabilitado === false) envuelta.canalSiiHabilitado = false;
    return envuelta;
  };
  const clavesDeEstaRonda = new Map();
  const solicitarClaveUnaVez = async (rut) => {
    const clave = normalizarRut(rut);
    if (!clavesDeEstaRonda.has(clave)) clavesDeEstaRonda.set(clave, await solicitarClave(rut));
    return clavesDeEstaRonda.get(clave);
  };
  try {
    for (const tarea of tareas) {
      await procesarTarea({
        servidor, tarea, solicitarClave: solicitarClaveUnaVez, carpeta: config.carpeta, conservar: config.conservar, log,
        descargar: conHeadless(descargar),
        consultar: conHeadless(consultar),
      });
    }
  } finally {
    clavesDeEstaRonda.clear();
  }
  return tareas.length;
}

async function main() {
  const env = { ...cargarEnv(path.resolve(__dirname, '..', '.env')), ...process.env };
  if (env.SII_CANAL) process.env.SII_CANAL = env.SII_CANAL;
  const log = (texto) => console.log(`[${new Date().toISOString()}] ${texto}`);
  const ruta = path.resolve(__dirname, '..', '.env');
  if (!env.SII_TOKEN_AGENTE && env.SII_CODIGO_EMPAREJAMIENTO) {
    if (!env.SII_URL_SERVIDOR) throw new Error('Falta SII_URL_SERVIDOR en sii-agent/.env.');
    env.SII_TOKEN_AGENTE = await emparejarAgente({
      url: env.SII_URL_SERVIDOR,
      codigo: env.SII_CODIGO_EMPAREJAMIENTO,
      nombre: os.hostname(),
      ruta,
      actualizarEnv,
    });
    delete env.SII_CODIGO_EMPAREJAMIENTO;
    log('PC emparejado con Cifrax. El código de un solo uso se eliminó.');
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

module.exports = { procesarTarea, normalizarRut, leerClaves, configurar, revisar };

if (require.main === module) {
  main().catch((e) => {
    console.error(e.message);
    process.exit(1);
  });
}
