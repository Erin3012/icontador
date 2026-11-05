# Agente SII: sincronización del RCV desde tu PC

Cifrax corre en un hosting compartido (cPanel, PHP y MySQL) donde no se puede instalar Playwright. Por eso el navegador corre en **tu PC**: este agente consulta Cifrax, inicia sesión en el SII con tu clave tributaria, descarga el Registro de Compras y Ventas (RCV) del período pedido y lo envía de vuelta a Cifrax, que lo guarda igual que una importación manual.

- La **clave tributaria se guarda cifrada en el servidor**, por empresa. Se escribe en RCV, en *Clave tributaria del SII*. El servidor la entrega al agente solo al reclamar una tarea de esa empresa, y el agente no la escribe en disco ni en el registro.
- El agente solo ve las tareas de las empresas a las que tienes acceso en Cifrax (el servidor filtra por empresa y rol).
- Hoy sincroniza **RCV de compras y ventas**. Honorarios queda para después.

## Cómo funciona

1. En Cifrax, en **RCV**, eliges un período, marcas Compras, Ventas o ambos y pulsas **Sincronizar SII**. Eso crea una tarea pendiente.
2. El agente, que corre en tu PC, revisa las tareas cada minuto (configurable) y reclama las pendientes.
3. Abre Chromium, entra al SII, descarga el detalle del libro, valida que sea el período y el libro pedidos y lo envía a Cifrax.
4. Cifrax guarda los documentos y la tarea pasa a **completada**. Si algo falla (clave incorrecta, el SII no responde, un archivo de otro RUT), la tarea queda en **error** con el motivo.

## Requisitos

- Node.js 18 o superior.
- El token del agente configurado en el servidor (ver abajo).

## Instalación (una vez)

```powershell
cd icontador\sii-agent
npm install
npx playwright install chromium
copy .env.example .env
```

Luego edita `.env`:

- `SII_URL_SERVIDOR`: la dirección de Cifrax, por ejemplo `https://qlc.cl`.
- `SII_CODIGO_EMPAREJAMIENTO`: el código de un solo uso que generas en Cifrax (RCV, *Vincular este PC*). Al primer arranque el agente lo canjea por su token propio, lo guarda en `SII_TOKEN_AGENTE` y borra el código. Vence en 10 minutos.
- `SII_TOKEN_AGENTE`: lo llena el emparejamiento. Si lo configuras a mano, usa el mismo valor que `sii_agente_token` en `api/config.php` del servidor.
- `SII_CLAVES` (opcional): clave por RUT para casos sin clave guardada en Cifrax, como la consulta de datos de una empresa nueva. Si la empresa tiene clave guardada, esta no se usa.

Para crear el token en el servidor, usa un valor largo y aleatorio, por ejemplo con `php -r "echo bin2hex(random_bytes(24));"`, y ponlo en `api/config.php`:

```php
'sii_agente_token' => 'el-token-generado',
```

(También sirve la variable de entorno `ICONTADOR_SII_TOKEN`.)

## Uso

Para dejarlo corriendo mientras trabajas:

```powershell
npm start
```

Para probar una sola vez, por ejemplo después de pedir una sincronización:

```powershell
npm run una-vez
```

La primera vez conviene ver la ventana del navegador: pon `SII_HEADLESS=false` en `.env`. Si el SII pide un CAPTCHA o un paso extra, resuélvelo en esa ventana.

Cada CSV descargado se borra después de importarlo. Para conservarlos, pon `SII_CONSERVAR=true`.

## Explorar la pantalla del SII después del login

Para decidir qué datos extraer, el agente puede guardar lo que ve tras iniciar sesión:

```powershell
npm run explorar
```

Genera un JSON en `descargas/` con los enlaces, campos y texto visible de la página. Es información tributaria: queda solo en tu PC, no se envía a Cifrax y no se sube a git. Revísalo antes de compartirlo.

## Pruebas

```powershell
npm test
```

Las pruebas no abren el navegador ni llaman al SII: verifican la lectura del `.env`, la validación de RUT y período, y el envío al servidor con datos de prueba.

En el servidor, `php tools/test-sii-api.php` prueba la cola de tareas, el reclamo del agente y la importación.

## Qué revisar al primer uso

Las direcciones del SII, los nombres de los campos de inicio de sesión y el selector de período están agrupados en `src/sii.js`, en el objeto `SII`. No se pudieron verificar contra el sitio real al escribir el agente (no hay acceso al SII desde el entorno de desarrollo), y **el SII cambia sus pantallas**: si una tarea falla con "No se pudo iniciar sesión" o "No se pudo descargar el RCV", abre `src/sii.js` con `SII_HEADLESS=false` y ajusta el selector que no encuentra.

## Seguridad

- `.env` y `descargas/` están en `.gitignore`: no se suben a git.
- El token del agente da acceso a las tareas de sincronización y a la importación del RCV. Si se filtra, cambia `sii_agente_token` en el servidor y en `.env`.
- Los endpoints del agente están en `api/sii-agente.php`; no usan sesión y exigen el token. El resto de la API exige sesión.
