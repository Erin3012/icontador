<?php
// Prueba del régimen Renta Presunta (Art. 34 LIR): F22 Línea 57 y Línea 4 / DJ 1943 según Suplemento AT 2026 (pp. 27-31, 189-191).
// Uso: php tools/test-ctrib-presunta.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/ctrib-pdf.php';
require dirname(__DIR__) . '/api/lib/ctrib-inicio.php';

function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FALLA: $msg\n"); exit(1); } }
function montos(array $r): array { return array_column($r['lineas'], 'monto', 'codigo'); }

$pdo = base_prueba();
empresas_schema($pdo);
crearEsquema($pdo);
ctrib_schema($pdo);
sii_schema($pdo);

// Caso 1: EI agrícola del ejemplo oficial Suplemento F22 AT 2026 p. 31
// Avalúo $200.000.000 -> base 1924 = $20.000.000, IDPC 25% = $5.000.000, contribuciones 1925 = $2.000.000 -> IDPC 1926 = $3.000.000
// Línea 4: letra a) Propios (1921 = 20.000.000, 1920 = 3.000.000).
$pdo->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')
    ->execute(['ei-agr', 'AGRICOLA EL CAMPO E.I.', json_encode(['rut' => '11111111-1', 'giro' => 'CULTIVOS AGRICOLAS']), '2026-11-27']);
$e1 = (int) $pdo->lastInsertId();
ctrib_empresa_config_guardar($pdo, $e1, 'EI', 'PRESUNTA', 'prueba');

ctrib_l57_guardar_base($pdo, $e1, 2026, 'BAGR', 200000000, 'prueba');
$r1 = ctrib_l57_guardar_base($pdo, $e1, 2026, 'CAGR', 2000000, 'prueba');
$m1 = montos($r1);
check($m1['1924'] === 20000000 && $m1['1925'] === 2000000 && $m1['1926'] === 3000000, 'EI agrícola Línea 57 (1924=20M, 1925=2M, 1926=3M)');
check($m1['187'] === 20000000 && $m1['188'] === 2000000 && $m1['189'] === 3000000, 'EI agrícola totales 187/188/189');
check($r1['propietarios']['codigoRenta'] === '1921' && $r1['propietarios']['codigoCredito'] === '1920', 'EI usa códigos Línea 4 letra a) 1921/1920');
check(!$r1['propietarios']['requiereDj1943'] && !$r1['propietarios']['requiereCertificado58'], 'EI no obliga DJ 1943 ni Cert. 58');
check(count($r1['propietarios']['filas']) === 1 && $r1['propietarios']['filas'][0]['rentaPresunta'] === 20000000 && $r1['propietarios']['filas'][0]['creditoIdpc'] === 3000000,
    'EI auto-asigna 100% al titular en Línea 4');
check(str_contains($r1['notaValidacion'], 'Por validar'), 'incluye advertencia por validar con el contador');

// Caso 2: SP agrícola con contribuciones que exceden IDPC + Transporte + Minería (AT 2026 p. 31 + p. 190)
$pdo->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')
    ->execute(['sp-mix', 'SOCIEDAD AGROTRANS MINERA LTDA', json_encode(['rut' => '76222333-4', 'giro' => 'TRANSPORTE Y AGRICULTURA']), '2026-11-27']);
$e2 = (int) $pdo->lastInsertId();
ctrib_composicion_guardar($pdo, $e2, 2026, 'LTDA', 'PRESUNTA', [
    ['rut' => '12345678-5', 'nombre' => 'SOCIO UNO', 'participacion' => 30.0, 'monto' => 25000000],
    ['rut' => '11111111-1', 'nombre' => 'SOCIO DOS', 'participacion' => 70.0, 'monto' => 0],
], false, 'prueba');

// Agrícola: avalúo $300M -> base 30M -> IDPC $7.5M; contribuciones pagadas $8.5M -> tope 1925 = $7.5M -> IDPC 1926 = $0 + aviso excedente $1M
ctrib_l57_guardar_base($pdo, $e2, 2026, 'BAGR', 300000000, 'prueba');
$r2a = ctrib_l57_guardar_base($pdo, $e2, 2026, 'CAGR', 8500000, 'prueba');
$m2a = montos($r2a);
check($m2a['1924'] === 30000000 && $m2a['1925'] === 7500000 && $m2a['1926'] === 0, 'Contribuciones se topan al IDPC agrícola (7.5M)');
check(str_contains(implode(' ', $r2a['avisos']), '$1.000.000'), 'avisa excedente $1.000.000 no imputable ni devolvible');

// Transporte pasajeros $100M -> base 1927 = 10M (IDPC 2.5M); Carga $60M -> 1929 = 6M (IDPC 1.5M); Minería $50M al 6% -> 1931 = 3M (IDPC 0.75M)
ctrib_l57_guardar_base($pdo, $e2, 2026, 'BPAS', 100000000, 'prueba');
ctrib_l57_guardar_base($pdo, $e2, 2026, 'BCAR', 60000000, 'prueba');
ctrib_l57_guardar_base($pdo, $e2, 2026, 'BMIN', 50000000, 'prueba');
$r2 = ctrib_l57_guardar_base($pdo, $e2, 2026, 'PMIN', 6, 'prueba');
$m2 = montos($r2);
check($m2['1927'] === 10000000 && $m2['1928'] === 2500000, 'Pasajeros 1927=10M, 1928=2.5M');
check($m2['1929'] === 6000000 && $m2['1930'] === 1500000, 'Carga 1929=6M, 1930=1.5M');
check($m2['1931'] === 3000000 && $m2['1932'] === 750000, 'Minería 1931=3M (6% de 50M), 1932=750k');
check($m2['187'] === 49000000 && $m2['188'] === 7500000 && $m2['189'] === 4750000, 'Totales 187=49M, 188=7.5M, 189=4.75M');
check($r2['propietarios']['codigoRenta'] === '1923' && $r2['propietarios']['codigoCredito'] === '1922', 'LTDA usa códigos 1923/1922');
check($r2['propietarios']['requiereDj1943'] && $r2['propietarios']['requiereCertificado58'], 'LTDA requiere DJ 1943 y Cert. 58');

$porRut = array_column($r2['propietarios']['filas'], null, 'rut');
$prop1 = $porRut['12345678-5'];
$prop2 = $porRut['11111111-1'];
check($prop1['rentaPresunta'] === 14700000 && $prop2['rentaPresunta'] === 34300000, 'Reparto renta presunta 30%/70% (14.7M / 34.3M)');
check($prop1['creditoIdpc'] === 1425000 && $prop2['creditoIdpc'] === 3325000, 'Reparto crédito IDPC 30%/70% (1.425M / 3.325M)');
check($prop1['excesoRetiros'] === 10300000, 'Calcula exceso retiros DJ 1943 (25M - 14.7M = 10.3M)');

// Override manual directo y reversión a automático al limpiar (null)
$rOverride = ctrib_l57_guardar_valor($pdo, $e2, 2026, '1931', 4000000, 'prueba');
check(montos($rOverride)['1931'] === 4000000 && montos($rOverride)['1932'] === 1000000, 'Override manual 1931=4M -> IDPC 1M');
$rRevert = ctrib_l57_guardar_valor($pdo, $e2, 2026, '1931', null, 'prueba');
check(montos($rRevert)['1931'] === 3000000, 'Limpiar manual revierte a base calculada (3M)');

// Bloqueos: crédito 1925 manual > IDPC agrícola y SA prohibida en Renta Presunta
$rTop = ctrib_l57_guardar_valor($pdo, $e2, 2026, '1925', 9000000, 'prueba');
check($rTop['bloqueos'] !== [] && !$rTop['puedeFinalizar'], 'Bloquea si 1925 manual excede IDPC agrícola');
ctrib_l57_guardar_valor($pdo, $e2, 2026, '1925', null, 'prueba');

// Inicio, Finalizar, Desactualizado, Cierre, PDF y Exención 1 UTA EI
$inicio = ctrib_inicio($pdo, $e2, 2026);
check($inicio['propio'] && $inicio['regimen'] === 'PRESUNTA', 'Inicio PRESUNTA habilitado');
check(array_column($inicio['grupos'], 'slot') === ['gestion', 'f22', 'dj'], 'Inicio PRESUNTA tiene gestión, F22 y DJ');
ctrib_l57_finalizar($pdo, $e2, 2026, 'prueba');
$cierre = ctrib_cierre_sellar($pdo, $e2, 2026, 'prueba');
check($cierre['cerrado'] && $cierre['completo'], 'Cierre PRESUNTA sella con L57 finalizado');
$pdf = ctrib_documento_html($pdo, $e2, 2026, 'L57');
check(str_contains($pdf, 'SOCIEDAD AGROTRANS MINERA LTDA') && !str_contains($pdf, 'sello">BORRADOR<') && str_contains($pdf, '1923') && str_contains($pdf, 'Por validar'),
    'PDF L57 finalizado contiene Línea 57, Línea 4 y nota de validación');

// Desactualizado al reabrir y cambiar base
ctrib_cierre_reabrir($pdo, $e2, 2026, 'prueba', 'ajuste');
ctrib_l57_reabrir($pdo, $e2, 2026, 'prueba', 'ajuste base');
ctrib_l57_guardar_base($pdo, $e2, 2026, 'BCAR', 80000000, 'prueba');
check(montos(ctrib_l57($pdo, $e2, 2026))['1929'] === 8000000, 'Reapertura permite modificar base carga');

// SA prohibida en Renta Presunta
$pdo->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')
    ->execute(['sa-rp', 'SOCIEDAD ANONIMA S.A.', json_encode(['rut' => '99888777-6']), '2026-11-27']);
$eSa = (int) $pdo->lastInsertId();
ctrib_empresa_config_guardar($pdo, $eSa, 'SA', 'PRESUNTA', 'prueba');
$rSa = ctrib_l57($pdo, $eSa, 2026);
check($rSa['bloqueos'] !== [] && !$rSa['puedeFinalizar'], 'SA bloqueada en Renta Presunta');

// EI bajo 1 UTA ($834.504): genera aviso Art. 40 N° 6
$pdo->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')
    ->execute(['ei-uta', 'CHICO E.I.', json_encode(['rut' => '22333444-5']), '2026-11-27']);
$eUta = (int) $pdo->lastInsertId();
ctrib_empresa_config_guardar($pdo, $eUta, 'EI', 'PRESUNTA', 'prueba');
$rUta = ctrib_l57_guardar_base($pdo, $eUta, 2026, 'BAGR', 5000000, 'prueba'); // 10% = $500.000 <= $834.504
check(str_contains(implode(' ', $rUta['avisos']), '1 UTA'), 'EI con base <= 1 UTA recibe aviso exención art. 40 N° 6');

echo "test-ctrib-presunta OK\n";
