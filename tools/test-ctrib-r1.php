<?php
// Prueba de Etapa 10 — Honorarios (Recuadro N° 1 AT 2026): lectura de boletas emitidas con reajuste IPC mensual,
// gastos presuntos 30% (tope 15 UTA = $12.517.560) vs efectivos, tope APV 600 UF, directores S.A.,
// traslado automático a F22 (cód. 110 y 198), tope cotizaciones cód. 900 (Suplemento p. 308) y PDF.
// Uso: php tools/test-ctrib-r1.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/honorarios.php';
require dirname(__DIR__) . '/api/lib/ctrib-pdf.php';

function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FALLA: $msg\n"); exit(1); } }
function montos(array $r): array { return array_column($r['lineas'], 'monto', 'codigo'); }

$pdo = base_prueba();
empresas_schema($pdo);
crearEsquema($pdo);
ctrib_schema($pdo);
hon_schema($pdo);
sii_schema($pdo);

$pdo->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')
    ->execute(['hon-p', 'PROFESIONAL PRUEBA', json_encode(['rut' => '11111111-1']), '2026-11-27']);
$e = (int) $pdo->lastInsertId();
ctrib_empresa_config_guardar($pdo, $e, 'PERSONA_NATURAL', 'PERSONA', 'prueba');

// Boletas emitidas en enero (factor 1.036) y diciembre (factor 1.000), más una anulada (no suma) y una sin retención
hon_guardar($pdo, [
    'kind' => 'emitidas',
    'period' => '2025-01',
    'rutEmpresa' => '11111111-1',
    'boletas' => [
        ['numero' => '1', 'fecha' => '2025-01-10', 'estado' => 'VIGENTE', 'rut' => '76111111-1', 'nombre' => 'CLIENTE A', 'bruto' => 10000000, 'retenido' => 1450000, 'pagado' => 8550000],
        ['numero' => '2', 'fecha' => '2025-01-15', 'estado' => 'ANULADA', 'rut' => '76111111-1', 'nombre' => 'NULA', 'bruto' => 5000000, 'retenido' => 725000, 'pagado' => 4275000],
        ['numero' => '3', 'fecha' => '2025-01-20', 'estado' => 'VIGENTE', 'rut' => '22222222-2', 'nombre' => 'PARTICULAR', 'bruto' => 2000000, 'retenido' => 0, 'pagado' => 2000000],
    ],
], $e);
hon_guardar($pdo, [
    'kind' => 'emitidas',
    'period' => '2025-12',
    'rutEmpresa' => '11111111-1',
    'boletas' => [
        ['numero' => '4', 'fecha' => '2025-12-05', 'estado' => 'VIGENTE', 'rut' => '76222222-2', 'nombre' => 'CLIENTE B', 'bruto' => 50000000, 'retenido' => 7250000, 'pagado' => 42750000],
    ],
], $e);

$r1 = ctrib_r1($pdo, $e, 2026);
$m1 = montos($r1);
// Enero con ret: 10M * 1.036 = 10.360.000, ret 1.45M * 1.036 = 1.502.200; Dic: 50M + ret 7.25M -> 461=60.360.000, 492=8.752.200
// Enero sin ret: 2M * 1.036 = 2.072.000 -> 545=2.072.000 -> 547=62.432.000
// Gastos presuntos 30% de 62.432.000 = 18.729.600 -> topado a 15 UTA ($12.517.560)!
check($r1['boletas']['vigentes'] === 3 && $r1['boletas']['anuladas'] === 1, 'Cuenta 3 boletas vigentes y excluye 1 anulada');
check($m1['461'] === 60360000 && $m1['492'] === 8752200 && $m1['545'] === 2072000 && $m1['547'] === 62432000,
    'R1 actualiza boletas emitidas por factor IPC mensual (461, 492, 545, 547)');
check($m1['494'] === 12517560 && $m1['467'] === (62432000 - 12517560) && $m1['618'] === (62432000 - 12517560) && $m1['619'] === 8752200,
    'Gastos presuntos 30% con tope 15 UTA ($12.517.560) y traslado a 618/619');
check($r1['cotizacionesReferencia']['baseImponiblePrevisional'] === (int) round(62432000 * 0.80), 'Base previsional referencial 80% de 547');

// Traslado automático a F22 (cód. 110 = 618 y cód. 198 = 619) y cargo cotizaciones cód. 900
$f22 = ctrib_f22($pdo, $e, 2026);
$mf = montos($f22);
check($mf['110'] === $m1['618'] && $mf['198'] === $m1['619'] && $mf['170'] === $m1['618'], 'F22 hereda cód. 110 y 198 automáticamente desde R1');

// Cotizaciones previsionales: si 900 <= 492 (8.752.200) se imputan sin bloqueo; si excede (492 + PPM), bloquea (Suplemento p. 308)
$fCotOk = ctrib_f22_guardar_valor($pdo, $e, 2026, '900', 2000000, 'prueba');
check($fCotOk['bloqueos'] === [] && montos($fCotOk)['305'] === (montos($fCotOk)['31'] + 2000000 - 8752200),
    'Cotizaciones cód. 900 dentro del tope retenciones/PPM se suman y compensan contra cód. 198');
$fCotExc = ctrib_f22_guardar_valor($pdo, $e, 2026, '900', 9500000, 'prueba');
check($fCotExc['bloqueos'] !== [], 'Bloquea si cód. 900 excede suma de retenciones 492 y PPM 2ª cat.');
ctrib_f22_guardar_valor($pdo, $e, 2026, '900', null, 'prueba');

// Cambio a gastos efectivos + directores S.A. + tope APV 600 UF ($23.836.776)
ctrib_r1_modo_gastos($pdo, $e, 2026, 'EFECTIVOS', 'prueba');
ctrib_r1_guardar_valor($pdo, $e, 2026, '465', 5000000, 'prueba');
$rEf = ctrib_r1_guardar_valor($pdo, $e, 2026, '479', 1000000, 'prueba');
$mEf = montos($rEf);
check($mEf['494'] === 0 && $mEf['465'] === 5000000 && $mEf['467'] === 57432000 && $mEf['618'] === 58432000,
    'Gastos efectivos anula 494 y suma directores S.A. 479 al total 618');

$rApvExc = ctrib_r1_guardar_valor($pdo, $e, 2026, '770', 25000000, 'prueba');
check($rApvExc['bloqueos'] !== [] && !$rApvExc['puedeFinalizar'], 'Bloquea APV > 600 UF ($23.836.776)');
ctrib_r1_guardar_valor($pdo, $e, 2026, '770', null, 'prueba');

// Finalizar, PDF R1 y reapertura
ctrib_r1_finalizar($pdo, $e, 2026, 'prueba');
$pdf = ctrib_documento_html($pdo, $e, 2026, 'R1');
check(str_contains($pdf, 'PROFESIONAL PRUEBA') && !str_contains($pdf, 'sello">BORRADOR<') && str_contains($pdf, '62.432.000') && str_contains($pdf, 'Enero'),
    'PDF R1 finalizado incluye tabla R1 y detalle mensual');
ctrib_r1_reabrir($pdo, $e, 2026, 'prueba', 'ajuste');
check(ctrib_r1($pdo, $e, 2026)['estado'] === 'BORRADOR', 'Reabrir R1 vuelve a BORRADOR');

echo "test-ctrib-r1 OK\n";
