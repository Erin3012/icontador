<?php
// Prueba del módulo Banco (plantilla CSV, importación, conciliación y saldo): php tools/test-banco-api.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/banco.php';
function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FALLA: $msg\n"); exit(1); } }
function rechaza(callable $f, string $msg, string $contiene = ''): void {
    try { $f(); } catch (ErrorValidacion $e) { check($contiene === '' || str_contains($e->getMessage(), $contiene), "$msg: " . $e->getMessage()); return; }
    check(false, "debió rechazar: $msg");
}

// ---- Lectura del CSV ----
check(banco_plantilla() === "\u{FEFF}Fecha;Descripción;Monto;Referencia\r\n", 'plantilla solo con encabezados');
check(banco_leer_csv(banco_plantilla())['errores'] === ['El archivo no tiene movimientos; complete la plantilla desde la segunda línea.'], 'plantilla vacía');

$csv = banco_plantilla()
    . "01-09-2026;Saldo inicial;1.000.000;\r\n"
    . "05/09/2026;Transferencia de Cliente Uno, factura 120;$ 238.000;TRF-001\r\n"
    . "2026-09-07;\"Pago proveedor; insumos\";-119000;CHQ 45\r\n"
    . "\r\n"
    . "30-09-2026;Comisión mantención;-4.500,00;\r\n";
$leido = banco_leer_csv($csv);
check($leido['errores'] === [], 'CSV válido sin errores: ' . json_encode($leido['errores'], JSON_UNESCAPED_UNICODE));
check($leido['movimientos'] === [
    ['fecha' => '2026-09-01', 'descripcion' => 'Saldo inicial', 'monto' => 1000000, 'referencia' => ''],
    ['fecha' => '2026-09-05', 'descripcion' => 'Transferencia de Cliente Uno, factura 120', 'monto' => 238000, 'referencia' => 'TRF-001'],
    ['fecha' => '2026-09-07', 'descripcion' => 'Pago proveedor; insumos', 'monto' => -119000, 'referencia' => 'CHQ 45'],
    ['fecha' => '2026-09-30', 'descripcion' => 'Comisión mantención', 'monto' => -4500, 'referencia' => ''],
], 'movimientos leídos (fechas, montos con puntos, comillas y línea vacía)');

// Separador coma, columnas en otro orden, sin referencia y archivo guardado por Excel en Windows-1252.
$coma = banco_leer_csv(mb_convert_encoding("Monto,Fecha,Descripción\n-2500,15-09-2026,Café oficina\n", 'Windows-1252', 'UTF-8'));
check($coma['errores'] === [] && $coma['movimientos'] === [['fecha' => '2026-09-15', 'descripcion' => 'Café oficina', 'monto' => -2500, 'referencia' => '']], 'coma, otro orden y Windows-1252');

foreach (['1.500' => 1500, '-15.000' => -15000, '+200' => 200, '2500,00' => 2500, '1234.5' => null, '12,5' => null, 'abc' => null, '1.2.3' => null, '' => null, '1,000' => null] as $texto => $esperado) {
    check(banco_monto((string) $texto) === $esperado, "monto \"$texto\"");
}
foreach (['31-12-2026' => '2026-12-31', '1/2/2026' => '2026-02-01', '2026-02-29' => null, '30-02-2026' => null, '2026/09/01' => null, '09-2026' => null] as $texto => $esperado) {
    check(banco_fecha($texto) === $esperado, "fecha \"$texto\"");
}

$errores = banco_leer_csv("Fecha;Descripción;Monto;Referencia\n32-09-2026;Algo;100;\n01-09-2026;;abc;\n02-09-2026;Cero;0;\n03-09-2026;Bien;100;\n")['errores'];
check($errores === [
    'Línea 2: fecha inválida "32-09-2026" (use DD-MM-AAAA).',
    'Línea 3: falta la descripción; monto inválido "abc" (use un número entero, negativo para cargos).',
    'Línea 4: el monto no puede ser cero.',
], 'errores por línea: ' . json_encode($errores, JSON_UNESCAPED_UNICODE));
check(str_contains(banco_leer_csv("Fecha;Detalle;Valor\n01-09-2026;x;1\n")['errores'][0], 'Falta: descripcion, monto'), 'encabezado incorrecto');
check(banco_leer_csv('')['errores'] === ['El archivo está vacío.'], 'archivo vacío');

// ---- Base de datos ----
$db = base_prueba();
empresas_schema($db);
banco_schema($db);
banco_schema($db); // idempotente
foreach (['p1', 'p2'] as $origen) {
    $db->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')->execute([$origen, 'EMPRESA ' . $origen, '{}', '2026-10-07']);
}
[$empresa, $otra] = array_map('intval', $db->query('SELECT id FROM empresas ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));

check(banco_importar($db, $csv, $empresa) === ['importados' => 4, 'omitidos' => 0, 'desde' => '2026-09-01', 'hasta' => '2026-09-30'], 'importar');
check(banco_importar($db, $csv, $empresa)['omitidos'] === 4, 'volver a subir la misma cartola no duplica');
// Dos cargos idénticos el mismo día son dos movimientos; el archivo siguiente trae uno ya cargado y uno nuevo.
$dobles = "Fecha;Descripción;Monto;Referencia\n30-09-2026;Comisión mantención;-4500;\n30-09-2026;Comisión mantención;-4500;\n";
check(banco_importar($db, $dobles, $empresa) === ['importados' => 1, 'omitidos' => 1, 'desde' => '2026-09-30', 'hasta' => '2026-09-30'], 'movimientos repetidos legítimos');
rechaza(fn() => banco_importar($db, $csv . "31-09-2026;Mala;1;\n", $empresa), 'un error no guarda nada', 'Línea 7');
check(count(banco_listar($db, [], $empresa)) === 5, 'el archivo con error no agregó movimientos');
check(banco_listar($db, [], $otra) === [] && banco_reporte($db, [], $otra)['saldoFinal'] === 0, 'datos separados por empresa');

// Búsqueda por texto, monto (sin signo) y fechas.
check(array_column(banco_listar($db, ['q' => 'PROVEEDOR'], $empresa), 'monto') === [-119000], 'buscar por descripción');
check(array_column(banco_listar($db, ['q' => 'trf-0'], $empresa), 'referencia') === ['TRF-001'], 'buscar por referencia');
check(count(banco_listar($db, ['q' => '%'], $empresa)) === 0, 'el % se busca literal');
check(array_column(banco_listar($db, ['monto' => '119.000'], $empresa), 'descripcion') === ['Pago proveedor; insumos'], 'buscar por monto');
check(count(banco_listar($db, ['desde' => '2026-09-05', 'hasta' => '2026-09-07'], $empresa)) === 2, 'buscar por fechas');
rechaza(fn() => banco_listar($db, ['desde' => '05-09-2026'], $empresa), 'fecha de filtro', 'inválida');
rechaza(fn() => banco_listar($db, ['estado' => 'otro'], $empresa), 'estado', 'Estado');

// ---- Conciliación ----
$db->prepare("INSERT INTO cuentas (empresa_id, codigo, nombre) VALUES (?, '1.1.02', 'Banco'), (?, '2.1.01', 'Proveedores'), (?, '1.1.03', 'Clientes')")->execute([$empresa, $empresa, $empresa]);
$pago = guardarVoucher($db, ['tipo' => 'E', 'fecha' => '2026-09-07', 'registro' => 'Ambos', 'glosa' => 'Pago proveedor insumos',
    'lineas' => [['cuenta' => '2.1.01', 'debe' => 119000], ['cuenta' => '1.1.02', 'haber' => 119000]]], null, $empresa);
$cobro = guardarVoucher($db, ['tipo' => 'I', 'fecha' => '2026-09-05', 'registro' => 'Ambos', 'glosa' => 'Cobro Cliente Uno',
    'lineas' => [['cuenta' => '1.1.02', 'debe' => 238000], ['cuenta' => '1.1.03', 'haber' => 238000]]], null, $empresa);
$comprobantes = banco_comprobantes_pendientes($db, [], $empresa);
check(array_column($comprobantes, 'monto') === [238000, -119000], 'vouchers sin conciliar con signo (Ingreso +, Egreso -)');
check(array_column(banco_comprobantes_pendientes($db, ['monto' => '-119000'], $empresa), 'id') === [$pago['id']], 'buscar voucher por monto');
check(array_column(banco_comprobantes_pendientes($db, ['q' => 'cliente'], $empresa), 'id') === [$cobro['id']], 'buscar voucher por glosa');
check(banco_comprobantes_pendientes($db, [], $otra) === [], 'vouchers de otra empresa no aparecen');

$cargo = banco_listar($db, ['monto' => '119000'], $empresa)[0];
$conciliado = banco_conciliar($db, $cargo['id'], $pago['id'], $empresa);
check($conciliado['estado'] === 'conciliado' && $conciliado['comprobante']['numero'] === $pago['numero'] && $conciliado['comprobante']['tipo'] === 'E', 'conciliar con voucher');
check(array_column(banco_comprobantes_pendientes($db, [], $empresa), 'id') === [$cobro['id']], 'el voucher conciliado sale de los pendientes');
check(count(banco_listar($db, ['estado' => 'pendiente'], $empresa)) === 4 && count(banco_listar($db, ['estado' => 'conciliado'], $empresa)) === 1, 'filtrar por estado');
rechaza(fn() => banco_conciliar($db, $cargo['id'], null, $empresa), 'ya conciliado', 'ya está conciliado');
rechaza(fn() => banco_eliminar($db, $cargo['id'], $empresa), 'no se elimina un conciliado', 'Desconcilie');
$comision = banco_listar($db, ['q' => 'comisión'], $empresa)[0];
rechaza(fn() => banco_conciliar($db, $comision['id'], $cobro['id'], $otra), 'movimiento de otra empresa', 'no existe');
rechaza(fn() => banco_conciliar($db, $comision['id'], 999, $empresa), 'voucher inexistente', 'voucher');
check(banco_conciliar($db, $comision['id'], null, $empresa)['comprobante'] === null, 'conciliar sin voucher');
check(banco_desconciliar($db, $cargo['id'], $empresa)['estado'] === 'pendiente' && count(banco_comprobantes_pendientes($db, [], $empresa)) === 2, 'desconciliar');
$sobrante = banco_listar($db, ['estado' => 'pendiente', 'q' => 'comisión'], $empresa)[0];
check(banco_eliminar($db, $sobrante['id'], $empresa) && !banco_eliminar($db, $sobrante['id'], $otra), 'eliminar pendiente solo en su empresa');

// ---- Reporte ----
$r = banco_reporte($db, ['desde' => '2026-09-02', 'hasta' => '2026-09-30'], $empresa);
check($r['saldoInicial'] === 1000000 && $r['abonos'] === 238000 && $r['cargos'] === -123500 && $r['saldoFinal'] === 1114500, 'saldos del período: ' . json_encode(array_slice($r, 0, 6)));
check(count($r['movimientos']) === 3 && end($r['movimientos'])['saldo'] === 1114500 && $r['conciliados'] === 1 && $r['pendientes'] === 2, 'movimientos del reporte con saldo acumulado');
check(banco_reporte($db, [], $empresa)['saldoInicial'] === 0 && banco_reporte($db, [], $empresa)['saldoFinal'] === 1114500, 'reporte sin fechas');
rechaza(fn() => banco_reporte($db, ['desde' => '2026-10-01', 'hasta' => '2026-09-01'], $empresa), 'desde > hasta', 'posterior');

echo "Banco PHP: plantilla, lectura y validación del CSV, importación sin duplicados, búsqueda, conciliación y saldos verificados\n";
