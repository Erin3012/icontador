// Pruebas del agente sin navegador ni SII: npm test (desde sii-agent/)
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { cargarEnv } = require('../src/env');
const { crearServidor } = require('../src/api');
const { procesarTarea, normalizarRut, leerClaves, configurar } = require('../src/index');
const { periodoSii } = require('../src/sii');

const raiz = path.resolve(__dirname, '..', '..');
const muestras = path.join(raiz, 'samples');
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'sii-agente-test-'));

// 1. .env: comillas, comentarios y variables ya presentes.
const env = path.join(temp, '.env');
fs.writeFileSync(env, '# comentario\nSII_URL_SERVIDOR=https://qlc.cl\nSII_CLAVES=\'{"76000000-0":"x y"}\'\n\nSII_TOKEN_AGENTE="tok"\n');
const valores = cargarEnv(env);
assert.equal(valores.SII_URL_SERVIDOR, 'https://qlc.cl');
assert.equal(valores.SII_CLAVES, '{"76000000-0":"x y"}');
assert.equal(valores.SII_TOKEN_AGENTE, 'tok');
assert.deepEqual(cargarEnv(path.join(temp, 'no-existe')), {});

// 2. RUT y claves: el formato con puntos y el de la clave deben coincidir.
assert.equal(normalizarRut('76.000.000-0'), '76000000-0');
assert.equal(normalizarRut('7.123.456-k'), '7123456-K');
assert.equal(leerClaves('{"76.000.000-0":"abc"}')['76000000-0'], 'abc');
assert.throws(() => leerClaves('{no json'), /JSON/);
assert.equal(periodoSii('2026-10'), '10-2026');

// 3. Servidor: envía el token y decodifica errores.
const llamadas = [];
const fetchFalso = async (url, opciones) => {
  llamadas.push({ url, opciones });
  return { ok: url.includes('importar') ? false : true, status: url.includes('importar') ? 422 : 200,
    json: async () => (url.includes('importar') ? { error: 'RUT distinto' } : { tareas: [{ id: 1 }] }) };
};
const servidor = crearServidor({ url: 'https://qlc.cl/', token: 'tok', fetchImpl: fetchFalso });
(async () => {
  assert.deepEqual(await servidor.pendientes(), [{ id: 1 }]);
  assert.equal(llamadas[0].url, 'https://qlc.cl/api/sii-agente.php?accion=pendientes');
  assert.equal(llamadas[0].opciones.headers['X-Agente-Token'], 'tok');
  await assert.rejects(servidor.importar(1, { docs: [] }), /RUT distinto/);

  // 4. Tarea completa: descarga (copia de la muestra), valida y envía al servidor; borra el CSV.
  const enviados = [];
  const servidorOk = {
    pendientes: async () => [],
    importar: async (id, datos) => { enviados.push({ id, datos }); return { guardados: datos.docs.length }; },
    fallar: async (id, mensaje) => { enviados.push({ id, mensaje }); return { ok: true }; },
  };
  const descargarMuestra = (archivoMuestra) => async ({ carpeta }) => {
    fs.mkdirSync(carpeta, { recursive: true });
    const destino = path.join(carpeta, 'RCV_descargado.csv');
    fs.copyFileSync(path.join(muestras, archivoMuestra), destino);
    return destino;
  };
  const claves = { '76000000-0': 'clave' };
  const carpeta = path.join(temp, 'descargas');
  const tarea = { id: 7, rut: '76.000.000-0', periodo: '2026-09', libro: 'compras' };
  let r = await procesarTarea({ servidor: servidorOk, tarea, claves, carpeta, conservar: false, log: () => {},
    descargar: descargarMuestra('RCV_COMPRA_REGISTRO_76000000-0_202609.csv') });
  assert.equal(r.ok, true);
  assert.equal(enviados[0].id, 7);
  assert.equal(enviados[0].datos.kind, 'compras');
  assert.equal(enviados[0].datos.period, '2026-09');
  assert.ok(enviados[0].datos.docs.length > 0);
  assert.equal(fs.readdirSync(carpeta).length, 0, 'borra el CSV tras importarlo');

  // 5. Sin clave para el RUT: se informa a la tarea y no se descarga nada.
  enviados.length = 0;
  let descargado = false;
  await procesarTarea({ servidor: servidorOk, tarea, claves: {}, carpeta, log: () => {}, descargar: async () => { descargado = true; } });
  assert.equal(descargado, false);
  assert.match(enviados[0].mensaje, /Falta la clave del SII para el RUT 76000000-0/);

  // 6. El archivo es de otro período: se informa y no se importa.
  enviados.length = 0;
  await procesarTarea({ servidor: servidorOk, tarea: { ...tarea, periodo: '2026-10' }, claves, carpeta, log: () => {},
    descargar: descargarMuestra('RCV_COMPRA_REGISTRO_76000000-0_202609.csv') });
  assert.ok(enviados.every((e) => e.mensaje && e.mensaje.includes('período 2026-09')), 'informa período distinto');

  // 7. El libro pedido no es el que llegó (ventas en vez de compras).
  enviados.length = 0;
  await procesarTarea({ servidor: servidorOk, tarea: { ...tarea, libro: 'ventas' }, claves, carpeta, log: () => {},
    descargar: descargarMuestra('RCV_COMPRA_REGISTRO_76000000-0_202609.csv') });
  assert.match(enviados[0].mensaje, /compras, no de ventas/);

  // 8. El servidor rechaza el archivo (RUT distinto): no se vuelve a informar como fallo local.
  enviados.length = 0;
  const servidorRechaza = { ...servidorOk, importar: async () => { throw new Error('RUT distinto'); } };
  r = await procesarTarea({ servidor: servidorRechaza, tarea, claves, carpeta, log: () => {},
    descargar: descargarMuestra('RCV_COMPRA_REGISTRO_76000000-0_202609.csv') });
  assert.equal(r.ok, false);
  assert.equal(enviados.length, 0);

  // 9. Error al descargar (clave incorrecta, por ejemplo): se informa a la tarea.
  await procesarTarea({ servidor: servidorOk, tarea, claves, carpeta, log: () => {},
    descargar: async () => { throw new Error('No se pudo iniciar sesión en el SII.'); } });
  assert.match(enviados[enviados.length - 1].mensaje, /No se pudo descargar el RCV del SII: No se pudo iniciar sesión/);

  // 10. Configuración: faltan datos obligatorios.
  assert.throws(() => configurar({}), /SII_URL_SERVIDOR/);
  const cfg = configurar({ SII_URL_SERVIDOR: 'https://qlc.cl', SII_TOKEN_AGENTE: 't', SII_HEADLESS: 'false', SII_INTERVALO_SEG: '5' });
  assert.equal(cfg.headless, false);
  assert.equal(cfg.intervaloMs, 15000, 'el intervalo mínimo es 15 segundos');

  fs.rmSync(temp, { recursive: true, force: true });
  console.log('Agente SII: .env, RUT, servidor, validación y errores verificados');
})().catch((e) => {
  console.error('FALLA:', e.message);
  process.exit(1);
});
