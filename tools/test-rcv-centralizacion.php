<?php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/vouchers.php';
require dirname(__DIR__) . '/api/lib/rcv.php';
require dirname(__DIR__) . '/api/lib/rcv-centralizacion.php';

$db = base_prueba();
crearEsquema($db);
rcv_schema($db);
rcv_centralizacion_schema($db);
cargarPlanEjemplo($db, 1);
cargarPlanEjemplo($db, 2);
$fallas = 0;
function comprobar_cent(bool $ok, string $mensaje): void { global $fallas; echo ($ok ? 'ok   ' : 'FALLA ') . $mensaje . PHP_EOL; $fallas += $ok ? 0 : 1; }
function doc_cent(int $tipo, string $folio, string $fecha, string $rut, string $razon, string $operacion, int $neto, int $iva, int $total, int $exento = 0): array {
    return ['tipo' => $tipo, 'folio' => $folio, 'fecha' => $fecha, 'rut' => $rut, 'razon' => $razon, 'tipoOperacion' => $operacion,
        'neto' => $neto, 'iva' => $iva, 'total' => $total, 'exento' => $exento];
}
$proveedor = '76123456-7';
rcv_guardar($db, ['period' => '2026-10', 'kind' => 'compras', 'docs' => [
    doc_cent(33, '101', '05/10/2026', $proveedor, 'Proveedor Uno', 'Del Giro', 1000, 190, 1190),
    doc_cent(33, '102', '06/10/2026', '77987654-3', 'Proveedor Dos', 'No Incluir', 500, 95, 595),
    doc_cent(33, '104', '10/10/2026', $proveedor, 'Proveedor Uno', 'Activo Fijo', 2000, 380, 2380),
]], 1);
rcv_guardar($db, ['period' => '2026-10', 'kind' => 'ventas', 'docs' => [
    doc_cent(33, '201', '07/10/2026', '76543210-K', 'Cliente Uno', '', 2000, 380, 2380),
    doc_cent(61, '202', '08/10/2026', '76543210-K', 'Cliente Uno', '', 1000, 190, 1190),
]], 1);
rcv_guardar($db, ['period' => '2026-10', 'kind' => 'ventas', 'docs' => [
    doc_cent(33, '999', '09/10/2026', '76543210-K', 'Otro', '', 10000, 1900, 11900),
]], 2);

$config = [
    'cuenta_compra_default' => '3.1.05', 'cuenta_iva_credito' => '1.1.05', 'cuenta_iva_no_rec' => '3.1.05',
    'cuenta_otros_compra' => '3.1.05', 'cuenta_proveedor_default' => '2.1.01', 'cuenta_venta_default' => '4.1.01',
    'cuenta_iva_debito' => '2.1.02', 'cuenta_retencion_venta' => '1.1.11', 'cuenta_otros_venta' => '4.1.02', 'cuenta_cliente' => '1.1.03',
    'cuentas_operacion' => ['activo fijo' => '1.2.02'],
];
$lista = rcv_centralizacion_proveedores($db, 1);
comprobar_cent(count($lista) === 2 && $lista[0]['cuenta_efectiva'] === '', 'proveedores se detectan por RUT dentro de la empresa');
try { $guardada = rcv_centralizacion_guardar_configuracion($db, 1, ['config' => $config, 'proveedores' => [['rut' => $proveedor, 'cuenta' => '2.1.11']]]); }
catch (Throwable $e) { echo 'Error de configuración: ' . $e->getMessage() . PHP_EOL; exit(1); }
$proveedorGuardado = array_values(array_filter($guardada['proveedores'], fn($p) => $p['rut'] === '761234567'))[0] ?? null;
comprobar_cent(($proveedorGuardado['cuenta_efectiva'] ?? '') === '2.1.11', 'asignación individual de proveedor reemplaza la cuenta común');
$proveedorNuevo = array_values(array_filter($guardada['proveedores'], fn($p) => $p['rut'] === '779876543'))[0] ?? null;
comprobar_cent(($proveedorNuevo['cuenta_efectiva'] ?? '') === '2.1.01', 'proveedor nuevo hereda la cuenta común por pagar');
try { rcv_centralizacion_guardar_configuracion($db, 1, ['config' => $config, 'proveedores' => [['rut' => '70000000-0', 'cuenta' => '2.1.01']]]); $rechazoProveedor = false; }
catch (RcvCentralizacionError) { $rechazoProveedor = true; }
comprobar_cent($rechazoProveedor, 'no permite asignar proveedores de otra empresa o ajenos a sus RCV');

$scope = ['desde' => '2026-10', 'hasta' => '2026-10', 'libros' => ['compras', 'ventas'], 'registro' => 'Tributario'];
rcv_guardar_resumen($db,['kind'=>'ventas','period'=>'2026-10','fileName'=>'resumen.csv','resumen'=>[['tipo'=>48,'tipoNombre'=>'Pago electrónico','documentos'=>187,'exento'=>0,'neto'=>1369623,'iva'=>260227,'total'=>1629850]]],1);
$previa = rcv_centralizacion_preparar($db, 1, $scope);
comprobar_cent($previa['vouchers_nuevos'] === 5 && $previa['no_incluir'] === 1 && $previa['resumenes_48_seleccionados'] === 1, 'previsualiza compras/ventas, nota de crédito, resumen 48 e ignora No Incluir');
comprobar_cent($previa['documentos_seleccionados']===5&&!array_filter($previa['items'],fn($item)=>$item['folio']===''),'resumen 48 crea un asiento agregado independiente de los documentos detallados');
comprobar_cent($previa['periodos_sin_datos'] === [], 'no reporta períodos vacíos con documentos');
$compra = array_values(array_filter($previa['items'], fn($i) => $i['libro'] === 'compras'))[0];
comprobar_cent($compra['voucher']['tipo'] === 'E' && end($compra['voucher']['lineas'])['haber'] === 1190 && end($compra['voucher']['lineas'])['cuenta'] === '2.1.11', 'compra crea egreso cuadrado con cuenta individual del proveedor');
$activo = array_values(array_filter($previa['items'], fn($i) => $i['folio'] === '104'))[0];
comprobar_cent($activo['voucher']['lineas'][0]['cuenta'] === '1.2.02', 'naturaleza Activo Fijo usa la cuenta configurada');
$nota = array_values(array_filter($previa['items'], fn($i) => $i['folio'] === '202'))[0];
comprobar_cent($nota['voucher']['tipo'] === 'I' && $nota['voucher']['lineas'][0]['haber'] === 1190, 'nota de crédito de venta invierte Debe/Haber');
$resumen48 = array_values(array_filter($previa['items'], fn($i) => !empty($i['resumen_48'])))[0] ?? null;
comprobar_cent($resumen48 && $resumen48['voucher']['tipo'] === 'I' && $resumen48['folio'] === 'Resumen 48'
    && $resumen48['voucher']['fecha'] === '2026-10-31'
    && array_sum(array_column($resumen48['voucher']['lineas'], 'debe')) === 1629850
    && array_sum(array_column($resumen48['voucher']['lineas'], 'haber')) === 1629850,
    'resumen tipo 48 crea un único voucher mensual cuadrado y fechado al cierre del mes');
$guardado = rcv_centralizacion_confirmar($db, 1, $scope);
comprobar_cent($guardado['creados'] === 5 && $guardado['resumenes_48_centralizados'] === 1 && count(listarVouchers($db, [], 1)) === 5, 'confirma lote atómico con el asiento agregado de ventas');
$repetida = rcv_centralizacion_preparar($db, 1, $scope);
comprobar_cent($repetida['vouchers_nuevos'] === 0 && $repetida['ya_centralizados'] === 5, 'repetir la operación omite documentos y resumen ya centralizados');
$vacios = rcv_centralizacion_preparar($db, 1, ['desde' => '2026-09', 'hasta' => '2026-10', 'libros' => ['compras', 'ventas']]);
comprobar_cent(count($vacios['periodos_sin_datos']) === 2 && $vacios['periodos_sin_datos'][0]['periodo'] === '2026-09', 'la vista previa identifica libros/períodos sin documentos importados');
$soloEmpresa2 = rcv_centralizacion_preparar($db, 2, $scope);
comprobar_cent($soloEmpresa2['documentos_seleccionados'] === 1 && count($soloEmpresa2['items']) === 1 && $soloEmpresa2['items'][0]['folio'] === '999', 'cada empresa solo centraliza sus propios documentos con su plan de cuentas');

rcv_guardar($db, ['period' => '2026-10', 'kind' => 'compras', 'docs' => [
    doc_cent(33, '101', '05/10/2026', $proveedor, 'Proveedor Uno', 'Del Giro', 1100, 190, 1290),
    doc_cent(33, '104', '10/10/2026', $proveedor, 'Proveedor Uno', 'Activo Fijo', 2000, 380, 2380),
    doc_cent(33, '103', '11/10/2026', $proveedor, 'Proveedor Uno', 'Del Giro', 1000, 190, 1200),
]], 1);
$actualizada = rcv_centralizacion_preparar($db, 1, $scope);
comprobar_cent($actualizada['ya_centralizados'] === 4 && $actualizada['cambiados'] === 1 && count($actualizada['errores']) === 1, 'detecta documento modificado y bloquea otro documento descuadrado sin duplicar el resumen 48');
$antes = (int) $db->query('SELECT COUNT(*) FROM vouchers WHERE empresa_id = 1')->fetchColumn();
try { rcv_centralizacion_confirmar($db, 1, $scope); $rollback = false; }
catch (RcvCentralizacionError) { $rollback = true; }
comprobar_cent($rollback && (int) $db->query('SELECT COUNT(*) FROM vouchers WHERE empresa_id = 1')->fetchColumn() === $antes, 'error durante el lote deja intactos los vouchers existentes');

if (!es_mysql($db)) {
    rcv_guardar($db, ['period' => '2026-11', 'kind' => 'ventas', 'docs' => [
        doc_cent(33, '12001', '12/11/2026', '76543210-K', 'Cliente', '', 10, 0, 10),
        doc_cent(33, '12002', '13/11/2026', '76543210-K', 'Cliente', '', 20, 0, 20),
    ]], 1);
    $db->exec("CREATE TRIGGER fixture_rcv_link_fail BEFORE INSERT ON rcv_centralizaciones WHEN NEW.folio = '12002' BEGIN SELECT RAISE(ABORT, 'fixture rollback'); END");
    try { rcv_centralizacion_confirmar($db, 1, ['desde' => '2026-11', 'hasta' => '2026-11', 'libros' => ['ventas']]); $rollbackReal = false; }
    catch (Throwable) { $rollbackReal = true; }
    $db->exec('DROP TRIGGER fixture_rcv_link_fail');
    comprobar_cent($rollbackReal && (int) $db->query('SELECT COUNT(*) FROM vouchers WHERE empresa_id = 1 AND periodo = "2026-11"')->fetchColumn() === 0,
        'rollback SQL elimina también los vouchers de una inserción parcial');
}

$docs = [];
for ($i = 1; $i <= 1001; $i++) $docs[] = doc_cent(33, (string) (10000 + $i), '12/10/2026', '70000000-1', 'Lote', 'Del Giro', 1, 0, 1);
rcv_guardar($db, ['period' => '2026-11', 'kind' => 'ventas', 'docs' => $docs], 1);
try { rcv_centralizacion_preparar($db, 1, ['desde' => '2026-11', 'hasta' => '2026-11', 'libros' => ['ventas']]); $limite = false; }
catch (RcvCentralizacionError $e) { $limite = str_contains($e->getMessage(), '1.000'); }
comprobar_cent($limite, 'limita una operación a 1.000 documentos');

echo $fallas ? "$fallas pruebas fallaron" . PHP_EOL : 'Todas las pruebas de centralización pasaron' . PHP_EOL;
exit($fallas ? 1 : 0);
