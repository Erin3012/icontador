<?php
// Prueba de Tesorería (pagos programados y flujo de caja): php tools/test-tesoreria-api.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/banco.php';
require dirname(__DIR__) . '/api/lib/tesoreria.php';

function check(bool $ok, string $msg): void {
    if (!$ok) {
        fwrite(STDERR, "FALLA: $msg\n");
        exit(1);
    }
    echo "✓ $msg\n";
}
function rechaza(callable $f, string $fragmento = ''): bool {
    try { $f(); return false; }
    catch (TesoreriaError $e) { return $fragmento === '' || str_contains($e->getMessage(), $fragmento); }
}
function en(int $dias): string { return date('Y-m-d', strtotime("+$dias days")); }

$db = base_prueba();
banco_schema($db);
Tesoreria::crearEsquema($db);
Tesoreria::crearEsquema($db); // idempotente
$t = new Tesoreria($db, 1);
$otra = new Tesoreria($db, 2);

// --- Validaciones
check(rechaza(fn() => $t->crearPagoProgramado('', 1000, en(3)), 'concepto'), 'rechaza concepto vacío');
check(rechaza(fn() => $t->crearPagoProgramado('X', 0, en(3)), 'monto'), 'rechaza monto cero');
check(rechaza(fn() => $t->crearPagoProgramado('X', -5, en(3)), 'monto'), 'rechaza monto negativo');
check(rechaza(fn() => $t->crearPagoProgramado('X', '12.5', en(3)), 'monto'), 'rechaza monto con decimales');
check(rechaza(fn() => $t->crearPagoProgramado('X', 1000, '2026-02-30'), 'fecha'), 'rechaza fecha inexistente');
check(rechaza(fn() => $t->crearPagoProgramado('X', 1000, en(3), 'gasto'), 'tipo'), 'rechaza tipo desconocido');
check(rechaza(fn() => $t->crearPagoProgramado('X', 1000, en(3), 'pago', 'anual'), 'recurrencia'), 'rechaza recurrencia desconocida');
check(rechaza(fn() => $t->crearPagoProgramado('X', 1000, en(3), 'pago', 'una_vez', en(9)), 'recurrentes'), 'fecha de término solo con recurrencia');
check(rechaza(fn() => $t->crearPagoProgramado('X', 1000, en(5), 'pago', 'mensual', en(1)), 'anterior'), 'fecha de término no anterior al pago');

// --- Crear y listar
$arriendo = $t->crearPagoProgramado('Arriendo <b>oficina</b>', 600000, en(3), 'pago', 'una_vez');
$cobro = $t->crearPagoProgramado('Cobro cliente', '900000', en(10), 'ingreso');
check($arriendo > 0 && $cobro > $arriendo, 'crea pagos programados (monto numérico en texto aceptado)');
$lista = $t->listarPagosProgramados(30);
check(count($lista) === 2 && $lista[0]['concepto'] === 'Arriendo <b>oficina</b>' && $lista[0]['proxima_fecha'] === en(3) && $lista[1]['monto'] === 900000, 'lista pendientes ordenados por próxima fecha');
check($otra->listarPagosProgramados(30) === [], 'una empresa no ve los pagos de otra');
check(count($t->listarPagosProgramados(5)) === 1, 'la lista respeta la ventana de días');

// --- Recurrencias
$base = ['fecha_pago' => '2026-01-31', 'fecha_fin' => null, 'recurrencia' => 'mensual'];
check(Tesoreria::ocurre($base, '2026-02-28') && !Tesoreria::ocurre($base, '2026-02-27') && Tesoreria::ocurre($base, '2026-03-31'), 'mensual del 31 cae el último día de meses cortos');
check(!Tesoreria::ocurre($base, '2025-12-31'), 'no ocurre antes de la fecha de inicio');
$semanal = ['fecha_pago' => '2026-03-02', 'fecha_fin' => '2026-03-20', 'recurrencia' => 'semanal'];
check(Tesoreria::ocurre($semanal, '2026-03-09') && !Tesoreria::ocurre($semanal, '2026-03-10') && !Tesoreria::ocurre($semanal, '2026-03-23'), 'semanal respeta día de la semana y fecha de término');
check(Tesoreria::ocurre(['fecha_pago' => '2026-03-02', 'fecha_fin' => null, 'recurrencia' => 'diaria'], '2026-04-01'), 'diaria ocurre todos los días');
check(!Tesoreria::ocurre(['fecha_pago' => '2026-03-02', 'fecha_fin' => null, 'recurrencia' => 'una_vez'], '2026-03-03'), 'una vez solo ocurre en su fecha');

$mensual = $t->crearPagoProgramado('Leasing', 100000, date('Y-m-d', strtotime('-40 days')), 'pago', 'mensual');
check(count(array_filter($t->listarPagosProgramados(30), fn($p) => $p['id'] === $mensual)) === 1, 'un pago recurrente iniciado antes aparece con su próxima fecha');

// --- Flujo de caja con saldo de la cartola
$db->exec("INSERT INTO extractos (empresa_id, fecha, descripcion, monto, referencia, estado, creado_en, actualizado_en)
    VALUES (1, '" . date('Y-m-d') . "', 'Depósito', 2000000, '', 'pendiente', 'x', 'x'), (1, '" . date('Y-m-d') . "', 'Cargo', -500000, '', 'pendiente', 'x', 'x'),
           (2, '" . date('Y-m-d') . "', 'Otra empresa', 9999999, '', 'pendiente', 'x', 'x')");
$flujo = $t->obtenerFlujoCaja(30);
check($flujo['saldo_actual'] === 1500000, 'el saldo actual suma la cartola de la empresa (cargos negativos)');
check(count($flujo['flujos']) === 31 && $flujo['flujos'][0]['fecha'] === date('Y-m-d'), 'proyecta desde hoy hasta N días');
$dia3 = array_values(array_filter($flujo['flujos'], fn($f) => $f['fecha'] === en(3)))[0];
check($dia3['egresos'] >= 600000 && $dia3['saldo_estimado'] < 1500000, 'el pago programado reduce el saldo proyectado');
$dia10 = array_values(array_filter($flujo['flujos'], fn($f) => $f['fecha'] === en(10)))[0];
check($dia10['ingresos'] === 900000, 'el ingreso programado aumenta el flujo');
check($flujo['alertas'] === [] && $flujo['saldo_minimo'] > 0, 'sin alertas mientras el saldo alcanza');
check($otra->obtenerFlujoCaja(30)['saldo_actual'] === 9999999, 'cada empresa tiene su propio saldo');

$vacia = (new Tesoreria($db, 3))->obtenerFlujoCaja(30);
check($vacia['saldo_actual'] === 0 && count($vacia['flujos']) === 31, 'empresa sin datos devuelve un flujo en cero');

// Sin la tabla de la cartola (nunca se cargó una) el saldo es cero en vez de fallar
$sinCartola = base_prueba();
Tesoreria::crearEsquema($sinCartola);
$sc = (new Tesoreria($sinCartola, 1))->obtenerFlujoCaja(30);
check($sc['saldo_actual'] === 0 && count($sc['flujos']) === 31, 'sin tabla de cartola el saldo es cero y el flujo se calcula');

// --- Alertas
$t->crearPagoProgramado('Pago grande', 5000000, en(2));
$alertas = $t->obtenerFlujoCaja(30)['alertas'];
check($alertas && $alertas[0]['tipo'] === 'error' && $alertas[0]['fecha'] === en(2), 'alerta de insolvencia desde el día en que el saldo queda negativo');

// --- Cambios de estado
$t->cambiarEstado($arriendo, 'pagado');
check(!in_array($arriendo, array_column($t->listarPagosProgramados(30), 'id'), true), 'un pago marcado como pagado deja de proyectarse');
check(rechaza(fn() => $t->cambiarEstado($arriendo, 'cancelado'), 'pendiente'), 'no se puede cambiar un pago que ya no está pendiente');
check(rechaza(fn() => $t->cambiarEstado($mensual, 'pagado'), 'recurrente'), 'un pago recurrente no se marca como pagado');
$t->cambiarEstado($mensual, 'cancelado');
check(!in_array($mensual, array_column($t->listarPagosProgramados(30), 'id'), true), 'un pago cancelado deja de proyectarse');
check(rechaza(fn() => $otra->cambiarEstado($cobro, 'cancelado'), 'no existe'), 'una empresa no puede cancelar pagos de otra');
check(rechaza(fn() => $t->cambiarEstado(9999, 'cancelado'), 'no existe'), 'pago inexistente');
check(rechaza(fn() => $t->cambiarEstado($cobro, 'borrado'), 'Estado'), 'estado desconocido');

echo "Tesorería: pagos programados, recurrencias y flujo de caja verificados ✓\n";
