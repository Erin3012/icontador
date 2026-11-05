' Lanza el agente SII en una ventana visible, necesaria para escribir la clave sin mostrarla.
' Está en la carpeta de instalación, junto a node\, sii-agent\ y browsers\.
Option Explicit

Dim sh, fso, base, nodo, navegadores, q, comando

Set fso = CreateObject("Scripting.FileSystemObject")
Set sh = CreateObject("WScript.Shell")

q = Chr(34)
base = fso.GetParentFolderName(WScript.ScriptFullName)
nodo = base & "\node\node.exe"
navegadores = base & "\browsers"

' Chromium de Playwright viene dentro de la instalación, no en el perfil del usuario.
sh.Environment("PROCESS")("PLAYWRIGHT_BROWSERS_PATH") = navegadores
sh.CurrentDirectory = base & "\sii-agent"

comando = q & nodo & q & " src\index.js"
sh.Run comando, 1, False
