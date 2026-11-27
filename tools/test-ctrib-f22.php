<?php
// Prueba del Anverso del Formulario 22 AT 2026 (Etapa 7): tabla IGC (Suplemento p. 102 y 14d8.xlsx),
// F22 Socio Estrada (14d8.xlsx), 14D3, 14A + IU 40%, 14G, 14D8, PRESUNTA EI, comparación SII y PDF.
// Uso: php tools/test-ctrib-f22.php
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

// 1. Tabla IGC AT 2026 (Suplemento p. 102 y 14d8.xlsx)
check(ctrib_igc_calcular(0, 2025)['impuesto'] === 0, 'IGC base 0 es 0');
check(ctrib_igc_calcular(11265804, 2025)['impuesto'] === 0, 'IGC tramo 1 exento');
check(ctrib_igc_calcular(25000000, 2025)['impuesto'] === 549368, 'IGC ejemplo oficial Suplemento p. 102 ($25M -> $549.368)');
check(ctrib_igc_calcular(34227329, 2025)['impuesto'] === 1286149, 'IGC Socio Estrada 14d8.xlsx ($34.227.329 -> $1.286.149)');
check(ctrib_igc_calcular(300000000, 2025)['tramo'] === 8 && ctrib_igc_calcular(300000000, 2025)['impuesto'] === 87604555, 'IGC tramo 8 (40% - $32.395.445,28)');

// 2. Caso real oficial: F22 Socio Estrada (14d8.xlsx)
$pdo->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')
    ->execute(['estrada', 'SOCIO ESTRADA', json_encode(['rut' => '12345678-5']), '2026-11-27']);
$eSocio = (int) $pdo->lastInsertId();
ctrib_empresa_config_guardar($pdo, $eSocio, 'PERSONA_NATURAL', 'PERSONA', 'prueba');
ctrib_f22_guardar_valor($pdo, $eSocio, 2026, '1632', 34227329, 'prueba');
ctrib_f22_guardar_valor($pdo, $eSocio, 2026, '1035', 16182, 'prueba');
ctrib_f22_guardar_valor($pdo, $eSocio, 2026, '1638', 241680, 'prueba');
ctrib_f22_guardar_valor($pdo, $eSocio, 2026, '610', 77675, 'prueba');
$fSocio = ctrib_f22_guardar_valor($pdo, $eSocio, 2026, '1645', 135406, 'prueba');
$ms = montos($fSocio);
check($ms['158'] === 34227329 && $ms['170'] === 34227329 && $ms['157'] === 1286149, 'Socio Estrada base 170 y tabla 157');
check($ms['304'] === 982976 && $ms['31'] === 982976, 'Socio Estrada IGC determinado 304/31 = 982.976');
check($ms['305'] === 847570 && $ms['90'] === 847570 && $ms['91'] === 847570 && $ms['87'] === 0, 'Socio Estrada resultado 305/90/91 = 847.570');

// Caso persona con saldo a favor y remanente devolución (Línea 52 negativa -> Línea 97 código 116)
ctrib_f22_guardar_valor($pdo, $eSocio, 2026, '1632', 10000000, 'prueba'); // tramo exento -> 157 = 0
ctrib_f22_guardar_valor($pdo, $eSocio, 2026, '1035', 0, 'prueba');
$fDev = ctrib_f22($pdo, $eSocio, 2026);
$md = montos($fDev);
check($md['304'] === -(241680 + 77675) && $md['31'] === 0 && $md['116'] === 319355, 'Remanente negativo con créditos devolvibles pasa a cód. 116');
check($md['305'] === -(319355 + 135406) && $md['85'] === 454761 && $md['87'] === 454761 && $md['91'] === 0, 'Devolución total en cód. 87 = 454.761');

// 3. Empresa 14 D N°3: IDPC desde R17 (12,5%), IU 40% art. 21, PPM y devolución / puesto a disposición socios
$pdo->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')
    ->execute(['pyme-f22', 'PYME F22 SPA', json_encode(['rut' => '76111222-3']), '2026-11-27']);
$ePyme = (int) $pdo->lastInsertId();
ctrib_empresa_config_guardar($pdo, $ePyme, 'SPA', '14D3', 'prueba');
ctrib_mapeo_guardar($pdo, $ePyme, ['1' => 'ACTIVO', '2' => 'PASIVO', '3' => 'CAPITAL', '4' => 'PERDIDA', '5' => 'GANANCIA'], 'prueba');
ctrib_balance_guardar($pdo, $ePyme, 2026, 'excel', [
    ['codigo' => '101001', 'nombre' => 'CAJA', 'debitos' => 20000000, 'creditos' => 0],
    ['codigo' => '301001', 'nombre' => 'CAPITAL', 'debitos' => 0, 'creditos' => 10000000],
    ['codigo' => '501001', 'nombre' => 'VENTAS', 'debitos' => 0, 'creditos' => 10000000],
], 'pyme.xlsx', 'prueba');
ctrib_homologar($pdo, $ePyme, 2026, '501001', '1400', 'prueba');
ctrib_f22_guardar_valor($pdo, $ePyme, 2026, '1904', 2000000, 'prueba'); // PPM > IDPC ($625.000 con 50% incentivo 14 E automático)
$fPyme = ctrib_f22_guardar_valor($pdo, $ePyme, 2026, '86', 250000, 'prueba');
$mp = montos($fPyme);
check($mp['18'] === 5000000 && $mp['20'] === 625000, '14D3 Línea 54 base 18=5M (tras 14 E) e IDPC 20=625k (12,5%)');
check($mp['36'] === 2000000 && $mp['305'] === -1375000 && $mp['85'] === 1375000 && $mp['86'] === 250000 && $mp['87'] === 1125000, '14D3 PPM 36, saldo a favor 85, socios 86 y devolución 87=1.125M');

// Comparación con F22 consultado en SII
$pdo->prepare("INSERT INTO sii_consultas (empresa_id, tipo, anio, estado, datos_json, consultado_en) VALUES (?, 'f22', 2026, 'Aceptada', ?, '2026-11-27 10:00:00')")
    ->execute([$ePyme, json_encode([
        'folio' => '22001122',
        'codigos' => [['codigo' => '18', 'valor' => 5000000], ['codigo' => '20', 'valor' => 625000], ['codigo' => '36', 'valor' => 1900000]],
    ])]);
$fSii = ctrib_f22($pdo, $ePyme, 2026);
$l36 = array_values(array_filter($fSii['lineas'], fn($l) => $l['codigo'] === '36'))[0];
check($fSii['sii']['disponible'] && $l36['valorSii'] === 1900000 && $l36['diferenciaSii'] === 100000, 'Comparación F22 calculado vs SII detecta diferencia en cód. 36');

// Finalizar, desactualizado al cambiar PPM, reabrir y PDF
ctrib_f22_finalizar($pdo, $ePyme, 2026, 'prueba');
$pdf = ctrib_documento_html($pdo, $ePyme, 2026, 'F22');
check(str_contains($pdf, 'PYME F22 SPA') && !str_contains($pdf, 'sello">BORRADOR<') && str_contains($pdf, '625.000'), 'PDF F22 finalizado');
ctrib_f22_reabrir($pdo, $ePyme, 2026, 'prueba', 'ajuste');

// 4. Empresa 14 A (27% + IU 40%) y 14 G (25%)
$pdo->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')
    ->execute(['reg14a-f22', 'SEMI INTEGRADA LTDA', json_encode(['rut' => '76333444-5']), '2026-11-27']);
$e14a = (int) $pdo->lastInsertId();
ctrib_empresa_config_guardar($pdo, $e14a, 'LTDA', '14A', 'prueba');
ctrib_mapeo_guardar($pdo, $e14a, ['1' => 'ACTIVO', '3' => 'CAPITAL', '5' => 'GANANCIA'], 'prueba');
ctrib_balance_guardar($pdo, $e14a, 2026, 'excel', [
    ['codigo' => '101001', 'nombre' => 'CAJA', 'debitos' => 10000000, 'creditos' => 0],
    ['codigo' => '501001', 'nombre' => 'VENTAS', 'debitos' => 0, 'creditos' => 10000000],
], '14a.xlsx', 'prueba');
ctrib_homologar($pdo, $e14a, 2026, '501001', '1657', 'prueba');
ctrib_f22_guardar_valor($pdo, $e14a, 2026, '113', 1000000, 'prueba'); // IU 40% = 400.000
$f14a = ctrib_f22_guardar_valor($pdo, $e14a, 2026, '39', 10000, 'prueba');
$m14a = montos($f14a);
check($m14a['1109'] === 10000000 && $m14a['1113'] === 2700000 && $m14a['114'] === 400000 && $m14a['305'] === 3100000 && $m14a['91'] === 3110000,
    '14A Línea 55 (27%) + Línea 65 IU 40% + reajuste 39 -> total a pagar 91 = 3.110.000');

// 5. EI en Renta Presunta: integra Línea 57 (IDPC) + Línea 4 (108) + Crédito IDPC (610) en un mismo F22
$pdo->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')
    ->execute(['ei-f22', 'AGRICOLA EI', json_encode(['rut' => '11111111-1']), '2026-11-27']);
$eEi = (int) $pdo->lastInsertId();
ctrib_empresa_config_guardar($pdo, $eEi, 'EI', 'PRESUNTA', 'prueba');
ctrib_l57_guardar_base($pdo, $eEi, 2026, 'BAGR', 200000000, 'prueba'); // base 20M, IDPC 5M
ctrib_l57_guardar_base($pdo, $eEi, 2026, 'CAGR', 2000000, 'prueba');   // contribuciones 2M -> IDPC 189 = 3M
$fEi = ctrib_f22($pdo, $eEi, 2026);
$mei = montos($fEi);
check($mei['187'] === 20000000 && $mei['189'] === 3000000, 'EI Renta Presunta traslada Línea 57 (187=20M, 189=3M)');
check($mei['108'] === 20000000 && $mei['170'] === 20000000 && $mei['610'] === 3000000, 'EI Renta Presunta traslada 108=20M y crédito 610=3M al IGC');
check($mei['157'] === 349368 && $mei['304'] === (349368 - 3000000) && $mei['116'] === (3000000 - 349368), 'EI IGC $349.368 menos crédito $3M genera remanente devolución 116');
check($mei['305'] === (3000000 - (3000000 - 349368)) && $mei['90'] === 349368, 'Neto EI = IDPC $3M - devolución IGC $2.650.632 = $349.368 a pagar');

// 6. 14 D N°8: sin IDPC ni código 36/86
$pdo->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')
    ->execute(['d8-f22', 'TRANSPARENTE SPA', json_encode(['rut' => '76555666-7']), '2026-11-27']);
$eD8 = (int) $pdo->lastInsertId();
ctrib_empresa_config_guardar($pdo, $eD8, 'SPA', '14D8', 'prueba');
$fD8 = ctrib_f22($pdo, $eD8, 2026);
check(!array_key_exists('36', montos($fD8)) && !array_key_exists('86', montos($fD8)) && montos($fD8)['305'] === 0 && $fD8['avisos'] !== [],
    '14D8 no declara PPM en cód. 36 ni 86 y muestra aviso DJ 1947');

echo "test-ctrib-f22 OK\n";
