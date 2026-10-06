<?php
// Separación de datos por empresa y migración de bases anteriores: php tools/test-empresas-api.php
declare(strict_types=1);
require dirname(__DIR__) . '/api/lib/vouchers.php';
require dirname(__DIR__) . '/api/lib/rcv.php';
require dirname(__DIR__) . '/api/lib/liquidaciones.php';
function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FALLA: $msg\n"); exit(1); } }
function base(): PDO { return new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]); }

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
$db->exec('CREATE TABLE cuentas (codigo VARCHAR(20) NOT NULL PRIMARY KEY, nombre VARCHAR(120) NOT NULL)');
$db->exec("CREATE TABLE vouchers (id INTEGER PRIMARY KEY AUTOINCREMENT, tipo CHAR(1) NOT NULL, periodo CHAR(7) NOT NULL, numero INT NOT NULL, fecha DATE NOT NULL, registro VARCHAR(12) NOT NULL, glosa VARCHAR(500) NOT NULL DEFAULT '', creado VARCHAR(25) NOT NULL, modificado VARCHAR(25) NOT NULL, UNIQUE (tipo, periodo, numero))");
$db->exec("CREATE TABLE voucher_lineas (id INTEGER PRIMARY KEY AUTOINCREMENT, voucher_id INT NOT NULL, orden INT NOT NULL, cuenta VARCHAR(20) NOT NULL, glosa VARCHAR(200) NOT NULL DEFAULT '', debe BIGINT NOT NULL DEFAULT 0, haber BIGINT NOT NULL DEFAULT 0, FOREIGN KEY (voucher_id) REFERENCES vouchers(id) ON DELETE CASCADE, FOREIGN KEY (cuenta) REFERENCES cuentas(codigo))");
$db->exec("INSERT INTO cuentas VALUES ('1.1.01', 'Caja'), ('4.1.01', 'Ventas')");
$db->exec("INSERT INTO vouchers VALUES (1, 'I', '2026-09', 1, '2026-09-01', 'Ambos', 'Antigua', 'x', 'x')");
$db->exec("INSERT INTO voucher_lineas (voucher_id, orden, cuenta, debe, haber) VALUES (1, 1, '1.1.01', 500, 0), (1, 2, '4.1.01', 0, 500)");
$db->exec("CREATE TABLE rcv_documentos (id INTEGER PRIMARY KEY AUTOINCREMENT, periodo TEXT NOT NULL, libro TEXT NOT NULL, archivo TEXT NOT NULL DEFAULT '', tipo_doc INTEGER NOT NULL, tipo_operacion TEXT NOT NULL DEFAULT '', rut TEXT NOT NULL DEFAULT '', razon_social TEXT NOT NULL DEFAULT '', folio TEXT NOT NULL DEFAULT '', fecha TEXT NOT NULL DEFAULT '', exento INTEGER NOT NULL DEFAULT 0, neto INTEGER NOT NULL DEFAULT 0, iva INTEGER NOT NULL DEFAULT 0, iva_no_rec INTEGER NOT NULL DEFAULT 0, iva_uso_comun INTEGER NOT NULL DEFAULT 0, iva_retenido INTEGER NOT NULL DEFAULT 0, otros INTEGER NOT NULL DEFAULT 0, total INTEGER NOT NULL DEFAULT 0, creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
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
check($db->query("SELECT COUNT(*) FROM sqlite_master WHERE name LIKE '%_nueva'")->fetchColumn() == 0, 'sin tablas temporales');
check($db->query('PRAGMA foreign_key_check')->fetchAll() === [], 'claves foráneas consistentes tras migrar');
echo "Empresas PHP: separación de datos y migración verificadas\n";
