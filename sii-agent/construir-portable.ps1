param([string]$NodeVersion = '20.18.1', [string]$PythonVersion = '3.14', [ValidatePattern('^[a-zA-Z0-9_-]+$')][string]$OutputName = 'portable')
$ErrorActionPreference = 'Stop'
$agentRoot = (Resolve-Path $PSScriptRoot).Path
$repoRoot = (Resolve-Path (Join-Path $agentRoot '..')).Path
$buildRoot = Join-Path $agentRoot 'build'
$workRoot = Join-Path $buildRoot 'gui-work'
$distRoot = Join-Path $buildRoot $OutputName
$appRoot = Join-Path $distRoot 'AgenteSII'
$stage = Join-Path $buildRoot 'portable-stage'
$nodeZip = Join-Path $buildRoot "node-v$NodeVersion-win-x64.zip"
$nodeExtract = Join-Path $buildRoot "node-v$NodeVersion-win-x64"

# Solo limpia artefactos generados por este script.
foreach ($p in @($workRoot, $distRoot, $nodeExtract, (Join-Path $stage 'sii-agent'), (Join-Path $stage 'assets'))) { if (Test-Path -LiteralPath $p) { Remove-Item -LiteralPath $p -Recurse -Force } }
New-Item -ItemType Directory -Force -Path $buildRoot,$stage,(Join-Path $stage 'node'),(Join-Path $stage 'sii-agent'),(Join-Path $stage 'assets') | Out-Null
if (-not (Test-Path -LiteralPath $nodeZip)) { Invoke-WebRequest "https://nodejs.org/dist/v$NodeVersion/node-v$NodeVersion-win-x64.zip" -OutFile $nodeZip }
Expand-Archive -LiteralPath $nodeZip -DestinationPath $buildRoot
Copy-Item -Path (Join-Path $nodeExtract '*') -Destination (Join-Path $stage 'node') -Recurse -Force
Copy-Item -LiteralPath (Join-Path $agentRoot 'src') -Destination (Join-Path $stage 'sii-agent\src') -Recurse
Copy-Item -LiteralPath (Join-Path $agentRoot 'package.json'),(Join-Path $agentRoot 'package-lock.json') -Destination (Join-Path $stage 'sii-agent')
Copy-Item -LiteralPath (Join-Path $repoRoot 'assets\rcv-import.js') -Destination (Join-Path $stage 'assets')
$npm = Join-Path $stage 'node\npm.cmd'
Push-Location (Join-Path $stage 'sii-agent')
try { & $npm ci --omit=dev; if ($LASTEXITCODE -ne 0) { throw 'npm ci falló.' } }
finally { Pop-Location }
$env:PLAYWRIGHT_BROWSERS_PATH = Join-Path $stage 'browsers'
try {
  if (-not (Get-ChildItem (Join-Path $stage 'browsers') -Directory -Filter 'chromium-*' -ErrorAction SilentlyContinue)) {
    Push-Location (Join-Path $stage 'sii-agent')
    try { & (Join-Path $stage 'node\node.exe') 'node_modules\playwright\cli.js' install chromium; if ($LASTEXITCODE -ne 0) { throw 'No se pudo instalar Chromium de Playwright.' } }
    finally { Pop-Location }
  }
}
finally { Remove-Item Env:\PLAYWRIGHT_BROWSERS_PATH -ErrorAction SilentlyContinue }

py "-$PythonVersion" -m PyInstaller --noconfirm --clean --onedir --windowed --name AgenteSII --distpath $distRoot --workpath $workRoot --specpath $buildRoot (Join-Path $agentRoot 'gui\app.py')
if ($LASTEXITCODE -ne 0) { throw 'PyInstaller no pudo compilar la interfaz.' }
Copy-Item -LiteralPath (Join-Path $stage 'node') -Destination (Join-Path $appRoot 'node') -Recurse
Copy-Item -LiteralPath (Join-Path $stage 'sii-agent') -Destination (Join-Path $appRoot 'sii-agent') -Recurse
Copy-Item -LiteralPath (Join-Path $stage 'assets') -Destination (Join-Path $appRoot 'assets') -Recurse
Copy-Item -LiteralPath (Join-Path $stage 'browsers') -Destination (Join-Path $appRoot 'browsers') -Recurse
$readme = @'
Agente SII portable · qlc.cl

Ejecuta AgenteSII.exe. Usa el token ya guardado en Windows Credential Manager para el usuario actual.
La aplicación muestra cola, actividad y logs; procesa una tarea por vez. Chromium queda abierto entre trabajos y las tareas consecutivas de la misma empresa reutilizan la sesión SII en memoria. La sesión se cierra al vaciarse la cola, cambiar de empresa o cerrar el agente. Cerrar cancela la tarea activa y sale. Reiniciar devuelve una tarea en curso a la cola.

No incluye .env, token del agente, claves tributarias ni cookies. El servidor se configura como https://qlc.cl.
'@
Set-Content -LiteralPath (Join-Path $appRoot 'LEEME.txt') -Value $readme -Encoding UTF8
$archive = Join-Path $buildRoot "AgenteSII-$OutputName.zip"
if (Test-Path -LiteralPath $archive) { Remove-Item -LiteralPath $archive -Force }
Compress-Archive -LiteralPath (Join-Path $distRoot 'AgenteSII') -DestinationPath $archive -CompressionLevel Optimal
Write-Host "Aplicación portable: $appRoot"
Write-Host "Archivo ZIP: $archive"
