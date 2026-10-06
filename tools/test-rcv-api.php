<?php
// Prueba de la persistencia del RCV con SQLite en memoria: php tools/test-rcv-api.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/rcv.php';
function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FALLA: $msg\n"); exit(1); } }

$db = base_prueba();
rcv_schema($db);
rcv_schema($db); // idempotente
$compra = ['tipo' => 33, 'tipoOperacion' => 'Del Giro', 'rut' => '77000001-1', 'razon' => 'PROVEEDOR', 'folio' => '1520', 'fecha' => '03/09/2026', 'exento' => 0, 'neto' => 1000000, 'iva' => 190000, 'ivaNoRec' => 0, 'ivaUsoComun' => 0, 'otros' => 0, 'total' => 1190000];
$nc = ['tipo' => 61, 'folio' => '9', 'neto' => 50000, 'iva' => 9500, 'total' => 59500];
check(rcv_guardar($db, ['kind' => 'compras', 'period' => '2026-09', 'fileName' => 'c.csv', 'docs' => [$compra, $nc]]) === 2, 'guardar compras');
check(rcv_guardar($db, ['kind' => 'ventas', 'period' => '2026-09', 'docs' => [['tipo' => 33, 'folio' => '401', 'neto' => 2000000, 'iva' => 380000, 'total' => 2380000]]]) === 1, 'guardar ventas');
// Reimportar el mismo libro reemplaza, no duplica.
check(rcv_guardar($db, ['kind' => 'compras', 'period' => '2026-09', 'fileName' => 'c2.csv', 'docs' => [$compra, $nc]]) === 2, 'reimportar');
$libros = rcv_leer($db, '2026-09');
check(count($libros) === 2, 'dos libros');
$compras = $libros[0];
check($compras['kind'] === 'compras' && count($compras['docs']) === 2 && $compras['fileName'] === 'c2.csv', 'compras leídas');
check($compras['docs'][0]['iva'] === 190000 && $compras['docs'][0]['razon'] === 'PROVEEDOR' && $compras['docs'][1]['tipo'] === 61, 'campos');
check(rcv_periodos($db) === [['period' => '2026-09', 'compras' => 2, 'ventas' => 1]], 'períodos');
foreach ([['kind' => 'otro', 'period' => '2026-09', 'docs' => []], ['kind' => 'compras', 'period' => '2026-13', 'docs' => []], ['kind' => 'compras', 'period' => '2026-09', 'docs' => [['tipo' => 33, 'iva' => 'mucho']]], ['kind' => 'compras', 'period' => '2026-09', 'docs' => [['folio' => '1']]]] as $malo) {
    try { rcv_guardar($db, $malo); check(false, 'debió rechazar ' . json_encode($malo)); } catch (RcvError) {}
}
check(count(rcv_leer($db, '2026-09')[0]['docs']) === 2, 'un rechazo no borra lo guardado');
$lc = rcv_libro($db, 'compras', '2026-09-01', '2026-09-30');
check(count($lc['docs']) === 2 && $lc['totales']['neto'] === 950000 && $lc['totales']['iva'] === 180500 && $lc['docs'][1]['signo'] === -1, 'Libro de Compras resta la nota de crédito');
check(count(rcv_libro($db, 'compras', '2026-09-04', '2026-09-30')['docs']) === 1, 'Libro de Compras filtra por fecha del documento');
check(count(rcv_libro($db, 'compras', null, null, 61)['docs']) === 1 && rcv_libro($db, 'ventas', '2026-10-01')['docs'] === [], 'Libro filtra por tipo y período');
check(rcv_libro($db, 'ventas')['totales']['total'] === 2380000, 'Libro de Ventas');
foreach ([['01-09-2026', null], [null, '2026-02-30'], ['2026-09-30', '2026-09-01']] as [$d, $h]) {
    try { rcv_libro($db, 'compras', $d, $h); check(false, "debió rechazar $d $h"); } catch (RcvError) {}
}
check(rcv_borrar($db, '2026-09') === 3 && rcv_periodos($db) === [], 'borrar');
echo "RCV PHP: guardar, reemplazar, leer, validar y borrar verificados\n";
