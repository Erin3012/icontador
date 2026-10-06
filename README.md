# iContador local

Copia del HTML renderizado, estilos, imágenes y fuentes servidos al navegador el 2 de octubre de 2026. Se revisaron los 16 módulos del menú de esta cuenta. Consulta `index.html` para recorrer las capturas y `inventory.json` para conocer la cobertura y las exclusiones.

## Abrir

Desde esta carpeta, con Node.js instalado:

```powershell
npm start
```

Abre http://127.0.0.1:4173. El servidor escucha únicamente en tu equipo. No necesitas instalar dependencias para consultar la copia. También puedes abrir `index.html` directamente.

## RCV con base de datos (PHP)

La pantalla RCV importa los CSV de detalle de compras y ventas que se descargan del SII, arma ambos libros y calcula el IVA del mes. Para guardar los libros en la base de datos, inicia la copia con PHP 8.1 o superior:

```powershell
npm run start:php
```

Sin configuración usa SQLite en `data/icontador.sqlite`. Para MySQL, copia `api/config.example.php` como `api/config.php` con tus datos de conexión (o usa las variables `ICONTADOR_DB_DSN`, `ICONTADOR_DB_USER` e `ICONTADOR_DB_PASS`); la tabla está en `api/schema/rcv.mysql.sql` y se crea sola. Con `npm start` la importación funciona igual, pero no guarda nada. Hay CSV ficticios en `samples/`; `npm test` y `npm run test:php` comprueban el cálculo y el guardado. Para correr las pruebas PHP contra MySQL/MariaDB (como en cPanel), define `ICONTADOR_TEST_MYSQL="host=127.0.0.1;port=3306"`, `ICONTADOR_TEST_USER` e `ICONTADOR_TEST_PASS`: cada prueba crea y borra su propia base.

## Vouchers y libros contables (PHP)

Las pantallas **Voucher**, **Crear Voucher**, **Libro Diario** y **Libro Mayor** guardan y leen la misma base de datos que el RCV, así que también necesitan `npm run start:php` (o Apache/XAMPP); con `npm start` muestran un aviso.

- Las tablas `cuentas`, `vouchers` y `voucher_lineas` se crean al primer uso; el plan de cuentas se carga por empresa desde Plan de Cuenta.
- Un voucher solo se guarda si Debe = Haber, tiene al menos dos líneas y cada línea usa una cuenta del plan con un monto en Debe o en Haber. La validación se hace en PHP (`api/lib/vouchers.php`).
- El número de comprobante es correlativo por tipo (Ingreso, Egreso, Traspaso) y mes.
- Libro Diario y Libro Mayor se generan desde la base de datos con filtros de fecha y tipo de contabilidad (Tributario/IFRS). PDF imprime el reporte; EXCEL y CSV descargan un CSV.
- API: `api/vouchers.php` (GET, POST, PUT, DELETE), `api/cuentas.php` (plan de cuentas) y `api/libros.php?libro=diario|mayor|balance|resultado`.

## Datos por empresa

**Empresas** lista las empresas de la base y permite seleccionarlas, crear una nueva (RUT con dígito verificador, régimen y contacto; opcionalmente con el plan de cuentas de ejemplo) y **exportar/importar** una empresa completa en un archivo JSON: ficha, plan de cuentas, vouchers, RCV, liquidaciones y datos importados de iContador. Así se pasa una empresa de la base SQLite local a la base MySQL del sitio publicado: en la copia local, Empresas → Exportar; en el sitio, Empresas → Importar empresa desde archivo. Si la empresa ya existe en el destino, sus datos se reemplazan por los del archivo.

**Importar ficha de empresas (CSV)** (solo administradores) carga el archivo que exporta icontador.cl en Empresas → Ficha de Empresas (punto y coma, Windows-1252 o UTF-8). Cada fila se busca por RUT (o por razón social si la empresa existente no tiene RUT): las nuevas se crean sin plan de cuentas y las existentes solo actualizan su ficha (los campos vacíos del archivo no borran datos; vouchers, plan de cuentas y demás datos no se tocan). Si un RUT se repite, gana la última fila. Desde la terminal: `php tools/importar-ficha-empresas.php "Ficha Empresas.csv" [--simular]`.

Vouchers, RCV y liquidaciones se guardan por empresa. La pantalla de inicio pide elegir la empresa y cada pantalla envía su id a la API en la cabecera `X-Empresa-Id` (la función `empresa_actual()` de `api/lib/empresas.php` la lee y valida). Al actualizar una base anterior, los datos guardados pasan a la única empresa importada; si hay varias, quedan sin empresa y solo se ven sin empresa seleccionada. Cada empresa tiene su propio plan de cuentas: en **Plan de Cuenta**, “Cargar plan de ejemplo” agrega un plan base de pyme (63 cuentas, sin pisar las existentes) y también se pueden agregar o eliminar cuentas una a una (solo las que no tienen movimientos). La importación desde iContador (`tools/importar-empresa.php`) guarda el plan en la empresa importada.

## Balance, Estado de Resultado y Libros de Compras y Ventas (PHP)

- **Balance General** (8 columnas) y **Estado de Resultado** se calculan desde los vouchers guardados, con los mismos filtros de fecha y tipo de contabilidad que el Libro Diario. Las cuentas se clasifican por el primer dígito del código: 1 activo, 2 pasivo y patrimonio, 3 costos y gastos, 4 ingresos.
- **Libro Compras** y **Libro Ventas** leen los documentos del RCV ya importados (`api/rcv.php?libro=compras|ventas&desde=&hasta=&tipo=`). Las notas de crédito restan en los totales.
- PDF imprime el reporte; EXCEL y CSV descargan un CSV. Los formatos propios del SII (Libro Compra Electrónico, Caracterización) aún no están disponibles.
- **Plan de Cuenta** muestra el plan de la empresa seleccionada, con el detalle importado de iContador cuando existe (`api/plan-cuentas.php`).

## Acceso con usuario y clave (PHP)

Con `npm run start:php` (o Apache/cPanel) la copia exige iniciar sesión: las pantallas redirigen a `/auth/login.php` y cada `api/*.php` responde 401 sin una sesión aprobada.

1. Crea la primera cuenta de administrador desde la terminal (pide la clave sin mostrarla):

   ```powershell
   php tools/crear-admin.php tu-correo@ejemplo.cl "Tu nombre"
   ```

2. Otras personas se registran en `/auth/registro.php`. Su cuenta queda **pendiente** y no puede entrar hasta que la apruebes.
3. En `/auth/usuarios.php` (enlace "Usuarios y aprobaciones" en el índice) apruebas o rechazas registros, deshabilitas cuentas y das o quitas el rol de administrador. Deshabilitar corta el acceso de inmediato.

Las claves se guardan con `password_hash`. La tabla `usuarios` se crea sola (`api/schema/usuarios.mysql.sql` para MySQL). En Apache, `.htaccess` hace pasar las pantallas HTML por `auth/vista.php` y bloquea `data/`, `tools/`, `api/lib/` y la configuración. Todo endpoint nuevo en `api/` debe empezar con `require_once __DIR__ . '/lib/sesion.php';`; `npm run test:php` lo comprueba.

## Trabajar con los archivos

- `views/`: pantallas HTML editables recuperadas del navegador.
- `assets/`: CSS, imágenes, fuentes y comportamiento visual local en `offline.js`.
- `catalog.json`: relación de pantallas capturadas.
- `inventory.json` y `coverage-notes.json`: recursos no recuperados y límites por pantalla.
- `reference/javascript/`: cuatro paquetes JavaScript originales para estudiar el frontend. Nunca se ejecutan en la copia y el servidor local bloquea esta carpeta.
- `reference/styles/`: estilos originales de referencia; los CSS activos usan rutas locales.
- `tools/`: servidor, sanitización, preparación y verificación. Para ejecutar estas utilidades de edición: `npm ci`.

Después de editar la navegación o el catálogo:

```powershell
node tools/finalize.cjs
npm run prepare-local
npm test
```

## Calculadora de liquidación

`views/remuneraciones-calculadora.html` (botón **Calculadora** del módulo Remuneraciones) calcula en el navegador una liquidación de sueldo a partir del sueldo bruto: gratificación legal, total imponible, AFP, salud (Fonasa o Isapre), seguro de cesantía, base tributable, impuesto único, sueldo líquido y aportes del empleador. Los parámetros de octubre 2026 están en `assets/liquidacion.js` y se pueden editar en pantalla. Con `npm run start:php` la liquidación se guarda por trabajador y período en la tabla `liquidaciones` (API `api/liquidaciones.php`, esquema MySQL en `api/schema/liquidaciones.mysql.sql`) usando la misma conexión que el RCV y los vouchers. Las pruebas están en `tools/test-liquidacion.cjs` y `tools/test-liquidaciones-api.php`.

## Cobertura y límites

Activos Fijos, Tributario y Cont. Express muestran avisos de servicio no contratado; se conservan esos avisos. Contratos, finiquitos y cargas familiares dependen de guardar un empleado. El detalle de una cartola bancaria necesita una cartola creada. No se crearon registros ni se cambiaron permisos, datos contables o credenciales.

Salvo las pantallas descritas arriba (Voucher, Libro Diario, Libro Mayor, Balance General, Estado de Resultado, Libros de Compras y Ventas, RCV, Plan de Cuenta, Calculadora de liquidación y el selector de empresa), los formularios y filtros son referencias visuales. No hay autenticación local. En las demás pantallas, guardar, borrar, enviar, pagar, sincronizar e importar archivos están desactivados y los reportes conservan solo su pantalla de configuración. Tampoco se descargaron informes con registros reales.

Las tablas contienen ejemplos; los campos personales y contraseñas están vacíos; las series de gráficos fueron sustituidas por barras ficticias. Los scripts originales, eventos inline, enlaces externos y llamadas al servidor se eliminan de las vistas activas. Una política CSP bloquea conexiones y envíos de formularios. No se guardan cookies, tokens ni contraseñas.

Se conserva la estructura y el estilo recibido. Las diferencias visuales incluyen el aviso superior de copia local, diálogos colocados dentro de la página, opciones genéricas, gráficos ficticios y algunos recursos decorativos omitidos. Las ayudas en vídeo, la paginación remota y listas dinámicas no funcionan sin backend. Los recursos que el navegador no recibió y los archivos con HTTP 404 aparecen en el inventario.

La navegación usa el índice y enlaces locales entre módulos, pestañas y formularios capturados. Las operaciones no disponibles muestran un aviso. Las verificaciones están en `verification.json` y `verification/`.

## Sugerencias y reportes

Cada pantalla tiene un botón **Reportar problema** (abajo a la derecha) que abre `views/sugerencias.html` con la pantalla de origen ya indicada. Quien tenga sesión puede describir el problema o la sugerencia y adjuntar hasta 5 archivos de 10 MB (imágenes, PDF, TXT, CSV, Excel o Word); también se puede pegar una captura con Ctrl+V. Cada usuario ve sus propios reportes y la respuesta del administrador.

Un administrador ve todos los reportes en la misma pantalla (también enlazada desde el índice), los filtra por estado (nueva, en revisión, resuelta) y deja una nota para quien reportó.

- API: `api/sugerencias.php` (GET lista, POST crea, PATCH cambia estado; `?adjunto=N` descarga un adjunto solo a su dueño o a un administrador). Tablas `sugerencias` y `sugerencia_adjuntos` (se crean solas; MySQL en `api/schema/sugerencias.mysql.sql`).
- Los adjuntos se guardan con nombre aleatorio en `data/adjuntos/`, que no se publica (`.htaccess`). Para guardarlos fuera del sitio, define `adjuntos_dir` en `api/config.php` o la variable `ICONTADOR_ADJUNTOS_DIR`. Se valida la extensión y el contenido real de cada archivo.
- En cPanel, `.user.ini` sube el límite de PHP a 10 MB por archivo.
- Prueba: `php tools/test-sugerencias-api.php` (incluida en `npm run test:php`).
