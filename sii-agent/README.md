# Agente SII central para RCV y Honorarios

Para ejecutar el worker como servicio en **Ubuntu 24.04**, consulta [ubuntu/README.md](ubuntu/README.md). La GUI Tkinter y el EXE son para Windows; en Ubuntu el agente mantiene Chromium headless y se administra con systemd.

## Aplicación portable Tkinter

Para compilar en Windows con Python 3.14 y PyInstaller instalado, ejecuta desde la raíz del proyecto:

```powershell
powershell -ExecutionPolicy Bypass -File .\sii-agent\construir-portable.ps1
```

Abre `sii-agent\build\portable\AgenteSII\AgenteSII.exe`. La carpeta y `AgenteSII-portable.zip` incluyen Node, Playwright y Chromium. El paquete no incluye `.env`, tokens, claves tributarias ni cookies; el token actual se obtiene de Windows Credential Manager. La app muestra el estado de la cola, los logs, cancela una tarea seleccionada y reinicia reencolando la tarea activa. Procesa un trabajo a la vez.

El panel depende de las acciones `estado`, `control` y `finalizar_control` de `api/sii-agente.php`. El estado ofrece resúmenes sin RUT ni secretos y no reclama tareas.

En modo GUI Chromium se inicia al abrir la app y queda abierto entre trabajos. Las tareas consecutivas de la misma empresa reutilizan un único contexto autenticado en memoria; al vaciarse la cola o cambiar de empresa, el agente cierra la sesión y el contexto, pero conserva Chromium abierto. Si vence la sesión, vuelve a autenticarse; las cookies nunca se guardan en disco. Las tareas mantienen estado separado por mes y libro. En MiSII, las tablas auxiliares de sucursales, características y documentos autorizados son opcionales: si una sección no aparece, se omite y se devuelven los datos principales disponibles.

Un único agente corre en el PC administrador y atiende las solicitudes de RCV y boletas de honorarios recibidas/emitidas que los usuarios autorizados crean en qlc.cl. Las tareas mensuales de Honorarios se guardan en el módulo existente de Honorarios y muestran su estado en RCV. Los usuarios no instalan Playwright. Cada tarea conserva su empresa y solicitante; la API solo la permite para empresas que el usuario ya puede usar.

### Diagnóstico visible en WSL

Para probar la navegación del SII sin importar datos a qlc.cl, ejecuta en una terminal WSL interactiva desde el repositorio:

```bash
cd /mnt/c/Users/local1/Documents/ChatGPT/icontador/sii-agent
node scripts/prueba-visible-honorarios.js '76.000.000-0' 2026-10 ambas
```

La clave se solicita con entrada oculta. Chromium permanece visible; los informes y capturas de error se guardan en `~/icontador-sii-diagnostico` con permisos privados. La prueba no envía ni importa resultados al servidor.

## Requisitos del servidor

- HTTPS activo en qlc.cl.
- `ICONTADOR_SII_CLAVE_CIFRADO`: llave AES-256 de 32 bytes codificada en Base64 y configurada como variable de entorno del hosting; o `ICONTADOR_PRIVATE_CONFIG`, una ruta absoluta a un PHP privado fuera de `DOCUMENT_ROOT` que devuelva `['sii_clave_cifrado' => '...']`.
- Mantén exactamente la misma llave para descifrar cualquier clave ya guardada. Antes de mover una llave desde una configuración antigua, copia el mismo valor al entorno privado; no la rotes sin re-cifrar los registros.

Genera una llave una sola vez con PHP: `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"`. No la subas a Git ni la guardes en `public_html`.

## Enrolar el agente central

En el PC que quedará encendido:

```powershell
cd C:\Users\local1\Documents\ChatGPT\icontador\sii-agent
npm install
npx playwright install chromium
Copy-Item .env.example .env
```

En `.env`, conserva `SII_URL_SERVIDOR=https://qlc.cl`. El operador genera el código temporal por SSH en el hosting, fuera del directorio público. Ese código no se pega en `.env`: el enrolamiento lo solicita en forma oculta y guarda el token técnico en Windows Credential Manager. Ejecuta:

```powershell
npm start
```

El agente canjea el código una sola vez. Si vence o se pierde antes del canje, el operador genera otro por CLI en el hosting. La operación de revocación también queda reservada a la consola del servidor.

Mantén la terminal abierta y el PC conectado. El agente reclama una tarea a la vez y publica su estado. Si el agente ya está corriendo, otra instancia queda en espera. La clave técnica no se almacena en `.env`; al detener el proceso se conserva en Windows Credential Manager.

## Claves y sincronización

En **RCV**, cada usuario con acceso a una empresa puede guardar, reemplazar o eliminar su Clave Tributaria. qlc.cl la cifra en reposo con AES-256-GCM. No vuelve a mostrar el valor. El agente central la recibe por HTTPS junto con una tarea autorizada, la usa en memoria para iniciar sesión en el SII y la descarta después de procesar el libro. No se registra en logs ni se escribe en archivos locales.

El usuario elige el período y compras, ventas o ambos, y pulsa **Sincronizar SII**. El sistema valida empresa, RUT, período y tipo de libro antes de guardar los documentos. Los CSV temporales se eliminan después de importarlos, salvo que `SII_CONSERVAR=true`.

## Configuración opcional

- `SII_HEADLESS=true`: usa Playwright sin mostrar Chromium. Usa `false` para inspeccionar visualmente un problema.
- `SII_CANAL=chrome`: usa Chrome instalado en Windows en vez del Chromium de Playwright.
- `SII_INTERVALO_SEG=30`: segundos entre revisiones de la cola.
- `SII_CARPETA=descargas`: ubicación de CSV temporales dentro de `sii-agent/`.
- `SII_CONSERVAR=false`: borra CSV después de importarlos.

No uses `SII_TOKEN_AGENTE` ni `SII_CLAVES`; pertenecen a implementaciones anteriores y el agente los rechaza. El agente procesa únicamente RCV de compras y ventas. La consulta automática de datos de empresa permanece deshabilitada. La importación manual de CSV oficiales sigue disponible.
