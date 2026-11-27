// Lectura de las consultas del F22 y de declaraciones juradas, con respuestas inventadas (sin navegador ni SII).
'use strict';
const assert = require('node:assert/strict');
const { parsearF22, parsearDdjj, declaracionesObservadas, estadoF22, fechaHora, limpiar } = require('../src/consultas');
const { procesarTarea, configurar } = require('../src/index');
const { crearServidor } = require('../src/api');

// 1. Estados del F22 según la frase del SII ("no presenta diferencias" es aceptada).
assert.equal(estadoF22('Tu declaración de Renta no presenta diferencias ... el SII la ha aceptado.'), 'Aceptada');
assert.equal(estadoF22('El Servicio de Impuestos Internos autorizó a la Tesorería General de la República la devolución total solicitada por $8.056.616.'), 'Aceptada');
assert.equal(estadoF22('Tu declaración presenta diferencias con la información del SII.'), 'Con inconsistencia');
assert.equal(estadoF22('Su declaración ha sido observada.'), 'Con inconsistencia');
assert.equal(estadoF22('Tu devolución se encuentra retenida.'), 'Devolución retenida');
assert.equal(estadoF22('La devolución solicitada se retuvo totalmente, dado que se generaron inconsistencias.'), 'Devolución retenida');
assert.equal(estadoF22('Declaración en proceso de revisión.'), 'En proceso');

// 2. F22: folio vigente, eventos de ese folio, resultado y códigos sin datos internos.
const f22 = parsearF22(2026, {
  consultaFolios: { data: [{ folio: 111, vgte: '0', fecIng: '01/04/2026' }, { folio: 222, vgte: '1', fecIng: '29/04/2026' }] },
  buscaDeclVgte: { data: { glosas: [{ descripcion: 'Tu declaraci&oacute;n ha sido aceptada.' }] } },
  buscaEventos: { data: [{ folio: '111', nombre: 'Otra', fechaEvento: '01/04/2026' }, { folio: '222', nombre: 'Declaracion aceptada por el SII.', fechaEvento: '20/05/2026' }] },
  situacionObservacion: { data: [] },
  f22Completo: { data: [{ codigo: 87, valor: '1.234.567', glosa: 'Monto devolución' }, { codigo: 312, valor: '9', glosa: 'Software' },
    { codigo: 9920, valor: '10.0.0.1', glosa: 'Origen' }, { codigo: 306, valor: '12345', glosa: 'Número de cuenta' }, { codigo: 1600, valor: '', glosa: 'Vacío' }] },
});
assert.equal(f22.estado, 'Aceptada');
assert.equal(f22.situacion, 'Tu declaración ha sido aceptada.');
assert.equal(f22.folio, 222);
assert.deepEqual(f22.eventos, [{ fecha: '20/05/2026', descripcion: 'Declaracion aceptada por el SII.' }]);
assert.deepEqual(f22.codigos.map((c) => c.codigo), [87]);
assert.deepEqual(f22.resultado, { devolucion: 1234567, pago: null });
assert.equal(parsearF22(2026, { consultaFolios: { data: [] } }).estado, 'Sin declaración');

// 3. DJ: una por presentación, con fecha legible y entidades decodificadas.
const detalle = { data: [
  { dj: 1879, titulo: 'Retenciones', result: [{ estado: 'Aceptada', folio: 31, fecha: '2026-02-11T00:00:00', tipoDeclaracion: 'Original', medioPresentacion: 'Formulario Electr&oacute;nico', casos: 4 }] },
  { dj: 1948, titulo: 'Retiros', result: [{ estado: 'Observada', folio: 32, fecha: '2026-03-29T22:08:15', tipoDeclaracion: 'Rectificatoria', casos: 1 }] },
] };
const dj = parsearDdjj(2026, detalle);
assert.equal(dj.estado, 'Con observadas');
const conMotivo = { data: [{ dj: 1879, titulo: 'Retenciones', result: [{ key: 5, estado: 'Observada', folio: 33 }] }] };
assert.deepEqual(declaracionesObservadas(conMotivo), [{ dj: 1879, key: 5 }]);
const obs = parsearDdjj(2026, conMotivo, { 5: [{ observacion: 'R000', descripcion: 'Diferencia  de retenciones', glosaObservacion: 'Monto no cuadra', orientacion: 'Revise el F29' }] });
assert.deepEqual(obs.declaraciones[0].observaciones, [{ codigo: 'R000', descripcion: 'Diferencia de retenciones', motivo: 'Monto no cuadra', orientacion: 'Revise el F29' }]);
assert.deepEqual(parsearDdjj(2026, conMotivo).declaraciones[0].observaciones, []);
assert.deepEqual(dj.declaraciones.map((d) => d.presentada), ['11/02/2026', '29/03/2026 22:08']);
assert.equal(dj.declaraciones[0].medio, 'Formulario Electrónico');
assert.equal(parsearDdjj(2026, { data: [detalle.data[0]] }).estado, 'Todas aceptadas');
assert.equal(parsearDdjj(2026, { data: [detalle.data[0], { dj: 1887, titulo: 'Sueldos', result: [{ estado: 'Anulada', folio: 99 }] }] }).estado, 'Todas aceptadas');
assert.equal(parsearDdjj(2026, { data: [] }).estado, 'Sin DJ presentadas');
assert.equal(fechaHora('29/04/2026'), '29/04/2026');
assert.equal(limpiar('  a   &#243; b '), 'a ó b');

// 4. Funcionalidad extra: el agente declara F22 y DDJJ al pedir tareas, y se puede desactivar en un PC.
const base = { SII_URL_SERVIDOR: 'https://qlc.cl', SII_TOKEN_AGENTE: 't' };
assert.deepEqual(configurar(base).funciones, ['f22', 'ddjj']);
assert.deepEqual(configurar({ ...base, SII_CONSULTAS_RENTA: 'false' }).funciones, []);
const cabeceras = [];
const fetchFalso = async (_url, opciones) => { cabeceras.push(opciones.headers); return { ok: true, status: 200, json: async () => ({ tareas: [] }) }; };
const pendientes = [crearServidor({ url: 'https://qlc.cl', token: 't', funciones: ['f22', 'ddjj'], fetchImpl: fetchFalso }),
  crearServidor({ url: 'https://qlc.cl', token: 't', fetchImpl: fetchFalso })].map((s) => s.pendientes());

// 5. Tarea de consulta: usa la clave que entrega Cifrax y envía el resultado; un fallo queda en la tarea.
(async () => {
  await Promise.all(pendientes);
  assert.equal(cabeceras[0]['X-Agente-Funciones'], 'f22,ddjj');
  assert.equal(cabeceras[1]['X-Agente-Funciones'], undefined);
  const enviados = [];
  const fallos = [];
  const servidor = { resultadoConsulta: async (id, r) => enviados.push([id, r]), fallar: async (id, m) => fallos.push([id, m]) };
  const consultas = { f22: async ({ rut, clave, anio }) => ({ tipo: 'f22', anio, estado: 'Aceptada', rut, largo: clave.length }),
    ddjj: async () => { throw new Error('pantalla distinta'); } };
  const ok = await procesarTarea({ servidor, claves: {}, consultas, log: () => {}, tarea: { id: 7, tipo: 'f22', periodo: '2026', rut: '76.000.000-0', clave: 'abc' } });
  assert.equal(ok.ok, true);
  assert.deepEqual(enviados, [[7, { tipo: 'f22', anio: 2026, estado: 'Aceptada', rut: '76000000-0', largo: 3 }]]);
  await procesarTarea({ servidor, claves: {}, consultas, log: () => {}, tarea: { id: 8, tipo: 'ddjj', periodo: '2026', rut: '76000000-0', clave: 'abc' } });
  await procesarTarea({ servidor, claves: {}, consultas, log: () => {}, tarea: { id: 9, tipo: 'f22', periodo: '2026', rut: '76000000-0', clave: null } });
  assert.deepEqual(fallos.map(([id]) => id), [8, 9]);
  assert.match(fallos[0][1], /declaraciones juradas.*pantalla distinta/);
  assert.match(fallos[1][1], /Falta la clave/);
  console.log('Agente SII: lectura del F22 y de declaraciones juradas, y tareas de consulta verificadas');
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
