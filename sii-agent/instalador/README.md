# Instalador del agente SII (Windows)

El instalador deja el agente listo en el PC del usuario, sin que tenga que instalar Node ni ejecutar comandos:

- **Node portable** (versión fijada en `construir-instalador.ps1`) y **Chromium de Playwright** dentro de la carpeta de instalación.
- **Pide solo dos datos**: la dirección de Cifrax (por defecto `https://qlc.cl`) y el token del agente. Escribe `sii-agent\.env`.
- **No pide ni guarda claves tributarias**: la clave del SII vive cifrada en Cifrax y el servidor la entrega al agente al reclamar cada tarea.
- **Inicio automático**: crea la tarea "Icontador SII Agente" del Programador de tareas para el inicio de sesión del usuario, sin privilegios de administrador. Lanza `lanzador.vbs`, que corre el agente oculto y guarda su salida en `logs\agente.log`.
- **Desinstalación**: borra la tarea del Programador de tareas. Si el agente está corriendo, ciérralo antes desde el Administrador de tareas (proceso `node.exe`).

## Cómo se construye

En un PC con Windows, con Inno Setup 6 instalado:

```powershell
cd sii-agent\instalador
powershell -ExecutionPolicy Bypass -File .\construir-instalador.ps1
```

Queda `sii-agent\build\salida\IcontadorSII-Agente-1.0.0.exe`. La carpeta `build\` no se sube a git.

El script arma `build\staging\` con esta disposición, que es la que espera `src/index.js` (`../../assets/rcv-import.js`):

```
staging\
  node\            Node portable
  sii-agent\       src\, package.json, dependencias de producción
  assets\          rcv-import.js
  browsers\        Chromium de Playwright
  lanzador.vbs
```

## Qué revisar al primer uso

- Ejecuta el instalador en un PC de prueba y confirma que `logs\agente.log` muestra "Agente SII activo".
- Si el SII pide CAPTCHA o un paso extra, cambia `SII_HEADLESS=false` en `sii-agent\.env` para ver la ventana.
- Actualizaciones: por ahora se hacen volviendo a instalar la versión nueva sobre la anterior. Si el instalador se corre de nuevo, pide URL y token otra vez.

## Pendiente

- No se pudo compilar ni probar en Windows desde el entorno de desarrollo (Linux). Hay que correr `construir-instalador.ps1` y probar el instalador en Windows antes de usarlo con clientes.
- Bandeja del sistema y actualizaciones automáticas no están incluidas (quedaron fuera de la opción elegida).
