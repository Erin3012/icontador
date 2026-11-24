<?php
// Prueba del Dashboard Ejecutivo con comprobantes, RCV e inventario reales: php tools/test-dashboard-api.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/vouchers.php';
require dirname(__DIR__) . '/api/lib/rcv.php';
require dirname(__DIR__) . '/api/lib/rcv-centralizacion.php';
require dirname(__DIR__) . '/api/lib/inventario.php';
require dirname(__DIR__) . '/api/lib/dashboard.php';

$fallas = 0;
function comprobar(bool $ok, string $mensaje): void {
    global $fallas;
    echo ($ok ? 'ok   ' : 'FALLA ') . $mensaje . PHP_EOL;
    $fallas += $ok ? 0 : 1;
}
function hoy(string $modificador = ''): string { return (new DateTime('first day of this month'))->modify($modificador ?: '+9 days')->format('Y-m-d'); }
function mes(int $atras): string { return (new DateTime('first day of this month'))->modify("-$atras months")->format('Y-m'); }
function voucher(PDO $db, int $empresa, string $tipo, string $fecha, string $glosa, array $lineas): void {
    guardarVoucher($db, ['tipo' => $tipo, 'fecha' => $fecha, 'registro' => 'Ambos', 'glosa' => $glosa, 'lineas' => $lineas], null, $empresa);
}
function linea(string $cuenta, int $debe, int $haber): array { return ['cuenta' => $cuenta, 'glosa' => '', 'debe' => $debe, 'haber' => $haber]; }

$db = base_prueba();
crearEsquema($db);
rcv_schema($db);
rcv_centralizacion_schema($db);
inventario_schema($db);
foreach ([1, 2, 3] as $e) cargarPlanEjemplo($db, $e);

// Empresa 1: venta cobrada en banco, arriendo pagado, compra de equipos a crédito y un ingreso en caja hace dos meses.
voucher($db, 1, 'I', hoy('+1 day'), 'Venta del mes', [linea('1.1.02', 2000000, 0), linea('4.1.01', 0, 2000000)]);
voucher($db, 1, 'E', hoy('+2 days'), 'Arriendo <b>oficina</b>', [linea('3.1.04', 500000, 0), linea('1.1.02', 0, 500000)]);
voucher($db, 1, 'T', hoy('+3 days'), 'Compra de equipos', [linea('1.2.02', 800000, 0), linea('2.1.01', 0, 800000)]);
voucher($db, 1, 'I', (new DateTime('first day of this month'))->modify('-2 months +4 days')->format('Y-m-d'), 'Ingreso antiguo', [linea('1.1.01', 100000, 0), linea('4.1.02', 0, 100000)]);
// Empresa 2 con datos distintos; no debe mezclarse con la 1.
voucher($db, 2, 'I', hoy('+1 day'), 'Venta otra empresa', [linea('1.1.02', 9000000, 0), linea('4.1.01', 0, 9000000)]);
// Empresa 3: poca caja y un comprobante descuadrado (no entra por la API de comprobantes, así que se fuerza en la base).
voucher($db, 3, 'I', hoy('+1 day'), 'Ingreso chico', [linea('1.1.01', 50000, 0), linea('4.1.01', 0, 50000)]);
$idDescuadrado = (int) $db->query('SELECT MAX(id) FROM vouchers WHERE empresa_id = 3')->fetchColumn();
$db->exec("INSERT INTO voucher_lineas (voucher_id, orden, cuenta, glosa, debe, haber) VALUES ($idDescuadrado, 99, '1.1.01', '', 10, 0)");

$d1 = (new Dashboard($db, 1))->obtenerResumen();
$k = $d1['kpis'];
comprobar($k['total_activo'] === 2400000 && $k['total_pasivo'] === 800000 && $k['patrimonio'] === 1600000, 'KPIs: activo, pasivo y patrimonio desde los comprobantes de la empresa');
comprobar($k['saldo_caja'] === 1600000, 'saldo de caja = cuentas 1.1.01 y 1.1.02');
comprobar($k['razon_corriente'] === 2.0 && $k['endeudamiento'] === 0.33, 'razón corriente (circulante) y endeudamiento');
comprobar(count($d1['balance_caja']) === 2 && $d1['balance_caja'][0] === ['banco' => 'Caja', 'numero_cuenta' => '1.1.01', 'saldo' => 100000]
    && $d1['balance_caja'][1]['banco'] === 'Banco' && $d1['balance_caja'][1]['saldo'] === 1500000, 'balance de caja por cuenta con nombre del plan');
comprobar(count($d1['ultimos_vouchers']) === 4 && $d1['ultimos_vouchers'][0]['descripcion'] === 'Compra de equipos' && $d1['ultimos_vouchers'][0]['tipo_nombre'] === 'Traspaso'
    && $d1['ultimos_vouchers'][0]['total_debe'] === 800000 && $d1['ultimos_vouchers'][0]['total_haber'] === 800000, 'últimos comprobantes, el más reciente primero');
comprobar($d1['alertas'] === [], 'sin alertas cuando todo está en orden');

$flujo = $d1['flujo_mensual'];
comprobar(count($flujo) === 6 && $flujo[5]['mes'] === mes(0) && $flujo[0]['mes'] === mes(5), 'flujo mensual de 6 meses continuos, el actual al final');
comprobar($flujo[5]['ingresos'] === 2000000 && $flujo[5]['egresos'] === 500000 && $flujo[5]['neto'] === 1500000, 'flujo del mes actual desde caja y bancos');
comprobar($flujo[3]['mes'] === mes(2) && $flujo[3]['ingresos'] === 100000 && $flujo[4]['ingresos'] === 0, 'meses anteriores con y sin movimientos');

// Aislamiento entre empresas
$d2 = (new Dashboard($db, 2))->obtenerResumen();
comprobar($d2['kpis']['total_activo'] === 9000000 && $d2['kpis']['razon_corriente'] === null, 'cada empresa ve solo sus comprobantes; sin pasivo circulante la razón es null');

// Alertas
$d3 = (new Dashboard($db, 3))->obtenerResumen();
$titulos = array_column($d3['alertas'], 'titulo');
comprobar(in_array('Vouchers Descuadrados', $titulos, true) && in_array('Saldo Bajo', $titulos, true), 'alertas de comprobante descuadrado y saldo de caja bajo');
comprobar(array_values(array_filter($d3['alertas'], fn($a) => $a['titulo'] === 'Vouchers Descuadrados'))[0]['mensaje'] === '1 comprobante(s) con Debe ≠ Haber', 'cuenta los comprobantes descuadrados');

// Empresa nueva: sin datos no hay alertas ni errores
$vacia = (new Dashboard($db, 99))->obtenerResumen();
comprobar($vacia['kpis']['saldo_caja'] === 0 && $vacia['ultimos_vouchers'] === [] && $vacia['balance_caja'] === [] && $vacia['alertas'] === [], 'empresa sin datos: todo en cero y sin alertas');
comprobar(count($vacia['flujo_mensual']) === 6 && array_sum(array_column($vacia['flujo_mensual'], 'ingresos')) === 0, 'empresa sin datos: seis meses en cero');

// RCV importado sin contabilizar
rcv_guardar($db, ['period' => mes(1), 'kind' => 'compras', 'docs' => [
    ['tipo' => 33, 'folio' => '101', 'fecha' => '05/' . substr(mes(1), 5, 2) . '/' . substr(mes(1), 0, 4), 'rut' => '76123456-7', 'razon' => 'Proveedor', 'tipoOperacion' => 'Del Giro', 'neto' => 1000, 'iva' => 190, 'total' => 1190, 'exento' => 0],
    ['tipo' => 33, 'folio' => '102', 'fecha' => '06/' . substr(mes(1), 5, 2) . '/' . substr(mes(1), 0, 4), 'rut' => '76123456-7', 'razon' => 'Proveedor', 'tipoOperacion' => 'Del Giro', 'neto' => 2000, 'iva' => 380, 'total' => 2380, 'exento' => 0],
]], 1);
$conRcv = (new Dashboard($db, 1))->obtenerResumen();
$rcv = array_values(array_filter($conRcv['alertas'], fn($a) => $a['titulo'] === 'RCV Pendiente'));
comprobar(count($rcv) === 1 && str_starts_with($rcv[0]['mensaje'], '2 documento(s)'), 'alerta de documentos RCV importados sin contabilizar');

// Inventario
inventario_agregar($db, 1, ['codigo' => 'INV001', 'descripcion' => 'Escritorio', 'categoria' => 'Mobiliario', 'cantidad' => 5, 'valor_unitario' => 200000, 'ubicacion' => 'Oficina']);
$inv = (new Dashboard($db, 1))->obtenerResumen()['resumen_inventario'];
comprobar($inv === ['items_totales' => 1, 'cantidad_total' => 5, 'valor_total' => 1000000], 'resumen de inventario por empresa');
comprobar((new Dashboard($db, 2))->obtenerResumen()['resumen_inventario']['items_totales'] === 0, 'el inventario de una empresa no aparece en otra');

// Base sin ninguna tabla: cada sección queda vacía en vez de fallar
$bare = base_prueba();
$sinTablas = (new Dashboard($bare, 1))->obtenerResumen();
comprobar($sinTablas['kpis']['total_activo'] === 0 && $sinTablas['resumen_inventario']['items_totales'] === 0 && $sinTablas['alertas'] === [], 'sin tablas el dashboard responde en cero');

echo $fallas ? "$fallas pruebas fallaron" . PHP_EOL : 'Todas las pruebas del dashboard pasaron' . PHP_EOL;
exit($fallas ? 1 : 0);
