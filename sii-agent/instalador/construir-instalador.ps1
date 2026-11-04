# Construye IcontadorSII-Agente-<version>.exe en sii-agent\build\salida.
# Ejecutar en Windows, desde sii-agent\instalador:  powershell -ExecutionPolicy Bypass -File .\construir-instalador.ps1
# Requiere: Inno Setup 6 instalado (ISCC.exe) y conexión a internet (Node y Chromium).
param(
  [string]$NodeVersion = '20.18.1'
)
$ErrorActionPreference = 'Stop'

$instalador = $PSScriptRoot
$agente = Resolve-Path (Join-Path $instalador '..')
$raiz = Resolve-Path (Join-Path $agente '..')
$build = Join-Path $agente 'build'
$staging = Join-Path $build 'staging'

if (Test-Path $staging) { Remove-Item $staging -Recurse -Force }
New-Item -ItemType Directory -Path $build, $staging, (Join-Path $staging 'sii-agent'), (Join-Path $staging 'assets') -Force | Out-Null

# 1. Node portable (no requiere instalar Node en el PC del usuario).
$zip = Join-Path $build "node-v$NodeVersion-win-x64.zip"
if (-not (Test-Path $zip)) {
  Invoke-WebRequest "https://nodejs.org/dist/v$NodeVersion/node-v$NodeVersion-win-x64.zip" -OutFile $zip
}
$expandido = Join-Path $build "node-v$NodeVersion-win-x64"
if (-not (Test-Path $expandido)) { Expand-Archive $zip -DestinationPath $build -Force }
Copy-Item $expandido (Join-Path $staging 'node') -Recurse

# 2. Agente y el lector de RCV que comparte con el navegador (src/index.js lo importa como ../../assets/rcv-import.js).
Copy-Item (Join-Path $agente 'src') (Join-Path $staging 'sii-agent\src') -Recurse
Copy-Item (Join-Path $agente 'package.json'), (Join-Path $agente 'package-lock.json'), (Join-Path $agente 'README.md') (Join-Path $staging 'sii-agent')
Copy-Item (Join-Path $raiz 'assets\rcv-import.js') (Join-Path $staging 'assets')
Copy-Item (Join-Path $instalador 'lanzador.vbs') $staging

# 3. Dependencias de producción (solo playwright).
$npm = Join-Path $staging 'node\npm.cmd'
Push-Location (Join-Path $staging 'sii-agent')
try {
  & $npm ci --omit=dev
  if ($LASTEXITCODE -ne 0) { throw 'npm ci falló.' }
} finally {
  Pop-Location
}

# 4. Chromium de Playwright dentro de la carpeta de instalación (lanzador.vbs fija la misma ruta al ejecutar).
$env:PLAYWRIGHT_BROWSERS_PATH = Join-Path $staging 'browsers'
Push-Location (Join-Path $staging 'sii-agent')
try {
  & (Join-Path $staging 'node\node.exe') 'node_modules\playwright\cli.js' install chromium
  if ($LASTEXITCODE -ne 0) { throw 'No se pudo instalar el Chromium de Playwright.' }
} finally {
  Pop-Location
  Remove-Item Env:\PLAYWRIGHT_BROWSERS_PATH
}

# 5. Compilar el instalador con Inno Setup.
$iscc = @(
  "${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe",
  "$env:ProgramFiles\Inno Setup 6\ISCC.exe"
) | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $iscc) { throw 'No se encontró ISCC.exe. Instala Inno Setup 6.' }

& $iscc "/DSourceDir=$staging" (Join-Path $instalador 'icontador-sii.iss')
if ($LASTEXITCODE -ne 0) { throw 'Inno Setup falló al compilar el instalador.' }

Write-Host "Listo: $(Join-Path $build 'salida')"
