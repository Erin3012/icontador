'use strict';
// Navegación en el SII con Playwright: inicia sesión con RUT y clave tributaria y descarga el "Detalle" del RCV.
// Las direcciones y los selectores están agrupados en SII para ajustarlos si el SII cambia su sitio.
// Solo este archivo habla con el SII; el resto del agente trabaja con el CSV descargado.
const fs = require('node:fs');
const path = require('node:path');

const SII = {
  login: 'https://zeusr.sii.cl/AUT2000/InicioAutenticacion/IngresoRutClave.html',
  // Consulta del Registro de Compra y Venta (página verificada en la exploración): pestañas COMPRA y VENTA.
  rcv: 'https://www4.sii.cl/consdcvinternetui/#/index',
  rcvPestana: { compras: 'COMPRA', ventas: 'VENTA' },
  selectores: {
    // Según la pantalla "Identificación de Contribuyentes": campo RUT con ejemplo "Ej: 123456789" (sin puntos ni guion)
    // y campo "Ingresar Clave Tributaria". Los ids de respaldo se verifican con el primer uso real.
    rut: '#rutcntr, input[name="rutcntr"], input[placeholder^="Ej"]',
    clave: '#clave, input[type="password"]',
    ingresar: '#bt_ingresar, button:has-text("INGRESAR")',
    empresaRcv: 'select[name="rut"]',
    mesRcv: '#periodoMes',
    anioRcv: 'select:has(option:text-is("2026"))',
    consultarRcv: 'button:has-text("Consultar")',
    // El botón de descarga aparece después de consultar; aún no verificado en pantalla.
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

/** Abre el navegador. Con SII_CANAL=chrome usa el Chrome instalado en el PC; si no, el Chromium de Playwright. */
function lanzarNavegador(chromium, headless) {
  const canal = String(process.env.SII_CANAL || '').trim();
  return chromium.launch(canal ? { headless, channel: canal } : { headless });
}

function trazar(mensaje) {
  console.log(`[SII ${new Date().toLocaleTimeString('es-CL')}] ${mensaje}`);
}

async function iniciarSesion(page, { rut, clave, manual = false }) {
  const canal = String(process.env.SII_CANAL || '').trim() || 'chromium (Playwright)';
  const rutEnviado = rutParaFormulario(rut);
  trazar(`Navegador: ${canal}. Página de login: ${SII.login}`);
  trazar(`RUT enviado: ${rutEnviado.slice(0, -1).replace(/./g, '*')}${rutEnviado.slice(-1)} (${rutEnviado.length} caracteres, sin guion). Clave: ${clave.length} caracteres (no se muestra).`);
  await page.goto(SII.login, { timeout: SII.tiempoMs });
  trazar(`Login abierto. URL: ${page.url()}`);
  await page.fill(SII.selectores.rut, rutEnviado);
  await page.fill(SII.selectores.clave, clave);
  trazar('Campos RUT y clave completados.');
  // Modo manual: el agente solo escribe las credenciales y espera a que tú presiones INGRESAR.
  if (!manual) {
    await page.click(SII.selectores.ingresar);
    trazar('INGRESAR presionado por el agente.');
  } else {
    console.log('Credenciales escritas. Presiona INGRESAR en la ventana del navegador (hay hasta 10 minutos).');
  }
  const inicio = Date.now();
  let ultimaUrl = page.url();
  const tope = manual ? 600000 : SII.tiempoMs;
  // Revisa cada 2 segundos para mostrar los cambios de página mientras esperas.
  while (Date.now() - inicio < tope) {
    await page.waitForTimeout(2000);
    if (page.url() !== ultimaUrl) {
      ultimaUrl = page.url();
      trazar(`Cambio de página: ${ultimaUrl}`);
    }
    if (!ultimaUrl.includes('IngresoRutClave')) break;
  }
  if (ultimaUrl.includes('IngresoRutClave')) {
    const error = await page.locator('.error, .mensaje-error, [role="alert"]').first().innerText({ timeout: 1000 }).catch(() => '');
    trazar(`Sigue en la página de login. Mensaje visible: ${error ? error.trim().slice(0, 200) : '(ninguno)'}`);
    throw new Error('No se pudo iniciar sesión en el SII. Revisa la clave tributaria en sii-agent/.env.');
  }
  // Si el SII muestra su propio mensaje de error (no es un rechazo de clave), lo mostramos tal cual.
  await page.waitForLoadState('domcontentloaded', { timeout: SII.tiempoMs }).catch(() => {});
  const texto = await page.innerText('body').catch(() => '');
  const codigo = /código de este mensaje es ([\d.]+)/i.exec(texto);
  trazar(`Página después del login: ${page.url()} | Texto (inicio): ${texto.replace(/\s+/g, ' ').trim().slice(0, 160)}`);
  if (/no se puede responder/i.test(texto)) {
    throw new Error(`El SII respondió con un error temporal${codigo ? ` (código ${codigo[1]})` : ''}. Espera unos minutos y vuelve a intentarlo.`);
  }
  if (/m[áa]ximo de sesiones/i.test(texto)) {
    throw new Error(`El SII tiene demasiadas sesiones abiertas para esta clave${codigo ? ` (código ${codigo[1]})` : ''}. Cierra sesión en el SII desde tu navegador (Cerrar Sesión) y cierra las ventanas del agente, luego espera unos minutos.`);
  }
  trazar('Login correcto.');
}

/** Descarga el detalle del RCV de un período y libro. Devuelve la ruta del CSV guardado en `carpeta`. */
async function descargarLibro({ rut, clave, periodo, libro, carpeta, headless = true }) {
  const { chromium } = require('playwright');
  fs.mkdirSync(carpeta, { recursive: true });
  const navegador = await lanzarNavegador(chromium, headless);
  try {
    const contexto = await navegador.newContext({ acceptDownloads: true });
    const page = await contexto.newPage();
    await iniciarSesion(page, { rut, clave });
    await page.goto(SII.rcv, { timeout: SII.tiempoMs });
    await page.getByText(SII.rcvPestana[libro], { exact: true }).first().click();
    const [anio, mes] = String(periodo).split('-');
    const empresa = `${String(rut).replace(/[^0-9kK]/g, '').slice(0, -1)}-${String(rut).slice(-1).toUpperCase()}`;
    await page.locator(SII.selectores.empresaRcv).first().selectOption({ label: empresa });
    const meses = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    await page.locator(SII.selectores.mesRcv).selectOption({ label: meses[Number(mes) - 1] });
    await page.locator(SII.selectores.anioRcv).first().selectOption({ label: anio });
    await page.click(SII.selectores.consultarRcv);
    const [descarga] = await Promise.all([
      page.waitForEvent('download', { timeout: SII.tiempoMs }).catch(() => {
        throw new Error('El SII no ofreció la descarga del detalle después de consultar. Revisa la pantalla y la sección Descargas Diferidas.');
      }),
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
  const navegador = await lanzarNavegador(chromium, headless);
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
  const navegador = await lanzarNavegador(chromium, headless);
  try {
    const page = await (await navegador.newContext()).newPage();
    await iniciarSesion(page, { rut, clave, manual: esperar });
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
