// Pruebas del agente sin navegador ni SII: npm test (desde sii-agent/)
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { cargarEnv } = require('../src/env');
const { crearServidor } = require('../src/api');
const { leerTokenSystemd, NOMBRE_CREDENCIAL_SYSTEMD } = require('../src/credential-store');
const { procesarTarea, normalizarRut, configurar } = require('../src/index');
const { periodoSii, rutParaFormulario, descartarAvisoActualizacion, cerrarModalAlertaSii, esperarRcvORechazoAcceso, seleccionarPestanaRcv, descargarLibro, cerrarSesionRcv, crearNavegadorAgente, cerrarNavegadorAgente, leerTablaOpcional, SII } = require('../src/sii');
const { parseRcv } = require('../../assets/rcv-import.js');

const raiz = path.resolve(__dirname, '..', '..');
const muestras = path.join(raiz, 'samples');
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'sii-agente-test-'));

// 1. .env: comillas, comentarios y variables ya presentes.
const env = path.join(temp, '.env');
fs.writeFileSync(env, '# comentario\nSII_URL_SERVIDOR=https://qlc.cl\nSII_CLAVES=\'{"76000000-0":"x y"}\'\n\nSII_TOKEN_AGENTE="tok"\n');
const valores = cargarEnv(env);
assert.equal(valores.SII_URL_SERVIDOR, 'https://qlc.cl');
assert.equal(valores.SII_TOKEN_AGENTE, 'tok');
assert.deepEqual(cargarEnv(path.join(temp, 'no-existe')), {});

// 2. El RUT se normaliza para el formulario del SII.
assert.equal(normalizarRut('76.000.000-0'), '76000000-0');
assert.equal(normalizarRut('7.123.456-k'), '7123456-K');
assert.equal(periodoSii('2026-10'), '10-2026');
assert.equal(rutParaFormulario('76.000.000-0'), '760000000');

// 2b. MiSII requiere cerrar el aviso de contacto antes de usar sus módulos.
// 3. Servidor: envía el token y decodifica errores.
const llamadas = [];
const fetchFalso = async (url, opciones) => {
  llamadas.push({ url, opciones });
  return { ok: url.includes('importar') ? false : true, status: url.includes('importar') ? 422 : 200,
    json: async () => (url.includes('importar') ? { error: 'RUT distinto' } : { tareas: [{ id: 1 }] }) };
};
const workerId = '12345678-1234-4234-8234-123456789abc';
const servidor = crearServidor({ url: 'https://qlc.cl/', token: 'tok', workerId, fetchImpl: fetchFalso });
(async () => {
  // El modal de aviso del SII bloquea el botón Resumenes; cerrarlo habilita el fallback tipo 48.
  {
    let visible = true, pulsado = false;
    const boton = { isVisible: async () => true, click: async () => { pulsado = true; visible = false; } };
    const modal = {
      isVisible: async () => visible,
      innerText: async () => 'No existe detalle individual para comprobantes electrónicos.',
      getByRole: () => ({ first: () => boton }),
      locator: () => ({ first: () => ({ isVisible: async () => false }) }),
      waitFor: async ({ state }) => { assert.equal(state, 'hidden'); assert.equal(visible, false); },
    };
    const cerrado = await cerrarModalAlertaSii({ locator: () => modal, keyboard: { press: async () => {} } });
    assert.equal(cerrado, true);
    assert.equal(pulsado, true);
  }

  let clickRealizado = false;
  const control = { first() { return this; }, isVisible: async () => true,
    click: async () => { clickRealizado = true; }, waitFor: async () => {} };
  const page = { getByRole: () => control, getByText: () => control };
  assert.equal(await descartarAvisoActualizacion(page, () => {}), true);
  assert.equal(clickRealizado, true, 'pulsa ACTUALIZAR MÁS TARDE cuando el aviso aparece');

  const fakePaginaAcceso = { locator: (selector) => ({ first: () => ({ waitFor: async () => {
    if (selector !== SII.selectores.rut) throw new Error('no visible');
  } }) }) };
  await assert.rejects(esperarRcvORechazoAcceso(fakePaginaAcceso), /El SII devolvió al formulario de ingreso/);
  const fakePaginaRcv = { locator: (selector) => ({ first: () => ({ waitFor: async () => {
    if (selector !== SII.selectores.empresaRcv) throw new Error('no visible');
  } }) }) };
  await esperarRcvORechazoAcceso(fakePaginaRcv);

  let pestanaActiva = 'compras';
  const pestanaVisible = (nombre) => ({
    count: async () => 1,
    nth() { return this; },
    first() { return this; },
    isVisible: async () => true,
    click: async () => { pestanaActiva = nombre === 'VENTA' ? 'ventas' : 'compras'; },
    evaluate: async () => true,
  });
  const pageTabs = {
    evaluate: async () => pestanaActiva,
    getByText: (nombre) => pestanaVisible(nombre),
    waitForTimeout: async () => {},
  };
  await seleccionarPestanaRcv(pageTabs, 'ventas');
  assert.equal(pestanaActiva, 'ventas', 'selecciona y verifica VENTA en la interfaz');
  await assert.rejects(seleccionarPestanaRcv({
    evaluate: async () => 'compras', getByText: () => ({ count: async () => 1, nth() { return this; }, isVisible: async () => true, click: async () => {}, }),
    waitForTimeout: async () => {},
  }, 'ventas'), /no se descargó ningún archivo/, 'no descarga si el SII no confirma la pestaña solicitada');

  // Un rango de una empresa reutiliza el mismo contexto y login entre libro/mes.
  const contextosRcv = [];
  let loginsRcv = 0; let cierresRcv = 0; let browserCerradoRcv = false;
  const browserRcv = {
    newContext: async () => {
      let cerrada = false;
      const estado = { login: false, tab: 'compras', pagina: null, cerrada: false };
      const locator = () => ({ first() { return this; }, waitFor: async () => {}, selectOption: async () => {},
        innerText: async () => '', count: async () => 0, evaluateAll: async () => [] });
      const pagina = {
        _url: '',
        url() { return this._url; },
        isClosed: () => cerrada,
        goto: async (url) => { this._url = url; if (url.includes('/autTermino.cgi')) { estado.login = false; cierresRcv++; } },
        fill: async () => {},
        click: async (selector) => { if (selector.includes('INGRESAR')) { estado.login = true; loginsRcv++; this._url = 'https://misiir.sii.cl/cgi_misii/siihome.cgi'; } },
        innerText: async () => '',
        waitForLoadState: async () => {},
        waitForTimeout: async () => {},
        locator,
        getByRole: () => ({ first() { return this; }, isVisible: async () => false }),
        getByText: (nombre) => (nombre instanceof RegExp ? nombre.test('ACTUALIZAR MÁS TARDE') : nombre === 'ACTUALIZAR MÁS TARDE')
          ? { first() { return this; }, isVisible: async () => false, waitFor: async () => { throw new Error('no aparece el aviso'); } }
          : pestanaVisible(nombre),
        evaluate: async () => estado.tab,
        waitForEvent: async () => ({ saveAs: async (destino) => {
          const muestra = estado.tab === 'ventas' ? 'RCV_VENTA_76000000-0_202609.csv' : 'RCV_COMPRA_REGISTRO_76000000-0_202609.csv';
          fs.copyFileSync(path.join(muestras, muestra), destino);
        } }),
      };
      // Keep tab clicks and active state attached to this context's page.
      pagina.getByText = (nombre) => (nombre instanceof RegExp ? nombre.test('ACTUALIZAR MÁS TARDE') : nombre === 'ACTUALIZAR MÁS TARDE')
        ? { first() { return this; }, isVisible: async () => false, waitFor: async () => { throw new Error('no aparece el aviso'); } }
        : ({ ...pestanaVisible(nombre), click: async () => { estado.tab = nombre === 'VENTA' ? 'ventas' : 'compras'; } });
      estado.pagina = pagina;
      const contexto = { newPage: async () => pagina, pages: () => [pagina], close: async () => { cerrada = true; estado.cerrada = true; } };
      contextosRcv.push({ contexto, estado });
      return contexto;
    },
    close: async () => { browserCerradoRcv = true; },
  };
  const carpetaRcv = path.join(temp, 'sesion-rcv');
  const parametrosRcv = { rut: '76.000.000-0', clave: 'temporal', periodo: '2026-09', carpeta: carpetaRcv,
    browser: browserRcv, playwright: { chromium: {} }, log: () => {} };
  const compraRcv = await descargarLibro({ ...parametrosRcv, libro: 'compras' });
  const ventaRcv = await descargarLibro({ ...parametrosRcv, libro: 'ventas' });
  assert.equal(contextosRcv.length, 1, 'mantiene un solo BrowserContext para los libros de la misma empresa');
  assert.equal(loginsRcv, 1, 'reutiliza el login entre compras y ventas');
  assert.equal(parseRcv(fs.readFileSync(compraRcv, 'utf8'), path.basename(compraRcv)).kind, 'compras');
  assert.equal(parseRcv(fs.readFileSync(ventaRcv, 'utf8'), path.basename(ventaRcv)).kind, 'ventas', 'la tarea de ventas descarga el libro correcto');
  const otraEmpresaRcv = await descargarLibro({ ...parametrosRcv, rut: '77.000.000-1', libro: 'compras' });
  assert.equal(contextosRcv.length, 2, 'un cambio de empresa inicia un contexto separado');
  assert.equal(contextosRcv[0].estado.cerrada, true, 'cierra el contexto anterior al cambiar de empresa');
  assert.equal(loginsRcv, 2, 'inicia sesión al cambiar de empresa');
  assert.equal(parseRcv(fs.readFileSync(otraEmpresaRcv, 'utf8'), path.basename(otraEmpresaRcv)).kind, 'compras');
  await cerrarSesionRcv(browserRcv, () => {});
  assert.equal(cierresRcv, 2, 'cierra la sesión al vaciarse la cola');
  assert.equal(contextosRcv[1].estado.cerrada, true);
  assert.equal(browserCerradoRcv, false, 'deja Chromium abierto después de cerrar la sesión SII');
  fs.rmSync(carpetaRcv, { recursive: true, force: true });

  let paginas = 0; let browserCerrado = false;
  const browserFalso = { newPage: async () => { paginas++; return {}; }, close: async () => { browserCerrado = true; } };
  const sesion = await crearNavegadorAgente({ chromium: { launch: async () => browserFalso } });
  assert.equal(sesion.browser, browserFalso);
  assert.equal(paginas, 1, 'inicia una página persistente visible al abrir la GUI');
  await cerrarNavegadorAgente(sesion);
  assert.equal(browserCerrado, true, 'cierra Chromium al cerrar el worker');

  const locator = { first() { return this; }, isVisible: async () => false, count: async () => 0,
    evaluate: async () => false };
  const opcional = await leerTablaOpcional({ locator: () => locator }, '#headingP12', '#tblUltimosDoc', ['tipo'], 'documentos autorizados', () => {});
  assert.deepEqual(opcional, [], 'una tabla MiSII opcional oculta no falla la consulta principal');

  assert.deepEqual(await servidor.pendientes(), [{ id: 1 }]);
  assert.equal(llamadas[0].url, 'https://qlc.cl/api/sii-agente.php?accion=pendientes');
  assert.equal(llamadas[0].opciones.headers['X-Agente-Token'], 'tok');
  assert.equal(llamadas[0].opciones.headers['X-Agente-Worker'], workerId);
  assert.throws(() => crearServidor({ url: 'http://qlc.cl', token: 'tok', workerId }), /solo acepta HTTPS/);
  await assert.rejects(servidor.importar(1, { docs: [] }), /RUT distinto/);

  // 4. Tarea completa: descarga (copia de la muestra), valida y envía al servidor; borra el CSV.
  const enviados = [];
  const servidorOk = {
    pendientes: async () => [],
    importar: async (id, datos) => { enviados.push({ id, datos }); return { guardados: datos.resumen ? datos.resumen.reduce((n, r) => n + r.documentos, 0) : (datos.docs || datos.boletas || []).length }; },
    fallar: async (id, mensaje) => { enviados.push({ id, mensaje }); return { ok: true }; },
  };
  const descargarMuestra = (archivoMuestra) => async ({ carpeta }) => {
    fs.mkdirSync(carpeta, { recursive: true });
    const destino = path.join(carpeta, 'RCV_descargado.csv');
    fs.copyFileSync(path.join(muestras, archivoMuestra), destino);
    return destino;
  };
  const carpeta = path.join(temp, 'descargas');
  const tarea = { id: 7, rut: '76.000.000-0', periodo: '2026-09', libro: 'compras', clave: 'clave' };
  let r = await procesarTarea({ servidor: servidorOk, tarea: { ...tarea }, carpeta, conservar: false, log: () => {},
    descargar: descargarMuestra('RCV_COMPRA_REGISTRO_76000000-0_202609.csv') });
  assert.equal(r.ok, true, enviados[0]?.mensaje || 'RCV task failed without server message');
  assert.equal(enviados[0].id, 7);
  assert.equal(enviados[0].datos.kind, 'compras');
  assert.equal(enviados[0].datos.period, '2026-09');
  assert.ok(enviados[0].datos.docs.length > 0);
  assert.equal(fs.readdirSync(carpeta).length, 0, 'borra el CSV tras importarlo');

  // El RCV 48 se conserva como resumen agregado de ventas, sin convertirlo en documentos.
  enviados.length = 0;
  const resumenPath=path.join(carpeta,'RCV_RESUMEN_VENTA_76000000-0_202609.csv');
  fs.mkdirSync(carpeta,{recursive:true});
  fs.writeFileSync(resumenPath,'Tipo Documento;Total Documentos;Monto Exento;Monto Neto;Monto IVA;Monto Total\nTotal mes Comprobantes Pago Electrónico(48);187;0;1369623;260227;1629850\n');
  r=await procesarTarea({servidor:servidorOk,tarea:{...tarea,libro:'ventas'},carpeta,conservar:false,log:()=>{},descargar:async()=>resumenPath});
  assert.equal(r.ok,true);assert.equal(enviados[0].datos.resumen[0].tipo,48);assert.equal(enviados[0].datos.resumen[0].documentos,187);assert.deepEqual(enviados[0].datos.docs,[]);assert.equal(fs.readdirSync(carpeta).length,0);

  // 5. Sin clave para el RUT: se informa a la tarea y no se descarga nada.
  enviados.length = 0;
  let descargado = false;
  await procesarTarea({ servidor: servidorOk, tarea: { ...tarea, clave: '' }, carpeta, log: () => {}, descargar: async () => { descargado = true; } });
  assert.equal(descargado, false);
  assert.match(enviados[0].mensaje, /No se recibió la Clave Tributaria guardada/);

  // 6. El archivo es de otro período: se informa y no se importa.
  enviados.length = 0;
  await procesarTarea({ servidor: servidorOk, tarea: { ...tarea, periodo: '2026-10' }, carpeta, log: () => {},
    descargar: descargarMuestra('RCV_COMPRA_REGISTRO_76000000-0_202609.csv') });
  assert.ok(enviados.every((e) => e.mensaje && e.mensaje.includes('período 2026-09')), 'informa período distinto');

  // 7. El libro pedido no es el que llegó (ventas en vez de compras).
  enviados.length = 0;
  await procesarTarea({ servidor: servidorOk, tarea: { ...tarea, libro: 'ventas' }, carpeta, log: () => {},
    descargar: descargarMuestra('RCV_COMPRA_REGISTRO_76000000-0_202609.csv') });
  assert.match(enviados[0].mensaje, /compras, no de ventas/);

  // 8. El servidor rechaza el archivo (RUT distinto): no se vuelve a informar como fallo local.
  enviados.length = 0;
  const servidorRechaza = { ...servidorOk, importar: async () => { throw new Error('RUT distinto'); } };
  r = await procesarTarea({ servidor: servidorRechaza, tarea: { ...tarea }, carpeta, log: () => {},
    descargar: descargarMuestra('RCV_COMPRA_REGISTRO_76000000-0_202609.csv') });
  assert.equal(r.ok, false);
  assert.equal(enviados.length, 0);

  // 9. Error al descargar (clave incorrecta, por ejemplo): se informa a la tarea.
  await procesarTarea({ servidor: servidorOk, tarea: { ...tarea }, carpeta, log: () => {},
    descargar: async () => { throw new Error('No se pudo iniciar sesión en el SII.'); } });
  assert.match(enviados[enviados.length - 1].mensaje, /No se pudo descargar el RCV del SII: No se pudo iniciar sesión/);

  // 9b. BHE sigue la ruta de Honorarios, valida libro/período y limpia el informe temporal.
  enviados.length = 0;
  const honorariosPath = path.join(carpeta, 'BHE_RECIBIDAS_76000000_202610.xls');
  fs.mkdirSync(carpeta, { recursive: true });
  fs.writeFileSync(honorariosPath, `<table><tr><td colspan="10">Contribuyente: PRUEBA<br>RUT : 76000000-0<p></p>Informe correspondiente al mes 10 del año 2026</td></tr><tr><td colspan="4"><strong>Boleta</strong></td><td colspan="3"><strong>Emisor</strong></td><td colspan="3"><strong>Honorarios</strong></td></tr><tr><td><strong>N°</strong></td><td><strong>Fecha</strong></td><td><strong>Estado</strong></td><td><strong>Fecha Anulación</strong></td><td><strong>Rut</strong></td><td><strong>Nombre o Razón Social</strong></td><td><strong>Soc. Prof.</strong></td><td><strong>Brutos</strong></td><td><strong>Retenido</strong></td><td><strong>Pagado</strong></td></tr><tr><td>9001</td><td>10/10/2026</td><td>VIGENTE</td><td></td><td>11111111-1</td><td>PRESTADOR</td><td>NO</td><td>100000</td><td>14500</td><td>85500</td></tr></table>`);
  const tareaBhe = { id: 11, tipo: 'honorarios', rut: '76.000.000-0', periodo: '2026-10', libro: 'recibidas', clave: 'clave de prueba' };
  r = await procesarTarea({ servidor: servidorOk, tarea: tareaBhe, carpeta, conservar: false, log: () => {},
    descargarHonorarios: async ({ rut, clave, periodo, libro }) => {
      assert.equal(rut, '76000000-0'); assert.equal(clave, 'clave de prueba'); assert.equal(periodo, '2026-10'); assert.equal(libro, 'recibidas');
      return honorariosPath;
    } });
  assert.equal(r.ok, true, enviados[0]?.mensaje || 'BHE task failed without server message');
  assert.equal(enviados[0].id, 11);
  assert.equal(enviados[0].datos.kind, 'recibidas');
  assert.equal(enviados[0].datos.boletas.length, 1);
  assert.equal(enviados[0].datos.boletas[0].numero, '9001');
  assert.equal(tareaBhe.clave, '', 'borra la clave de la tarea después de descargar BHE');
  assert.equal(fs.readdirSync(carpeta).length, 0, 'borra el informe BHE después de importarlo');

  enviados.length = 0;
  r = await procesarTarea({ servidor: servidorOk, tarea: { ...tareaBhe, id: 12, libro: 'emitidas', clave: 'clave de prueba' }, carpeta, conservar: false, log: () => {},
    descargarHonorarios: async () => ({ sinDocumentos: true, kind: 'emitidas', period: '2026-10', fileName: 'BHE_EMITIDAS.xls' }) });
  assert.equal(r.ok, true);
  assert.deepEqual(enviados[0].datos.boletas, [], 'un período sin boletas se importa como conjunto vacío');
  assert.equal(enviados[0].datos.kind, 'emitidas');

  // 10. Configuración: faltan datos obligatorios y no se admiten tokens/claves de versiones anteriores.
  assert.throws(() => configurar({}), /SII_URL_SERVIDOR/);
  const cfg = configurar({ SII_URL_SERVIDOR: 'https://qlc.cl', SII_HEADLESS: 'false', SII_INTERVALO_SEG: '5' });
  assert.equal(cfg.headless, false);
  assert.equal(cfg.intervaloMs, 15000, 'el intervalo mínimo es 15 segundos');
  assert.throws(() => configurar({ SII_URL_SERVIDOR: 'https://qlc.cl', SII_TOKEN_AGENTE: 'legacy' }), /almacén seguro/);

  // 10b. Linux lee el token desde el directorio efímero que systemd entrega al servicio.
  const credDir = path.join(temp, 'systemd-credentials');
  fs.mkdirSync(credDir, { recursive: true });
  const variableCred = process.env.CREDENTIALS_DIRECTORY;
  process.env.CREDENTIALS_DIRECTORY = credDir;
  fs.writeFileSync(path.join(credDir, NOMBRE_CREDENCIAL_SYSTEMD), 'b'.repeat(64));
  assert.equal(leerTokenSystemd(), 'b'.repeat(64));
  fs.writeFileSync(path.join(credDir, NOMBRE_CREDENCIAL_SYSTEMD), 'no-es-token');
  assert.throws(() => leerTokenSystemd(), /no es válida/);
  if (variableCred === undefined) delete process.env.CREDENTIALS_DIRECTORY;
  else process.env.CREDENTIALS_DIRECTORY = variableCred;

  // 11. La clave solo llega con la tarea; jamás se toma desde .env ni se devuelve al servidor.
  const usadas = [];
  const mensajes = [];
  const servidorFalso = { fallar: async (id, m) => { mensajes.push(m); }, importar: async () => ({}) };
  const descargarFalso = async ({ clave }) => { usadas.push(clave); throw new Error('corte de prueba'); };
  const base = { servidor: servidorFalso, descargar: descargarFalso, carpeta: temp, conservar: false, log: () => {} };
  await procesarTarea({ ...base, tarea: { id: 9, tipo: 'rcv', rut: '76.000.000-0', periodo: '2026-10', libro: 'compras', clave: 'desde-servidor' } });
  await procesarTarea({ ...base, tarea: { id: 10, tipo: 'rcv', rut: '76.000.000-0', periodo: '2026-10', libro: 'compras', clave: null } });
  assert.deepEqual(usadas, ['desde-servidor']);
  assert.match(mensajes[0], /No se pudo descargar el RCV/);
  assert.ok(!mensajes.some((m) => m.includes('desde-servidor')), 'la clave no se envía de vuelta al servidor');
  assert.ok(mensajes.some((m) => m.includes('No se recibió la Clave Tributaria guardada')), 'sin clave del servidor, no usa un fallback local');

  fs.rmSync(temp, { recursive: true, force: true });
  console.log('Agente SII central: .env, RUT, servidor, validación y errores verificados');
})().catch((e) => {
  console.error('FALLA:', e.message);
  process.exit(1);
});
