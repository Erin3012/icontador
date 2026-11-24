'use strict';

// Prueba manual local del flujo de Honorarios. No usa la cola central ni importa datos a qlc.cl.
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const readline = require('node:readline');
const { descargarBoletasHonorarios, crearNavegadorAgente, cerrarSesionRcv, cerrarNavegadorAgente } = require('../src/sii');
const { parseInforme } = require('../../assets/honorarios-import.js');

function preguntar(texto) {
  const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
  return new Promise(resolve => rl.question(texto, respuesta => { rl.close(); resolve(respuesta.trim()); }));
}
function preguntarSecreto(texto) {
  if (!process.stdin.isTTY || typeof process.stdin.setRawMode !== 'function') throw new Error('Ejecuta la prueba desde una terminal WSL interactiva para ocultar la clave.');
  return new Promise((resolve, reject) => {
    const entrada = process.stdin, salida = process.stdout; let valor = '';
    salida.write(texto); entrada.setRawMode(true); entrada.resume();
    const limpiar = () => { entrada.removeListener('data', recibir); entrada.setRawMode(false); entrada.pause(); salida.write('\n'); };
    const recibir = datos => { for (const byte of datos) {
      if (byte === 3) { limpiar(); reject(new Error('Prueba interrumpida.')); return; }
      if (byte === 13 || byte === 10) { limpiar(); resolve(valor); return; }
      if (byte === 127 || byte === 8) valor = valor.slice(0, -1); else if (byte >= 32) valor += String.fromCharCode(byte);
    } };
    entrada.on('data', recibir);
  });
}

async function main() {
  if (process.platform !== 'linux') throw new Error('Esta prueba está pensada para ejecutarse dentro de WSL/Ubuntu.');
  const rut = process.argv[2] || await preguntar('RUT del contribuyente: ');
  const periodo = process.argv[3] || await preguntar('Período (AAAA-MM): ');
  if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(periodo)) throw new Error('Período inválido; usa AAAA-MM.');
  const seleccion = (process.argv[4] || await preguntar('Libro (recibidas/emitidas/ambas, defecto ambas): ') || 'ambas').toLowerCase();
  const libros = seleccion === 'ambas' ? ['recibidas', 'emitidas'] : [seleccion];
  if (libros.some(libro => !['recibidas', 'emitidas'].includes(libro))) throw new Error('Usa recibidas, emitidas o ambas.');
  let clave = await preguntarSecreto('Clave Tributaria (no se verá al escribir; escribe y pulsa Enter): ');
  if (!rut || !clave) throw new Error('RUT y clave son obligatorios.');
  const carpeta = path.resolve(process.env.SII_DIAGNOSTICO_DIR || path.join(os.homedir(), 'icontador-sii-diagnostico'));
  fs.mkdirSync(carpeta, { recursive: true, mode: 0o700 });
  try { fs.chmodSync(carpeta, 0o700); } catch {}
  const sesion = await crearNavegadorAgente({ headless: false });
  const logAnterior = console.log;
  let secretoLog = clave;
  console.log = (...args) => {
    const texto = args.map(String).join(' ');
    const linea = secretoLog ? texto.split(secretoLog).join('[CLAVE OCULTA]') : texto;
    try { fs.appendFileSync(path.join(carpeta, `prueba-honorarios-${periodo}.log`), `${new Date().toISOString()} ${linea}\n`, { mode: 0o600 }); } catch {}
    logAnterior(...args);
  };
  console.log(`Prueba visible SII · ${periodo} · ${libros.join(' y ')}.`);
  console.log(`Carpeta temporal protegida: ${carpeta}`);
  console.log('El navegador permanecerá visible. No se importan datos a qlc.cl.');
  try {
    for (const libro of libros) {
      console.log(`[flujo] iniciando ${libro}`);
      try {
        const archivo = await descargarBoletasHonorarios({ rut, clave, periodo, libro, carpeta, headless: false, browser: sesion.browser,
          onProgress: etapa => console.log(`[flujo] ${libro} ${etapa}`),
          onFormReady: async ({ page, libro: libroActual }) => {
            const captura = path.join(carpeta, `formulario-honorarios-${libroActual}-${periodo}.jpg`);
            fs.writeFileSync(captura, await page.screenshot({ type: 'jpeg', quality: 70, fullPage: false }), { mode: 0o600 });
            const controles = await page.locator('input, select, button').evaluateAll(items => items.map(el => ({
              tag: el.tagName, type: el.type || '', name: el.name || '', id: el.id || '',
              label: (el.labels ? Array.from(el.labels, x => x.innerText).join(' ') : el.parentElement?.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 100),
              options: el.tagName === 'SELECT' ? Array.from(el.options, o => o.text.trim()).slice(0, 20) : undefined,
            })));
            fs.writeFileSync(path.join(carpeta, `controles-honorarios-${libroActual}-${periodo}.json`), JSON.stringify(controles, null, 2), { mode: 0o600 });
            console.log(`Formulario abierto en Chromium. Captura: ${captura}`);
            console.log(`El agente seleccionará el informe mensual ${periodo} de ${libroActual} y consultará automáticamente.`);
          },
          onCheckpoint: async ({ page, libro: libroActual }) => {
            const captura = path.join(carpeta, `consulta-honorarios-${libroActual}-${periodo}.jpg`);
            fs.writeFileSync(captura, await page.screenshot({ type: 'jpeg', quality: 70, fullPage: false }), { mode: 0o600 });
            console.log(`Consulta abierta en Chromium. Captura local: ${captura}`);
            await preguntar(`Revisa la pantalla del SII en Chromium para ${libroActual}; pulsa Enter en esta terminal para continuar... `);
          },
          onFailure: async jpeg => {
            const captura = path.join(carpeta, `fallo-honorarios-${libro}-${periodo}-${Date.now()}.jpg`);
            fs.writeFileSync(captura, jpeg, { mode: 0o600 });
            console.log(`Captura guardada localmente: ${captura}`);
          },
        });
        if (archivo?.sinDocumentos) { console.log(`RESULTADO: el SII confirma cero boletas ${libro} para ${periodo}.`); continue; }
        try { fs.chmodSync(archivo, 0o600); } catch {}
        const bytes = fs.readFileSync(archivo);
        let texto;
        try { texto = new TextDecoder('utf-8', { fatal: true }).decode(bytes); }
        catch { texto = new TextDecoder('windows-1252').decode(bytes); }
        const datos = parseInforme(texto, path.basename(archivo));
        if (datos.kind !== libro || datos.period !== periodo) throw new Error('El informe no coincide con el libro o período solicitado.');
        console.log(`RESULTADO: ${libro} · ${datos.boletas.length} boletas · bruto ${datos.totales.bruto} · retenido ${datos.totales.retenido} · pagado ${datos.totales.pagado}.`);
        console.log(`Archivo validado localmente: ${archivo}`);
      } catch (e) { console.log(`ERROR ${libro}: ${String(e.message || 'Error desconocido.').split(secretoLog).join('[CLAVE OCULTA]')}`); }
    }
  } finally {
    console.log = logAnterior;
    secretoLog = '';
    clave = '';
    await cerrarSesionRcv(sesion.browser).catch(() => {});
    await cerrarNavegadorAgente(sesion).catch(() => {});
  }
}
main().catch(e => { console.error(`Prueba visible detenida: ${e.message}`); process.exitCode = 1; });
