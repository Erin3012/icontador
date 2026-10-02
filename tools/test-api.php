<?php
// Pruebas de la lógica contable en PHP con una base SQLite en memoria: php tools/test-api.php
declare(strict_types=1);
require dirname(__DIR__) . '/api/lib.php';

$fallas = 0;
function comprobar(bool $ok, string $mensaje): void
{
    global $fallas;
    echo ($ok ? 'ok   ' : 'FALLA ') . $mensaje . PHP_EOL;
    $fallas += $ok ? 0 : 1;
}
function errores(callable $f): array
{
    try {
        $f();
    } catch (ErrorValidacion $e) {
        return $e->errores;
    }
    return [];
}

$pdo = conectar(['dsn' => 'sqlite::memory:']);
comprobar(count(planCuentas($pdo)) === count(PLAN_BASE), 'plan de cuentas inicial cargado');

$venta = ['tipo' => 'I', 'fecha' => '2026-10-01', 'registro' => 'Ambos', 'glosa' => 'Venta al contado', 'lineas' => [
    ['cuenta' => '1.1.01', 'debe' => '119000'], ['cuenta' => '4.1.01', 'haber' => 100000], ['cuenta' => '2.1.02', 'haber' => 19000],
]];
$v1 = guardarVoucher($pdo, $venta);
comprobar($v1['numero'] === 1 && $v1['debe'] === 119000 && $v1['haber'] === 119000, 'voucher cuadrado se guarda con N° 1');
$v2 = guardarVoucher($pdo, ['tipo' => 'E', 'fecha' => '2026-10-05', 'registro' => 'Tributario', 'glosa' => 'Pago arriendo', 'lineas' => [
    ['cuenta' => '3.1.04', 'debe' => 50000], ['cuenta' => '1.1.01', 'haber' => 50000],
]]);
$v3 = guardarVoucher($pdo, ['tipo' => 'I', 'fecha' => '2026-10-20', 'registro' => 'IFRS', 'glosa' => 'Aporte', 'lineas' => [
    ['cuenta' => '1.1.02', 'debe' => 1000], ['cuenta' => '2.3.01', 'haber' => 1000],
]]);
comprobar($v2['numero'] === 1 && $v3['numero'] === 2, 'correlativo por tipo y mes');
$v4 = guardarVoucher($pdo, ['tipo' => 'I', 'fecha' => '2026-11-02', 'registro' => 'Ambos', 'glosa' => 'Venta', 'lineas' => [
    ['cuenta' => '1.1.01', 'debe' => 10], ['cuenta' => '4.1.01', 'haber' => 10],
]]);
comprobar($v4['numero'] === 1, 'el correlativo reinicia en un mes nuevo');

$e = errores(fn() => guardarVoucher($pdo, ['tipo' => 'I', 'fecha' => '2026-10-02', 'registro' => 'Ambos', 'lineas' => [
    ['cuenta' => '1.1.01', 'debe' => 100], ['cuenta' => '4.1.01', 'haber' => 90],
]]));
comprobar((bool) preg_grep('/descuadrado/', $e), 'rechaza Debe distinto de Haber');
comprobar((bool) preg_grep('/Debe o en Haber/', errores(fn() => validarVoucher(['tipo' => 'T', 'fecha' => '2026-10-02', 'registro' => 'Ambos', 'lineas' => [
    ['cuenta' => '1.1.01', 'debe' => 5, 'haber' => 5], ['cuenta' => '4.1.01'],
]], ['1.1.01', '4.1.01']))), 'rechaza líneas con Debe y Haber a la vez o vacías');
comprobar(count(errores(fn() => validarVoucher(['tipo' => 'X', 'fecha' => '2026-02-30', 'registro' => '?', 'lineas' => [['cuenta' => '9', 'debe' => -1]]], ['1.1.01']))) >= 4, 'rechaza tipo, fecha, registro, cuenta y montos inválidos');
comprobar(count(listarVouchers($pdo)) === 4, 'los vouchers rechazados no se guardan');

$editado = guardarVoucher($pdo, ['glosa' => 'Venta corregida'] + $venta, $v1['id']);
comprobar($editado['numero'] === 1 && $editado['glosa'] === 'Venta corregida', 'editar conserva el número');

$diario = libroDiario($pdo, ['desde' => '2026-10-01', 'hasta' => '2026-10-31']);
comprobar(count($diario['asientos']) === 3 && $diario['debe'] === 170000 && $diario['haber'] === 170000, 'Libro Diario de octubre con totales cuadrados');
comprobar($diario['asientos'][0]['lineas'][0]['nombre'] === 'Caja', 'Libro Diario incluye el nombre de la cuenta');
comprobar(count(libroDiario($pdo, ['registro' => 'Tributario'])['asientos']) === 3, 'Libro Diario tributario excluye vouchers solo IFRS');

$mayor = libroMayor($pdo, ['desde' => '2026-10-02', 'hasta' => '2026-11-30', 'cuenta' => '1.1.01']);
comprobar(count($mayor) === 1 && $mayor[0]['saldoAnterior'] === 119000, 'Libro Mayor calcula saldo anterior');
comprobar($mayor[0]['saldo'] === 69010 && end($mayor[0]['movimientos'])['saldo'] === 69010, 'Libro Mayor calcula saldo acumulado');
$caja = array_values(array_filter(libroMayor($pdo), fn($c) => $c['codigo'] === '4.1.01'))[0];
comprobar($caja['haber'] === 100010 && $caja['saldo'] === -100010, 'Libro Mayor de Ventas con saldo acreedor');

comprobar(eliminarVoucher($pdo, $v2['id']) && count(listarVouchers($pdo)) === 3, 'eliminar voucher');
comprobar((int) $pdo->query('SELECT COUNT(*) FROM voucher_lineas WHERE voucher_id = ' . $v2['id'])->fetchColumn() === 0, 'eliminar borra sus líneas');

echo $fallas ? "$fallas pruebas fallaron" . PHP_EOL : 'Todas las pruebas pasaron' . PHP_EOL;
exit($fallas ? 1 : 0);
