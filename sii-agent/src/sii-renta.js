'use strict';
// Funcionalidad extra del agente: consultas de renta, solo de lectura (Formulario 22 y declaraciones juradas).
// Usa el inicio y cierre de sesión de sii.js sin cambiar nada de la descarga del RCV.
const fs = require('node:fs');
const path = require('node:path');
const { SII, iniciarSesion, cerrarSesionSii, lanzarNavegador, trazar } = require('./sii');
const { parsearF22, parsearDdjj, declaracionesObservadas } = require('./consultas');

// Rutas verificadas en pantalla el 26-11-2026. Solo se usa "Consultar", "Formulario 22" y "Buscar": en la pantalla de
// declaraciones juradas hay botones "Anular" y "Rectificar" que nunca se tocan. Los botones se buscan por su acción o
// su texto visible: su nombre accesible incluye iconos y no calza exacto.
const RENTA = {
  f22: {
    url: 'https://www4.sii.cl/consultaestadof22ui/#!/default',
    anio: 'select',
    consultar: '[ng-click="vm.Consultar()"]',
    verF22: '[ng-click="vm.f22Completo()"]',
  },
  ddjj: {
    // La consulta de detalle (/djconsulta/consultaddjjs) redirige al resumen si se abre directo.
    estado: 'https://www2.sii.cl/djconsulta/estadoddjjs',
    anio: '#inputGroupSelect04',
    buscar: 'button:has-text("Buscar") >> visible=true',
    detalle: '/recurso/v1/dj/getDjIntegralAnualDetalle',
    observacion: '/recurso/v1/dj/getDjObservacion',
  },
};

/** Abre una sesión, ejecuta la consulta y cierra siempre la sesión. Si falla, deja una captura en descargas/. */
async function conSesion({ rut, clave, headless }, consulta) {
  const { chromium } = require('playwright');
  const navegador = await lanzarNavegador(chromium, headless);
  let page = null;
  try {
    page = await (await navegador.newContext()).newPage();
    await iniciarSesion(page, { rut, clave });
    return await consulta(page);
  } catch (e) {
    if (page) {
      const carpeta = path.resolve(__dirname, '..', 'descargas');
      fs.mkdirSync(carpeta, { recursive: true });
      const captura = path.join(carpeta, `falla-consulta-${Date.now()}.png`);
      await page.screenshot({ path: captura, fullPage: true }).then(() => trazar(`Captura de la pantalla con la falla: ${captura} (${page.url()})`)).catch(() => {});
    }
    throw e;
  } finally {
    await cerrarSesionSii(navegador);
    await navegador.close();
  }
}

// Una espera de respuesta que se abandona (porque falló el paso anterior) no debe botar el agente.
function esperaSegura(promesa) {
  promesa.catch(() => {});
  return promesa;
}

/** Respuesta JSON del servicio `nombre` que carga la página (por ejemplo "buscaEventos"). */
function respuestaDe(page, nombre, timeout = SII.tiempoMs) {
  return esperaSegura(page.waitForResponse((r) => r.url().split('?')[0].endsWith(`/${nombre}`) && r.ok(), { timeout }).then((r) => r.json()));
}

/** Estado del F22 del año tributario: situación, folio, eventos, observaciones y los códigos declarados. */
async function consultarF22({ rut, clave, anio, headless = true }) {
  return conSesion({ rut, clave, headless }, async (page) => {
    await page.goto(RENTA.f22.url, { timeout: SII.tiempoMs });
    await page.locator(RENTA.f22.anio).first().selectOption({ label: String(anio) }, { timeout: SII.tiempoMs });
    const folios = respuestaDe(page, 'consultaFolios');
    const secundarias = ['buscaDeclVgte', 'buscaEventos', 'situacionObservacion']
      .map((nombre) => respuestaDe(page, nombre, 30000).catch(() => null));
    await page.locator(RENTA.f22.consultar).first().click({ timeout: SII.tiempoMs });
    const consultaFolios = await folios;
    const [buscaDeclVgte, buscaEventos, situacionObservacion] = await Promise.all(secundarias);
    let f22Completo = null;
    if (consultaFolios?.data?.length) {
      const completo = respuestaDe(page, 'f22Completo', 30000).catch(() => null);
      await page.locator(RENTA.f22.verF22).first().click({ timeout: 30000 }).catch(() => trazar('No se pudo abrir el Formulario 22 completo.'));
      f22Completo = await completo;
    }
    trazar(`F22 AT ${anio} consultado.`);
    return parsearF22(anio, { consultaFolios, buscaDeclVgte, buscaEventos, situacionObservacion, f22Completo });
  });
}

/** Año tributario que pide el detalle de declaraciones juradas ({"periodo": 2026}). */
function periodoPedido(respuesta) {
  try {
    return Number(JSON.parse(respuesta.request().postData() || '{}').periodo);
  } catch {
    return 0;
  }
}

/**
 * Observaciones de las declaraciones observadas. Se piden al mismo servicio que usa la pantalla "Observaciones",
 * desde la página y con sus cabeceras, sin pulsar nada en la fila (al lado están "Anular" y "Rectificar").
 */
async function leerObservaciones(page, peticion, anio, observadas) {
  const salida = {};
  if (!observadas.length) return salida;
  const propias = await peticion.allHeaders();
  const cabeceras = Object.fromEntries(Object.entries(propias).filter(([k]) => ['content-type', 'accept', 'authorization'].includes(k) || k.startsWith('x-')));
  const url = peticion.url().replace(RENTA.ddjj.detalle, RENTA.ddjj.observacion);
  for (const { dj, key } of observadas) {
    try {
      const r = await page.evaluate(async ({ url, cabeceras, cuerpo }) => {
        const res = await fetch(url, { method: 'POST', headers: cabeceras, body: cuerpo });
        return { ok: res.ok, estado: res.status, texto: await res.text() };
      }, { url, cabeceras, cuerpo: JSON.stringify({ periodo: String(anio), dj, key }) });
      if (!r.ok) throw new Error(`HTTP ${r.estado}`);
      salida[key] = JSON.parse(r.texto);
      trazar(`Observación de la DJ ${dj} leída.`);
    } catch (e) {
      trazar(`No se pudo leer la observación de la DJ ${dj}: ${e.message}`);
    }
  }
  return salida;
}

/**
 * Declaraciones juradas anuales del año tributario con su estado, folio, fecha de presentación y observaciones.
 * El SII abre primero el resumen de los tres últimos años; al detalle se entra por el enlace del estado.
 */
async function consultarDdjj({ rut, clave, anio, headless = true }) {
  return conSesion({ rut, clave, headless }, async (page) => {
    const periodoActual = respuestaDe(page, 'getPeriodo').catch(() => null);
    const resumen = respuestaDe(page, 'getDjIntegralAnual');
    await page.goto(RENTA.ddjj.estado, { timeout: SII.tiempoMs });
    const declaradas = (await resumen)?.data || [];
    const ultimo = Number((await periodoActual) || new Date().getFullYear());
    const delAnio = declaradas.filter((d) => d.estados?.[String(anio)]);
    if (!delAnio.length && anio <= ultimo && anio >= ultimo - 2) {
      trazar(`Sin declaraciones juradas anuales en el AT ${anio}.`);
      return parsearDdjj(anio, { data: [] });
    }
    const esDetalle = (r) => r.url().includes(RENTA.ddjj.detalle) && r.ok();
    const selector = page.locator(RENTA.ddjj.anio);
    const conEnlace = delAnio[0] || declaradas[0];
    let respuesta = null;
    if (conEnlace) {
      const primero = esperaSegura(page.waitForResponse(esDetalle, { timeout: SII.tiempoMs }));
      const estado = conEnlace.estados?.[String(anio)] || Object.values(conEnlace.estados || {})[0];
      await page.locator('table a', { hasText: estado }).first().click({ timeout: SII.tiempoMs });
      respuesta = await primero;
    }
    if (!respuesta || periodoPedido(respuesta) !== anio) {
      await selector.selectOption({ label: String(anio) }, { timeout: SII.tiempoMs });
      const pedido = esperaSegura(page.waitForResponse((r) => esDetalle(r) && periodoPedido(r) === anio, { timeout: SII.tiempoMs }));
      await page.locator(RENTA.ddjj.buscar).first().click({ timeout: SII.tiempoMs });
      respuesta = await pedido;
    }
    const datos = await respuesta.json();
    const observaciones = await leerObservaciones(page, respuesta.request(), anio, declaracionesObservadas(datos));
    trazar(`Declaraciones juradas AT ${anio} consultadas.`);
    return parsearDdjj(anio, datos, observaciones);
  });
}

module.exports = { consultarF22, consultarDdjj, RENTA };
