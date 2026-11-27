<?php
// Prueba del régimen Semi Integrado (Art. 14 letra A) con el ejercicio oficial del SII (Sociedad Fénix Ltda, AT 2026).
// Verifica homologación al R12, cálculos de R12, R14 (Razonabilidad CPT) y R13 (RAI), inicio, cierre y PDFs.
// Uso: php tools/test-ctrib-14a.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/ctrib-pdf.php';
require dirname(__DIR__) . '/api/lib/ctrib-inicio.php';
require dirname(__DIR__) . '/api/lib/ctrib-sugerencias.php';

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

$pdo = base_prueba();
empresas_schema($pdo);
crearEsquema($pdo);
ctrib_schema($pdo);
$pdo->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')
    ->execute(['fenix', 'SOCIEDAD FÉNIX LTDA.', json_encode(['rut' => '76123456-0']), '2026-11-01']);
$e = (int) $pdo->lastInsertId();

ctrib_empresa_config_guardar($pdo, $e, 'LTDA', '14A', 'prueba');
$cuenta = fn(string $codigo, string $nombre, int $debe, int $haber) => ['codigo' => $codigo, 'nombre' => $nombre, 'debitos' => $debe, 'creditos' => $haber];
$mapeo = ['1' => 'ACTIVO', '2' => 'PASIVO', '3' => 'CAPITAL', '4' => 'PERDIDA', '5' => 'GANANCIA'];
ctrib_mapeo_guardar($pdo, $e, $mapeo, 'prueba');

// Cuentas de resultado del ejercicio oficial 14a.xlsx (Sociedad Fénix Ltda)
ctrib_balance_guardar($pdo, $e, 2026, 'prueba', [
    $cuenta('101001', 'CAJA', 200000000, 0),
    $cuenta('201001', 'PROVEEDORES', 0, 10000000),
    $cuenta('301001', 'CAPITAL', 0, 106599500),
    $cuenta('401001', 'REMUNERACIONES', 23611617, 0),
    $cuenta('401002', 'IMPUESTO RENTA E IMPUESTO DIFERIDO', 18144000, 0),
    $cuenta('401003', 'OTROS GASTOS DEDUCIDOS', 5885224, 0),
    $cuenta('501001', 'INGRESOS DEL GIRO', 0, 116095312),
    $cuenta('501002', 'RENTAS FUENTE EXTRANJERA', 0, 13946029),
    $cuenta('501003', 'DIVIDENDOS PERCIBIDOS', 0, 1000000),
], 'fenix.csv', 'prueba');

// 1. Homologación 14 A + sugerencias traducidas al R12
$hom = ctrib_homologacion_con_sugerencias($pdo, $e, 2026);
check($hom['pendientes'] === 6, '6 cuentas de resultado pendientes');
$sugPorCuenta = array_column($hom['cuentasResultado'], 'sugerencia', 'cuenta');
check($sugPorCuenta['401001']['codigo'] === '1662' && $sugPorCuenta['401002']['codigo'] === '1670', 'sugerencias 14A para remuneraciones e imp renta');
check($sugPorCuenta['501001']['codigo'] === '1657', 'sugerencia 14A para ingresos del giro');

falla(fn() => ctrib_homologar($pdo, $e, 2026, '501001', '1662', 'prueba'), 'es de egresos', 'rechaza código de egresos en cuenta de ingreso');
foreach ([
    '501001' => '1657', '501002' => '1658', '501003' => '1660',
    '401001' => '1662', '401002' => '1670', '401003' => '1671',
] as $c => $cod) {
    ctrib_homologar($pdo, $e, 2026, (string) $c, $cod, 'prueba');
}

// 2. R12: reproducir exactamente Sociedad Fénix Ltda (1672 = 83.400.500, 1728 = 99.289.909, 1690 = 89.190.933)
foreach ([
    '1673' => 3932218, '1674' => 1284000, '1144' => 17606776, '1676' => 4529670,
    '1677' => 201181, '1678' => 3777295, '1176' => 2800000, '1682' => 777295,
    '1683' => 3000000, '1686' => 1000000, '1154' => 10098976,
] as $cod => $monto) {
    ctrib_r12_guardar_valor($pdo, $e, 2026, (string) $cod, $monto, 'prueba');
}
$r12 = ctrib_r12($pdo, $e, 2026);
$m12 = montos($r12);
check($m12['1672'] === 83400500, 'R12 resultado financiero Fénix (83.400.500)');
check($m12['1728'] === 99289909, 'R12 RLI antes incentivo Fénix (99.289.909)');
check($m12['1690'] === 89190933, 'R12 RLI afecta a IDPC Fénix (89.190.933)');
check($r12['idpcEstimado']['tasa'] === 27.0 && $r12['idpcEstimado']['monto'] === 24081552, 'IDPC estimado 27% sobre 89.190.933');
falla(fn() => ctrib_r14_finalizar($pdo, $e, 2026, 'prueba'), 'Finalice el Recuadro 12', 'R14 exige R12 finalizado');
ctrib_r12_finalizar($pdo, $e, 2026, 'prueba');

// 3. R14: reproducir CPT final de Fénix Ltda (645 = 139.854.589), incluyendo CM automática 3,4% (3.932.218)
foreach ([
    '1145' => 115653480, '1180' => 1000000, '1182' => 61284000, '1188' => 1130242,
] as $cod => $monto) {
    ctrib_r14_guardar_valor($pdo, $e, 2026, (string) $cod, $monto, 'prueba');
}
$r14 = ctrib_r14($pdo, $e, 2026);
$m14 = montos($r14);
check($m14['1177'] === 3932218, 'R14 CM automática 3,4% sobre CPT inicial (3.932.218)');
check($m14['645'] === 139854589 && $m14['646'] === 0, 'R14 CPT positivo final Fénix (139.854.589)');
falla(fn() => ctrib_r13_finalizar($pdo, $e, 2026, 'prueba'), 'Finalice el Recuadro 14', 'R13 exige R14 finalizado');
ctrib_r14_finalizar($pdo, $e, 2026, 'prueba');

// 4. R13: reproducir RAI de Fénix Ltda (1199 = 80.382.996 con CPT ajustado del ejercicio 139.653.408)
ctrib_r13_guardar_valor($pdo, $e, 2026, '1698', 139653408, 'prueba');
ctrib_r13_guardar_valor($pdo, $e, 2026, '1693', 180526, 'prueba');
ctrib_r13_guardar_valor($pdo, $e, 2026, '844', 120373886, 'prueba');
$r13 = ctrib_r13($pdo, $e, 2026);
$m13 = montos($r13);
check($m13['1718'] === 200937408, 'R13 subtotal Fénix (200.937.408)');
check($m13['1199'] === 80382996, 'R13 RAI final Fénix (80.382.996)');
ctrib_r13_finalizar($pdo, $e, 2026, 'prueba');

// 5. Inicio, cierre (R4, R6, R12, R14, R13) y PDFs
$inicio = ctrib_inicio($pdo, $e, 2026);
check($inicio['propio'] && $inicio['regimen'] === '14A' && $inicio['cierre'] !== null, 'inicio 14 A con cierre habilitado');
foreach (['R4', 'R6'] as $rec) {
    ($rec === 'R4' ? 'ctrib_r4_finalizar' : 'ctrib_r6_finalizar')($pdo, $e, 2026, 'prueba');
}
$cierre = ctrib_cierre_sellar($pdo, $e, 2026, 'prueba');
check($cierre['cerrado'] === true, 'cierre anual 14 A sellado');
foreach (['R12', 'R13', 'R14'] as $doc) {
    $html = ctrib_documento_html($pdo, $e, 2026, $doc);
    check(str_contains($html, 'SOCIEDAD FÉNIX LTDA') && !str_contains($html, '<span class="sello">BORRADOR</span>'), "PDF $doc finalizado");
}

echo "Cifrax Tributario 14 A (Sociedad Fénix Ltda: R12, R14, R13, homologación, cierre y PDF): verificado\n";
