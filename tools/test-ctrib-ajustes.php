<?php
// Prueba de los puntos completados el 26-11: cuentas de ajuste CXC/CXP con reparto FIFO (1817 y 1818), partidas del
// informe RLI, parámetros del RAI y REX en el RTRE, imputación de retiros en exceso al REX, factores de actualización
// y el Informe SII en hojas foliadas. Uso: php tools/test-ctrib-ajustes.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/ctrib-pdf.php';

function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FALLA: $msg\n"); exit(1); } }
function falla(callable $accion, string $contiene, string $msg): void
{
    try { $accion(); } catch (Throwable $e) {
        check(str_contains($e->getMessage(), $contiene), "$msg (mensaje: " . $e->getMessage() . ")");
        return;
    }
    check(false, "$msg (no lanzó error)");
}
function linea(array $r17, string $codigo): array
{
    foreach ($r17['lineas'] as $l) {
        if ($l['codigo'] === $codigo) {
            return $l;
        }
    }
    throw new RuntimeException("sin línea $codigo");
}

$pdo = base_prueba();
empresas_schema($pdo);
crearEsquema($pdo);
ctrib_schema($pdo);
$pdo->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')
    ->execute(['1', 'ENYEL PRUEBA', json_encode(['rut' => '78072579-6']), '2026-11-26']);
$e = (int) $pdo->lastInsertId();
$semilla = json_decode((string) file_get_contents(dirname(__DIR__) . '/api/lib/ctrib/enyel-2025.json'), true, 512, JSON_THROW_ON_ERROR);
ctrib_mapeo_guardar($pdo, $e, $semilla['mapeo'], 'prueba');
ctrib_balance_guardar($pdo, $e, 2026, 'itributario', $semilla['balance'], 'captura', 'prueba');
ctrib_homologacion_importar($pdo, $e, 2026, $semilla['homologacion_itributario'], 'prueba');
ctrib_homologar($pdo, $e, 2026, '406001', '1419', 'prueba', 'Corrección');

$base = ctrib_r17($pdo, $e, 2026);
check(linea($base, '1400')['monto'] === 22018000 && linea($base, '1817')['monto'] === 0 && linea($base, '1818')['monto'] === 0, 'sin ajustes: igual que iTributario (1400 22.018.000, 1817 y 1818 en cero)');

// ---- 12. Cuentas de ajuste CXC / CXP y reparto FIFO ----
falla(fn() => ctrib_ajuste_guardar($pdo, $e, 2026, '1402', ['101060'], null, null, 'p'), 'no admite cuentas de ajuste', 'código sin ajuste rechazado');
falla(fn() => ctrib_ajuste_guardar($pdo, $e, 2026, '1400', ['202040'], null, null, 'p'), 'debe ser de activo', 'CXC con cuenta de pasivo rechazada');
falla(fn() => ctrib_ajuste_guardar($pdo, $e, 2026, '1400', ['404001'], null, null, 'p'), 'no es una cuenta de activo o pasivo', 'cuenta de resultado rechazada');
falla(fn() => ctrib_ajuste_guardar($pdo, $e, 2026, '1400', ['101060'], 'x', null, 'p'), 'número entero', 'saldo no numérico rechazado');

ctrib_ajuste_guardar($pdo, $e, 2026, '1400', ['101060'], 1000000, null, 'p');
$r = ctrib_r17($pdo, $e, 2026);
$a = linea($r, '1400')['ajuste'];
check($a['saldoInicial'] === 1000000 && $a['saldoFinal'] === 0 && $a['montoFinal'] === 23018000 && $a['repartoAnioAnterior'] === 1000000, 'CXC: percibido = devengado + SI − SF');
check(linea($r, '1400')['monto'] === 22018000 && linea($r, '1817')['monto'] === 1000000, 'FIFO: 1.000.000 de cobros de años anteriores van al 1817');
check($r['totales']['1410'] === $base['totales']['1410'] + 1000000, 'el 1410 suma el 1817');
falla(fn() => ctrib_ajuste_guardar($pdo, $e, 2026, '1409', ['101060'], null, null, 'p'), 'debe ser de pasivo', 'CXP con cuenta de activo rechazada');

ctrib_ajuste_guardar($pdo, $e, 2026, '1409', ['202040'], 100000, null, 'p');
$r = ctrib_r17($pdo, $e, 2026);
$a = linea($r, '1409')['ajuste'];
check($a['saldoFinal'] === 71400 && $a['montoFinal'] === 8588293 + 100000 - 71400, 'CXP: pagado = devengado + SI − SF (SF sugerido desde el balance: 71.400)');
check(linea($r, '1409')['monto'] === 8588293 + 100000 - 71400 - 100000 && linea($r, '1818')['monto'] === 100000, 'FIFO: 100.000 de pagos de años anteriores van al 1818');
check(ctrib_ajuste_cuentas_disponibles($pdo, $e, 2026)[array_search('202040', array_column(ctrib_ajuste_cuentas_disponibles($pdo, $e, 2026), 'cuenta'), true)]['codigo'] === '1409', 'la cuenta queda marcada como ajuste del 1409');
falla(fn() => ctrib_ajuste_guardar($pdo, $e, 2026, '1412', ['202040'], null, null, 'p'), 'ya es cuenta de ajuste del código 1409', 'una cuenta de ajuste pertenece a un solo código');

// Saldo inicial sugerido desde el balance del año anterior.
ctrib_balance_guardar($pdo, $e, 2025, 'itributario', [
    ['codigo' => '101060', 'nombre' => 'CLIENTES', 'debitos' => 500000, 'creditos' => 0],
    ['codigo' => '301001', 'nombre' => 'CAPITAL', 'debitos' => 0, 'creditos' => 500000],
], 'captura', 'p');
ctrib_ajuste_guardar($pdo, $e, 2026, '1400', ['101060'], null, null, 'p');
$a = linea(ctrib_r17($pdo, $e, 2026), '1400')['ajuste'];
check($a['saldoInicialSugerido'] === 500000 && $a['saldoInicial'] === 500000 && $a['repartoAnioAnterior'] === 500000, 'SI sugerido desde el balance del año anterior (500.000)');
$hom = ctrib_documento_html($pdo, $e, 2026, 'HOMOLOGACION');
check(str_contains($hom, 'CXC 1400') && str_contains($hom, 'CXP 1409'), 'PDF de homologación: columna Ajuste CxC / CxP');
check(str_contains(ctrib_documento_html($pdo, $e, 2026, 'R17'), 'al 1817'), 'PDF del R17 con el detalle del ajuste');
// Volver sin ajustes para lo que sigue.
ctrib_ajuste_guardar($pdo, $e, 2026, '1400', [], null, null, 'p');
ctrib_ajuste_guardar($pdo, $e, 2026, '1409', [], null, null, 'p');
check(ctrib_r17($pdo, $e, 2026)['totales'] === $base['totales'], 'sin ajustes, el R17 vuelve al cálculo original');

// ---- 9. Partidas del informe RLI ----
$rli = ctrib_rli($pdo, $e, 2026);
check($rli['resultado'] === 6905357 && $rli['agregados'] === [] && $rli['determinada'] === 6905357, 'RLI: resultado del balance sin partidas');
falla(fn() => ctrib_rli_partida_agregar($pdo, $e, 2026, 'OTRO', 'x', 1, 'p'), 'agregado o un deducido', 'tipo de partida inválido');
falla(fn() => ctrib_rli_partida_agregar($pdo, $e, 2026, 'AGREGADO', '', 1, 'p'), 'concepto', 'partida sin concepto');
falla(fn() => ctrib_rli_partida_agregar($pdo, $e, 2026, 'AGREGADO', 'Multas', -5, 'p'), 'mayor que cero', 'monto negativo rechazado');
ctrib_rli_partida_agregar($pdo, $e, 2026, 'AGREGADO', 'Multas e intereses fiscales', 120000, 'p');
$rli = ctrib_rli_partida_agregar($pdo, $e, 2026, 'DEDUCIDO', 'Ingresos no renta', '20000', 'p');
check($rli['totalAgregados'] === 120000 && $rli['totalDeducidos'] === 20000 && $rli['determinada'] === 6905357 + 100000, 'RLI determinada = resultado + agregados − deducidos');
check($rli['final'] === $rli['determinada'] - $rli['incentivo'] && $rli['diferencia'] === $rli['final'] - $rli['codigo1440'], 'RLI final y diferencia con el 1440');
$pdf = ctrib_documento_html($pdo, $e, 2026, 'RLI');
check(str_contains($pdf, 'Multas e intereses fiscales') && str_contains($pdf, 'Ingresos no renta') && !str_contains($pdf, 'Sin partidas.'), 'PDF RLI con las partidas');
$rli = ctrib_rli_partida_quitar($pdo, $e, 2026, $rli['agregados'][0]['id'], 'p');
check($rli['agregados'] === [] && $rli['determinada'] === 6905357 - 20000, 'quitar una partida');
falla(fn() => ctrib_rli_partida_quitar($pdo, $e, 2026, 99999, 'p'), 'no existe', 'partida inexistente');

// ---- 11. RTRE: capital aportado, FUR, sobreprecio y REX en el RAI; retiros en exceso al REX ----
$r20 = ctrib_r20($pdo, $e, 2026);
$raiBase = $r20['rai'];
falla(fn() => ctrib_r20_guardar_parametro($pdo, $e, 2026, '9999', 1, 'p'), 'no se ingresa a mano', 'parámetro desconocido');
falla(fn() => ctrib_r20_guardar_parametro($pdo, $e, 2026, '1494', -1, 'p'), 'negativo', 'capital negativo rechazado');
$r20 = ctrib_r20_guardar_parametro($pdo, $e, 2026, '1494', 500000, 'p');
check($r20['formulaRai']['capitalAportado'] === 500000 && $r20['rai'] === max(0, $raiBase - 500000), 'capital aportado (1494) resta en el RAI');
$r20 = ctrib_r20_guardar_parametro($pdo, $e, 2026, '1725', 100000, 'p');
check($r20['rai'] === max(0, $raiBase - 600000) && $r20['parametros']['1725'] === 100000, 'saldo FUR resta en el RAI');
ctrib_r20_guardar_parametro($pdo, $e, 2026, '1494', null, 'p');
$r20 = ctrib_r20_guardar_parametro($pdo, $e, 2026, '1725', '', 'p');
check($r20['rai'] === $raiBase, 'parámetros quitados: RAI original');

// REX positivo de 2.000.000 en 4.4 y retiros mayores al RAI.
ctrib_r20_guardar_celda($pdo, $e, 2026, '4.4', 'REX_CON_PRIORIDAD', 2000000, 'p');
$r20 = ctrib_r20($pdo, $e, 2026);
check($r20['formulaRai']['rai'] === max(0, $raiBase - 2000000), 'el REX positivo antes de imputaciones resta en el RAI');
$retiros = $r20['rai'] + 1500000;
ctrib_composicion_guardar($pdo, $e, 2026, 'EIRL', '14D3', [['rut' => '12.345.678-5', 'nombre' => 'TITULAR', 'participacion' => 100, 'monto' => $retiros]], false, 'p');
$r20 = ctrib_r20($pdo, $e, 2026);
$raiNuevo = $r20['rai'];
$celdas = [];
foreach ($r20['celdas'] as $c) {
    $celdas[$c['fila'] . '::' . $c['columna']] = $c['monto'];
}
check($r20['retirosImputadosRai'] === min($retiros, $raiNuevo), 'retiros imputados primero al RAI');
check(($celdas['6.1::REX_CON_PRIORIDAD'] ?? 0) === min(2000000, $retiros - $r20['retirosImputadosRai']), 'el exceso sobre el RAI se imputa al REX (6.1)');
check($r20['retirosNoImputados'] === $retiros - $r20['retirosImputadosRai'] - $r20['retirosImputadosRex'], 'lo que no cabe en RAI ni REX queda sin imputar');
check((int) array_column(ctrib_r6($pdo, $e, 2026)['lineas'], 'monto', 'codigo')['1193'] === $r20['retirosNoImputados'], 'R6 1193 = retiros no imputados a los RTRE');

// ---- 8. Factores de actualización ----
$f = ctrib_factores_mes(2025);
check($f !== null && $f[1] === 1.036 && $f[12] === 1.0, 'factores 2025: enero 1,036 y diciembre 1');
check(ctrib_factores_mes(1990) === null, 'sin tabla de factores: null');
$ret = ctrib_documento_html($pdo, $e, 2026, 'RETIROS');
check(str_contains($ret, 'Ene 1,036') && str_contains($ret, number_format($retiros, 0, ',', '.')), 'PDF de retiros con factores (retiro de diciembre reajustado × 1)');

// ---- 7. Informe SII en hojas foliadas ----
falla(fn() => ctrib_opciones_folio('R17', 1, 1, 'H'), 'no tiene Informe SII', 'el R17 no tiene Informe SII');
falla(fn() => ctrib_opciones_folio('RTRE', 5, 3, 'H'), 'folio desde y folio hasta', 'hasta menor que desde');
falla(fn() => ctrib_opciones_folio('RTRE', 1, 2000, 'H'), '999 hojas', 'rango demasiado grande');
falla(fn() => ctrib_opciones_folio('RTRE', 1, 2, 'X'), 'horizontal o vertical', 'orientación inválida');
$op = ctrib_opciones_folio('RTRE', '5', '7', 'V');
$html = ctrib_documento_html($pdo, $e, 2026, 'RTRE', $op);
check(str_contains($html, 'counter(page)') && str_contains($html, '@page:first{counter-increment:page 5}') && str_contains($html, 'size:letter;'), 'Informe SII: folio desde 5 en cada hoja, vertical');
check(str_contains($html, 'Hojas foliadas:</strong> N° 5 a 7') && str_contains($html, 'Informe SII (hojas foliadas N° 5 a 7)'), 'Informe SII: rango de folios en el membrete');
check(str_contains(ctrib_documento_html($pdo, $e, 2026, 'BALANCE', ctrib_opciones_folio('BALANCE', 1, 3, 'H')), 'size:letter landscape'), 'Informe SII horizontal');

echo "Cifrax Tributario 26-11: ajustes CXC/CXP con FIFO (1817 y 1818), partidas RLI, parámetros y REX del RTRE, retiros en exceso, factores e Informe SII foliado verificados\n";
