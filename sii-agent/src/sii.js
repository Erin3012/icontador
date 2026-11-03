'use strict';
// Navegación en el SII con Playwright: inicia sesión con RUT y clave tributaria y descarga el "Detalle" del RCV.
// Las direcciones y los selectores están agrupados en SII para ajustarlos si el SII cambia su sitio.
// Solo este archivo habla con el SII; el resto del agente trabaja con el CSV descargado.
const fs = require('node:fs');
const path = require('node:path');

const SII = {
  login: 'https://zeusr.sii.cl/AUT2000/InicioAutenticacion/IngresoRutClave.html',
  rcv: { compras: 'https://www4.sii.cl/rcvcompraInternet/', ventas: 'https://www4.sii.cl/rcvventainternet/' },
  selectores: {
    // Según la pantalla "Identificación de Contribuyentes": campo RUT con ejemplo "Ej: 123456789" (sin puntos ni guion)
    // y campo "Ingresar Clave Tributaria". Los ids de respaldo se verifican con el primer uso real.
    rut: '#rutcntr, input[name="rutcntr"], input[placeholder^="Ej"]',
    clave: '#clave, input[type="password"]',
    ingresar: '#bt_ingresar, button:has-text("INGRESAR")',
    periodo: 'select[name="periodo"]',
    descargarDetalle: 'text=Descargar Detalles',
  },
  // Datos del contribuyente (razón social, giro). Dirección y selectores sin verificar contra el SII real.
  datosContribuyente: 'https://www4.sii.cl/getdatosinternet/',
  selectoresEmpresa: { razon: '#razonSocial', giro: '#giro' },
  tiempoMs: 120000,
};

// "2026-10" -> "10-2026", el formato que muestra el selector de período del SII.
function periodoSii(periodo) {
  const [anio, mes] = String(periodo).split('-');
  return `${mes}-${anio}`;
}

/** RUT como lo pide el formulario del SII: solo dígitos y dígito verificador, sin puntos ni guion. */
function rutParaFormulario(rut) {
  return String(rut).replace(/[^0-9kK]/g, '').toUpperCase();
}

async function iniciarSesion(page, { rut, clave }) {
  await page.goto(SII.login, { timeout: SII.tiempoMs });
  await page.fill(SII.selectores.rut, rutParaFormulario(rut));
  await page.fill(SII.selectores.clave, clave);
  await page.click(SII.selectores.ingresar);
  try {
    await page.waitForURL((url) => !url.toString().includes('IngresoRutClave'), { timeout: SII.tiempoMs });
  } catch {
    throw new Error('No se pudo iniciar sesión en el SII. Revisa la clave tributaria en sii-agent/.env.');
  }
}

/** Descarga el detalle del RCV de un período y libro. Devuelve la ruta del CSV guardado en `carpeta`. */
async function descargarLibro({ rut, clave, periodo, libro, carpeta, headless = true }) {
  const { chromium } = require('playwright');
  fs.mkdirSync(carpeta, { recursive: true });
  const navegador = await chromium.launch({ headless });
  try {
    const contexto = await navegador.newContext({ acceptDownloads: true });
    const page = await contexto.newPage();
    await iniciarSesion(page, { rut, clave });
    await page.goto(SII.rcv[libro], { timeout: SII.tiempoMs });
    await page.selectOption(SII.selectores.periodo, periodoSii(periodo));
    const [descarga] = await Promise.all([
      page.waitForEvent('download', { timeout: SII.tiempoMs }),
      page.click(SII.selectores.descargarDetalle),
    ]);
    const destino = path.join(carpeta, `RCV_${libro.toUpperCase()}_${rut}_${periodo.replace('-', '')}_${Date.now()}.csv`);
    await descarga.saveAs(destino);
    return destino;
  } finally {
    await navegador.close();
  }
}

/** Lee razón social y giro del contribuyente con la clave del agente. No descarga nada. */
async function consultarEmpresa({ rut, clave, headless = true }) {
  const { chromium } = require('playwright');
  const navegador = await chromium.launch({ headless });
  try {
    const page = await (await navegador.newContext()).newPage();
    await iniciarSesion(page, { rut, clave });
    await page.goto(SII.datosContribuyente, { timeout: SII.tiempoMs });
    const razon_social = (await page.locator(SII.selectoresEmpresa.razon).first().innerText({ timeout: SII.tiempoMs })).trim();
    const giro = (await page.locator(SII.selectoresEmpresa.giro).first().innerText({ timeout: 5000 }).catch(() => '')).trim();
    return { razon_social, giro };
  } finally {
    await navegador.close();
  }
}

module.exports = { descargarLibro, consultarEmpresa, periodoSii, rutParaFormulario, SII };
