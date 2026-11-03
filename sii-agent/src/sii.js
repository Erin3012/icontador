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

/**
 * Modo exploración: inicia sesión y guarda en un JSON local lo que muestra la página después del login
 * (enlaces, campos, texto visible). No envía nada a Cifrax. Sirve para decidir qué extraer y ajustar selectores.
 */
async function explorarSii({ rut, clave, carpeta, headless = true, esperar = false }) {
  const { chromium } = require('playwright');
  fs.mkdirSync(carpeta, { recursive: true });
  const navegador = await chromium.launch({ headless });
  try {
    const page = await (await navegador.newContext()).newPage();
    await iniciarSesion(page, { rut, clave });
    await page.waitForLoadState('domcontentloaded', { timeout: SII.tiempoMs });
    if (esperar) {
      // Modo guiado: navegas a mano en la ventana (por ejemplo, al módulo RCV) y luego Enter para guardar esa página.
      const readline = require('node:readline');
      const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
      await new Promise((resolve) => rl.question('Navega a la pantalla que quieras guardar y pulsa Enter aquí... ', () => { rl.close(); resolve(); }));
      await page.waitForLoadState('domcontentloaded', { timeout: SII.tiempoMs }).catch(() => {});
    }
    const foto = {
      generado: new Date().toISOString(),
      url: page.url(),
      titulo: await page.title(),
      enlaces: await page.$$eval('a[href]', (as) => as.slice(0, 400).map((a) => ({ texto: (a.innerText || '').trim().slice(0, 120), href: a.href }))),
      campos: await page.$$eval('input, select, textarea, button', (els) => els.slice(0, 200).map((e) => ({
        tag: e.tagName, id: e.id, name: e.name, type: e.type, placeholder: e.placeholder || '',
        texto: (e.innerText || e.value || '').trim().slice(0, 80),
      }))),
      texto: (await page.innerText('body').catch(() => '')).slice(0, 20000),
    };
    const destino = path.join(carpeta, `explorar-${Date.now()}.json`);
    fs.writeFileSync(destino, JSON.stringify(foto, null, 2), 'utf8');
    return destino;
  } finally {
    await navegador.close();
  }
}

module.exports = { descargarLibro, consultarEmpresa, explorarSii, periodoSii, rutParaFormulario, SII };
