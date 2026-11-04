// Pruebas del paquete de Windows sin compilar el instalador: npm test (desde sii-agent/)
// Verifican que la disposición de la carpeta "staging" que arma construir-instalador.ps1 permite
// cargar el agente, y que los archivos del instalador existen y apuntan a las rutas correctas.
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

const agente = path.resolve(__dirname, '..');
const raiz = path.resolve(agente, '..');
const instalador = path.join(agente, 'instalador');

// 1. Archivos del instalador presentes.
for (const archivo of ['construir-instalador.ps1', 'icontador-sii.iss', 'lanzador.vbs']) {
  assert.ok(fs.existsSync(path.join(instalador, archivo)), `Falta instalador/${archivo}`);
}

// 2. El lanzador registra la salida y usa la misma carpeta de navegadores que el build.
const vbs = fs.readFileSync(path.join(instalador, 'lanzador.vbs'), 'utf8');
assert.match(vbs, /PLAYWRIGHT_BROWSERS_PATH/);
assert.match(vbs, /\\node\\node\.exe/);
assert.match(vbs, /\\browsers/);
assert.match(vbs, /src\\index\.js/);
const ps1 = fs.readFileSync(path.join(instalador, 'construir-instalador.ps1'), 'utf8');
assert.match(ps1, /Join-Path \$staging 'browsers'/);

// 3. El instalador no pide ni guarda claves tributarias, solo URL y token del agente.
const iss = fs.readFileSync(path.join(instalador, 'icontador-sii.iss'), 'utf8');
assert.match(iss, /PrivilegesRequired=lowest/);
assert.match(iss, /SII_TOKEN_AGENTE=/);
assert.doesNotMatch(iss, /SII_CLAVES/);

// 4. Disposición staging: staging/sii-agent/src y staging/assets. Desde src, ../../assets debe resolver.
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'sii-paquete-test-'));
const staging = path.join(temp, 'staging');
fs.mkdirSync(path.join(staging, 'sii-agent'), { recursive: true });
fs.mkdirSync(path.join(staging, 'assets'), { recursive: true });
fs.cpSync(path.join(agente, 'src'), path.join(staging, 'sii-agent', 'src'), { recursive: true });
fs.copyFileSync(path.join(raiz, 'assets', 'rcv-import.js'), path.join(staging, 'assets', 'rcv-import.js'));
// Las dependencias se enlazan desde el repo solo para esta prueba; el build real hace npm ci --omit=dev.
fs.symlinkSync(path.join(agente, 'node_modules'), path.join(staging, 'sii-agent', 'node_modules'), 'dir');

const salida = execFileSync(process.execPath, ['-e', "require('./src/index.js'); console.log('cargado')"], {
  cwd: path.join(staging, 'sii-agent'),
  encoding: 'utf8',
});
assert.equal(salida.trim(), 'cargado');

fs.rmSync(temp, { recursive: true, force: true });
console.log('Paquete Windows: archivos del instalador y disposición staging verificados');
