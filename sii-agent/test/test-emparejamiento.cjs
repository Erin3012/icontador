// Pruebas del emparejamiento por código: npm test (desde sii-agent/). No llama al servidor real.
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { cargarEnv, actualizarEnv } = require('../src/env');
const { emparejarAgente } = require('../src/api');

const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'sii-emparejar-test-'));
const ruta = path.join(temp, '.env');
const original = '# agente\nSII_URL_SERVIDOR=https://qlc.cl\nSII_CODIGO_EMPAREJAMIENTO=ABCDE-FGHJK\nSII_HEADLESS=true\n';
fs.writeFileSync(ruta, original);

// 1. actualizarEnv reemplaza, quita y agrega sin tocar el resto.
actualizarEnv(ruta, { SII_TOKEN_AGENTE: 'tok', SII_CODIGO_EMPAREJAMIENTO: null });
let valores = cargarEnv(ruta);
assert.equal(valores.SII_TOKEN_AGENTE, 'tok');
assert.equal(valores.SII_CODIGO_EMPAREJAMIENTO, undefined);
assert.equal(valores.SII_URL_SERVIDOR, 'https://qlc.cl');
assert.equal(valores.SII_HEADLESS, 'true');
assert.equal(fs.readFileSync(ruta, 'utf8').split('\n').filter((l) => l.startsWith('SII_TOKEN_AGENTE=')).length, 1);

// 2. Canje correcto: el servidor recibe el código y el nombre, y el token queda en .env.
fs.writeFileSync(ruta, original);
let enviado = null;
const ok = async (url, opciones) => {
  enviado = { url, opciones, cuerpo: JSON.parse(opciones.body) };
  return { ok: true, status: 200, json: async () => ({ token: 'a'.repeat(48) }) };
};
(async () => {
  const token = await emparejarAgente({ url: 'https://qlc.cl/', codigo: ' ABCDE-FGHJK ', nombre: 'PC-PRUEBA', ruta, actualizarEnv, fetchImpl: ok });
  assert.equal(token, 'a'.repeat(48));
  assert.equal(enviado.url, 'https://qlc.cl/api/sii-agente.php?accion=canjear');
  assert.equal(enviado.opciones.headers['X-Agente-Token'], undefined, 'el canje no manda token');
  assert.deepEqual(enviado.cuerpo, { codigo: 'ABCDE-FGHJK', nombre: 'PC-PRUEBA' });
  valores = cargarEnv(ruta);
  assert.equal(valores.SII_TOKEN_AGENTE, 'a'.repeat(48));
  assert.equal(valores.SII_CODIGO_EMPAREJAMIENTO, undefined);

  // 3. Código vencido o inválido: el error del servidor se muestra y el código queda en .env para reintentar.
  fs.writeFileSync(ruta, original);
  const rechaza = async () => ({ ok: false, status: 422, json: async () => ({ error: 'Código inválido o vencido.' }) });
  await assert.rejects(
    emparejarAgente({ url: 'https://qlc.cl', codigo: 'X', nombre: '', ruta, actualizarEnv, fetchImpl: rechaza }),
    /Código inválido o vencido/
  );
  assert.equal(cargarEnv(ruta).SII_CODIGO_EMPAREJAMIENTO, 'ABCDE-FGHJK');
  assert.equal(cargarEnv(ruta).SII_TOKEN_AGENTE, undefined);

  fs.rmSync(temp, { recursive: true, force: true });
  console.log('Emparejamiento: canje, actualización de .env y código vencido verificados');
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
