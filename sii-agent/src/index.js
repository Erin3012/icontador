'use strict';
// Agente central de sincronización RCV con qlc.cl. Reclama, descarga e importa una tarea por vez.
//   node src/index.js            -> corre en bucle (cada SII_INTERVALO_SEG segundos)
//   node src/index.js --una-vez  -> revisa una vez y termina
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { randomUUID } = require('node:crypto');
const { cargarEnv, actualizarEnv } = require('./env');
const { crearServidor, emparejarAgente } = require('./api');
const { descargarLibro, descargarBoletasHonorarios, consultarEmpresa, crearNavegadorAgente, cerrarNavegadorAgente, cerrarSesionRcv } = require('./sii');
const { guardarToken, leerToken } = require('./credential-store');
const { parseRcv } = require('../../assets/rcv-import.js');
const { parseInforme: parseHonorarios } = require('../../assets/honorarios-import.js');

function leerInformeHonorarios(ruta) {
  const bytes = fs.readFileSync(ruta);
  try { return new TextDecoder('utf-8', { fatal: true }).decode(bytes); }
  catch { return new TextDecoder('windows-1252').decode(bytes); }
}

/** "76.000.000-0" -> "76000000-0" (la forma en que se busca la clave). */
function normalizarRut(rut) {
  const limpio = String(rut || '').replace(/[^0-9kK]/g, '').toUpperCase();
  return limpio.length < 2 ? '' : `${limpio.slice(0, -1)}-${limpio.slice(-1)}`;
}

/**
 * Ejecuta una tarea: descarga el libro, lo valida con el mismo lector del navegador (assets/rcv-import.js)
 * y lo envía a Cifrax. Los fallos locales se informan a la tarea; los que rechaza el servidor ya quedan registrados allí.
 */
async function procesarTarea({ servidor, tarea, descargar = descargarLibro, descargarHonorarios = descargarBoletasHonorarios, carpeta, conservar, log = console.log, signal, progreso = async () => {} }) {
  if (tarea.tipo === 'honorarios') return procesarTareaHonorarios({ servidor, tarea, descargar: descargarHonorarios, carpeta, conservar, log, signal, progreso });
  if (tarea.tipo && tarea.tipo !== 'rcv') {
    if (Object.hasOwn(tarea, 'clave')) tarea.clave = '';
    return servidor.fallar(tarea.id, 'El agente central solo procesa tareas RCV.');
  }
  const rut = normalizarRut(tarea.rut);
  if (!rut) {
    if (Object.hasOwn(tarea, 'clave')) tarea.clave = '';
    return servidor.fallar(tarea.id, 'La empresa no tiene RUT en qlc.cl.');
  }
  if (descargar.canalSiiHabilitado === false) {
    if (Object.hasOwn(tarea, 'clave')) tarea.clave = '';
    return servidor.fallar(tarea.id, 'La descarga automática del RCV está deshabilitada hasta validar un canal autorizado por el SII.');
  }

  let archivo = null;
  let clave = '';
  let sinDocumentos = false;
  try {
    clave = typeof tarea.clave === 'string' ? tarea.clave : '';
    if (!clave) throw new Error('No se recibió la Clave Tributaria guardada para esta empresa.');
    await progreso('iniciada');
    archivo = await descargar({ rut, clave, periodo: tarea.periodo, libro: tarea.libro, carpeta, signal, onProgress: progreso,
      onFailure: (jpeg) => servidor.captura(tarea.id, jpeg).catch(() => {}) });
    sinDocumentos = archivo?.sinDocumentos === true;
    clave = '';
    if (Object.hasOwn(tarea, 'clave')) tarea.clave = '';
  } catch (e) {
    if (signal?.aborted) { clave = ''; if (Object.hasOwn(tarea, 'clave')) tarea.clave = ''; return { ok: false, cancelada: true }; }
    const detalle = String(e?.message || 'Error desconocido.');
    const mensaje = `No se pudo descargar el RCV del SII: ${clave ? detalle.split(clave).join('[oculta]') : detalle}`;
    clave = '';
    if (Object.hasOwn(tarea, 'clave')) tarea.clave = '';
    log(`Tarea ${tarea.id}: ${mensaje}`);
    await progreso('error');
    await servidor.fallar(tarea.id, mensaje).catch(() => {});
    return { ok: false, mensaje };
  }
  try {
    if (signal?.aborted) return { ok: false, cancelada: true };
    await progreso('validando');
    const parsed = sinDocumentos
      ? { kind: tarea.libro, period: tarea.periodo, fileName: archivo.fileName, docs: [] }
      : parseRcv(fs.readFileSync(archivo, 'utf8'), path.basename(archivo));
    if (parsed.kind !== tarea.libro) throw new Error(`El archivo descargado es de ${parsed.kind}, no de ${tarea.libro}.`);
    if (parsed.period !== tarea.periodo) throw new Error(`El archivo es del período ${parsed.period || 'desconocido'}, no del ${tarea.periodo}.`);
    if (!sinDocumentos && !parsed.docs.length && !parsed.resumen?.length) throw new Error('El archivo del SII no trae documentos ni filas de resumen.');
    const datos = { period: parsed.period, kind: parsed.kind, fileName: parsed.fileName, rutEmpresa: parsed.rutEmpresa || rut, docs: parsed.docs, ...(parsed.resumen ? { resumen: parsed.resumen } : {}) };
    try {
      await progreso('importando');
      const { guardados } = await servidor.importar(tarea.id, datos);
      await progreso('completada', guardados);
      log(sinDocumentos
        ? `Tarea ${tarea.id}: período sin documentos de ${tarea.libro} (${tarea.periodo}).`
        : parsed.resumen
          ? `Tarea ${tarea.id}: ${guardados} comprobantes guardados como resumen mensual de ventas; sin vouchers individuales (${tarea.periodo}).`
          : `Tarea ${tarea.id}: ${guardados} documentos de ${tarea.libro} guardados (${tarea.periodo}).`);
      return { ok: true, guardados };
    } catch (e) {
      // El servidor ya dejó la tarea en error con su motivo (por ejemplo, RUT distinto).
      await progreso('error');
      log(`Tarea ${tarea.id}: el servidor rechazó el archivo: ${e.message}`);
      return { ok: false, mensaje: e.message };
    }
  } catch (e) {
    // Archivo que no es el libro pedido o que no se puede leer: se informa a la tarea.
    log(`Tarea ${tarea.id}: ${e.message}`);
    await progreso('error');
    await servidor.fallar(tarea.id, e.message).catch(() => {});
    return { ok: false, mensaje: e.message };
  } finally {
    if (typeof archivo === 'string' && archivo && !conservar) fs.rmSync(archivo, { force: true });
  }
}

/** Descarga y guarda un informe mensual de BHE usando el parser y almacenamiento del módulo Honorarios. */
async function procesarTareaHonorarios({ servidor, tarea, descargar = descargarBoletasHonorarios, carpeta, conservar, log = console.log, signal, progreso = async () => {} }) {
  if (!['recibidas', 'emitidas'].includes(tarea.libro)) { if (Object.hasOwn(tarea, 'clave')) tarea.clave = ''; return servidor.fallar(tarea.id, 'Libro de honorarios inválido; use recibidas o emitidas.'); }
  const rut = normalizarRut(tarea.rut);
  if (!rut) { if (Object.hasOwn(tarea, 'clave')) tarea.clave = ''; return servidor.fallar(tarea.id, 'La empresa no tiene RUT en qlc.cl.'); }
  let archivo = null;
  let clave = typeof tarea.clave === 'string' ? tarea.clave : '';
  try {
    if (!clave) throw new Error('No se recibió la Clave Tributaria guardada para esta empresa.');
    await progreso('iniciada');
    archivo = await descargar({ rut, clave, periodo: tarea.periodo, libro: tarea.libro, carpeta, signal,
      onProgress: progreso, onFailure: (jpeg) => servidor.captura(tarea.id, jpeg).catch(() => {}) });
    clave = '';
    if (Object.hasOwn(tarea, 'clave')) tarea.clave = '';
    if (signal?.aborted) return { ok: false, cancelada: true };
    await progreso('validando');
    const parsed = archivo?.sinDocumentos
      ? { kind: tarea.libro, period: tarea.periodo, fileName: archivo.fileName, rutEmpresa: rut, boletas: [] }
      : parseHonorarios(leerInformeHonorarios(archivo), path.basename(archivo));
    if (parsed.kind !== tarea.libro) throw new Error(`El informe descargado es de ${parsed.kind}, no de ${tarea.libro}.`);
    if (parsed.period !== tarea.periodo) throw new Error(`El informe es del período ${parsed.period || 'desconocido'}, no del ${tarea.periodo}.`);
    if (parsed.rutEmpresa && normalizarRut(parsed.rutEmpresa) !== rut) throw new Error('El RUT del informe no coincide con la empresa seleccionada.');
    if (signal?.aborted) return { ok: false, cancelada: true };
    await progreso('importando');
    const { guardados } = await servidor.importar(tarea.id, { kind: parsed.kind, period: parsed.period, fileName: parsed.fileName, rutEmpresa: rut, boletas: parsed.boletas });
    await progreso('completada', guardados);
    log(guardados ? `Tarea ${tarea.id}: ${guardados} boletas ${tarea.libro} guardadas (${tarea.periodo}).` : `Tarea ${tarea.id}: sin boletas ${tarea.libro} (${tarea.periodo}).`);
    return { ok: true, guardados };
  } catch (e) {
    if (signal?.aborted) return { ok: false, cancelada: true };
    const detalle = String(e?.message || 'Error desconocido.');
    const mensaje = `No se pudieron descargar boletas de honorarios: ${clave ? detalle.split(clave).join('[oculta]') : detalle}`;
    log(`Tarea ${tarea.id}: ${mensaje}`);
    await progreso('error');
    await servidor.fallar(tarea.id, mensaje).catch(() => {});
    return { ok: false, mensaje };
  } finally {
    clave = '';
    if (Object.hasOwn(tarea, 'clave')) tarea.clave = '';
    if (typeof archivo === 'string' && archivo && !conservar) fs.rmSync(archivo, { force: true });
  }
}

/** Consulta el perfil de MiSII para completar el alta de empresa. La clave se usa en memoria y se elimina al terminar. */
async function procesarConsultaEmpresa({ servidor, tarea, consultar = consultarEmpresa, headless = true, log = console.log, signal }) {
  let clave = typeof tarea.clave === 'string' ? tarea.clave : '';
  const limpiar = () => { clave = ''; if (Object.hasOwn(tarea, 'clave')) tarea.clave = ''; };
  if (!clave) return servidor.fallar(tarea.id, 'No se recibió la Clave Tributaria para consultar MiSII.');
  if (consultar.canalSiiHabilitado === false) {
    limpiar();
    return servidor.fallar(tarea.id, 'La consulta de datos del contribuyente aún no está habilitada.');
  }
  try {
    const datos = await consultar({ rut: normalizarRut(tarea.rut), clave, headless, signal });
    limpiar();
    if (signal?.aborted) return { ok: false, cancelada: true };
    await servidor.resultadoEmpresa(tarea.id, datos);
    log(`Consulta MiSII ${tarea.id}: datos de empresa recibidos para revisión.`);
    return { ok: true };
  } catch (e) {
    if (signal?.aborted) { limpiar(); return { ok: false, cancelada: true }; }
    const detalle = String(e?.message || 'Error desconocido.');
    const mensaje = `No se pudieron leer los datos de MiSII: ${clave ? detalle.split(clave).join('[oculta]') : detalle}`;
    limpiar();
    log(`Consulta MiSII ${tarea.id}: ${mensaje}`);
    await servidor.fallar(tarea.id, mensaje).catch(() => {});
    return { ok: false, mensaje };
  } finally {
    limpiar();
  }
}

function configurar(env) {
  const url = env.SII_URL_SERVIDOR;
  if (!url) throw new Error('Falta SII_URL_SERVIDOR en sii-agent/.env.');
  if (env.SII_CLAVES) throw new Error('Elimina SII_CLAVES de sii-agent/.env; las claves se administran cifradas por empresa en qlc.cl.');
  if (env.SII_TOKEN_AGENTE) throw new Error('Elimina el token individual antiguo de sii-agent/.env. El agente central usa el almacén seguro del sistema operativo.');
  return {
    url,
    headless: String(env.SII_HEADLESS ?? 'true').toLowerCase() !== 'false',
    intervaloMs: Math.max(15, Number(env.SII_INTERVALO_SEG || 60)) * 1000,
    carpeta: path.resolve(__dirname, '..', env.SII_CARPETA || 'descargas'),
    conservar: String(env.SII_CONSERVAR ?? 'false').toLowerCase() === 'true',
  };
}

/** Una vuelta: el agente central reclama y procesa como máximo una tarea. */
async function revisar({ servidor, config, descargar = descargarLibro, descargarHonorarios = descargarBoletasHonorarios, consultar = consultarEmpresa, browser = null, log, alIniciarTarea, signal }) {
  if (descargar.canalSiiHabilitado === false) {
    log('El agente no consulta el SII hasta configurar el canal autorizado.');
    return 0;
  }
  const tareas = await servidor.pendientes();
  const conHeadless = (fn) => {
    const envuelta = (opciones) => fn({ ...opciones, headless: config.headless, ...(browser ? { browser } : {}) });
    if (fn.canalSiiHabilitado === false) envuelta.canalSiiHabilitado = false;
    return envuelta;
  };
  const consultarConHeadless = conHeadless(consultar);
  const descargarHonorariosConHeadless = conHeadless(descargarHonorarios);
  for (const tarea of tareas) {
    if (signal?.aborted) break;
    const controller = new AbortController();
    alIniciarTarea?.(tarea, controller);
    if (tarea.tipo === 'datos_empresa') {
      await cerrarSesionRcv(browser);
      await procesarConsultaEmpresa({ servidor, tarea, consultar: consultarConHeadless, headless: config.headless, log, signal: controller.signal });
    } else if (tarea.tipo === 'honorarios') {
      await procesarTareaHonorarios({ servidor, tarea, carpeta: config.carpeta, conservar: config.conservar, log, signal: controller.signal,
        descargar: descargarHonorariosConHeadless,
        progreso: (etapa, detalle = '') => servidor.evento(tarea.id, etapa, detalle).catch(() => {}) });
    } else {
      await procesarTarea({ servidor, tarea, carpeta: config.carpeta, conservar: config.conservar, log, descargar: conHeadless(descargar), signal: controller.signal,
        progreso: (etapa, detalle = '') => servidor.evento(tarea.id, etapa, detalle).catch(() => {}) });
    }
  }
  return tareas.length;
}

async function main() {
  const env = { ...cargarEnv(path.resolve(__dirname, '..', '.env')), ...process.env };
  if (env.SII_CANAL) process.env.SII_CANAL = env.SII_CANAL;
  const log = (texto) => console.log(`[${new Date().toISOString()}] ${texto}`);
  const ruta = path.resolve(__dirname, '..', '.env');
  if (process.platform === 'linux' && env.SII_CODIGO_SERVICIO) {
    throw new Error('En Ubuntu, enrola el agente con «npm run enrolar:linux» para cifrar el token mediante systemd.');
  }
  let token = await leerToken();
  if (env.SII_CODIGO_SERVICIO) {
    if (!env.SII_URL_SERVIDOR) throw new Error('Falta SII_URL_SERVIDOR en sii-agent/.env.');
    token = await emparejarAgente({
      url: env.SII_URL_SERVIDOR,
      codigo: env.SII_CODIGO_SERVICIO,
      nombre: os.hostname(),
    });
    await guardarToken(token);
    actualizarEnv(ruta, { SII_CODIGO_SERVICIO: null, SII_CODIGO_EMPAREJAMIENTO: null, SII_TOKEN_AGENTE: null });
    delete env.SII_TOKEN_AGENTE;
    log('Agente central enrolado. El código de un solo uso se eliminó y el token quedó en Windows Credential Manager.');
  } else if (token && (env.SII_CODIGO_EMPAREJAMIENTO || env.SII_TOKEN_AGENTE)) {
    actualizarEnv(ruta, { SII_CODIGO_SERVICIO: null, SII_CODIGO_EMPAREJAMIENTO: null, SII_TOKEN_AGENTE: null });
    delete env.SII_TOKEN_AGENTE;
  }
  if (!token) throw new Error('El agente no está enrolado. Genera un código de servicio como administrador en RCV y agrégalo temporalmente a SII_CODIGO_SERVICIO en .env.');
  if (!env.SII_URL_SERVIDOR) throw new Error('Falta SII_URL_SERVIDOR en sii-agent/.env.');
  if (env.SII_CLAVES) throw new Error('Elimina SII_CLAVES de sii-agent/.env; las claves tributarias se administran cifradas por empresa en qlc.cl.');
  if (env.SII_TOKEN_AGENTE) {
    actualizarEnv(ruta, { SII_TOKEN_AGENTE: null });
    throw new Error('Se eliminó el token individual antiguo de .env. Vuelve a iniciar para usar Windows Credential Manager.');
  }
  const config = configurar(env);
  const workerId = randomUUID();
  const servidor = crearServidor({ url: config.url, token, workerId });
  const gui = process.argv.includes('--gui');
  let activo = null;
  let reiniciar = false;
  const senalAgente = new AbortController();
  let sesionNavegador = null;
  const emitir = (tipo, datos = {}) => { if (gui) process.stdout.write(`@@AGENTE_GUI@@${JSON.stringify({ tipo, ...datos })}\n`); };
  const publicarEstado = async () => {
    if (!gui) return;
    try { emitir('estado', { ...await servidor.estado(), activo: activo ? { id: activo.tarea.id, tipo: activo.tarea.tipo, periodo: activo.tarea.periodo, libro: activo.tarea.libro } : null,
      navegador: { activo: Boolean(sesionNavegador?.browser), visible: Boolean(sesionNavegador && !sesionNavegador.headless) } }); }
    catch (e) { emitir('estado', { agente: { conectado: false, activo: false }, tareas: [], error: e.message, activo: null,
      navegador: { activo: Boolean(sesionNavegador?.browser), visible: Boolean(sesionNavegador && !sesionNavegador.headless) } }); }
  };
  if (gui) {
    const readline = require('node:readline');
    const rl = readline.createInterface({ input: process.stdin });
    rl.on('close', () => { detenido = true; senalAgente.abort(); if (activo) controlar('cancelar'); });
    rl.on('line', async (line) => {
      let cmd; try { cmd = JSON.parse(line); } catch { return; }
      if (cmd.accion === 'mostrar_navegador') { config.headless = !cmd.valor; return; }
      if (cmd.accion === 'salir') {
        detenido = true;
        senalAgente.abort();
        if (activo && !activo.accion) await controlar('cancelar');
        return;
      }
      if (cmd.accion === 'reiniciar') {
        senalAgente.abort();
        if (activo) await controlar('reencolar'); else { reiniciar = true; detenido = true; }
      } else if (cmd.accion === 'cancelar') {
        if (Number.isInteger(cmd.tarea_id) && activo?.tarea.id === cmd.tarea_id) await controlar('cancelar');
        else if (Number.isInteger(cmd.tarea_id)) {
          try { await servidor.controlar(cmd.tarea_id, 'cancelar'); logWorker(`Tarea ${cmd.tarea_id}: cancelación solicitada desde la cola.`); await publicarEstado(); }
          catch (e) { logWorker(`No se pudo cancelar la tarea ${cmd.tarea_id}: ${e.message}`); }
        } else if (activo) await controlar('cancelar');
      }
    });
  }
  async function controlar(accion) {
    if (!activo || activo.accion) return;
    try {
      await servidor.controlar(activo.tarea.id, accion);
      activo.accion = accion;
      activo.controller.abort();
      log(`Tarea ${activo.tarea.id}: ${accion === 'cancelar' ? 'cancelación' : 'reinicio'} solicitada.`);
      emitir('control', { tarea_id: activo.tarea.id, accion });
    } catch (e) { log(`No se pudo controlar la tarea ${activo.tarea.id}: ${e.message}`); }
  }
  const logWorker = (texto) => { const linea = `[${new Date().toISOString()}] ${texto}`; if (gui) emitir('log', { mensaje: linea }); else console.log(linea); };
  const asegurarNavegador = async () => {
    if (sesionNavegador?.browser && !sesionNavegador.browser.isConnected()) sesionNavegador = null;
    if (sesionNavegador && sesionNavegador.headless !== config.headless) {
      await cerrarSesionRcv(sesionNavegador.browser);
      await cerrarNavegadorAgente(sesionNavegador);
      sesionNavegador = null;
    }
    if (!sesionNavegador?.browser) {
      sesionNavegador = await crearNavegadorAgente({ headless: config.headless });
      logWorker(`Chromium ${config.headless ? 'en segundo plano' : 'visible'} abierto y listo.`);
      await publicarEstado();
    }
    return sesionNavegador.browser;
  };
  if (process.argv.includes('--una-vez')) {
    const heartbeatTimer = setInterval(() => { servidor.heartbeat().catch(() => {}); }, 30000);
    try {
      const estado = await servidor.heartbeat();
      if (estado.ocupado) { log('Otra instancia del agente central tiene el control.'); return; }
      const n = await revisar({ servidor, config, log });
      log(n ? `${n} tarea(s) revisadas.` : 'No hay tareas pendientes.');
    } finally { clearInterval(heartbeatTimer); await servidor.liberar().catch(() => {}); }
    return;
  }
  logWorker(`Agente central SII activo. Revisando ${config.url} cada ${config.intervaloMs / 1000} s.`);
  let listo = false;
  let detenido = false;
  const latido = async () => {
    try {
      const estado = await servidor.heartbeat();
      listo = Boolean(estado.activo && !estado.ocupado);
      if (estado.ocupado) logWorker('Otra instancia del agente central tiene el control; esta instancia queda en espera.');
      return listo;
    } catch (e) {
      listo = false;
      logWorker(`No se pudo renovar la conexión del agente: ${e.message}`);
      return false;
    }
  };
  await latido();
  try { await asegurarNavegador(); }
  catch (e) { logWorker(`No se pudo iniciar Chromium: ${e.message}`); }
  const temporizadorLatido = setInterval(() => { latido().catch(() => {}); }, 30000);
  const detener = () => {
    detenido = true;
    senalAgente.abort();
    if (activo && !activo.accion) controlar('cancelar');
  };
  process.once('SIGINT', detener);
  process.once('SIGTERM', detener);
  try {
    const stdinTimer = setInterval(() => publicarEstado(), 5000);
    await publicarEstado();
    let proximaRevision = 0;
    while (!detenido) {
      if (listo && Date.now() >= proximaRevision) {
        proximaRevision = Date.now() + config.intervaloMs;
        try {
          const browser = await asegurarNavegador();
          const revisadas = await revisar({ servidor, config, browser, log: logWorker,
            signal: senalAgente.signal,
            alIniciarTarea: (tarea, controller) => { activo = { tarea, controller, accion: null }; publicarEstado(); } });
          if (revisadas > 0) proximaRevision = Date.now();
          else {
            await cerrarSesionRcv(browser, logWorker);
            proximaRevision = Date.now() + config.intervaloMs;
          }
        } catch (e) { logWorker(`No se pudo revisar la cola central: ${e.message}`); }
        if (activo) {
          const final = activo;
          if (final.accion) await servidor.finalizarControl(final.tarea.id, final.accion).catch((e) => logWorker(`No se pudo finalizar el control de la tarea ${final.tarea.id}: ${e.message}`));
          activo = null;
          await publicarEstado();
          if (final.accion === 'reencolar') { reiniciar = true; detenido = true; }
        }
      }
      if (!detenido) await new Promise((r) => { const t=setTimeout(r, Math.min(config.intervaloMs, 1000)); if (gui) t.unref?.(); });
    }
    clearInterval(stdinTimer);
  } finally {
    clearInterval(temporizadorLatido);
    await cerrarSesionRcv(sesionNavegador?.browser, logWorker);
    await cerrarNavegadorAgente(sesionNavegador);
    await servidor.liberar().catch(() => {});
  }
  if (reiniciar) process.exitCode = 23;

}

module.exports = { procesarTarea, procesarTareaHonorarios, procesarConsultaEmpresa, normalizarRut, configurar, revisar };

if (require.main === module) {
  main().catch((e) => {
    console.error(e.message);
    process.exit(1);
  });
}
