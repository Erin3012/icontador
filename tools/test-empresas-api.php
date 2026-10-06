<?php
// Separación de datos por empresa y migración de bases anteriores: php tools/test-empresas-api.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/vouchers.php';
require dirname(__DIR__) . '/api/lib/rcv.php';
require dirname(__DIR__) . '/api/lib/empresa-paquete.php';
function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FALLA: $msg\n"); exit(1); } }
function base(): PDO { return base_prueba(); }

// Base nueva: cada empresa ve solo sus datos y tiene su propio correlativo.
$db = base();
crearEsquema($db);
cargarPlanEjemplo($db, 1);
check(planCuentas($db, 2) === [] && count(planCuentas($db, 1)) === count(PLAN_EJEMPLO), 'plan de cuentas propio de cada empresa');
try { guardarVoucher($db, ['tipo' => 'I', 'fecha' => '2026-10-01', 'registro' => 'Ambos', 'lineas' => [['cuenta' => '1.1.01', 'debe' => 1], ['cuenta' => '4.1.01', 'haber' => 1]]], null, 2); check(false, 'sin plan no hay voucher'); } catch (ErrorValidacion) {}
check(agregarCuenta($db, ['codigo' => '1.1.01', 'nombre' => 'Caja chica'], 2)['nombre'] === 'Caja chica' && planCuentas($db, 1)[0]['nombre'] === 'Caja', 'el mismo código puede tener otro nombre en otra empresa');
foreach ([['codigo' => 'X1', 'nombre' => 'a'], ['codigo' => '1.1.01', 'nombre' => 'b'], ['codigo' => '5', 'nombre' => '']] as $mala) {
    try { agregarCuenta($db, $mala, 2); check(false, 'debió rechazar ' . json_encode($mala)); } catch (ErrorValidacion) {}
}
cargarPlanEjemplo($db, 2);
check(planCuentas($db, 2)[0]['nombre'] === 'Caja chica', 'el plan de ejemplo no pisa cuentas existentes');
$voucher = ['tipo' => 'I', 'fecha' => '2026-10-01', 'registro' => 'Ambos', 'glosa' => 'Venta', 'lineas' => [['cuenta' => '1.1.01', 'debe' => 100], ['cuenta' => '4.1.01', 'haber' => 100]]];
$a = guardarVoucher($db, $voucher, null, 1);
$b = guardarVoucher($db, $voucher, null, 2);
check($a['numero'] === 1 && $b['numero'] === 1, 'correlativo independiente por empresa');
check(count(listarVouchers($db, [], 1)) === 1 && count(listarVouchers($db, [], 3)) === 0, 'listado filtrado por empresa');
check(obtenerVoucher($db, $a['id'], 2) === null && !eliminarVoucher($db, $a['id'], 2), 'otra empresa no ve ni borra el voucher');
try { guardarVoucher($db, $voucher, $a['id'], 2); check(false, 'otra empresa no edita'); } catch (ErrorValidacion) {}
try { eliminarCuenta($db, '1.1.01', 1); check(false, 'no elimina cuentas con movimientos'); } catch (ErrorValidacion) {}
check(eliminarCuenta($db, '1.1.12', 1) && !eliminarCuenta($db, '9.9', 1), 'elimina cuentas sin movimientos');
check(balanceGeneral($db, [], 1)['totales']['debitos'] === 100 && estadoResultado($db, [], 3)['resultado'] === 0, 'reportes por empresa');

rcv_schema($db);
rcv_guardar($db, ['kind' => 'ventas', 'period' => '2026-09', 'docs' => [['tipo' => 33, 'folio' => '1', 'neto' => 100, 'iva' => 19, 'total' => 119]]], 1);
rcv_guardar($db, ['kind' => 'ventas', 'period' => '2026-09', 'docs' => [['tipo' => 33, 'folio' => '2', 'neto' => 50, 'iva' => 10, 'total' => 60]]], 2);
check(rcv_libro($db, 'ventas', null, null, null, 1)['totales']['total'] === 119 && count(rcv_periodos($db, 3)) === 0, 'RCV por empresa');
check(rcv_borrar($db, '2026-09', 2) === 1 && count(rcv_leer($db, '2026-09', 1)) === 1, 'borrar el RCV de una empresa no toca a otra');

liq_schema($db);
$liq = ['periodo' => '2026-10', 'trabajador' => 'Ana', 'sueldoBase' => 100, 'gratificacion' => 0, 'imponible' => 100, 'totalHaberes' => 100, 'afp' => 10, 'salud' => 7, 'afc' => 1, 'impuesto' => 0, 'totalDescuentos' => 18, 'liquido' => 82, 'costoEmpresa' => 105];
$id = liq_guardar($db, $liq, 1);
check(count(liq_listar($db, null, 1)) === 1 && liq_listar($db, null, 2) === [] && liq_borrar($db, $id, 2) === 0, 'liquidaciones por empresa');

// Base anterior: los datos sin empresa pasan a la única empresa importada y conservan sus líneas.
$db = base();
empresas_schema($db);
$db->exec("INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES ('77', 'EMPRESA UNO', '{}', '')");
$autoId = es_mysql($db) ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
$motor = es_mysql($db) ? ' ENGINE=InnoDB' : '';
$db->exec('CREATE TABLE cuentas (codigo VARCHAR(20) NOT NULL PRIMARY KEY, nombre VARCHAR(120) NOT NULL)' . $motor);
$db->exec("CREATE TABLE vouchers (id $autoId, tipo CHAR(1) NOT NULL, periodo CHAR(7) NOT NULL, numero INT NOT NULL, fecha DATE NOT NULL, registro VARCHAR(12) NOT NULL, glosa VARCHAR(500) NOT NULL DEFAULT '', creado VARCHAR(25) NOT NULL, modificado VARCHAR(25) NOT NULL, UNIQUE (tipo, periodo, numero))$motor");
$db->exec("CREATE TABLE voucher_lineas (id $autoId, voucher_id INT NOT NULL, orden INT NOT NULL, cuenta VARCHAR(20) NOT NULL, glosa VARCHAR(200) NOT NULL DEFAULT '', debe BIGINT NOT NULL DEFAULT 0, haber BIGINT NOT NULL DEFAULT 0, FOREIGN KEY (voucher_id) REFERENCES vouchers(id) ON DELETE CASCADE, FOREIGN KEY (cuenta) REFERENCES cuentas(codigo))$motor");
$db->exec("INSERT INTO cuentas VALUES ('1.1.01', 'Caja'), ('4.1.01', 'Ventas')");
$db->exec("INSERT INTO vouchers VALUES (1, 'I', '2026-09', 1, '2026-09-01', 'Ambos', 'Antigua', 'x', 'x')");
$db->exec("INSERT INTO voucher_lineas (voucher_id, orden, cuenta, debe, haber) VALUES (1, 1, '1.1.01', 500, 0), (1, 2, '4.1.01', 0, 500)");
// Tabla del RCV tal como la creaba la versión anterior (sin empresa_id).
$db->exec(es_mysql($db) ? file_get_contents(__DIR__ . '/rcv-anterior.mysql.sql') : "CREATE TABLE rcv_documentos (id INTEGER PRIMARY KEY AUTOINCREMENT, periodo TEXT NOT NULL, libro TEXT NOT NULL, archivo TEXT NOT NULL DEFAULT '', tipo_doc INTEGER NOT NULL, tipo_operacion TEXT NOT NULL DEFAULT '', rut TEXT NOT NULL DEFAULT '', razon_social TEXT NOT NULL DEFAULT '', folio TEXT NOT NULL DEFAULT '', fecha TEXT NOT NULL DEFAULT '', exento INTEGER NOT NULL DEFAULT 0, neto INTEGER NOT NULL DEFAULT 0, iva INTEGER NOT NULL DEFAULT 0, iva_no_rec INTEGER NOT NULL DEFAULT 0, iva_uso_comun INTEGER NOT NULL DEFAULT 0, iva_retenido INTEGER NOT NULL DEFAULT 0, otros INTEGER NOT NULL DEFAULT 0, total INTEGER NOT NULL DEFAULT 0, creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
$db->exec("INSERT INTO rcv_documentos (periodo, libro, tipo_doc, total) VALUES ('2026-09', 'compras', 33, 119)");
crearEsquema($db);
crearEsquema($db); // idempotente
rcv_schema($db);
$migrado = listarVouchers($db, [], 1);
check(count($migrado) === 1 && $migrado[0]['debe'] === 500 && count($migrado[0]['lineas']) === 2, 'los vouchers anteriores pasan a la empresa importada con sus líneas');
check(count(planCuentas($db, 1)) === 2 && planCuentas($db, 2) === [], 'el plan anterior pasa a la empresa importada');
cargarPlanEjemplo($db, 2);
check(guardarVoucher($db, ['fecha' => '2026-09-02'] + $voucher, null, 2)['numero'] === 1, 'tras migrar, otra empresa puede usar el mismo número');
check(guardarVoucher($db, ['fecha' => '2026-09-03'] + $voucher, null, 1)['numero'] === 2, 'la empresa migrada continúa su correlativo');
check(rcv_periodos($db, 1) === [['period' => '2026-09', 'compras' => 1, 'ventas' => 0]], 'el RCV anterior pasa a la empresa importada');
if (!es_mysql($db)) {
    check($db->query("SELECT COUNT(*) FROM sqlite_master WHERE name LIKE '%_nueva'")->fetchColumn() == 0, 'sin tablas temporales');
    check($db->query('PRAGMA foreign_key_check')->fetchAll() === [], 'claves foráneas consistentes tras migrar');
}

// Crear, exportar e importar en otra base (como pasar ENYEL de la base local al sitio publicado).
$origen = base();
empresas_esquema_completo($origen);
check(normalizarRut('76.086.428-5') === '76086428-5' && normalizarRut('76086428-4') === null && normalizarRut('1-9') === '1-9', 'RUT con dígito verificador');
$creada = empresa_crear($origen, ['razon_social' => 'ENYEL SPA', 'rut' => '76.086.428-5', 'regimen' => 'Pro Pyme General (14 D N°3)', 'email' => 'a@b.cl', 'plan_ejemplo' => true]);
$eid = $creada['empresa']['id'];
check($creada['cuentas_agregadas'] === count(PLAN_EJEMPLO) && $creada['empresa']['rut'] === '76086428-5' && $creada['empresa']['cuentas'] === count(PLAN_EJEMPLO), 'crear empresa con plan de ejemplo');
foreach ([['razon_social' => ''], ['razon_social' => 'X', 'rut' => '11.111.111-2'], ['razon_social' => 'X', 'rut' => '76086428-5'], ['razon_social' => 'X', 'email' => 'no']] as $mala) {
    try { empresa_crear($origen, $mala); check(false, 'debió rechazar ' . json_encode($mala)); } catch (ErrorValidacion) {}
}
check(empresa_crear($origen, ['razon_social' => 'SIN PLAN'])['empresa']['cuentas'] === 0, 'crear empresa sin plan');
guardarVoucher($origen, $voucher, null, $eid);
rcv_guardar($origen, ['kind' => 'compras', 'period' => '2026-09', 'docs' => [['tipo' => 33, 'folio' => '7', 'neto' => 100, 'iva' => 19, 'total' => 119]]], $eid);
liq_guardar($origen, $liq, $eid);
$origen->prepare("INSERT INTO importacion_vistas (empresa_id, vista, datos_json, actualizado) VALUES (?, 'plan-cuentas', '{}', '')")->execute([$eid]);
$origen->prepare("INSERT INTO importacion_registros (empresa_id, vista, tabla, huella, datos_json) VALUES (?, 'plan-cuentas', 't', 'h1', '{\"celdas\":[]}')")->execute([$eid]);
$paquete = json_decode(json_encode(empresa_exportar($origen, $eid)), true);
check($paquete['formato'] === 'icontador-empresa' && count($paquete['vouchers'][0]['lineas']) === 2 && count($paquete['rcv']) === 1, 'exportar empresa con sus datos');
check(empresa_exportar($origen, 999) === null, 'exportar empresa inexistente');

$destino = base();
empresas_esquema_completo($destino);
empresa_crear($destino, ['razon_social' => 'OTRA']);
$r = empresa_importar($destino, $paquete);
$nid = $r['empresa']['id'];
check($r['empresa']['razon_social'] === 'ENYEL SPA' && $r['empresa']['rut'] === '76086428-5' && $r['importado']['vouchers'] === 1, 'importar en otra base');
check(count(planCuentas($destino, $nid)) === count(PLAN_EJEMPLO) && listarVouchers($destino, [], $nid)[0]['debe'] === 100, 'plan y vouchers importados');
check(rcv_libro($destino, 'compras', null, null, null, $nid)['totales']['total'] === 119 && count(liq_listar($destino, null, $nid)) === 1, 'RCV y liquidaciones importados');
check(empresa_importar($destino, $paquete)['empresa']['id'] === $nid && count(listarVouchers($destino, [], $nid)) === 1 && count(empresas_listar($destino)) === 2, 'reimportar reemplaza sin duplicar');
foreach ([['formato' => 'otro'], ['formato' => 'icontador-empresa', 'empresa' => ['origen_id' => 'x', 'razon_social' => 'Y'], 'vouchers' => 'no'],
    ['formato' => 'icontador-empresa', 'empresa' => ['origen_id' => 'z', 'razon_social' => 'Z'], 'cuentas' => [['codigo' => '1', 'nombre' => 'a'], ['codigo' => '1', 'nombre' => 'b']]]] as $malo) {
    try { empresa_importar($destino, $malo); check(false, 'debió rechazar ' . json_encode($malo)); } catch (ErrorValidacion) {}
}
check(count(empresas_listar($destino)) === 2, 'un archivo con errores no deja datos a medias');
echo "Empresas PHP: separación de datos, migración, creación y exportación verificadas\n";
