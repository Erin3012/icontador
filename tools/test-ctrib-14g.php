<?php
// Prueba del régimen Art. 14 letra G (Fundaciones / entidades no sujetas al Art. 14) con el balance real de
// Fundación Enlazados (balance fundacion.pdf, año comercial 2024 / AT 2025) y un caso con base imponible al 25%.
// Uso: php tools/test-ctrib-14g.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/ctrib-pdf.php';
require dirname(__DIR__) . '/api/lib/ctrib-inicio.php';
require dirname(__DIR__) . '/api/lib/ctrib-sugerencias.php';

function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FALLA: $msg\n"); exit(1); } }
function montos(array $r): array { return array_column($r['lineas'], 'monto', 'codigo'); }

$pdo = base_prueba();
empresas_schema($pdo);
crearEsquema($pdo);
ctrib_schema($pdo);
sii_schema($pdo);

$pdo->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')
    ->execute(['fundacion', 'FUNDACIÓN ENLAZADOS', json_encode(['rut' => '65237643-6', 'giro' => 'ESPACIO PEDAGOGICO TERAPEUTICO']), '2026-11-27']);
$e = (int) $pdo->lastInsertId();

ctrib_empresa_config_guardar($pdo, $e, 'OTRO', '14G', 'prueba');
$mapeo = ['1' => 'ACTIVO', '2' => 'PASIVO', '3' => 'CAPITAL', '4' => 'PERDIDA', '5' => 'GANANCIA'];
ctrib_mapeo_guardar($pdo, $e, $mapeo, 'prueba');

$cuenta = fn(string $codigo, string $nombre, int $debe, int $haber) => ['codigo' => $codigo, 'nombre' => $nombre, 'debitos' => $debe, 'creditos' => $haber];

// Balance General de 8 Columnas 01-01-2024 a 31-12-2024 (AT 2025) de Fundación Enlazados
$b = ctrib_balance_guardar($pdo, $e, 2025, 'excel', [
    $cuenta('101001', 'CAJA', 3000000, 2672974),
    $cuenta('101091', 'IVA CREDITO FISCAL', 9575, 9575),
    $cuenta('202040', 'PROVEEDORES', 59974, 59974),
    $cuenta('202050', 'RETENCION 2DA. CATEGORIA', 359290, 359290),
    $cuenta('202085', 'HONORARIOS POR PAGAR', 2253710, 2253710),
    $cuenta('301001', 'CAPITAL', 0, 3000000),
    $cuenta('405002', 'HONORARIOS PROFESIONALES', 2613000, 0),
    $cuenta('405020', 'GASTOS GENERALES', 50399, 0),
    $cuenta('441001', 'IMPUESTOS NO RECUPERABLES', 9575, 0),
], 'balance_fundacion.pdf', 'prueba');

check($b['totales']['debitos'] === 8355523 && $b['totales']['activo'] === 327026 && $b['totales']['pasivo'] === 3000000 && $b['totales']['perdida'] === 2672974,
    'totales balance Fundación Enlazados');

// 1. Homologación 14 G usa catálogo R12 y sugerencias 14A/14G
$hom = ctrib_homologacion_con_sugerencias($pdo, $e, 2025);
check($hom['pendientes'] === 3, '3 cuentas de resultado pendientes');
ctrib_homologar($pdo, $e, 2025, '405002', '1671', 'prueba');
ctrib_homologar($pdo, $e, 2025, '405020', '1671', 'prueba');
ctrib_homologar($pdo, $e, 2025, '441001', '1671', 'prueba');

// 2. R12: pérdida financiera y tributaria (-2.672.974), tasa IDPC 14 G = 25% (monto 0 por pérdida)
$r12 = ctrib_r12($pdo, $e, 2025);
$m12 = montos($r12);
check($m12['1672'] === -2672974 && $m12['1728'] === -2672974 && $m12['1690'] === -2672974, 'R12 pérdida Fundación Enlazados (-2.672.974)');
check($r12['idpcEstimado']['regimen'] === '14G' && $r12['idpcEstimado']['tasa'] === 25.0 && $r12['idpcEstimado']['monto'] === 0, 'IDPC 14 G tasa 25% y monto 0 en pérdida');
ctrib_r12_finalizar($pdo, $e, 2025, 'prueba');

// 3. R14: aporte capital $3.000.000 menos pérdida tributaria $2.672.974 = CPT final $327.026 (= Caja)
ctrib_r14_guardar_valor($pdo, $e, 2025, '893', 3000000, 'prueba');
$r14 = ctrib_r14($pdo, $e, 2025);
$m14 = montos($r14);
check($m14['1695'] === 2672974 && $m14['645'] === 327026 && $m14['646'] === 0, 'R14 CPT final Fundación Enlazados (327.026 = Caja)');
ctrib_r14_finalizar($pdo, $e, 2025, 'prueba');

// 4. Inicio, cierre (R4, R6, R12, R14 sin R13/R15/R16) y PDFs
$inicio = ctrib_inicio($pdo, $e, 2025);
check($inicio['propio'] && $inicio['regimen'] === '14G', 'inicio 14 G habilitado');
check(array_column($inicio['grupos'], 'slot') === ['gestion', 'f22'], '14 G solo tiene gestión y F22 (sin DJ de propietarios)');
ctrib_r4_finalizar($pdo, $e, 2025, 'prueba');
ctrib_r6_finalizar($pdo, $e, 2025, 'prueba');
$cierre = ctrib_cierre_sellar($pdo, $e, 2025, 'prueba');
check($cierre['cerrado'] === true && array_column($cierre['recuadros'], 'codigo') === ['R4', 'R6', 'R12', 'R14'], 'cierre 14 G sellado con R4, R6, R12, R14');
foreach (['BALANCE', 'HOMOLOGACION', 'R12', 'R14'] as $doc) {
    $html = ctrib_documento_html($pdo, $e, 2025, $doc);
    check(str_contains($html, 'FUNDACIÓN ENLAZADOS') && !str_contains($html, '<span class="sello">BORRADOR</span>'), "PDF $doc finalizado");
}

// 5. Entidad 14 G con base imponible positiva: aplica tasa 25% (Suplemento F22 p. 187)
$pdo->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')
    ->execute(['corp', 'CORPORACIÓN EJEMPLO', json_encode(['rut' => '65000001-9']), '2026-11-27']);
$e2 = (int) $pdo->lastInsertId();
ctrib_empresa_config_guardar($pdo, $e2, 'OTRO', '14G', 'prueba');
ctrib_mapeo_guardar($pdo, $e2, $mapeo, 'prueba');
ctrib_balance_guardar($pdo, $e2, 2026, 'prueba', [
    $cuenta('101001', 'CAJA', 1000000, 0),
    $cuenta('501001', 'INGRESOS', 0, 1000000),
], 'corp.csv', 'prueba');
ctrib_homologar($pdo, $e2, 2026, '501001', '1657', 'prueba');
$r12pos = ctrib_r12($pdo, $e2, 2026);
check($r12pos['idpcEstimado']['tasa'] === 25.0 && $r12pos['idpcEstimado']['monto'] === 250000, '14 G con base imponible positiva calcula 25% IDPC');

echo "Cifrax Tributario 14 G (Fundación Enlazados: balance, homologación R12, R12, R14, cierre 14G y PDFs): verificado\n";
