# Prototipo de integración SII (deshabilitado)

El usuario confirmó el 5 de noviembre de 2026 que el SII autorizó un canal técnico. Aún falta incorporar al proyecto la especificación del canal y adaptar el agente. Por eso la automatización sigue deshabilitada y la importación manual de CSV oficiales continúa disponible en RCV.

- Cuando se implemente el canal, la autenticación seguirá su especificación. El agente actual no solicita ni guarda credenciales del SII.
- El adaptador de autenticación dependerá de la especificación recibida; no se debe asumir que el canal usa Clave Tributaria.
- Las rutas automáticas no se ejecutan mientras `src/sii.js` mantiene el canal deshabilitado.

El agente no consulta la cola mientras el adaptador está deshabilitado. Para importar RCV, descarga los archivos desde la pantalla oficial del SII y cárgalos en la vista RCV.

La inspección visual del portal y de la pantalla de acceso, realizada con un perfil de navegador temporal, está documentada en [`INSPECCION-SII.md`](./INSPECCION-SII.md). Se puede repetir con `node .\tools\inspect-sii-ui.cjs`; no inicia sesión ni descarga información tributaria.

## Requisitos

- Node.js 18 o superior.
- Un agente emparejado a la cuenta de usuario correspondiente. La integración no se activa hasta configurar el canal autorizado.

## Instalación (una vez)

```powershell
cd icontador\sii-agent
npm install
npx playwright install chromium
copy .env.example .env
```

Luego edita `.env`:

- `SII_URL_SERVIDOR`: la dirección de Cifrax, por ejemplo `https://qlc.cl`.
- `SII_CODIGO_EMPAREJAMIENTO`: código temporal generado por Cifrax. El agente lo canjea por un token exclusivo de este PC y elimina el código del `.env`.
- `SII_TOKEN_AGENTE`: token exclusivo que el agente guarda al emparejarse.

No copies la Clave Tributaria a `.env` ni a argumentos. El adaptador aún no solicita credenciales porque no sabemos qué autenticación requiere el canal autorizado.

## Preparación futura

No ejecutes el agente todavía. Cuando se habilite un canal autorizado, se podrá iniciar con:

```powershell
npm start
```

## Estado de la integración

La consulta pública de situación tributaria de terceros permite consultar razón social y giro con el RUT, sin iniciar sesión; el SII la documenta como consulta abierta. En cambio, el Web Service que entrega identificación y actividades económicas está descrito para un convenio institucional: requiere perfil asignado e IP autorizada por el SII. No es una API general para conectar cualquier hosting.

`src/sii.js` bloquea las consultas automáticas y no automatiza la interfaz del SII. El canal fue autorizado según lo informado por el titular; para implementarlo falta su documentación técnica y validar que cada respuesta corresponda al RUT y período solicitados. La consulta pública de terceros y la descarga manual de CSV se mantienen como alternativas oficiales.

Fuentes oficiales: [consulta de terceros](https://www.sii.cl/como_se_hace_para/situacion_trib_terceros.html), [SII: servicios y consultas disponibles](https://www.sii.cl/ccp/consultas_sit_trib.html), [Resolución SII 129/2019, convenio SENCE y perfiles/IP autorizadas](https://www.sii.cl/normativa_legislacion/resoluciones/2019/reso129.pdf).

## Seguridad

- `.env` y `descargas/` están en `.gitignore`: no se suben a git. `.env` contiene la URL, el token propio del agente y, durante el emparejamiento, un código temporal; nunca la Clave Tributaria.
- El token de cada agente solo debe acceder a tareas creadas por el usuario al que fue emparejado. Si se filtra, revoca ese PC desde Cifrax.
- Los endpoints del agente están en `api/sii-agente.php`; no usan sesión y exigen el token. El resto de la API exige sesión.
