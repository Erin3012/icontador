<?php
// Prueba de Etapa 9 — Empresario Individual (EI): une los recuadros de su negocio (14D3, 14A, 14D8, PRESUNTA)
// con sus líneas personales de IGC en un mismo F22, más participaciones externas y exclusión de Certificado 70.
// Uso: php tools/test-ctrib-ei.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/ctrib-pdf.php';

function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FALLA: $msg\n"); exit(1); } }
function montos(array $r): array { return array_column($r['lineas'], 'monto', 'codigo'); }
function falla(callable $fn, string $aguja, string $msg): void {
    try { $fn(); check(false, $msg); } catch (CtribError $e) { check(str_contains($e->getMessage(), $aguja), "$msg: " . $e->getMessage()); }
}

$pdo = base_prueba();
empresas_schema($pdo);
crearEsquema($pdo);
ctrib_schema($pdo);
sii_schema($pdo);

$mapeo = ['1' => 'ACTIVO', '2' => 'PASIVO', '3' => 'CAPITAL', '4' => 'PERDIDA', '5' => 'GANANCIA'];
$rutEi = '11111111-1';

// 1. EI en 14 D N°3: IDPC (cód. 20) + retiros propios afectos (104) + incremento (159) + crédito SAC (610) + PPM (36) en el mismo F22
$pdo->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')
    ->execute(['ei-14d3', 'COMERCIAL JUAN PEREZ E.I.', json_encode(['rut' => $rutEi]), '2026-11-27']);
$eD3 = (int) $pdo->lastInsertId();
ctrib_mapeo_guardar($pdo, $eD3, $mapeo, 'prueba');
ctrib_balance_guardar($pdo, $eD3, 2026, 'excel', [
    ['codigo' => '101001', 'nombre' => 'CAJA', 'debitos' => 40000000, 'creditos' => 0],
    ['codigo' => '501001', 'nombre' => 'VENTAS', 'debitos' => 0, 'creditos' => 40000000],
], 'ei.xlsx', 'prueba');
ctrib_homologar($pdo, $eD3, 2026, '501001', '1400', 'prueba');
ctrib_composicion_guardar($pdo, $eD3, 2026, 'EI', '14D3', [
    ['rut' => $rutEi, 'nombre' => 'JUAN PEREZ', 'participacion' => 100.0, 'monto' => 15000000],
], false, 'prueba');
ctrib_f22_guardar_valor($pdo, $eD3, 2026, '1904', 1000000, 'prueba');

$fD3 = ctrib_f22($pdo, $eD3, 2026);
$mD3 = montos($fD3);
$esperadoCred = (int) round(15000000 * round(12.5 / 87.5, 6), 0, PHP_ROUND_HALF_UP); // 2.142.855 (factorSac 6 dec. R20)
check($fD3['esEmpresarioIndividual'] && $fD3['negocioEI']['fuente'] === 'Negocio propio (R20 / Línea 1 a)', 'EI 14D3 detecta negocio propio');
check($mD3['18'] > 0 && $mD3['20'] > 0, 'EI 14D3 calcula IDPC empresarial');
check($mD3['104'] === 15000000 && $mD3['159'] === $esperadoCred && $mD3['610'] === $esperadoCred,
    'EI 14D3 traslada retiro propio (104=15M), incremento (159) y SAC (610) al mismo F22');
check($mD3['36'] === 1000000 && $mD3['305'] !== 0, 'EI 14D3 une IGC + IDPC - PPM en un solo resultado 305');

// EI no emite Certificado N° 70
$dj = ctrib_dj1948($pdo, $eD3, 2026);
check(!$dj['paso1']['listo'] && str_contains($dj['paso1']['mensaje'], 'excluido'), 'DJ 1948 marca EI excluido de Cert. 70');
falla(fn() => ctrib_certificado_emitir($pdo, $eD3, 2026, $rutEi, 'prueba'), 'Certificado N° 70', 'Bloquea emitir Cert. 70 en EI');

// 2. EI en 14 D N°8: R22 (1630, 1609, 1605) y PPM 1904 pasan directo al mismo F22 (1632, 1638, 610, 1645), sin duplicar aunque esté en ctrib_socios
$rutEiD8 = '12345678-5';
$pdo->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')
    ->execute(['ei-14d8', 'SERVICIOS EI TRANSPARENTE', json_encode(['rut' => $rutEiD8]), '2026-11-27']);
$eD8 = (int) $pdo->lastInsertId();
ctrib_mapeo_guardar($pdo, $eD8, $mapeo, 'prueba');
ctrib_balance_guardar($pdo, $eD8, 2026, 'excel', [
    ['codigo' => '101001', 'nombre' => 'CAJA', 'debitos' => 30000000, 'creditos' => 0],
    ['codigo' => '501001', 'nombre' => 'VENTAS', 'debitos' => 0, 'creditos' => 30000000],
], 'eid8.xlsx', 'prueba');
ctrib_homologar($pdo, $eD8, 2026, '501001', '1400', 'prueba');
ctrib_composicion_guardar($pdo, $eD8, 2026, 'EI', '14D8', [
    ['rut' => $rutEiD8, 'nombre' => 'MARIA LOPEZ', 'participacion' => 100.0, 'monto' => 0],
], false, 'prueba');
ctrib_r22_guardar_valor($pdo, $eD8, 2026, '1605', 100000, 'prueba');
ctrib_r22_guardar_valor($pdo, $eD8, 2026, '1609', 200000, 'prueba');
$fD8 = ctrib_f22_guardar_valor($pdo, $eD8, 2026, '1904', 500000, 'prueba');
$mD8 = montos($fD8);
check($mD8['1632'] === 30300000 && $mD8['1638'] === 200000 && $mD8['610'] === 100000 && $mD8['1645'] === 500000,
    'EI 14D8 traslada R22 y PPM 1904 propio a 1632/1638/610/1645 del mismo F22');
check($fD8['participaciones']['filas'] === [], 'EI 14D8 no duplica su propio negocio en participaciones externas');

// 3. EI en 14 A: IDPC 27% (1113) + retiro afecto R15 (104), SAC con restitución (610), incremento (159) y débito 35% (1035)
$rutEi14a = '33333333-3';
$pdo->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')
    ->execute(['ei-14a', 'INVERSIONES JUAN EI', json_encode(['rut' => $rutEi14a]), '2026-11-27']);
$e14a = (int) $pdo->lastInsertId();
ctrib_mapeo_guardar($pdo, $e14a, $mapeo, 'prueba');
ctrib_balance_guardar($pdo, $e14a, 2026, 'excel', [
    ['codigo' => '101001', 'nombre' => 'CAJA', 'debitos' => 20000000, 'creditos' => 0],
    ['codigo' => '501001', 'nombre' => 'VENTAS', 'debitos' => 0, 'creditos' => 20000000],
], 'ei14a.xlsx', 'prueba');
ctrib_composicion_guardar($pdo, $e14a, 2026, 'EI', '14A', [
    ['rut' => $rutEi14a, 'nombre' => 'PEDRO SOTO', 'participacion' => 100.0, 'monto' => 5000000],
], false, 'prueba');
ctrib_homologar($pdo, $e14a, 2026, '501001', '1657', 'prueba');
ctrib_r15_guardar_celda($pdo, $e14a, 2026, '6.1', 'RAI', -5000000, 'prueba');
ctrib_r15_guardar_celda($pdo, $e14a, 2026, '6.1', 'SAC_REST_CON_DEV', -1849315, 'prueba');
$f14a = ctrib_f22($pdo, $e14a, 2026);
$m14a = montos($f14a);
check($m14a['1113'] === 5400000 && $m14a['104'] === 5000000 && $m14a['610'] === 1849315 && $m14a['159'] === 1849315 && $m14a['1035'] === (int) round(1849315 * 0.35),
    'EI 14A traslada IDPC 27% + retiro R15 + SAC con restitución 35% en un mismo F22');

// PDF del EI muestra la sección Negocio propio y participaciones
$pdf = ctrib_documento_html($pdo, $e14a, 2026, 'F22');
check(str_contains($pdf, 'Negocio propio (EI)'), 'PDF F22 de EI muestra sección Negocio propio integrado');

echo "test-ctrib-ei OK\n";
