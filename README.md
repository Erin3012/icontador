# iContador local

Copia del HTML renderizado, estilos, imágenes y fuentes servidos al navegador el 2 de octubre de 2026. Se revisaron los 16 módulos del menú de esta cuenta. Consulta `index.html` para recorrer las capturas y `inventory.json` para conocer la cobertura y las exclusiones.

## Abrir

Desde esta carpeta, con Node.js instalado:

```powershell
npm start
```

Abre http://127.0.0.1:4173. El servidor escucha únicamente en tu equipo. No necesitas instalar dependencias para consultar la copia. También puedes abrir `index.html` directamente.

## Vouchers y libros contables (PHP)

Las pantallas **Voucher**, **Crear Voucher**, **Libro Diario** y **Libro Mayor** funcionan con una base de datos en PHP (`api/`). Para usarlas inicie el servidor PHP en lugar de `npm start`:

```powershell
npm run start:php
```

Esto ejecuta `php -S 127.0.0.1:4173 tools/php-router.php` (requiere PHP 8.1 o superior con PDO). También funciona copiando la carpeta en Apache/XAMPP.

- La conexión es la compartida de `api/db.php`: sin configuración usa SQLite (`data/icontador.sqlite`, se crea solo). Para MySQL/MariaDB copie `api/config.example.php` como `api/config.php` con sus datos (o use `ICONTADOR_DB_DSN`, `ICONTADOR_DB_USER` e `ICONTADOR_DB_PASS`); las tablas se crean al primer uso.
- Un voucher solo se guarda si Debe = Haber, tiene al menos dos líneas y cada línea usa una cuenta del plan con un monto en Debe o en Haber. La validación se hace en PHP (`api/lib/vouchers.php`).
- El número de comprobante es correlativo por tipo (Ingreso, Egreso, Traspaso) y mes.
- Libro Diario y Libro Mayor se generan desde la base de datos con filtros de fecha y tipo de contabilidad (Tributario/IFRS). PDF imprime el reporte; EXCEL y CSV descargan un CSV.
- API: `api/vouchers.php` (GET, POST, PUT, DELETE), `api/cuentas.php` (plan de cuentas) y `api/libros.php?libro=diario|mayor`.
- Pruebas: `npm test` (vistas y JavaScript) y `npm run test:php` (lógica contable y base de datos).

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

## Cobertura y límites

Activos Fijos, Tributario y Cont. Express muestran avisos de servicio no contratado; se conservan esos avisos. Contratos, finiquitos y cargas familiares dependen de guardar un empleado. El detalle de una cartola bancaria necesita una cartola creada. No se crearon registros ni se cambiaron permisos, datos contables o credenciales.

Los formularios y filtros son referencias visuales. No hay PHP, base de datos, autenticación local, generación de documentos ni operaciones contables. Guardar, borrar, enviar, pagar, sincronizar e importar archivos están desactivados. Los reportes conservan sus pantallas de configuración; sus resultados no se generaron. Tampoco se descargaron informes con registros reales.

Las tablas contienen ejemplos; los campos personales y contraseñas están vacíos; las series de gráficos fueron sustituidas por barras ficticias. Los scripts originales, eventos inline, enlaces externos y llamadas al servidor se eliminan de las vistas activas. Una política CSP bloquea conexiones y envíos de formularios. No se guardan cookies, tokens ni contraseñas.

Se conserva la estructura y el estilo recibido. Las diferencias visuales incluyen el aviso superior de copia local, diálogos colocados dentro de la página, opciones genéricas, gráficos ficticios y algunos recursos decorativos omitidos. Las ayudas en vídeo, la paginación remota y listas dinámicas no funcionan sin backend. Los recursos que el navegador no recibió y los archivos con HTTP 404 aparecen en el inventario.

La navegación usa el índice y enlaces locales entre módulos, pestañas y formularios capturados. Las operaciones no disponibles muestran un aviso. Las verificaciones están en `verification.json` y `verification/`.
