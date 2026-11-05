# Agente SII para RCV (Playwright)

El agente corre en tu PC, recibe solicitudes desde la pantalla RCV de qlc.cl, inicia sesión en el SII con Playwright, descarga el detalle de compras o ventas y lo devuelve a la empresa y período seleccionados.

## Requisitos

- Node.js 18 o superior.
- Una terminal interactiva de Windows abierta mientras el agente está en uso.
- El PC emparejado con el mismo usuario de qlc.cl que crea la solicitud.

## Preparación

Desde PowerShell, en la carpeta del proyecto:

```powershell
cd C:\Users\local1\Documents\ChatGPT\icontador\sii-agent
npm install
npx playwright install chromium
Copy-Item .env.example .env
```

En qlc.cl abre **RCV → Vincular este PC → Generar código**. Copia el código temporal a `.env` en `SII_CODIGO_EMPAREJAMIENTO`. El agente lo canjea al iniciar, lo elimina del archivo y guarda el token propio de este PC. El código vence en 10 minutos.

No guardes la Clave Tributaria en `.env`, archivos ni argumentos.

## Ejecutar

```powershell
npm start
```

Deja esa ventana de PowerShell abierta. Desde qlc.cl, en **RCV**, elige el período y los libros y pulsa **Sincronizar SII**. El agente pedirá en la terminal la Clave Tributaria de la empresa; se escribe sin mostrar caracteres y se usa solo en memoria para iniciar sesión. Si el SII presenta un paso adicional, configura `SII_HEADLESS=false` en `.env` para ver la ventana de Playwright.

La sesión se cierra al terminar cada descarga. El agente valida tipo de libro, período y RUT antes de enviar los documentos a qlc.cl. Los CSV temporales se borran después de importarse, salvo que `SII_CONSERVAR=true`.

## Configuración

`.env` acepta estas opciones:

- `SII_URL_SERVIDOR=https://qlc.cl`
- `SII_CODIGO_EMPAREJAMIENTO`: código temporal del primer inicio.
- `SII_TOKEN_AGENTE`: lo escribe automáticamente el emparejamiento.
- `SII_HEADLESS=true`: usa Playwright sin mostrar el navegador.
- `SII_INTERVALO_SEG=60`: intervalo para revisar solicitudes nuevas.
- `SII_CARPETA=descargas`: ubicación de archivos temporales.
- `SII_CONSERVAR=false`: borra los CSV tras importarlos.

El agente requiere una terminal interactiva para pedir la clave; no uses el lanzador oculto ni el inicio automático del instalador mientras la autenticación dependa de este ingreso seguro.

## Seguridad y alcance

- La clave no se guarda en qlc.cl, `.env`, archivos, argumentos ni registros.
- El token del agente solo permite reclamar tareas creadas por el usuario que emparejó el PC. Puedes revocarlo desde RCV.
- La automatización de datos de empresa continúa deshabilitada; este agente procesa solamente compras y ventas del RCV.
- La importación manual de CSV oficiales sigue disponible.
