'use strict';

// Canjea el código de servicio y entrega el token por stdin a systemd-creds,
// evitando guardarlo en .env, argumentos, logs o archivos temporales en claro.
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { spawn } = require('node:child_process');
const { cargarEnv } = require('./env');
const { emparejarAgente } = require('./api');
const { NOMBRE_CREDENCIAL_SYSTEMD } = require('./credential-store');

function leerCodigoOculto() {
  return new Promise((resolve, reject) => {
    if (!process.stdin.isTTY || typeof process.stdin.setRawMode !== 'function') {
      reject(new Error('Ejecuta este comando desde una terminal interactiva para ingresar el código de forma oculta.'));
      return;
    }
    process.stdout.write('Código temporal de servicio de qlc.cl: ');
    const partes = [];
    const terminar = (error) => {
      process.stdin.setRawMode(false);
      process.stdin.pause();
      process.stdin.removeListener('data', recibir);
      process.stdout.write('\n');
      if (error) reject(error); else resolve(Buffer.concat(partes).toString('utf8').trim());
    };
    const recibir = (dato) => {
      for (const byte of dato) {
        if (byte === 3) return terminar(new Error('Enrolamiento cancelado.'));
        if (byte === 13 || byte === 10) return terminar();
        if (byte === 8 || byte === 127) { if (partes.length) partes.pop(); continue; }
        if (byte >= 32 && byte <= 126 && partes.length < 100) partes.push(Buffer.from([byte]));
      }
    };
    process.stdin.setRawMode(true);
    process.stdin.resume();
    process.stdin.on('data', recibir);
  });
}

function sudoValidar() {
  return new Promise((resolve, reject) => {
    const child = spawn('sudo', ['-v'], { stdio: 'inherit' });
    child.once('error', () => reject(new Error('No está disponible sudo para crear la credencial cifrada de systemd.')));
    child.once('exit', (code) => code === 0 ? resolve() : reject(new Error('No se autorizó el guardado seguro del token en systemd.')));
  });
}

function cifrarConSystemd(token) {
  return new Promise((resolve, reject) => {
    const ruta = `/etc/credstore.encrypted/${NOMBRE_CREDENCIAL_SYSTEMD}`;
    const args = ['-n', 'systemd-creds', 'encrypt', '--with-key=host', `--name=${NOMBRE_CREDENCIAL_SYSTEMD}`, '-', ruta];
    const child = spawn('sudo', args, { stdio: ['pipe', 'ignore', 'pipe'] });
    let errorText = '';
    child.stderr.setEncoding('utf8');
    child.stderr.on('data', (s) => { errorText = (errorText + s).slice(-1000); });
    child.once('error', () => reject(new Error('No se pudo iniciar systemd-creds para cifrar el token.')));
    child.once('exit', (code) => {
      if (code === 0) resolve();
      else reject(new Error(`systemd-creds no pudo guardar el token cifrado${errorText ? `: ${errorText.trim()}` : '.'}`));
    });
    child.stdin.end(token, 'utf8');
  });
}

function verificarCifradoDisponible() {
  return new Promise((resolve, reject) => {
    const args = ['-n', 'systemd-creds', 'encrypt', '--with-key=host', '--name=icontador-sii-preflight', '-', '-'];
    const child = spawn('sudo', args, { stdio: ['pipe', 'ignore', 'pipe'] });
    let errorText = '';
    child.stderr.setEncoding('utf8');
    child.stderr.on('data', (s) => { errorText = (errorText + s).slice(-1000); });
    child.once('error', () => reject(new Error('No se pudo verificar systemd-creds. Ejecuta primero el instalador de Ubuntu.')));
    child.once('exit', (code) => code === 0
      ? resolve()
      : reject(new Error(`systemd-creds no está listo para cifrar con la clave del VPS${errorText ? `: ${errorText.trim()}` : '.'}`)));
    child.stdin.end('prueba-de-cifrado');
  });
}

async function main() {
  if (process.platform !== 'linux') throw new Error('Este enrolamiento está destinado a Ubuntu/Linux.');
  const envPath = path.resolve(__dirname, '..', '.env');
  const env = { ...cargarEnv(envPath), ...process.env };
  const url = env.SII_URL_SERVIDOR || 'https://qlc.cl';
  const codigo = await leerCodigoOculto();
  if (!codigo) throw new Error('El código de servicio está vacío.');
  await sudoValidar();
  await verificarCifradoDisponible();
  const token = await emparejarAgente({ url, codigo, nombre: os.hostname() });
  await cifrarConSystemd(token);
  console.log('Agente enrolado. El token quedó cifrado con la clave del host y no se guardó en archivos en claro.');
  console.log('Ahora inicia el servicio con: sudo systemctl enable --now icontador-sii');
}

if (require.main === module) main().catch((error) => { console.error(error.message); process.exitCode = 1; });

module.exports = { leerCodigoOculto, cifrarConSystemd, verificarCifradoDisponible };
