<?php
// Prueba de Dashboard y Reportes contra el esquema real de vouchers, banco e inventario:
// php tools/test-dashboard-reportes-api.php
declare(strict_types=1);

require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/dashboard.php';
require dirname(__DIR__) . '/api/lib/reportes.php';
require dirname(__DIR__) . '/api/lib/banco.php';
require dirname(__DIR__) . '/api/lib/inventario.php';

function check(bool $ok, string $msg): void
{
    if (!$ok) {
        fwrite(STDERR, "FALLA: $msg\n");
        exit(1);
    }
}

$db = base_prueba();
empresas_schema($db);

foreach (['p1', 'p2'] as $origen) {
    $db->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')
        ->execute([$origen, 'EMPRESA ' . strtoupper($origen), '{}', '2026-11-27']);
}
[$empresa1, $empresa2] = array_map('intval', $db->query('SELECT id FROM empresas ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));

// 1. Con BD recién creada (sin tablas de extractos ni inventario todavía), no debe lanzar errores.
$dashVacio = new Dashboard($db, $empresa1);
$resumenVacio = $dashVacio->obtenerResumen();
check(!isset($resumenVacio['kpis']['error']), 'dashboard sin tablas opcionales no devuelve error');
check($resumenVacio['kpis']['saldo_caja'] === 0 && $resumenVacio['kpis']['total_activo'] === 0, 'KPIs iniciales en cero');
check($resumenVacio['ultimos_vouchers'] === [] && $resumenVacio['balance_caja'] === [] && $resumenVacio['alertas'] === [], 'listas vacías sin alertas espurias');
check($resumenVacio['resumen_inventario'] === ['items_totales' => 0, 'cantidad_total' => 0, 'valor_total' => 0], 'resumen inventario en cero');

$repVacio = new Reportes($db, $empresa1);
foreach (['diario', 'mayor', 'balance', 'resultado', 'flujo'] as $t) {
    $r = $repVacio->obtenerDatosReporte($t, ['desde' => '2026-01-01', 'hasta' => '2026-12-31']);
    check(!isset($r['error']), "reporte '$t' vacío sin error");
}

// 2. Poblar plan de cuentas y vouchers en dos empresas distintas
crearEsquema($db);
cargarPlanEjemplo($db, $empresa1);
cargarPlanEjemplo($db, $empresa2);

$mesAnterior = date('Y-m-15', strtotime('-1 month'));
$mesActual1 = date('Y-m-10');
$mesActual2 = date('Y-m-11');
$mesActual3 = date('Y-m-12');
$desde = substr($mesAnterior, 0, 8) . '01';
$hasta = date('Y-m-t');

// Empresa 1: aporte capital, arriendo (egreso), venta contado (ingreso tributario), compra mercadería (traspaso IFRS)
guardarVoucher($db, [
    'tipo' => 'I', 'fecha' => $mesAnterior, 'registro' => 'Ambos', 'glosa' => 'Aporte inicial',
    'lineas' => [['cuenta' => '1.1.02', 'debe' => 2000000], ['cuenta' => '2.3.01', 'haber' => 2000000]],
], null, $empresa1);

guardarVoucher($db, [
    'tipo' => 'E', 'fecha' => $mesActual1, 'registro' => 'Ambos', 'glosa' => 'Arriendo oficina',
    'lineas' => [['cuenta' => '3.1.04', 'debe' => 300000], ['cuenta' => '1.1.02', 'haber' => 300000]],
], null, $empresa1);

guardarVoucher($db, [
    'tipo' => 'I', 'fecha' => $mesActual2, 'registro' => 'Tributario', 'glosa' => 'Venta contado',
    'lineas' => [['cuenta' => '1.1.01', 'debe' => 800000], ['cuenta' => '4.1.01', 'haber' => 800000]],
], null, $empresa1);

guardarVoucher($db, [
    'tipo' => 'T', 'fecha' => $mesActual3, 'registro' => 'IFRS', 'glosa' => 'Compra mercaderías crédito',
    'lineas' => [['cuenta' => '1.1.07', 'debe' => 500000], ['cuenta' => '2.1.01', 'haber' => 500000]],
], null, $empresa1);

// Empresa 2: movimientos diferentes para verificar aislamiento y cuentas por devengo (Traspaso en 3.x y 4.x)
guardarVoucher($db, [
    'tipo' => 'I', 'fecha' => $mesActual1, 'registro' => 'Ambos', 'glosa' => 'Aporte empresa 2',
    'lineas' => [['cuenta' => '1.1.02', 'debe' => 500000], ['cuenta' => '2.3.01', 'haber' => 500000]],
], null, $empresa2);

guardarVoucher($db, [
    'tipo' => 'T', 'fecha' => $mesActual2, 'registro' => 'Ambos', 'glosa' => 'Factura venta crédito E2',
    'lineas' => [['cuenta' => '1.1.03', 'debe' => 950000], ['cuenta' => '4.1.01', 'haber' => 950000]],
], null, $empresa2);

guardarVoucher($db, [
    'tipo' => 'T', 'fecha' => $mesActual3, 'registro' => 'Ambos', 'glosa' => 'Devengo sueldo E2',
    'lineas' => [['cuenta' => '3.1.02', 'debe' => 250000], ['cuenta' => '2.1.04', 'haber' => 250000]],
], null, $empresa2);

// Inventario y Banco (extractos) para Empresa 1
inventario_schema($db);
inventario_agregar($db, $empresa1, ['codigo' => 'INV-1', 'descripcion' => 'Notebook', 'cantidad' => 3, 'valor_unitario' => 400000]);
inventario_agregar($db, $empresa2, ['codigo' => 'INV-2', 'descripcion' => 'Otro', 'cantidad' => 10, 'valor_unitario' => 999999]);

banco_schema($db);
banco_importar($db, banco_plantilla() . "10-11-2026;Abono cliente;150000;REF-1\r\n", $empresa1);

// 3. Verificar Dashboard de Empresa 1
$dash1 = new Dashboard($db, $empresa1);
$r1 = $dash1->obtenerResumen();

check(!isset($r1['kpis']['error']), 'KPIs sin error: ' . ($r1['kpis']['error'] ?? ''));
check($r1['kpis']['saldo_caja'] === 2500000, 'saldo_caja esperado 2.500.000, obtenido ' . $r1['kpis']['saldo_caja']);
check($r1['kpis']['total_activo'] === 3000000, 'total_activo esperado 3.000.000, obtenido ' . $r1['kpis']['total_activo']);
check($r1['kpis']['total_pasivo'] === 500000, 'total_pasivo esperado 500.000, obtenido ' . $r1['kpis']['total_pasivo']);
check($r1['kpis']['patrimonio'] === 2500000, 'patrimonio esperado 2.500.000');
check($r1['kpis']['razon_corriente'] === 6.0, 'razon_corriente 6.0');
check($r1['kpis']['endeudamiento'] === 0.17, 'endeudamiento 0.17');

check(count($r1['ultimos_vouchers']) === 4, '4 últimos vouchers en empresa 1');
check($r1['ultimos_vouchers'][0]['glosa'] === 'Compra mercaderías crédito' && $r1['ultimos_vouchers'][0]['tipo'] === 'Traspaso', 'último voucher mapea tipo y glosa');
check($r1['ultimos_vouchers'][1]['tipo'] === 'Ingreso' && $r1['ultimos_vouchers'][2]['tipo'] === 'Egreso', 'mapeo de tipos Ingreso/Egreso');

$cajasPorCuenta = array_column($r1['balance_caja'], 'saldo', 'numero_cuenta');
check(($cajasPorCuenta['1.1.01'] ?? null) === 800000 && ($cajasPorCuenta['1.1.02'] ?? null) === 1700000, 'saldos caja/banco por cuenta');
check(($cajasPorCuenta['Extractos'] ?? null) === 150000, 'saldo extractos bancarios');

$flujoPorMes = array_column($r1['flujo_mensual'], null, 'mes');
$ymAnt = substr($mesAnterior, 0, 7);
$ymAct = substr($mesActual1, 0, 7);
check(($flujoPorMes[$ymAnt]['ingresos'] ?? 0) === 2000000 && ($flujoPorMes[$ymAnt]['neto'] ?? 0) === 2000000, 'flujo mes anterior');
check(($flujoPorMes[$ymAct]['ingresos'] ?? 0) === 800000 && ($flujoPorMes[$ymAct]['egresos'] ?? 0) === 300000 && ($flujoPorMes[$ymAct]['neto'] ?? 0) === 500000, 'flujo mes actual sin traspasos');

check($r1['resumen_inventario'] === ['items_totales' => 1, 'cantidad_total' => 3, 'valor_total' => 1200000], 'resumen inventario aislado por empresa');

$titulosAlertas1 = array_column($r1['alertas'], 'titulo');
check(in_array('Banco Pendiente', $titulosAlertas1, true), 'alerta de extractos pendientes');
check(!in_array('Saldo Bajo', $titulosAlertas1, true), 'sin alerta Saldo Bajo cuando caja >= 1M');
check(!in_array('Vouchers Descuadrados', $titulosAlertas1, true), 'sin alerta de vouchers descuadrados');

// 4. Verificar Reportes de Empresa 1
$rep1 = new Reportes($db, $empresa1);

$diario = $rep1->obtenerDatosReporte('diario', ['desde' => $desde, 'hasta' => $hasta, 'registro' => 'Ambos']);
check($diario['totales'] === ['debe' => 3600000, 'haber' => 3600000, 'cantidad' => 4], 'Libro Diario completo');
check($diario['vouchers'][1]['descripcion'] === 'Arriendo oficina' && $diario['vouchers'][1]['tipo'] === 'Egreso', 'Libro Diario expone descripcion y tipo legible');

$diarioTrib = $rep1->obtenerDatosReporte('diario', ['desde' => $desde, 'hasta' => $hasta, 'registro' => 'Tributario']);
check($diarioTrib['totales']['cantidad'] === 3 && $diarioTrib['totales']['debe'] === 3100000, 'filtro Tributario incluye Ambos + Tributario');

$diarioEgreso = $rep1->obtenerDatosReporte('diario', ['desde' => $desde, 'hasta' => $hasta, 'registro' => 'Ambos', 'tipo' => 'Egreso']);
check($diarioEgreso['totales']['cantidad'] === 1 && $diarioEgreso['totales']['debe'] === 300000, 'filtro tipo Egreso funciona');

$mayor = $rep1->obtenerDatosReporte('mayor', ['desde' => $desde, 'hasta' => $hasta, 'registro' => 'Ambos']);
$mayorPorCodigo = array_column($mayor['cuentas'], null, 'codigo');
check($mayorPorCodigo['1.1.02']['debe_total'] === 2000000 && $mayorPorCodigo['1.1.02']['haber_total'] === 300000, 'Libro Mayor aislado de empresa 2');
check($mayorPorCodigo['1.1.03']['debe_total'] === 0 && $mayorPorCodigo['3.1.02']['debe_total'] === 0, 'movimientos de empresa 2 no contaminan empresa 1');
check($mayor['totales']['debe'] === 3600000 && $mayor['totales']['haber'] === 3600000, 'totales del Libro Mayor cuadran');

$balance = $rep1->obtenerDatosReporte('balance', ['desde' => $desde, 'hasta' => $hasta, 'registro' => 'Ambos']);
check(isset($balance['balance']['Activo'], $balance['balance']['Pasivo'], $balance['balance']['Resultado'], $balance['balance']['Ingresos']), 'Balance General agrupado por clase');

$resultado1 = $rep1->obtenerDatosReporte('resultado', ['desde' => $desde, 'hasta' => $hasta, 'registro' => 'Ambos']);
check($resultado1['totales'] === ['ingresos' => 800000, 'gastos' => 300000, 'resultado' => 500000], 'Estado de Resultado Empresa 1');

$flujoRep = $rep1->obtenerDatosReporte('flujo', ['desde' => $desde, 'hasta' => $hasta]);
check($flujoRep['totales'] === ['ingresos' => 2800000, 'egresos' => 300000, 'neto' => 2500000], 'Reporte Flujo de Caja');

// 5. Verificar Estado de Resultado con vouchers de Traspaso + alertas en Empresa 2
$rep2 = new Reportes($db, $empresa2);
$resultado2 = $rep2->obtenerDatosReporte('resultado', ['desde' => $desde, 'hasta' => $hasta]);
check($resultado2['totales'] === ['ingresos' => 950000, 'gastos' => 250000, 'resultado' => 700000], 'Estado de Resultado incluye devengos tipo Traspaso');

// Insertar un voucher descuadrado directamente en Empresa 2 para comprobar alerta
$db->prepare("INSERT INTO vouchers (empresa_id, tipo, periodo, numero, fecha, registro, glosa, creado, modificado) VALUES (?, 'T', ?, 99, ?, 'Ambos', 'Descuadrado', '2026-11-27', '2026-11-27')")
    ->execute([$empresa2, substr($mesActual3, 0, 7), $mesActual3]);
$vidDesc = (int) $db->lastInsertId();
$db->prepare("INSERT INTO voucher_lineas (voucher_id, orden, cuenta, glosa, debe, haber) VALUES (?, 1, '1.1.01', '', 1000, 0)")
    ->execute([$vidDesc]);

$dash2 = new Dashboard($db, $empresa2);
$titulosAlertas2 = array_column($dash2->obtenerResumen()['alertas'], 'titulo');
check(in_array('Vouchers Descuadrados', $titulosAlertas2, true), 'alerta Vouchers Descuadrados');
check(in_array('Saldo Bajo', $titulosAlertas2, true), 'alerta Saldo Bajo cuando caja < 1M');

echo "Dashboard y Reportes PHP: KPIs, balance caja, flujo mensual, alertas, inventario, diario, mayor, balance y resultado verificados\n";
