'use strict';
// Guarda el token técnico en Windows Credential Manager o lo lee desde systemd credentials.
// El secreto viaja al proceso PowerShell por su entorno, nunca como argumento de línea de comandos.
const { execFile } = require('node:child_process');
const { promisify } = require('node:util');
const execFileAsync = promisify(execFile);

const TARGET = 'qlc.cl/iContadorSiiAgent';
const SCRIPT = String.raw`
$ErrorActionPreference = 'Stop'
Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;
public static class IcontadorCred {
  [StructLayout(LayoutKind.Sequential, CharSet=CharSet.Unicode)]
  public struct CREDENTIAL {
    public UInt32 Flags; public UInt32 Type;
    [MarshalAs(UnmanagedType.LPWStr)] public string TargetName;
    [MarshalAs(UnmanagedType.LPWStr)] public string Comment;
    public UInt64 LastWritten; public UInt32 CredentialBlobSize; public IntPtr CredentialBlob;
    public UInt32 Persist; public UInt32 AttributeCount; public IntPtr Attributes;
    [MarshalAs(UnmanagedType.LPWStr)] public string TargetAlias;
    [MarshalAs(UnmanagedType.LPWStr)] public string UserName;
  }
  [DllImport("advapi32.dll", EntryPoint="CredWriteW", CharSet=CharSet.Unicode, SetLastError=true)]
  public static extern bool CredWrite(ref CREDENTIAL credential, UInt32 flags);
  [DllImport("advapi32.dll", EntryPoint="CredReadW", CharSet=CharSet.Unicode, SetLastError=true)]
  public static extern bool CredRead(string target, UInt32 type, UInt32 flags, out IntPtr credential);
  [DllImport("advapi32.dll", EntryPoint="CredDeleteW", CharSet=CharSet.Unicode, SetLastError=true)]
  public static extern bool CredDelete(string target, UInt32 type, UInt32 flags);
  [DllImport("advapi32.dll")] public static extern void CredFree(IntPtr buffer);
}
'@
$p = ConvertFrom-Json -InputObject $env:ICONTADOR_CRED_PAYLOAD
$target = 'qlc.cl/iContadorSiiAgent'
switch ($p.op) {
  'set' {
    $bytes = [Text.Encoding]::Unicode.GetBytes([string]$p.token)
    $mem = [Runtime.InteropServices.Marshal]::AllocCoTaskMem($bytes.Length)
    try {
      [Runtime.InteropServices.Marshal]::Copy($bytes, 0, $mem, $bytes.Length)
      $c = New-Object IcontadorCred+CREDENTIAL
      $c.Type = 1; $c.TargetName = $target; $c.UserName = 'qlc.cl SII central worker'
      $c.CredentialBlobSize = [UInt32]$bytes.Length; $c.CredentialBlob = $mem; $c.Persist = 2
      if (-not [IcontadorCred]::CredWrite([ref]$c, 0)) { throw 'Windows no pudo guardar la credencial del agente.' }
    } finally { [Runtime.InteropServices.Marshal]::FreeCoTaskMem($mem) }
    [Console]::Write('ok')
  }
  'get' {
    $ptr = [IntPtr]::Zero
    if (-not [IcontadorCred]::CredRead($target, 1, 0, [ref]$ptr)) { exit 3 }
    try {
      $c = [Runtime.InteropServices.Marshal]::PtrToStructure($ptr, [type][IcontadorCred+CREDENTIAL])
      $bytes = New-Object byte[]([int]$c.CredentialBlobSize)
      [Runtime.InteropServices.Marshal]::Copy($c.CredentialBlob, $bytes, 0, $bytes.Length)
      [Console]::Write([Text.Encoding]::Unicode.GetString($bytes))
    } finally { [IcontadorCred]::CredFree($ptr) }
  }
  'delete' {
    [void][IcontadorCred]::CredDelete($target, 1, 0)
    [Console]::Write('ok')
  }
  default { throw 'Operación de credencial inválida.' }
}
`;

async function invoke(op, token) {
  if (process.platform !== 'win32') throw new Error('El almacén seguro del agente central requiere Windows Credential Manager.');
  let salida;
  try {
    ({ stdout: salida } = await execFileAsync('powershell.exe', ['-NoLogo', '-NoProfile', '-NonInteractive', '-Command', SCRIPT], {
      windowsHide: true,
      timeout: 15000,
      maxBuffer: 1024 * 1024,
      env: { ...process.env, ICONTADOR_CRED_PAYLOAD: JSON.stringify({ op, token: token || '' }) },
    }));
  } catch (error) {
    if (op === 'get' && error.code === 3) return null;
    throw new Error(op === 'get' ? 'No se pudo leer el token del agente desde Windows Credential Manager.' : 'No se pudo actualizar Windows Credential Manager.');
  }
  return salida.trim();
}

const NOMBRE_CREDENCIAL_SYSTEMD = 'icontador-sii-agent-token';

function leerTokenSystemd() {
  const directorio = process.env.CREDENTIALS_DIRECTORY;
  if (!directorio) return null;
  const ruta = require('node:path').join(directorio, NOMBRE_CREDENCIAL_SYSTEMD);
  let token;
  try { token = require('node:fs').readFileSync(ruta, 'utf8').trim(); }
  catch { return null; }
  if (token && !/^[a-f0-9]{64}$/i.test(token)) throw new Error('La credencial systemd del agente no es válida. Vuelve a enrolar el servicio.');
  return token || null;
}

async function guardarToken(token) {
  if (typeof token !== 'string' || token.length < 32) throw new Error('Token de servicio inválido.');
  if (process.platform !== 'win32') throw new Error('En Linux, enrola el agente con «npm run enrolar:linux» para guardar el token cifrado por systemd.');
  await invoke('set', token);
}
async function leerToken() {
  if (process.platform !== 'win32') return leerTokenSystemd();
  const token = await invoke('get');
  if (token && !/^[a-f0-9]{64}$/i.test(token)) throw new Error('El token guardado en Windows Credential Manager no es válido. Vuelve a enrolar el agente.');
  return token || null;
}
async function borrarToken() {
  if (process.platform !== 'win32') throw new Error('En Linux, elimina la credencial cifrada del servicio systemd para revocar este equipo.');
  await invoke('delete');
}

module.exports = { guardarToken, leerToken, borrarToken, leerTokenSystemd, NOMBRE_CREDENCIAL_SYSTEMD };
