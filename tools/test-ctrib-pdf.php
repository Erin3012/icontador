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
    ->execute(['52502', 'ENYEL CAPACITACIONES ANGELA SOBARZO RAMÍREZ <EIRL>', json_encode([
        'rut' => '78072579-6', 'giro' => 'SERVICIOS DE CAPACITACIÓN', 'direccion' => 'FRANCISCO BILBAO #483', 'comuna' => 'Coyhaique', 'ciudad' => 'COYHAIQUE',
    ]), '2026-11-25']);
$empresa = (int) $pdo->lastInsertId();
$semilla = json_decode((string) file_get_contents(dirname(__DIR__) . '/api/lib/ctrib/enyel-2025.json'), true, 512, JSON_THROW_ON_ERROR);
ctrib_mapeo_guardar($pdo, $empresa, $semilla['mapeo'], 'prueba');
ctrib_balance_guardar($pdo, $empresa, 2026, 'itributario', $semilla['balance'], 'captura', 'prueba');
ctrib_homologacion_importar($pdo, $empresa, 2026, $semilla['homologacion_itributario'], 'prueba');
ctrib_homologar($pdo, $empresa, 2026, '406001', '1419', 'prueba', 'Corrección');
ctrib_composicion_guardar($pdo, $empresa, 2026, 'EIRL', '14D3', [['rut' => '12.345.678-5', 'nombre' => 'ANGELA SOBARZO RAMÍREZ', 'participacion' => 100, 'monto' => 1500000]], false, 'prueba');

// Formato de iTributario: membrete con RUT con puntos, giro y dirección; título numerado; banda "Recuadro N° X";
// marca de agua BORRADOR mientras el recuadro no esté finalizado; hoja horizontal en balance, homologación, retiros, RTRE, R20 y R21.
$esperado = [
    'BALANCE' => ['Balance General 8 Columnas', '96.772.918', '6.905.357', 'Totales Generales', 'captura de iTributario', 'letter landscape'],
    'HOMOLOGACION' => ['Homologación de cuentas', 'Código Recuadro 17', '405029', '1415', 'No aplica', 'Configurada', 'Avance:</strong> 100% (14 de 14', 'letter landscape'],
    'RETIROS' => ['Retiros y Dividendos', '12.345.678-5', 'ANGELA SOBARZO RAMÍREZ', '1.500.000', '100,0%', 'Total reajustado'],
    'R4' => ['<span>4.</span>', 'Recuadro N° 4. Enajenación de acciones', 'ENAJENACIÓN DE DERECHOS SOCIALES', 'DETERMINACIÓN DEL RESULTADO', '1816'],
    'R6' => ['<span>6.</span>', 'DATOS DE BALANCE', '20.509.426', '13.604.069', '843'],
    'R17' => ['<span>17.</span>', 'Recuadro N° 17. Base imponible régimen Pro Pyme (art. 14 letra D) N° 3 LIR)', 'Percibido o pagado', '22.018.000', '15.112.643',
        '<td class="sig">+</td>', '<td class="sig">=</td>', 'class="agua"', 'class="sello">BORRADOR'],
    'RLI' => ['INFORME DE RENTA LÍQUIDA IMPONIBLE', 'Resultado del Balance', '$6.905.357', 'Sin partidas.', 'RENTA LÍQUIDA IMPONIBLE FINAL'],
    'R18' => ['<span>18.</span>', '1500', 'Rentas afectas a IGC o IA (RAI) del ejercicio', '1.500.000'],
    'R19' => ['<span>19.</span>', 'CPTS régimen Pro Pyme', '1545', '1.500.000'],
    'CPT' => ['Capital Propio Tributario a partir del Activo', 'TOTAL ACTIVO SEGÚN BALANCE', '$20.509.426', 'CAPITAL PROPIO DETERMINADO', 'Sin cuentas configuradas.'],
    'R20' => ['<span>20.</span>', 'Recuadro N° 20', 'Rentas con tributación cumplida', '1463', '1484', 'letter landscape'],
    'RTRE' => ['REGISTRO TRIBUTARIO DE RENTAS EMPRESARIALES — HOJA DE TRABAJO', 'RAI FINAL', 'SAC DISPONIBLE', 'RETIROS DEL EJERCICIO', '4.2', 'Saldo final', 'Borrador (cálculo con datos vigentes)'],
    'R21' => ['<span>21.</span>', 'Registro SAC', 'Acumulados a contar desde el 01.01.2017', '1513', '1564'],
    'DJ1948' => ['ANGELA SOBARZO RAMÍREZ', '12.345.678-5', '$1.500.000', '100%'],
];
foreach ($esperado as $doc => $textos) {
    $html = ctrib_documento_html($pdo, $empresa, 2026, $doc);
    check(str_starts_with($html, '<!doctype html>') && str_contains($html, 'Imprimir o guardar como PDF'), "$doc: documento imprimible");
    check(str_contains($html, '<strong>RUT:</strong> 78.072.579-6') && str_contains($html, '<strong>Año Tributario:</strong> 2026'), "$doc: membrete con RUT y año");
    check(str_contains($html, 'SERVICIOS DE CAPACITACIÓN') && str_contains($html, 'FRANCISCO BILBAO #483, Coyhaique, COYHAIQUE'), "$doc: membrete con giro y dirección");
    check(str_contains($html, '&lt;EIRL&gt;') && !str_contains($html, '<EIRL>'), "$doc: razón social escapada");
    foreach ($textos as $t) {
        check(str_contains($html, $t), "$doc contiene «{$t}»");
    }
}
// Informe RLI: resultado del balance menos el incentivo; observa si no cuadra con el 1440 del R17.
$r17 = ctrib_r17($pdo, $empresa, 2026);
$rli = ctrib_documento_html($pdo, $empresa, 2026, 'RLI');
$final = 6905357 - (int) $r17['totales']['1432'];
check(str_contains($rli, ctrib_pesos($final)), 'RLI final = resultado del balance − 1432');
check($final === (int) $r17['totales']['1440'] ? str_contains($rli, 'cuadra con el código 1440') : str_contains($rli, 'NO CUADRA con el código 1440'), 'RLI: observación frente al 1440');
// El R4 sin finalizar lleva marca BORRADOR; el balance (reporte) no.
check(!str_contains(ctrib_documento_html($pdo, $empresa, 2026, 'BALANCE'), 'class="agua"'), 'el balance no lleva marca de agua');
// El R18 muestra todo lo que resta la fórmula del RAI: saldo REX positivo (1493) y sobreprecio de acciones.
ctrib_r20_guardar_celda($pdo, $empresa, 2026, '1.1', 'REX_CON_PRIORIDAD', 1000000, 'prueba');
ctrib_r20_guardar_parametro($pdo, $empresa, 2026, 'SOBR', 250000, 'prueba');
$f = ctrib_r20($pdo, $empresa, 2026)['formulaRai'];
$r18 = ctrib_documento_html($pdo, $empresa, 2026, 'R18');
check($f['rexPositivoPrevio'] === 1000000 && preg_match('/<td class="cod">1493<\/td><td class="mon">1\.000\.000</', $r18) === 1, 'R18: el 1493 muestra el saldo REX que se resta');
check($f['rai'] === $f['subtotalRai'] - 1000000 - 250000 && str_contains($r18, '<td class="cod">1727</td><td class="mon">250.000</td>') && str_contains($r18, '250.000'), 'R18: el sobreprecio aparece y las líneas suman el RAI');
check(!str_contains($r18, 'se toman como cero'), 'R18: sin la nota antigua');
// En régimen 14 D N°8 el título del recuadro cambia a N° 8.
ctrib_empresa_config_guardar($pdo, $empresa, 'EIRL', '14D8', 'prueba');
check(str_contains(ctrib_documento_html($pdo, $empresa, 2026, 'R17'), '(art. 14 letra D) N° 8 LIR)'), 'título del R17 en 14 D N°8');
try {
    ctrib_documento_html($pdo, $empresa, 2026, 'XX');
    check(false, 'documento desconocido debe rechazarse');
} catch (CtribError $e) {
    check(str_contains($e->getMessage(), 'no válido'), 'documento desconocido rechazado');
}
echo "Cifrax Tributario PDF: los 14 documentos se generan con el formato de iTributario (membrete, título numerado, signo, BORRADOR)\n";
