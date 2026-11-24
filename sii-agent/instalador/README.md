# Instalador del agente SII central (Windows)

El instalador prepara el único PC que ejecutará Playwright para atender solicitudes de RCV de qlc.cl:

- Incluye Node portable, Chromium y el código del agente.
- Pide la URL de qlc.cl y un código de servicio temporal que genera un administrador en **RCV → Estado del agente central**.
- En el primer arranque, el agente canjea el código, lo elimina de `.env` y guarda el token en Windows Credential Manager.
- Las claves tributarias se guardan cifradas por empresa en qlc.cl y se entregan al agente central solo al procesar una tarea.
- El lanzador abre una ventana visible. Deja el agente ejecutándose para que el servicio aparezca conectado y procese la cola.

Si el agente se revoca, el administrador genera un código nuevo. Agrégalo como `SII_CODIGO_SERVICIO` en el `.env` local y reinicia el agente; el token del Credential Manager se reemplaza al canjear el código.

## Construcción

En Windows con Inno Setup 6 instalado:

```powershell
cd sii-agent\instalador
powershell -ExecutionPolicy Bypass -File .\construir-instalador.ps1
```

El resultado queda en `sii-agent\build\salida`. La carpeta `build\` no se sube a Git. Antes de distribuirlo, prueba instalación, enrolamiento, reconexión tras revocar y eliminación del token desde Windows Credential Manager si desinstalas el agente.
