<?php
// Prueba de Etapa 8 — Socios y accionistas: consolidación automática por RUT desde
// 14 D N°8 (DJ 1947 / F22 Socio Estrada de 14d8.xlsx), 14 D N°3 (DJ 1948 / Cert. 70),
// 14 A (DJ 1948 / SAC con restitución 35%) y Renta Presunta (DJ 1943 / L57).
// Uso: php tools/test-ctrib-socios.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/ctrib-pdf.php';

function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FALLA: $msg\n"); exit(1); } }
function montos(array $r): array { return array_column($r['lineas'], 'monto', 'codigo'); }

$pdo = base_prueba();
empresas_schema($pdo);
crearEsquema($pdo);
ctrib_schema($pdo);
sii_schema($pdo);

$mapeo = ['1' => 'ACTIVO', '2' => 'PASIVO', '3' => 'CAPITAL', '4' => 'PERDIDA', '5' => 'GANANCIA'];
$rutEstrada = '11111111-1';
$rutEscudero = '12345678-5';

// 1. Persona natural Socio Estrada
$pdo->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')
    ->execute(['estrada-p', 'SOCIO ESTRADA', json_encode(['rut' => $rutEstrada]), '2026-11-27']);
$eEstrada = (int) $pdo->lastInsertId();
ctrib_empresa_config_guardar($pdo, $eEstrada, 'PERSONA_NATURAL', 'PERSONA', 'prueba');

// 2. Empresa 14 D N°8 replicando cifras exactas de 14d8.xlsx (F1947 -> F22 Socio Estrada)
// Base 1630 = 68.454.657, Crédito IDPC 1605 = 155.349, Crédito 33 bis 1609 = 483.360, PPM 1904 = 270.811
$pdo->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')
    ->execute(['nsd-d8', 'SOCIEDAD NSD & GET LTDA', json_encode(['rut' => '76100200-3']), '2026-11-27']);
$eD8 = (int) $pdo->lastInsertId();
ctrib_mapeo_guardar($pdo, $eD8, $mapeo, 'prueba');
ctrib_balance_guardar($pdo, $eD8, 2026, 'excel', [
    ['codigo' => '101001', 'nombre' => 'CAJA', 'debitos' => 67815948, 'creditos' => 0],
    ['codigo' => '501001', 'nombre' => 'VENTAS', 'debitos' => 0, 'creditos' => 67815948],
], '14d8.xlsx', 'prueba');
ctrib_homologar($pdo, $eD8, 2026, '501001', '1400', 'prueba');
ctrib_composicion_guardar($pdo, $eD8, 2026, 'LTDA', '14D8', [
    ['rut' => $rutEstrada, 'nombre' => 'SOCIO ESTRADA', 'participacion' => 50.0, 'monto' => 0],
    ['rut' => $rutEscudero, 'nombre' => 'SOCIO ESCUDERO', 'participacion' => 50.0, 'monto' => 0],
], false, 'prueba');
ctrib_r22_guardar_valor($pdo, $eD8, 2026, '1605', 155349, 'prueba'); // se suma a ingresos (68.299.308 + 155.349 = 68.454.657)
ctrib_r22_guardar_valor($pdo, $eD8, 2026, '1609', 483360, 'prueba');
ctrib_r22_finalizar($pdo, $eD8, 2026, 'prueba');
ctrib_f22_guardar_valor($pdo, $eD8, 2026, '1904', 270811, 'prueba'); // PPM puestos a disposición

// Verificar F22 Socio Estrada alimentado automáticamente desde 14 D N°8
$f1 = ctrib_f22($pdo, $eEstrada, 2026);
$m1 = montos($f1);
check(count($f1['participaciones']['filas']) === 1 && $f1['participaciones']['filas'][0]['fuente'] === 'DJ 1947 / Cert. 69',
    'Detecta participación 14D8 por RUT');
check($m1['1632'] === 34227329 && $m1['1638'] === 241680 && $m1['610'] === 77675 && $m1['1645'] === 135406,
    'Socio Estrada recibe automáticamente 1632=34.227.329, 1638=241.680, 610=77.675 y 1645=135.406 desde 14D8');
// Agregando el débito de restitución manual (16.182) del crédito sujeto a restitución de 14d8.xlsx, llega al peso exacto 847.570
$f1Rest = ctrib_f22_guardar_valor($pdo, $eEstrada, 2026, '1035', 16182, 'prueba');
$m1r = montos($f1Rest);
check($m1r['157'] === 1286149 && $m1r['304'] === 982976 && $m1r['305'] === 847570 && $m1r['90'] === 847570,
    'F22 Socio Estrada cuadra al peso con 14d8.xlsx ($847.570)');
ctrib_f22_guardar_valor($pdo, $eEstrada, 2026, '1035', null, 'prueba');

// 3. Empresa 14 D N°3 (DJ 1948 / Cert. 70): retiro 2.000.000 y SAC sin restitución
$pdo->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')
    ->execute(['pyme-d3', 'COMERCIAL PYME LTDA', json_encode(['rut' => '76200300-4']), '2026-11-27']);
$eD3 = (int) $pdo->lastInsertId();
ctrib_mapeo_guardar($pdo, $eD3, $mapeo, 'prueba');
ctrib_balance_guardar($pdo, $eD3, 2026, 'excel', [
    ['codigo' => '101001', 'nombre' => 'CAJA', 'debitos' => 20000000, 'creditos' => 0],
    ['codigo' => '501001', 'nombre' => 'VENTAS', 'debitos' => 0, 'creditos' => 20000000],
], 'pyme.xlsx', 'prueba');
ctrib_homologar($pdo, $eD3, 2026, '501001', '1400', 'prueba');
ctrib_composicion_guardar($pdo, $eD3, 2026, 'LTDA', '14D3', [
    ['rut' => $rutEstrada, 'nombre' => 'SOCIO ESTRADA', 'participacion' => 100.0, 'monto' => 2000000],
], false, 'prueba');

// 4. Empresa 14 A (SpA -> dividendo 105 + crédito SAC con restitución -> 159, 610 y 35% en 1035)
$pdo->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')
    ->execute(['semi-14a', 'INVERSIONES SEMI SPA', json_encode(['rut' => '76300400-5']), '2026-11-27']);
$e14a = (int) $pdo->lastInsertId();
ctrib_mapeo_guardar($pdo, $e14a, $mapeo, 'prueba');
ctrib_balance_guardar($pdo, $e14a, 2026, 'excel', [
    ['codigo' => '101001', 'nombre' => 'CAJA', 'debitos' => 10000000, 'creditos' => 0],
    ['codigo' => '501001', 'nombre' => 'VENTAS', 'debitos' => 0, 'creditos' => 10000000],
], '14a.xlsx', 'prueba');
ctrib_composicion_guardar($pdo, $e14a, 2026, 'SPA', '14A', [
    ['rut' => $rutEstrada, 'nombre' => 'SOCIO ESTRADA', 'participacion' => 100.0, 'monto' => 1000000],
], false, 'prueba');
ctrib_homologar($pdo, $e14a, 2026, '501001', '1657', 'prueba');
// Fijamos en R15 una imputación explícita de dividendo 1.000.000 con crédito SAC con restitución con devolución 369.863
ctrib_r15_guardar_celda($pdo, $e14a, 2026, '6.1', 'RAI', -1000000, 'prueba');
ctrib_r15_guardar_celda($pdo, $e14a, 2026, '6.1', 'SAC_REST_CON_DEV', -369863, 'prueba');

// 5. Empresa Renta Presunta (LTDA -> 108 y 610 sin incremento 159)
$pdo->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')
    ->execute(['rp-ltda', 'TRANSPORTE PRESUNTO LTDA', json_encode(['rut' => '76400500-6']), '2026-11-27']);
$eRp = (int) $pdo->lastInsertId();
ctrib_composicion_guardar($pdo, $eRp, 2026, 'LTDA', 'PRESUNTA', [
    ['rut' => $rutEstrada, 'nombre' => 'SOCIO ESTRADA', 'participacion' => 100.0, 'monto' => 0],
], false, 'prueba');
ctrib_l57_guardar_base($pdo, $eRp, 2026, 'BCAR', 40000000, 'prueba'); // 10% = 4.000.000 -> IDPC 25% = 1.000.000

// Consolidación total en F22 de Socio Estrada (4 empresas a la vez)
$fTotal = ctrib_f22($pdo, $eEstrada, 2026);
$mt = montos($fTotal);
check(count($fTotal['participaciones']['filas']) === 4, 'Consolida las 4 empresas (14D8, 14D3, 14A, PRESUNTA) por RUT');
check($mt['104'] === 2000000 && $mt['105'] === 1000000 && $mt['108'] === 4000000 && $mt['1632'] === 34227329,
    'Consolida 104 (14D3), 105 (14A SpA), 108 (Presunta) y 1632 (14D8)');
$cred14d3Esperado = (int) round(2000000 * round(12.5 / 87.5, 6), 0, PHP_ROUND_HALF_UP); // 285.714
check($mt['1035'] === (int) round(369863 * 0.35), 'Calcula débito restitución 35% sobre SAC con restitución de 14 A');
check($mt['159'] === ($cred14d3Esperado + 369863) && $mt['610'] === ($cred14d3Esperado + 77675 + 369863 + 1000000),
    'Incremento 159 y crédito 610 consolidan exactamente 14D3 + 14A + 14D8 + PRESUNTA');

// Override manual y reversión a automático al limpiar (null)
$fMan = ctrib_f22_guardar_valor($pdo, $eEstrada, 2026, '104', 5000000, 'prueba');
check(montos($fMan)['104'] === 5000000, 'Manual sobrescribe consolidado en 104');
$fRev = ctrib_f22_guardar_valor($pdo, $eEstrada, 2026, '104', null, 'prueba');
check(montos($fRev)['104'] === 2000000, 'Limpiar manual revierte a consolidado 14D3 (2M)');

// Aislamiento multi-usuario: si solo se autoriza $eD8, no incluye 14D3/14A/PRESUNTA
$fRestringido = ctrib_f22($pdo, $eEstrada, 2026, [$eEstrada, $eD8]);
check(count($fRestringido['participaciones']['filas']) === 1 && montos($fRestringido)['104'] === 0 && montos($fRestringido)['1632'] === 34227329,
    'Aislamiento por empresas permitidas respeta permisos del usuario');

// PDF incluye cuadro de participaciones
$pdf = ctrib_documento_html($pdo, $eEstrada, 2026, 'F22');
check(str_contains($pdf, 'SOCIEDAD NSD &amp; GET LTDA') && str_contains($pdf, 'INVERSIONES SEMI SPA'),
    'PDF F22 muestra detalle de empresas consolidadas por RUT');

echo "test-ctrib-socios OK\n";
