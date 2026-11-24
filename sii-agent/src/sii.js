'use strict';
// Navegación en el SII con Playwright: inicia sesión con RUT y clave tributaria y descarga el "Detalle" del RCV.
// Las direcciones y los selectores están agrupados en SII para ajustarlos si el SII cambia su sitio.
// Solo este archivo habla con el SII; el resto del agente trabaja con el CSV descargado.
const fs = require('node:fs');
const path = require('node:path');
const sesionesRcv = new WeakMap();

const SII = {
  logout: 'https://zeusr.sii.cl/cgi_AUT2000/autTermino.cgi',
  login: 'https://zeusr.sii.cl//AUT2000/InicioAutenticacion/IngresoRutClave.html?https://misiir.sii.cl/cgi_misii/siihome.cgi',
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
    consultarRcv: 'button:has-text("Consultar")',
    // El botón de descarga aparece después de consultar; aún no verificado en pantalla.
    descargarDetalle: 'text=Descargar Detalles',
  },
  // Perfil visible de MiSII, observado en la página de Datos Personales y Tributarios.
  datosContribuyente: 'https://misiir.sii.cl/cgi_misii/siihome.cgi#',
  selectoresEmpresa: {
    perfil: '#info_profile', regimen: '#box_user_info', datos: '#collapse2Cntrb',
    actividades: '#tblIdGiros', sucursales: '#direccionesVigentes', caracteristicas: '#collapse13Cntrb', documentos: '#tblUltimosDoc',
  },
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

/** Mantiene Chromium abierto en el agente GUI para reutilizar el proceso entre trabajos. */
async function crearNavegadorAgente({ headless = false, chromium: chromiumOverride = null } = {}) {
  const { chromium } = chromiumOverride ? { chromium: chromiumOverride } : require('playwright');
  const browser = await lanzarNavegador(chromium, headless);
  const paginaInactiva = headless ? null : await browser.newPage();
  return { browser, paginaInactiva, headless };
}

async function cerrarNavegadorAgente(sesion) {
  if (!sesion?.browser) return;
  await sesion.browser.close().catch(() => {});
  sesion.browser = null;
}

function trazar(mensaje) {
  console.log(`[SII ${new Date().toLocaleTimeString('es-CL')}] ${mensaje}`);
}

/** Cierra la sesión del SII al terminar, para no dejar sesiones abiertas (el SII bloquea si hay demasiadas). */
async function cerrarSesionSii(navegador) {
  const contextos = typeof navegador.contexts === 'function' ? navegador.contexts() : [navegador];
  for (const contexto of contextos) {
    for (const pagina of contexto.pages()) {
      await pagina.goto(SII.logout, { timeout: 15000 }).catch(() => {});
    }
  }
  trazar('Sesión del SII cerrada.');
}

/** Finaliza la sesión RCV reutilizable sin cerrar el proceso Chromium de la GUI. */
async function cerrarSesionRcv(navegador, log = trazar) {
  if (!navegador || !sesionesRcv.has(navegador)) return false;
  const sesion = sesionesRcv.get(navegador);
  sesionesRcv.delete(navegador);
  if (sesion.contexto) {
    await cerrarSesionSii(sesion.contexto).catch(() => {});
    await sesion.contexto.close().catch(() => {});
  }
  log('Sesión RCV cerrada; Chromium queda disponible.');
  return true;
}

async function iniciarSesion(page, { rut, clave, manual = false, onProgress = async () => {} }) {
  const canal = String(process.env.SII_CANAL || '').trim() || 'chromium (Playwright)';
  const rutEnviado = rutParaFormulario(rut);
  await onProgress('autenticando');
  trazar(`Navegador: ${canal}. Página de login: ${SII.login}`);
    trazar(`RUT enviado: ${rutEnviado.slice(0, -1).replace(/./g, '*')}${rutEnviado.slice(-1)} (${rutEnviado.length} caracteres, sin guion).`);
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
    throw new Error('No se pudo iniciar sesión en el SII. Verifica el acceso en la ventana del agente.');
  }
  // Si el SII muestra su propio mensaje de error (no es un rechazo de clave), lo mostramos tal cual.
  await page.waitForLoadState('domcontentloaded', { timeout: SII.tiempoMs }).catch(() => {});
  const texto = await page.innerText('body').catch(() => '');
  const codigo = /código de este mensaje es ([\d.]+)/i.exec(texto);
  // No registrar texto de MiSII: el encabezado puede incluir RUT, nombre y datos de contacto.
  trazar(`Página después del login: ${page.url()}`);
  if (/no se puede responder/i.test(texto)) {
    throw new Error(`El SII respondió con un error temporal${codigo ? ` (código ${codigo[1]})` : ''}. Espera unos minutos y vuelve a intentarlo.`);
  }
  if (/m[áa]ximo de sesiones/i.test(texto)) {
    throw new Error(`El SII tiene demasiadas sesiones abiertas para esta clave${codigo ? ` (código ${codigo[1]})` : ''}. Cierra sesión en el SII desde tu navegador (Cerrar Sesión) y cierra las ventanas del agente, luego espera unos minutos.`);
  }
  await descartarAvisoActualizacion(page);
  await onProgress('sesion_lista');
  trazar('Login correcto.');
}

/** MiSII bloquea la página con el aviso de contacto hasta elegir ACTUALIZAR MÁS TARDE. */
async function descartarAvisoActualizacion(page, log = trazar) {
  const expresion = /ACTUALIZAR\s+MÁS\s+TARDE/i;
  let boton = page.getByRole('button', { name: expresion }).first();
  let visible = await boton.isVisible().catch(() => false);
  if (!visible) {
    boton = page.getByText(expresion).first();
    visible = await boton.waitFor({ state: 'visible', timeout: 8000 }).then(() => true).catch(() => false);
  }
  if (!visible) return false;
  await boton.click({ timeout: 8000 });
  await boton.waitFor({ state: 'hidden', timeout: 5000 }).catch(() => {});
  log('Aviso de datos de contacto MiSII cerrado con ACTUALIZAR MÁS TARDE.');
  return true;
}

function normalizarEtiquetaRcv(texto) {
  return String(texto || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/\s+/g, ' ').trim().toUpperCase();
}

/** Lee la pestaña seleccionada por el marcado de navegación del módulo SII. */
async function pestanaRcvActiva(page) {
  return page.evaluate(() => {
    const etiquetas = new Map([['COMPRA', 'compras'], ['VENTA', 'ventas']]);
    const nodos = [...document.querySelectorAll('a,button,[role="tab"],li,[aria-selected]')];
    for (const nodo of nodos) {
      const texto = (nodo.innerText || nodo.textContent || '').replace(/\s+/g, ' ').trim().normalize('NFD').replace(/[\u0300-\u036f]/g, '').toUpperCase();
      const libro = etiquetas.get(texto);
      if (!libro) continue;
      let actual = nodo;
      for (let i = 0; actual && i < 3; i++, actual = actual.parentElement) {
        const clases = typeof actual.className === 'string' ? actual.className : '';
        if (actual.getAttribute('aria-selected') === 'true' || actual.getAttribute('aria-current') === 'page'
          || /(?:^|\s)(?:active|selected|tab-active)(?:\s|$)/i.test(clases)) return libro;
      }
    }
    const hash = window.location.hash.toLowerCase();
    if (/venta/.test(hash)) return 'ventas';
    if (/compra/.test(hash)) return 'compras';
    return '';
  }).catch(() => '');
}

/** Elige la pestaña real del módulo y comprueba su estado; no construye rutas hash supuestas. */
async function seleccionarPestanaRcv(page, libro, onProgress = async () => {}) {
  const nombre = SII.rcvPestana[libro];
  if (!nombre) throw new Error('Libro RCV inválido; no se seleccionó ninguna pestaña.');
  let activa = await pestanaRcvActiva(page);
  if (activa === libro) return;
  const objetivo = normalizarEtiquetaRcv(nombre);
  const textos = page.getByText(nombre, { exact: true });
  const cantidad = await textos.count().catch(() => 0);
  for (let i = 0; i < cantidad; i++) {
    const candidato = textos.nth(i);
    if (await candidato.isVisible().catch(() => false)) {
      await candidato.click({ timeout: 8000 });
    } else {
      await candidato.evaluate((elemento, textoObjetivo) => {
      const normalizar = (s) => String(s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/\s+/g, ' ').trim().toUpperCase();
      let control = elemento;
      for (let i = 0; control && i < 5; i++, control = control.parentElement) {
        if (control.matches('a,button,[role="tab"]') && normalizar(control.innerText || control.textContent) === textoObjetivo) {
          control.click();
          return true;
        }
      }
      // El SII también ha servido pestañas dentro de navegación colapsada con el listener en un contenedor.
      elemento.click();
      return true;
      }, objetivo).catch(() => false);
    }
    for (let intento = 0; intento < 20; intento++) {
      activa = await pestanaRcvActiva(page);
      if (activa === libro) break;
      await page.waitForTimeout(250);
    }
    if (activa === libro) {
      trazar(`Pestaña ${nombre} seleccionada y verificada.`);
      await onProgress('seleccionando_libro');
      return;
    }
  }
  if (!cantidad) throw new Error(`No se encontró un control del SII para seleccionar ${nombre}; no se descargó ningún archivo.`);
  throw new Error(`El SII no confirmó la pestaña ${nombre}; no se descargó ningún archivo.`);
}

async function cerrarModalAlertaSii(page) {
  const modal = page.locator('#alert-modal');
  if (!await modal.isVisible().catch(() => false)) return false;
  const texto = (await modal.innerText().catch(() => '')).replace(/\s+/g, ' ').trim();
  trazar(`Aviso del SII bloquea la descarga: ${texto.slice(0, 220) || 'mensaje sin texto'}.`);
  const botonTexto = modal.getByRole('button', { name: /aceptar|cerrar|entendido|continuar|^ok$/i }).first();
  const botonCierre = modal.locator('.close, [data-dismiss="modal"], [aria-label="Cerrar"], [aria-label="Close"]').first();
  if (await botonTexto.isVisible().catch(() => false)) await botonTexto.click({ timeout: 5000 });
  else if (await botonCierre.isVisible().catch(() => false)) await botonCierre.click({ timeout: 5000 });
  else await page.keyboard.press('Escape').catch(() => {});
  await modal.waitFor({ state: 'hidden', timeout: 5000 }).catch(() => {
    throw new Error(`El SII mostró un aviso que bloquea la descarga y no se pudo cerrar: ${texto.slice(0, 180) || 'sin texto'}`);
  });
  trazar('Aviso modal del SII cerrado; se continuará con el resumen de ventas.');
  return true;
}

/** Espera a que termine la consulta asíncrona del SII antes de pedir el archivo. */
async function esperarConsultaRcv(page, periodo, libro) {
  const indicador = page.getByText(/consultando información/i).first();
  const aparecio = typeof indicador.waitFor === 'function'
    && await indicador.waitFor({ state: 'visible', timeout: 3000 }).then(() => true).catch(() => false);
  if (aparecio) {
    await indicador.waitFor({ state: 'hidden', timeout: SII.tiempoMs }).catch(() => {
      throw new Error(`El SII dejó la consulta de ${SII.rcvPestana[libro]} · ${periodo} cargando; no se solicitó la descarga.`);
    });
  } else {
    await page.waitForTimeout(1000);
  }
}

/** Espera a que desaparezca cualquier capa modal que impida pulsar el formulario. */
async function esperarCapaRcvLibre(page) {
  const capa = page.locator('.modal-backdrop').first();
  const existe = await capa.count().catch(() => 0);
  if (!existe) return;
  await capa.waitFor({ state: 'hidden', timeout: 15000 }).catch(() => {
    throw new Error('El SII mantiene una ventana modal sobre el Registro de Compras y Ventas; no se pudo continuar con el formulario.');
  });
}

/** Detecta cuando el SII devuelve al formulario de acceso en vez de abrir el módulo RCV. */
async function esperarRcvORechazoAcceso(page) {
  const formularioRcv = page.locator(SII.selectores.empresaRcv).first();
  const formularioAcceso = page.locator(SII.selectores.rut).first();
  const resultado = await new Promise((resolve) => {
    let pendientes = 2;
    const fallida = () => { if (--pendientes === 0) resolve(''); };
    formularioRcv.waitFor({ state: 'visible', timeout: 30000 }).then(() => resolve('rcv')).catch(fallida);
    formularioAcceso.waitFor({ state: 'visible', timeout: 30000 }).then(() => resolve('acceso')).catch(fallida);
  });
  if (resultado === 'acceso') {
    throw new Error('El SII devolvió al formulario de ingreso. Verifica el RUT y la Clave Tributaria; no se descargó ningún archivo.');
  }
  if (resultado !== 'rcv') await formularioRcv.waitFor({ state: 'visible', timeout: SII.tiempoMs });
}

/** Descarga el detalle del RCV de un período y libro. Devuelve la ruta del CSV guardado en `carpeta`. */
async function descargarLibro({ rut, clave, periodo, libro, carpeta, headless = true, signal, browser: navegadorExistente = null, playwright: playwrightOverride = null, onProgress = async () => {}, onFailure = async () => {} }) {
  const { chromium } = playwrightOverride || require('playwright');
  fs.mkdirSync(carpeta, { recursive: true });
  const propio = !navegadorExistente;
  const navegador = navegadorExistente || await lanzarNavegador(chromium, headless);
  let contexto = null;
  let pagina = null;
  let sesionReutilizada = false;
  let autenticada = false;
  let invalidarSesion = false;
  const rutNormalizado = rutParaFormulario(rut);
  const abortar = () => {
    invalidarSesion = true;
    if (navegadorExistente) sesionesRcv.delete(navegador);
    return contexto ? contexto.close().catch(() => {}) : (propio ? navegador.close().catch(() => {}) : undefined);
  };
  signal?.addEventListener('abort', abortar, { once: true });
  try {
    if (signal?.aborted) throw new Error('Tarea interrumpida.');
    let sesion = navegadorExistente ? sesionesRcv.get(navegador) : null;
    if (sesion && sesion.rut !== rutNormalizado) {
      trazar('La cola cambió de empresa; cerrando la sesión SII anterior antes de continuar.');
      await cerrarSesionRcv(navegador);
      sesion = null;
    }
    if (sesion) {
      try { contexto = sesion.contexto; pagina = sesion.pagina; if (pagina.isClosed()) throw new Error('Página cerrada'); sesionReutilizada = true; autenticada = true; }
      catch { sesionesRcv.delete(navegador); contexto = null; pagina = null; sesion = null; }
    }
    if (!contexto) {
      contexto = await navegador.newContext({ acceptDownloads: true });
      pagina = await contexto.newPage();
      invalidarSesion = Boolean(navegadorExistente);
      await iniciarSesion(pagina, { rut, clave, onProgress });
      autenticada = true;
      invalidarSesion = false;
    } else {
      trazar(`Reutilizando la sesión SII para el mismo contribuyente (${rutNormalizado.slice(0, -1).replace(/./g, '*')}${rutNormalizado.slice(-1)}).`);
      await onProgress('sesion_lista');
    }
    await onProgress('abriendo_rcv');
    await pagina.goto(SII.rcv, { timeout: SII.tiempoMs });
    await esperarRcvORechazoAcceso(pagina);
    if (sesionReutilizada && /IngresoRutClave/i.test(pagina.url())) {
      trazar('La sesión SII venció; se volverá a autenticar para esta tarea.');
      invalidarSesion = true;
      autenticada = false;
      sesionesRcv.delete(navegador);
      await contexto.close().catch(() => {});
      contexto = await navegador.newContext({ acceptDownloads: true });
      pagina = await contexto.newPage();
      await iniciarSesion(pagina, { rut, clave, onProgress });
      autenticada = true;
      invalidarSesion = false;
      sesionReutilizada = false;
      sesionesRcv.set(navegador, { rut: rutNormalizado, contexto, pagina });
      await pagina.goto(SII.rcv, { timeout: SII.tiempoMs });
      await esperarRcvORechazoAcceso(pagina);
    }
    await pagina.locator(SII.selectores.empresaRcv).first().waitFor({ state: 'visible', timeout: SII.tiempoMs });
    await onProgress('seleccionando_empresa');
    const [anio, mes] = String(periodo).split('-');
    const rutLimpio = rutParaFormulario(rut);
    const empresa = `${rutLimpio.slice(0, -1)}-${rutLimpio.slice(-1)}`;
    await pagina.locator(SII.selectores.empresaRcv).first().selectOption({ label: empresa });
    const meses = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    await pagina.locator(SII.selectores.mesRcv).selectOption({ label: meses[Number(mes) - 1] });
    await pagina.locator(`select:has(option:text-is("${anio}"))`).first().selectOption({ label: anio });
    await onProgress('configurando_periodo');
    // El SII consulta primero el registro de COMPRA por defecto y crea las pestañas
    // con el resultado. Elegir VENTA antes de consultar deja una carga modal activa.
    await esperarCapaRcvLibre(pagina);
    await onProgress('consultando');
    await pagina.click(SII.selectores.consultarRcv);
    await esperarConsultaRcv(pagina, periodo, 'compras');
    let libroActivo = await pestanaRcvActiva(pagina);
    if (libroActivo !== libro) {
      trazar(`Consulta inicial completada (${libroActivo || 'pestaña desconocida'}); cambiando a ${SII.rcvPestana[libro]}.`);
      await seleccionarPestanaRcv(pagina, libro, onProgress);
      await esperarConsultaRcv(pagina, periodo, libro);
      libroActivo = await pestanaRcvActiva(pagina);
    }
    if (libroActivo !== libro) {
      throw new Error(`El SII no mantuvo activa la pestaña ${SII.rcvPestana[libro]} después de consultar; no se descargó ningún archivo.`);
    }
    const cuerpo = await pagina.innerText('body').catch(() => '');
    const sinDocumentos = libro === 'ventas'
      ? /no hay información de ventas/i
      : /no hay información de (?:compras|registro)/i;
    if (sinDocumentos.test(cuerpo)) {
      trazar(`El SII confirma que no hay documentos de ${libro} para ${periodo}.`);
      return { sinDocumentos: true, kind: libro, period: periodo, fileName: `RCV_SIN_DOCUMENTOS_${libro.toUpperCase()}_${periodo.replace('-', '')}.csv` };
    }
    const tieneResumen48 = libro === 'ventas' && /total mes comprobantes pago electrónico\s*\(48\)/i.test(cuerpo);
    const descargarResumenVentas = async (boton) => {
      await cerrarModalAlertaSii(pagina);
      trazar(`El SII no entregó detalle de ventas para ${periodo}; descargando el resumen agregado de comprobantes de pago electrónico (48).`);
      await onProgress('descargando');
      const [descarga] = await Promise.all([
        pagina.waitForEvent('download', { timeout: SII.tiempoMs }).catch(() => { throw new Error('El SII no ofreció la descarga del resumen mensual de ventas.'); }),
        boton.click(),
      ]);
      const destino = path.join(carpeta, `RCV_RESUMEN_VENTA_${empresa}_${periodo.replace('-', '')}_${Date.now()}.csv`);
      await descarga.saveAs(destino);
      return destino;
    };
    let botonResumen48 = null;
    if (tieneResumen48) {
      const encontrarVisible = async (locator) => {
        const cantidad = await locator.count().catch(() => 0);
        for (let i = 0; i < cantidad; i++) {
          const candidato = locator.nth(i);
          if (await candidato.isVisible().catch(() => false)) return candidato;
        }
        return null;
      };
      botonResumen48 = await encontrarVisible(pagina.getByText(/descargar res[uú]menes/i));
      const botonDetalle = await encontrarVisible(pagina.getByText(/descargar detalles/i));
      if (!botonDetalle && botonResumen48) return await descargarResumenVentas(botonResumen48);
      if (botonDetalle) trazar('El RCV muestra el botón de detalle; se intentará primero la descarga individual de ventas.');
      else throw new Error(`El SII muestra comprobantes de pago electrónico (48), pero no aparece un botón visible de descarga para ${periodo}.`);
    }
    trazar(`Consulta RCV confirmada: ${SII.rcvPestana[libro]} · ${periodo}.`);
    await onProgress('descargando');
    try {
      const [descarga] = await Promise.all([
        pagina.waitForEvent('download', { timeout: tieneResumen48 && botonResumen48 ? 20000 : SII.tiempoMs }).catch(() => {
          throw new Error('El SII no ofreció la descarga del detalle después de consultar. Revisa la pantalla y la sección Descargas Diferidas.');
        }),
        pagina.click(SII.selectores.descargarDetalle),
      ]);
      const destino = path.join(carpeta, `RCV_${libro.toUpperCase()}_${empresa}_${periodo.replace('-', '')}_${Date.now()}.csv`);
      await descarga.saveAs(destino);
      return destino;
    } catch (error) {
      if (tieneResumen48 && botonResumen48) return await descargarResumenVentas(botonResumen48);
      throw error;
    }
  } catch (error) {
    // Solo capturar la pantalla del módulo RCV ya autenticado: nunca el login ni el formulario de clave.
    if (autenticada && pagina && !pagina.isClosed() && /\/consdcvinternetui(?:\/|#|$)/i.test(pagina.url())) {
      try {
        const jpeg = await pagina.screenshot({ type: 'jpeg', quality: 55, fullPage: false, animations: 'disabled', timeout: 8000 });
        await onFailure(jpeg, pagina).catch(() => {});
      } catch {}
    }
    throw error;
  } finally {
    signal?.removeEventListener('abort', abortar);
    if (navegadorExistente && pagina?.isClosed()) invalidarSesion = true;
    if (navegadorExistente) {
      if (invalidarSesion || signal?.aborted) {
        sesionesRcv.delete(navegador);
        if (contexto) {
          if (!signal?.aborted) await cerrarSesionSii(contexto);
          await contexto.close().catch(() => {});
        }
      } else if (contexto && autenticada && !sesionReutilizada && !sesionesRcv.has(navegador)) {
        sesionesRcv.set(navegador, { rut: rutNormalizado, contexto, pagina });
      }
    } else {
      if (!signal?.aborted && contexto) await cerrarSesionSii(contexto);
      await contexto?.close().catch(() => {});
    }
    if (propio) await navegador.close().catch(() => {});
  }
}

/** Descarga el informe mensual de boletas recibidas o emitidas desde el menú de Honorarios del SII. */
async function descargarBoletasHonorarios({ rut, clave, periodo, libro, carpeta, headless = true, signal, browser: navegadorExistente = null, playwright: playwrightOverride = null, onProgress = async () => {}, onFailure = async () => {}, onFormReady = async () => {}, onCheckpoint = async () => {} }) {
  if (!['recibidas', 'emitidas'].includes(libro)) throw new Error('Libro de honorarios inválido; usa recibidas o emitidas.');
  const { chromium } = playwrightOverride || require('playwright');
  fs.mkdirSync(carpeta, { recursive: true });
  const propio = !navegadorExistente;
  const navegador = navegadorExistente || await lanzarNavegador(chromium, headless);
  let contexto = null, pagina = null, autenticada = false, sesionReutilizada = false, invalidarSesion = false;
  const rutNormalizado = rutParaFormulario(rut);
  const abortar = () => {
    invalidarSesion = true;
    if (navegadorExistente) sesionesRcv.delete(navegador);
    return contexto ? contexto.close().catch(() => {}) : (propio ? navegador.close().catch(() => {}) : undefined);
  };
  signal?.addEventListener('abort', abortar, { once: true });
  try {
    if (signal?.aborted) throw new Error('Tarea interrumpida.');
    let sesion = navegadorExistente ? sesionesRcv.get(navegador) : null;
    if (sesion && sesion.rut !== rutNormalizado) {
      await cerrarSesionRcv(navegador);
      sesion = null;
    }
    if (sesion) {
      contexto = sesion.contexto; pagina = sesion.pagina; sesionReutilizada = true; autenticada = true;
      if (pagina.isClosed()) throw new Error('La sesión del SII fue cerrada.');
      await onProgress('sesion_lista');
    } else {
      contexto = await navegador.newContext({ acceptDownloads: true });
      pagina = await contexto.newPage();
      invalidarSesion = Boolean(navegadorExistente);
      await iniciarSesion(pagina, { rut, clave, onProgress });
      autenticada = true;
      invalidarSesion = false;
    }
    await onProgress('abriendo_honorarios');
    await pagina.goto('https://www.sii.cl/servicios_online/1040-1287.html', { timeout: SII.tiempoMs, waitUntil: 'domcontentloaded' });
    const nombreConsulta = libro === 'recibidas' ? /consultar boletas recibidas/i : /consultar boletas emitidas/i;
    let enlace = pagina.getByRole('link', { name: nombreConsulta }).first();
    if (!await enlace.isVisible().catch(() => false)) {
      const seccionConsultas = pagina.getByText(/consultas sobre boletas de honorarios electr[oó]nicas/i).first();
      await seccionConsultas.waitFor({ state: 'visible', timeout: 15000 }).catch(() => {});
      if (await seccionConsultas.isVisible().catch(() => false)) await seccionConsultas.click({ timeout: 10000 });
    }
    await enlace.waitFor({ state: 'visible', timeout: 20000 }).catch(() => {
      throw new Error(`No apareció en el menú del SII la opción de boletas ${libro}.`);
    });
    const destino = await enlace.getAttribute('href').catch(() => '');
    if (destino && destino !== '#' && !/^javascript:/i.test(destino)) {
      await pagina.goto(new URL(destino, pagina.url()).href, { timeout: SII.tiempoMs, waitUntil: 'domcontentloaded' });
    } else {
      await enlace.click({ timeout: 12000 });
      await pagina.waitForLoadState('domcontentloaded', { timeout: 20000 }).catch(() => {});
    }
    if (/IngresoRutClave/i.test(pagina.url())) throw new Error('El SII pidió iniciar sesión nuevamente al abrir Honorarios.');
    await onFormReady({ page: pagina, libro, periodo });
    const [anio, mes] = String(periodo).split('-');
    const mesNombre = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'][Number(mes) - 1];
    // La consulta tiene formularios/filas separados para anual, mensual y diario.
    // Los nombres cb*informemensual identifican explícitamente el formulario mensual.
    const mesMensual = pagina.locator('select[name="cbmesinformemensual"]');
    const anioMensual = pagina.locator('select[name="cbanoinformemensual"]');
    await mesMensual.waitFor({ state: 'visible', timeout: 15000 }).catch(() => {
      throw new Error(`No apareció la fila del informe mensual de boletas ${libro} en el SII.`);
    });
    await anioMensual.waitFor({ state: 'visible', timeout: 10000 }).catch(() => {
      throw new Error(`No apareció el selector de año del informe mensual de boletas ${libro}.`);
    });
    const seleccionarOpcion = async (select, valor, alias, esMes) => {
      const opciones = await select.locator('option').evaluateAll(items => items.map(el => ({ value: el.value, text: el.textContent.trim() })));
      const opcion = opciones.find(o => esMes
        ? o.text.toLocaleLowerCase('es-CL').includes(alias) || o.value === valor || o.value === String(Number(valor))
        : o.text.trim() === valor || o.value === valor);
      if (!opcion) return false;
      await select.selectOption(opcion.value !== '' ? opcion.value : { label: opcion.text });
      return true;
    };
    await onProgress('configurando_periodo');
    if (!await seleccionarOpcion(mesMensual, mes, mesNombre, true)) throw new Error(`No se encontró selector mensual para el mes ${mesNombre} en Honorarios.`);
    if (!await seleccionarOpcion(anioMensual, anio, '', false)) throw new Error(`No se encontró selector mensual para el año ${anio} en Honorarios.`);
    await onProgress('consultando');
    // En el SII hay dos botones cmdconsultar1: mensual aparece antes que diario.
    const consultar = pagina.locator('input[name="cmdconsultar1"]').first();
    await consultar.waitFor({ state: 'visible', timeout: 15000 });
    await consultar.click({ timeout: 15000 });
    await pagina.waitForTimeout(1200);
    await onCheckpoint({ page: pagina, libro, periodo });
    const cuerpo = (await pagina.locator('body').innerText().catch(() => '')).replace(/\s+/g, ' ').trim();
    if (/no hay (?:boletas|informaci[oó]n)|sin (?:boletas|informaci[oó]n)|no existen boletas|no se encontraron boletas/i.test(cuerpo)) {
      trazar(`El SII confirma que no hay boletas ${libro} para ${periodo}.`);
      const base = rutNormalizado.replace(/[^0-9kK]/g, '');
      return { sinDocumentos: true, kind: libro, period: periodo, fileName: `BHE_${libro.toUpperCase()}_${base}_${periodo.replace('-', '')}.xls` };
    }
    const descargaLocator = pagina.getByRole('button', { name: /descargar|planilla|excel/i }).or(pagina.getByRole('link', { name: /descargar|planilla|excel/i })).first();
    await descargaLocator.waitFor({ state: 'visible', timeout: 20000 }).catch(() => {
      throw new Error(`El SII no mostró la descarga del informe mensual de boletas ${libro} para ${periodo}.`);
    });
    await onProgress('descargando');
    const [descarga] = await Promise.all([
      pagina.waitForEvent('download', { timeout: SII.tiempoMs }).catch(() => { throw new Error('El SII no inició la descarga del informe mensual de Honorarios.'); }),
      descargaLocator.click({ timeout: 15000 }),
    ]);
    const base = rutNormalizado.replace(/[^0-9kK]/g, '');
    const destinoArchivo = path.join(carpeta, `BHE_${libro.toUpperCase()}_${base}_${periodo.replace('-', '')}_${Date.now()}.xls`);
    await descarga.saveAs(destinoArchivo);
    trazar(`Informe mensual de boletas ${libro} descargado para ${periodo}.`);
    return destinoArchivo;
  } catch (error) {
    if (autenticada && pagina && !pagina.isClosed() && !/IngresoRutClave/i.test(pagina.url())) {
      try { await onFailure(await pagina.screenshot({ type: 'jpeg', quality: 55, fullPage: false, animations: 'disabled', timeout: 8000 }), pagina); } catch {}
    }
    if (error instanceof Error) throw error;
    throw new Error('Falló la navegación de boletas de honorarios en el SII.');
  } finally {
    signal?.removeEventListener('abort', abortar);
    if (navegadorExistente) {
      if (invalidarSesion || signal?.aborted || pagina?.isClosed()) {
        sesionesRcv.delete(navegador);
        if (contexto) { if (!signal?.aborted) await cerrarSesionSii(contexto); await contexto.close().catch(() => {}); }
      } else if (contexto && autenticada && !sesionReutilizada && !sesionesRcv.has(navegador)) {
        sesionesRcv.set(navegador, { rut: rutNormalizado, contexto, pagina });
      }
    } else {
      if (!signal?.aborted && contexto) await cerrarSesionSii(contexto);
      await contexto?.close().catch(() => {});
    }
    if (propio) await navegador.close().catch(() => {});
  }
}

function etiqueta(texto, nombre) {
  const lineas = String(texto || '').split(/\r?\n/).map((x) => x.replace(/\s+/g, ' ').trim()).filter(Boolean);
  const buscado = nombre.toLocaleLowerCase('es-CL');
  const i = lineas.findIndex((linea) => {
    const normal = linea.toLocaleLowerCase('es-CL');
    return normal === buscado || normal.startsWith(`${buscado}:`);
  });
  if (i < 0) return '';
  const inline = lineas[i].split(':').slice(1).join(':').trim();
  return inline || lineas[i + 1] || '';
}

function filasTabla(filas, campos) {
  return filas.map((celdas) => Object.fromEntries(campos.map((campo, i) => [campo, String(celdas[i] || '').trim()]))).filter((fila) => Object.values(fila).some(Boolean));
}

async function abrirAcordeon(page, encabezado, contenido, timeout = 10000) {
  const panel = page.locator(contenido).first();
  if (await panel.isVisible().catch(() => false)) return;
  const id = contenido.replace(/^#/, '');
  const candidatos = page.locator(`${encabezado} a, ${encabezado} button, ${encabezado} [role="button"], [href="#${id}"], [data-target="#${id}"], [aria-controls="${id}"]`);
  for (let i = 0, n = await candidatos.count(); i < n; i++) {
    const control = candidatos.nth(i);
    if (await control.isVisible().catch(() => false) && await control.isEnabled().catch(() => false)) {
      await control.click({ timeout: 8000 });
      await panel.waitFor({ state: 'visible', timeout });
      return;
    }
  }

  // Algunas versiones de MiSII dejan el encabezado marcado como oculto, aunque
  // el control del acordeón siga conectado al evento de Bootstrap. En ese caso
  // activar el enlace interno y comprobar que el contenido realmente se abrió.
  const encabezadoNode = page.locator(encabezado).first();
  const activado = await encabezadoNode.evaluate((el) => {
    const control = el.matches('a,button,[role="button"]') ? el : el.querySelector('a,button,[role="button"]');
    if (!control) return false;
    control.click();
    return true;
  }).catch(() => false);
  if (!activado) throw new Error(`No encontré un control utilizable para abrir la sección MiSII (${id}).`);
  await panel.waitFor({ state: 'visible', timeout }).catch(() => {
    throw new Error(`MiSII no abrió la sección ${id}; la pantalla del contribuyente puede haber cambiado.`);
  });
}

/** Las secciones auxiliares pueden no existir para todos los contribuyentes; no invalidan el perfil principal. */
async function leerTablaOpcional(page, encabezado, selector, campos, nombre, log = trazar) {
  try {
    const tabla = page.locator(selector).first();
    if (!(await tabla.isVisible().catch(() => false))) await abrirAcordeon(page, encabezado, selector, 3500);
    if (!(await tabla.isVisible().catch(() => false))) throw new Error('La tabla sigue oculta.');
    const filas = await page.locator(`${selector} tbody tr`).evaluateAll((rows) => rows.map((row) => Array.from(row.cells, (cell) => cell.innerText.trim())));
    return filasTabla(filas, campos).slice(0, 30);
  } catch {
    log(`MiSII no mostró la sección opcional «${nombre}»; se continúa con los demás datos.`);
    return [];
  }
}

function regimenSii(texto) {
  const t = String(texto || '').toLocaleUpperCase('es-CL');
  if (/PYME.*TRANSPARENTE|14\s*D\s*N?[°º]?\s*8/.test(t)) return 'Pro Pyme Transparente (14 D N°8)';
  if (/PYME.*GENERAL|14\s*D\s*N?[°º]?\s*3/.test(t)) return 'Pro Pyme General (14 D N°3)';
  if (/GENERAL|14\s*A/.test(t)) return 'Régimen General (14 A)';
  if (/PRESUNTA/.test(t)) return 'Renta presunta';
  return '';
}

/** Lee el perfil público autenticado de MiSII para proponer campos editables al crear una empresa. */
async function consultarEmpresa({ rut, clave, headless = true, signal, browser: navegadorExistente = null }) {
  const { chromium } = require('playwright');
  const propio = !navegadorExistente;
  const navegador = navegadorExistente || await lanzarNavegador(chromium, headless);
  let contexto = null;
  const abortar = () => contexto ? contexto.close().catch(() => {}) : (propio ? navegador.close().catch(() => {}) : undefined);
  signal?.addEventListener('abort', abortar, { once: true });
  try {
    if (signal?.aborted) throw new Error('Tarea interrumpida.');
    contexto = await navegador.newContext();
    const page = await contexto.newPage();
    await iniciarSesion(page, { rut, clave });
    await page.goto(SII.datosContribuyente, { timeout: SII.tiempoMs });
    const s = SII.selectoresEmpresa;
    // MiSII parte en Inicio y mantiene el acordeón de datos tributarios oculto
    // hasta que se entra a esta opción del menú lateral.
    const menuDatos = page.locator('#menu_datos_contribuyente').first();
    await menuDatos.waitFor({ state: 'visible', timeout: SII.tiempoMs });
    await menuDatos.click({ timeout: 8000 });
    await page.locator('#headingTwo').waitFor({ state: 'visible', timeout: SII.tiempoMs }).catch(() => {
      throw new Error('No se pudo abrir Datos tributarios en MiSII. Confirma que el menú haya cargado y vuelve a intentar.');
    });
    const perfil = await page.locator(s.perfil).innerText({ timeout: SII.tiempoMs });
    const resumenRegimen = await page.locator(s.regimen).innerText({ timeout: 5000 }).catch(() => '');
    const rutSii = etiqueta(perfil, 'RUT');
    if (rutParaFormulario(rutSii) !== rutParaFormulario(rut)) throw new Error('El RUT del perfil MiSII no coincide con el consultado.');
    const nombreSii = ['Razón Social', 'Razon Social', 'Nombre o Razón Social', 'Nombre o Razon Social', 'Contribuyente']
      .map((etiquetaNombre) => etiqueta(perfil, etiquetaNombre)).find(Boolean)
      || perfil.split(/\r?\n/).map((x) => x.trim()).find((x) => x && !/^RUT\s*:/i.test(x) && !/@/.test(x)) || '';
    await abrirAcordeon(page, '#headingTwo', s.datos);
    const datosGenerales = await page.locator(s.datos).innerText({ timeout: 10000 });
    const actividadRows = await page.locator(`${s.actividades} tbody tr`).evaluateAll((rows) => rows.map((row) => Array.from(row.cells, (cell) => cell.innerText.trim()))).catch(() => []);
    const actividades = filasTabla(actividadRows, ['descripcion', 'codigo', 'categoria', 'afecta_iva', 'desde']).slice(0, 30);

    const sucursales = await leerTablaOpcional(page, '#headingOne', s.sucursales, ['codigo', 'tipo', 'direccion', 'desde'], 'sucursales');
    const direccionSii = sucursales.find((x) => x.tipo.toLocaleUpperCase('es-CL') === 'DOMICILIO')?.direccion || '';
    const direccionPrincipal = etiqueta(perfil, 'Domicilio') || direccionSii;
    const domicilio = direccionPrincipal.replace(/\s+COMUNA\s+.+$/i, '').replace(/\s+REGI[ÓO]N\s+.+$/i, '').trim();
    const comunaMatch = /COMUNA\s+(.+?)(?:\s+REGI[ÓO]N\s+|$)/i.exec(direccionPrincipal);
    const comuna = etiqueta(perfil, 'Comuna') || (comunaMatch ? comunaMatch[1].trim() : '');
    const regionTexto = `${direccionPrincipal} ${domicilio}`;
    const regionMatch = /REGI[ÓO]N\s+(?:DEL?\s+)?([A-ZÁÉÍÓÚÑ ]+)/i.exec(regionTexto);
    const region = regionMatch ? `Región ${/BIO\s*BIO/i.test(regionMatch[1]) ? 'del Biobío' : `de ${regionMatch[1].trim().replace(/\s+/g, ' ')}`}` : '';

    const caracteristicas = await leerTablaOpcional(page, '#headingP13', `${s.caracteristicas} table`, ['nombre', 'desde'], 'características');
    const documentos_autorizados = await leerTablaOpcional(page, '#headingP12', s.documentos, ['tipo', 'hasta', 'fecha'], 'documentos autorizados');

    const giro = etiqueta(datosGenerales, 'Glosa Descriptiva') || actividades[0]?.descripcion || '';
    const regimenTexto = resumenRegimen.split(/\r?\n/).map((x) => x.trim()).find(Boolean) || etiqueta(datosGenerales, 'Régimen tributario');
    return {
      rut: rutSii,
      razon_social: nombreSii,
      giro,
      regimen: regimenSii(regimenTexto),
      telefono: etiqueta(perfil, 'Teléfono móvil'),
      email: etiqueta(perfil, 'Correo electrónico'),
      domicilio,
      comuna,
      region,
      fecha_constitucion: etiqueta(datosGenerales, 'Fecha Constitución'),
      fecha_inicio_actividades: etiqueta(datosGenerales, 'Fecha Inicio actividades'),
      categoria_tributaria: etiqueta(datosGenerales, 'Categoría tributaria'),
      tipo_contribuyente: etiqueta(datosGenerales, 'Tipo Contribuyente'),
      subtipo_contribuyente: etiqueta(datosGenerales, 'Subtipo Contribuyente'),
      segmento: etiqueta(datosGenerales, 'Segmento'),
      glosa_descriptiva: etiqueta(datosGenerales, 'Glosa Descriptiva'),
      actividades, sucursales, caracteristicas, documentos_autorizados,
    };
  } finally {
    signal?.removeEventListener('abort', abortar);
    if (!signal?.aborted && contexto) await cerrarSesionSii(contexto);
    await contexto?.close().catch(() => {});
    if (propio) await navegador.close().catch(() => {});
  }
}
// Esta sesión tiene alcance solo para RCV. La consulta automática de datos de empresa sigue separada.
descargarLibro.canalSiiHabilitado = true;
consultarEmpresa.canalSiiHabilitado = true;

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
    await cerrarSesionSii(navegador);
    await navegador.close();
  }
}

module.exports = { descargarLibro, descargarBoletasHonorarios, consultarEmpresa, explorarSii, periodoSii, rutParaFormulario, descartarAvisoActualizacion, cerrarModalAlertaSii, esperarRcvORechazoAcceso, seleccionarPestanaRcv, pestanaRcvActiva, cerrarSesionRcv, crearNavegadorAgente, cerrarNavegadorAgente, leerTablaOpcional, SII };
