<?php
// Prueba del 14 D N° 8 (R22, R23, asignación a propietarios, cierre e inicio) con una empresa inventada.
// Uso: php tools/test-ctrib-r22.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/ctrib-pdf.php';
require dirname(__DIR__) . '/api/lib/ctrib-inicio.php';

function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FALLA: $msg\n"); exit(1); } }
function falla(callable $accion, string $contiene, string $msg): void
{
    try { $accion(); } catch (Throwable $e) {
        check(str_contains($e->getMessage(), $contiene), "$msg (mensaje: " . $e->getMessage() . ")");
        return;
    }
    check(false, "$msg (no lanzó error)");
}
function montos(array $r): array { return array_column($r['lineas'], 'monto', 'codigo'); }
function origenes(array $r): array { return array_column($r['lineas'], 'origen', 'codigo'); }

$pdo = base_prueba();
empresas_schema($pdo);
crearEsquema($pdo);
ctrib_schema($pdo);
$insertar = $pdo->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)');
$insertar->execute(['t1', 'EMPRESA PRUEBA TRANSPARENTE', json_encode(['rut' => '99000044-0']), '2026-11-01']);
$e = (int) $pdo->lastInsertId();
$insertar->execute(['t2', 'EMPRESA PRUEBA CON PERDIDA', json_encode(['rut' => '99000055-6']), '2026-11-01']);
$perdida = (int) $pdo->lastInsertId();

$cuenta = fn(string $codigo, string $nombre, int $debe, int $haber) => ['codigo' => $codigo, 'nombre' => $nombre, 'debitos' => $debe, 'creditos' => $haber];
$mapeo = ['1' => 'ACTIVO', '2' => 'PASIVO', '3' => 'CAPITAL', '4' => 'PERDIDA', '5' => 'GANANCIA'];
ctrib_mapeo_guardar($pdo, $e, $mapeo, 'prueba');
ctrib_balance_guardar($pdo, $e, 2026, 'prueba', [
    $cuenta('101001', 'CAJA', 21700000, 0),
    $cuenta('102001', 'EQUIPOS COMPUTACIONALES', 2000000, 0),
    $cuenta('102002', 'DEPRECIACION ACUMULADA EQUIPOS', 0, 200000),
    $cuenta('102003', 'IVA CREDITO ACTIVO FIJO', 100000, 0),
    $cuenta('201001', 'PROVEEDORES', 0, 1000000),
    $cuenta('301001', 'CAPITAL', 0, 5000000),
    $cuenta('401001', 'REMUNERACIONES', 8000000, 0),
    $cuenta('401002', 'HONORARIOS', 1000000, 0),
    $cuenta('401003', 'ARRIENDOS', 2400000, 0),
    $cuenta('401004', 'MULTAS FISCALES', 300000, 0),
    $cuenta('401005', 'OTROS GASTOS', 700000, 0),
    $cuenta('501001', 'VENTAS', 0, 30000000),
], 'ficticio.csv', 'prueba');
foreach (['501001' => '1400', '401001' => '1411', '401002' => '1412', '401003' => '1415', '401004' => '1422', '401005' => '1424'] as $c => $codigo) {
    ctrib_homologar($pdo, $e, 2026, (string) $c, $codigo, 'prueba');
}
ctrib_empresa_config_guardar($pdo, $e, 'SPA', '14D8', 'prueba');

// 1. R22 desde el balance: conceptos homologados trasladados, activo fijo desde la cuenta de activo y sin gastos rechazados.
$r = ctrib_r22($pdo, $e, 2026);
$m = montos($r);
check($m['1600'] === 30000000 && $m['1616'] === 8000000 && $m['1617'] === 1000000 && $m['1620'] === 2400000 && $m['1625'] === 700000, 'conceptos del R17 trasladados al R22');
check($m['1618'] === 2000000 && origenes($r)['1618'] === 'ACTIVO_FIJO', '1618: solo la cuenta de equipos (sin depreciación ni IVA)');
check($r['totales'] === ['1610' => 30000000, '1629' => 14100000, '1630' => 15900000], 'totales 1610, 1629 y 1630');
check(str_contains(implode(' ', $r['avisos']), 'activo fijo') && str_contains(implode(' ', $r['avisos']), '300.000'), 'avisos de activo fijo y gastos rechazados');
check($r['propietarios']['filas'] === [] && str_contains($r['propietarios']['avisos'][0], 'propietarios'), 'sin propietarios: se pide registrarlos');

// 2. Valores a mano: solo códigos permitidos, no negativos, y vaciar vuelve al automático.
falla(fn() => ctrib_r22_guardar_valor($pdo, $e, 2026, '1600', 1, 'prueba'), 'no se ingresa a mano', 'el 1600 no se escribe');
falla(fn() => ctrib_r22_guardar_valor($pdo, $e, 2026, '1604', -5, 'prueba'), 'negativo', 'sin montos negativos');
check(montos(ctrib_r22_guardar_valor($pdo, $e, 2026, '1618', 1500000, 'prueba'))['1618'] === 1500000, '1618 corregido a mano');
check(montos(ctrib_r22_guardar_valor($pdo, $e, 2026, '1618', null, 'prueba'))['1618'] === 2000000, 'al vaciar vuelve el monto del balance');
foreach (['1604' => 500000, '1605' => 100000, '1608' => 200001, '1609' => 150000] as $codigo => $monto) {
    ctrib_r22_guardar_valor($pdo, $e, 2026, (string) $codigo, $monto, 'prueba');
}
check(ctrib_r22($pdo, $e, 2026)['totales']['1630'] === 16850001, 'base con dividendos, incremento, ingreso diferido y crédito 33 bis');
$r = ctrib_r22_guardar_valor($pdo, $e, 2026, '1609', 5000000, 'prueba');
check(str_contains(implode(' ', $r['bloqueos']), '1609') && $r['puedeFinalizar'] === false, 'el 1609 no puede superar el IDPC de la base');
ctrib_r22_guardar_valor($pdo, $e, 2026, '1609', 150000, 'prueba');

// 3. Propietarios: la asignación suma exactamente y en 14 D N° 8 los retiros usan la base del R22, no la del R17.
ctrib_composicion_guardar($pdo, $e, 2026, 'SPA', '14D8', [
    ['rut' => '11111111-1', 'nombre' => 'PROPIETARIO UNO', 'participacion' => 50],
    ['rut' => '12345678-5', 'nombre' => 'PROPIETARIO DOS', 'participacion' => 50],
], false, 'prueba');
$filas = ctrib_r22($pdo, $e, 2026)['propietarios']['filas'];
check(array_column($filas, 'baseImponible') === [8425001, 8425000], 'base asignada con mayor resto');
check(array_column($filas, 'ingresoDiferido') === [100001, 100000] && array_column($filas, 'credito33bis') === [75000, 75000] && array_column($filas, 'creditoIdpc') === [50000, 50000], 'datos asignados a cada propietario');
check(ctrib_retiros_del_anio($pdo, $e, 2026)['total'] === 16850001, 'retiro de la totalidad: la base del R22');
check(montos(ctrib_r6($pdo, $e, 2026))['1193'] === 0, 'R6: en 14 D N° 8 no hay retiros excedentes');

// 4. R23.
ctrib_r23_guardar_valor($pdo, $e, 2026, '1573', 5000000, 'prueba');
$r = ctrib_r23($pdo, $e, 2026);
$m = montos($r);
check($m['1712'] === 16850001 && $m['1576'] === 16850001 && $m['1715'] === 200001 && $m['1577'] === 300000 && $m['1716'] === 150000 && $m['1578'] === 100000, 'R23 desde el R22 y los retiros');
check($r['totales'] === ['1581' => 4249999, '1583' => 0], 'CPTS final con la totalidad retirada');
check(str_contains(implode(' ', $r['avisos']), '50.000 UF') && str_contains(implode(' ', $r['avisos']), 'totalidad'), 'avisos del R23');
$r = ctrib_r23_guardar_valor($pdo, $e, 2026, '1576', 1000000, 'prueba');
check($r['totales']['1581'] === 20100000 && origenes($r)['1576'] === 'MANUAL', 'se puede indicar lo efectivamente repartido');

// 5. Inicio, cierre y documentos del régimen.
$i = ctrib_inicio($pdo, $e, 2026);
check($i['propio'] && $i['regimen'] === '14D8' && !$i['vistaPrevia'] && $i['cierre'] !== null, 'inicio propio del 14 D N° 8');
$codigos = [];
foreach ($i['grupos'] as $g) foreach ($g['filas'] as $f) if ($f['codigo'] !== '') $codigos[] = $f['codigo'];
check($codigos === ['R4', 'R6', 'R22', 'R23', 'DJ 1947'], 'recuadros y declaración que corresponden');
$previa = ctrib_inicio($pdo, $e, 2026, '14D3');
check($previa['vistaPrevia'] && $previa['regimen'] === '14D3' && $previa['regimenGuardado'] === '14D8' && $previa['cierre'] === null, 'vista previa de otro régimen sin guardarlo');
check(array_column(ctrib_cierre($pdo, $e, 2026)['recuadros'], 'codigo') === ['R4', 'R6', 'R22', 'R23'], 'el cierre exige los recuadros del régimen');
ctrib_r4_finalizar($pdo, $e, 2026, 'prueba');
ctrib_r6_finalizar($pdo, $e, 2026, 'prueba');
falla(fn() => ctrib_r22_finalizar($pdo, $e, 2026, 'prueba'), '1618', 'sin balance anterior hay que confirmar el 1618');
ctrib_r22_guardar_valor($pdo, $e, 2026, '1618', 2000000, 'prueba');
ctrib_r22_finalizar($pdo, $e, 2026, 'prueba');
ctrib_r23_finalizar($pdo, $e, 2026, 'prueba');
check(ctrib_cierre($pdo, $e, 2026)['completo'] === true, 'con los cuatro finalizados se puede cerrar');
falla(fn() => ctrib_r22_guardar_valor($pdo, $e, 2026, '1604', 1, 'prueba'), 'finalizado', 'finalizado no se modifica');
$dj = null;
foreach (ctrib_inicio($pdo, $e, 2026)['grupos'] as $g) foreach ($g['filas'] as $f) if ($f['codigo'] === 'DJ 1947') $dj = $f['estado'];
check($dj === 'COMPLETADO', 'DJ 1947 completa con el R22 finalizado y los RUT');
foreach (['R22' => '1630', 'R23' => '1581', 'DJ1947' => '8.425.001'] as $doc => $texto) {
    check(str_contains(ctrib_documento_html($pdo, $e, 2026, $doc), $texto), "documento $doc");
}
ctrib_r22_reabrir($pdo, $e, 2026, 'prueba', 'revisión');
check(ctrib_r22($pdo, $e, 2026)['estado'] === 'BORRADOR', 'reabrir');

// 6. Año siguiente: pérdida anterior, CPT inicial y activo fijo sin el saldo ya existente.
ctrib_mapeo_guardar($pdo, $perdida, $mapeo, 'prueba');
ctrib_balance_guardar($pdo, $perdida, 2026, 'prueba', [
    $cuenta('101001', 'CAJA', 0, 3000000), $cuenta('102001', 'MUEBLES', 1000000, 0), $cuenta('301001', 'CAPITAL', 0, 0),
    $cuenta('401001', 'REMUNERACIONES', 2000000, 0),
], 'ficticio.csv', 'prueba');
ctrib_homologar($pdo, $perdida, 2026, '401001', '1411', 'prueba');
ctrib_empresa_config_guardar($pdo, $perdida, 'SPA', '14D8', 'prueba');
$r = ctrib_r22($pdo, $perdida, 2026);
check($r['totales']['1630'] === -3000000, 'pérdida tributaria');
$r23 = ctrib_r23($pdo, $perdida, 2026);
check(montos($r23)['1713'] === 3000000 && $r23['totales'] === ['1581' => 0, '1583' => 3000000], 'R23 con pérdida: CPTS negativo');
ctrib_r22_guardar_valor($pdo, $perdida, 2026, '1618', 1000000, 'prueba'); // sin balance anterior
ctrib_r22_finalizar($pdo, $perdida, 2026, 'prueba');
ctrib_r23_finalizar($pdo, $perdida, 2026, 'prueba');
ctrib_balance_guardar($pdo, $perdida, 2027, 'prueba', [
    $cuenta('101001', 'CAJA', 0, 3500000), $cuenta('102001', 'MUEBLES', 1500000, 0), $cuenta('301001', 'CAPITAL', 0, 0),
    $cuenta('401001', 'REMUNERACIONES', 2000000, 0),
], 'ficticio.csv', 'prueba');
ctrib_homologar($pdo, $perdida, 2027, '401001', '1411', 'prueba');
$r = ctrib_r22($pdo, $perdida, 2027);
check(montos($r)['1618'] === 500000, '1618: solo lo comprado en el año');
check(montos($r)['1627'] === 3000000 && origenes($r)['1627'] === 'AÑO_ANTERIOR', '1627: pérdida del R22 anterior');
$r23 = ctrib_r23($pdo, $perdida, 2027);
check(montos($r23)['1582'] === 3000000 && montos($r23)['1714'] === 3000000, 'R23: CPT inicial negativo y pérdidas anteriores');

echo "Cifrax Tributario 14 D N°8 (R22, R23, propietarios, cierre e inicio): verificado\n";
