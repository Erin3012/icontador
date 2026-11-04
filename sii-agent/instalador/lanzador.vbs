' Lanza el agente SII sin ventana. Lo ejecuta el Programador de tareas al iniciar sesión en Windows.
' Está en la carpeta de instalación, junto a node\, sii-agent\ y browsers\. Sus registros van a logs\agente.log.
Option Explicit

Dim sh, fso, base, nodo, navegadores, registro, q, interno, comando

Set fso = CreateObject("Scripting.FileSystemObject")
Set sh = CreateObject("WScript.Shell")

q = Chr(34)
base = fso.GetParentFolderName(WScript.ScriptFullName)
nodo = base & "\node\node.exe"
navegadores = base & "\browsers"
registro = base & "\logs\agente.log"

If Not fso.FolderExists(base & "\logs") Then fso.CreateFolder base & "\logs"

' Chromium de Playwright viene dentro de la instalación, no en el perfil del usuario.
sh.Environment("PROCESS")("PLAYWRIGHT_BROWSERS_PATH") = navegadores
sh.CurrentDirectory = base & "\sii-agent"

' cmd /d /c "<node> src\index.js >> <registro> 2>&1": la salida queda en el registro y no se abre ventana.
interno = q & nodo & q & " src\index.js >> " & q & registro & q & " 2>&1"
comando = "cmd.exe /d /c " & q & interno & q
sh.Run comando, 0, False
