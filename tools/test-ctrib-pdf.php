<?php
// Prueba de los documentos imprimibles (Ver PDF) de Cifrax Tributario. Uso: php tools/test-ctrib-pdf.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/ctrib-pdf.php';

function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FALLA: $msg\n"); exit(1); } }

$pdo = base_prueba();
empresas_schema($pdo);
crearEsquema($pdo);
ctrib_schema($pdo);
$pdo->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')
    ->execute(['52502', 'ENYEL CAPACITACIONES ANGELA SOBARZO RAMÍREZ <EIRL>', json_encode(['rut' => '78072579-6']), '2026-11-25']);
$empresa = (int) $pdo->lastInsertId();
$semilla = json_decode((string) file_get_contents(dirname(__DIR__) . '/api/lib/ctrib/enyel-2025.json'), true, 512, JSON_THROW_ON_ERROR);
ctrib_mapeo_guardar($pdo, $empresa, $semilla['mapeo'], 'prueba');
ctrib_balance_guardar($pdo, $empresa, 2026, 'itributario', $semilla['balance'], 'captura', 'prueba');
ctrib_homologacion_importar($pdo, $empresa, 2026, $semilla['homologacion_itributario'], 'prueba');
ctrib_homologar($pdo, $empresa, 2026, '406001', '1419', 'prueba', 'Corrección');
ctrib_composicion_guardar($pdo, $empresa, 2026, 'EIRL', '14D3', [['rut' => '12.345.678-5', 'nombre' => 'ANGELA SOBARZO RAMÍREZ', 'participacion' => 100, 'monto' => 1500000]], false, 'prueba');

$esperado = [
    'BALANCE' => ['96.772.918', '6.905.357', 'Totales Generales', 'Origen: captura de iTributario', 'landscape'],
    'R4' => ['1816', 'Enajenación'],
    'R6' => ['$20.509.426', '$13.604.069', '843'],
    'R17' => ['$22.018.000', '$15.112.643', '1440'],
    'RLI' => ['405029 ARRIENDOS', 'Incentivo al ahorro', 'Base reinvertida'],
    'R18' => ['1721', 'Rentas afectas a impuestos (RAI)', '$1.500.000'],
    'R19' => ['1545', '$1.500.000'],
    'CPT' => ['Capital Propio Tributario Simplificado', '1545'],
    'R20' => ['Fórmula del RAI', 'Celdas con valor', '12,5%'],
    'RTRE' => ['Registro Tributario de Rentas Empresariales'],
    'R21' => ['1513', '1564'],
    'DJ1948' => ['ANGELA SOBARZO RAMÍREZ', '12345678-5', '$1.500.000', '100%'],
];
foreach ($esperado as $doc => $textos) {
    $html = ctrib_documento_html($pdo, $empresa, 2026, $doc);
    check(str_starts_with($html, '<!doctype html>') && str_contains($html, 'Imprimir o guardar como PDF'), "$doc: documento imprimible");
    check(str_contains($html, 'RUT 78072579-6') && str_contains($html, 'Año tributario 2026'), "$doc: membrete con RUT y año");
    check(str_contains($html, '&lt;EIRL&gt;') && !str_contains($html, '<EIRL>'), "$doc: razón social escapada");
    foreach ($textos as $t) {
        check(str_contains($html, $t), "$doc contiene «{$t}»");
    }
}
try {
    ctrib_documento_html($pdo, $empresa, 2026, 'XX');
    check(false, 'documento desconocido debe rechazarse');
} catch (CtribError $e) {
    check(str_contains($e->getMessage(), 'no válido'), 'documento desconocido rechazado');
}
echo "Cifrax Tributario PDF: los 12 documentos (balance, R4, R6, R17, RLI, R18, R19, CPT, RTRE, R21, DJ 1948) se generan con membrete y datos\n";
