<?php
// Prueba de la persistencia de liquidaciones con SQLite en memoria: php tools/test-liquidaciones-api.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/liquidaciones.php';
function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FALLA: $msg\n"); exit(1); } }

$db = base_prueba();
liq_schema($db);
liq_schema($db); // idempotente
$liq = ['periodo' => '2026-10', 'trabajador' => '  Juana Pérez ', 'sueldoBase' => 1000000, 'gratificacion' => 219115, 'imponible' => 1219115, 'totalHaberes' => 1219115,
    'afp' => 137394, 'salud' => 85338, 'afc' => 7315, 'impuesto' => 601, 'totalDescuentos' => 230648, 'liquido' => 988467, 'costoEmpresa' => 1302381,
    'detalle' => ['afp' => 'Habitat', 'contrato' => 'indefinido']];
$id = liq_guardar($db, $liq);
check($id === 1, 'guardar');
liq_guardar($db, ['periodo' => '2026-09'] + $liq);
$todas = liq_listar($db);
check(count($todas) === 2 && $todas[0]['periodo'] === '2026-10', 'listar ordenado');
$octubre = liq_listar($db, '2026-10');
check(count($octubre) === 1 && $octubre[0]['trabajador'] === 'Juana Pérez' && $octubre[0]['liquido'] === 988467 && $octubre[0]['detalle']['afp'] === 'Habitat', 'leer campos');
foreach ([['periodo' => '2026-13'] + $liq, ['trabajador' => ''] + $liq, ['liquido' => 1] + $liq, ['afp' => 'mucho'] + $liq, ['impuesto' => -5] + $liq, ['imponible' => 9999999] + $liq] as $malo) {
    try { liq_guardar($db, $malo); check(false, 'debió rechazar ' . json_encode($malo)); } catch (LiquidacionError) {}
}
check(count(liq_listar($db)) === 2, 'un rechazo no guarda nada');
check(liq_borrar($db, $id) === 1 && count(liq_listar($db)) === 1, 'borrar');
echo "Liquidaciones PHP: guardar, listar, validar y borrar verificados\n";
