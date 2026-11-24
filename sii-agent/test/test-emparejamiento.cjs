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
const original = '# agente\nSII_URL_SERVIDOR=https://qlc.cl\nSII_CODIGO_SERVICIO=ABCDE-FGHJK-LMNPR-STUVW\nSII_HEADLESS=true\n';
fs.writeFileSync(ruta, original);

// 1. actualizarEnv reemplaza, quita y agrega sin tocar el resto.
actualizarEnv(ruta, { SII_TOKEN_AGENTE: null, SII_CODIGO_SERVICIO: null });
let valores = cargarEnv(ruta);
assert.equal(valores.SII_TOKEN_AGENTE, undefined);
assert.equal(valores.SII_CODIGO_SERVICIO, undefined);
assert.equal(valores.SII_URL_SERVIDOR, 'https://qlc.cl');
assert.equal(valores.SII_HEADLESS, 'true');

// 2. Canje correcto: el servidor recibe el código y nombre; el caller guarda token en Credential Manager.
fs.writeFileSync(ruta, original);
let enviado = null;
const ok = async (url, opciones) => {
  enviado = { url, opciones, cuerpo: JSON.parse(opciones.body) };
  return { ok: true, status: 200, json: async () => ({ token: 'a'.repeat(64) }) };
};
(async () => {
  const token = await emparejarAgente({ url: 'https://qlc.cl/', codigo: ' ABCDE-FGHJK-LMNPR-STUVW ', nombre: 'PC-PRUEBA', fetchImpl: ok });
  assert.equal(token, 'a'.repeat(64));
  assert.equal(enviado.url, 'https://qlc.cl/api/sii-agente.php?accion=canjear_servicio');
  assert.equal(enviado.opciones.headers['X-Agente-Token'], undefined, 'el canje no manda token');
  assert.deepEqual(enviado.cuerpo, { codigo: 'ABCDE-FGHJK-LMNPR-STUVW', nombre: 'PC-PRUEBA' });
  assert.equal(cargarEnv(ruta).SII_TOKEN_AGENTE, undefined, 'el token no se guarda en .env');

  // 3. Código vencido o inválido: el error se muestra y el código queda disponible para reintentar.
  fs.writeFileSync(ruta, original);
  const rechaza = async () => ({ ok: false, status: 422, json: async () => ({ error: 'Código inválido o vencido.' }) });
  await assert.rejects(
    emparejarAgente({ url: 'https://qlc.cl', codigo: 'X', nombre: '', fetchImpl: rechaza }),
    /Código inválido o vencido/
  );
  assert.equal(cargarEnv(ruta).SII_CODIGO_SERVICIO, 'ABCDE-FGHJK-LMNPR-STUVW');
  assert.equal(cargarEnv(ruta).SII_TOKEN_AGENTE, undefined);

  fs.rmSync(temp, { recursive: true, force: true });
  console.log('Emparejamiento: canje, actualización de .env y código vencido verificados');
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
