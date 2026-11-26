<?php
// Prueba de la carga de un balance como voucher resumen y su traspaso a Cifrax Tributario.
// Uso: php tools/test-ctrib-balance-resumen.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require __DIR__ . '/cargar-balance-resumen.php';

function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FALLA: $msg\n"); exit(1); } }
function falla(callable $accion, string $contiene, string $msg): void
{
    try { $accion(); } catch (Throwable $e) {
        check(str_contains($e->getMessage(), $contiene), "$msg (mensaje: " . $e->getMessage() . ")");
        return;
    }
    check(false, "$msg (no lanzó error)");
}

$pdo = base_prueba();
crearEsquema($pdo);
ctrib_schema($pdo);
$semilla = json_decode((string) file_get_contents(dirname(__DIR__) . '/api/lib/ctrib/enyel-2025.json'), true, 512, JSON_THROW_ON_ERROR);
ctrib_mapeo_guardar($pdo, 1, $semilla['mapeo'], 'prueba');
$cuentas = $semilla['balance'];

// Un voucher previo de otra fecha no debe tocarse.
agregarCuenta($pdo, ['codigo' => '101001', 'nombre' => 'CAJA'], 1);
agregarCuenta($pdo, ['codigo' => '505002', 'nombre' => 'VENTAS EXENTAS'], 1);
$otro = guardarVoucher($pdo, ['tipo' => 'I', 'fecha' => '2024-06-01', 'registro' => 'Ambos', 'glosa' => 'otro año',
    'lineas' => [['cuenta' => '101001', 'debe' => 10, 'haber' => 0], ['cuenta' => '505002', 'debe' => 0, 'haber' => 10]]], null, 1);

$r = cargar_balance_resumen($pdo, 1, 2026, $cuentas, 'prueba.json', 'prueba');
check($r['agregadas'] === 23, 'agrega al plan solo las cuentas que faltan (23 de 25), hubo ' . $r['agregadas']);
check($r['reemplazados'] === 0, 'primera carga sin voucher previo');
$b = $r['balance'];
check($b['origen'] === 'vouchers' && count($b['cuentas']) === 25, 'balance traspasado a Cifrax Tributario con 25 cuentas');
check($b['totales']['debitos'] === 96772918 && $b['totales']['creditos'] === 96772918, 'débitos y créditos 96.772.918');
check($b['resultado'] === 6905357 && $b['sumasIguales']['activo'] === 20509426, 'resultado 6.905.357 y sumas iguales 20.509.426');
$porCodigo = array_column($b['cuentas'], null, 'codigo');
foreach ($cuentas as $c) {
    check($porCodigo[$c['codigo']]['debitos'] === $c['debitos'] && $porCodigo[$c['codigo']]['creditos'] === $c['creditos'], "cuenta {$c['codigo']} igual a los datos");
}

// Segunda carga: reemplaza el voucher resumen, no duplica.
$r2 = cargar_balance_resumen($pdo, 1, 2026, $cuentas, 'prueba.json', 'prueba');
check($r2['agregadas'] === 0 && $r2['reemplazados'] === 1, 'segunda carga reemplaza el voucher resumen');
$cantidad = (int) $pdo->query("SELECT COUNT(*) FROM vouchers WHERE empresa_id = 1 AND glosa LIKE 'Balance resumen importado%'")->fetchColumn();
check($cantidad === 1, "un solo voucher resumen, hay $cantidad");
check(obtenerVoucher($pdo, $otro['id'], 1) !== null, 'el voucher de otro año sigue intacto');
check($r2['balance']['totales']['debitos'] === 96772918, 'el balance no se duplica');

// El balance de contabilidad (libros) también tiene los débitos y créditos.
$libro = balanceGeneral($pdo, ['desde' => '2025-01-01', 'hasta' => '2025-12-31'], 1);
check($libro['totales']['debitos'] === 96772918 && $libro['totales']['creditos'] === 96772918, 'el balance de contabilidad 2025 suma 96.772.918');

// Datos que no cuadran no cambian nada.
$malo = $cuentas;
$malo[0]['debitos'] += 1;
falla(fn() => cargar_balance_resumen($pdo, 1, 2026, $malo, 'malo.json', 'prueba'), 'no cuadra', 'un balance descuadrado se rechaza');
falla(fn() => cargar_balance_resumen($pdo, 1, 2026, [['codigo' => 'x1', 'nombre' => 'X', 'debitos' => 1, 'creditos' => 1]], 'malo.json', 'prueba'), 'código de cuenta', 'código inválido se rechaza');
check((int) $pdo->query("SELECT COUNT(*) FROM vouchers WHERE empresa_id = 1 AND glosa LIKE 'Balance resumen importado%'")->fetchColumn() === 1, 'tras un rechazo sigue un solo voucher');

echo "Cifrax Tributario balance resumen: cuentas agregadas, voucher resumen idempotente, traspaso a Cifrax Tributario y balance de contabilidad verificados\n";
