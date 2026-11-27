<?php
// Prueba de las existencias del 1409 (la variación del año se suma al devengado, como iTributario) y del IDPC estimado
// del R17 en 14 D N°3. Uso: php tools/test-ctrib-existencias.php
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
    ->execute(['1', 'ENYEL PRUEBA', json_encode(['rut' => '76000000-0']), '2026-11-26']);
$e = (int) $pdo->lastInsertId();
$semilla = json_decode((string) file_get_contents(dirname(__DIR__) . '/api/lib/ctrib/enyel-2025.json'), true, 512, JSON_THROW_ON_ERROR);
ctrib_mapeo_guardar($pdo, $e, $semilla['mapeo'], 'prueba');
ctrib_balance_guardar($pdo, $e, 2026, 'itributario', $semilla['balance'], 'captura', 'prueba');
ctrib_homologacion_importar($pdo, $e, 2026, $semilla['homologacion_itributario'], 'prueba');
ctrib_homologar($pdo, $e, 2026, '406001', '1419', 'prueba', 'Corrección');

$base = ctrib_r17($pdo, $e, 2026);
$l = linea($base, '1409');
check($l['monto'] === 8588293 && $l['origen'] === 'CUENTAS' && $l['existencias']['configurado'] === false, 'sin existencias: el 1409 queda igual (8.588.293)');
check($base['pendientesDeCalculo'] === [], 'el 1409 ya no figura como pendiente de cálculo');
check($base['idpcEstimado'] === ['tasa' => 12.5, 'base' => 3452678, 'monto' => 431585], 'IDPC estimado sin régimen configurado (14 D N°3): 12,5 % del 1440 = 431.585');

falla(fn() => ctrib_existencias_guardar($pdo, $e, 2026, ['202040'], null, null, 'p'), 'las existencias son de activo', 'cuenta de pasivo rechazada');
falla(fn() => ctrib_existencias_guardar($pdo, $e, 2026, ['404001'], null, null, 'p'), 'no es una cuenta de activo', 'cuenta de resultado rechazada');
falla(fn() => ctrib_existencias_guardar($pdo, $e, 2026, ['101002'], 'x', null, 'p'), 'número entero', 'saldo no numérico rechazado');

// Cuenta de activo 101002 (saldo final 17.356.464 en el balance) con saldo inicial escrito a mano.
ctrib_existencias_guardar($pdo, $e, 2026, ['101002'], 17000000, null, 'p');
$r = ctrib_r17($pdo, $e, 2026);
$x = linea($r, '1409')['existencias'];
check($x['saldoFinal'] === 17356464 && $x['saldoInicial'] === 17000000 && $x['variacion'] === 356464, 'variación = final (sugerido del balance) − inicial (manual)');
check(linea($r, '1409')['monto'] === 8588293 + 356464 && linea($r, '1409')['origen'] === 'CUENTAS_EXISTENCIAS', '1409 = devengado de las cuentas + variación');
check($x['devengadoCuentas'] === 8588293 && $x['devengadoConExistencias'] === 8588293 + 356464, 'el detalle trae el devengado antes y después de las existencias');
check($r['totales']['1430'] === $base['totales']['1430'] + 356464, 'el 1430 suma la variación');

ctrib_existencias_guardar($pdo, $e, 2026, ['101002'], 30000000, 0, 'p');
$l = linea(ctrib_r17($pdo, $e, 2026), '1409');
check($l['monto'] === 0 && $l['huboPisoEnCero'] === true, 'variación negativa mayor que el devengado: piso en cero');

// Con ajuste CXP en el 1409, el pagado se calcula sobre el devengado con existencias y el FIFO al 1818 sigue igual.
ctrib_existencias_guardar($pdo, $e, 2026, ['101002'], 17000000, null, 'p');
ctrib_ajuste_guardar($pdo, $e, 2026, '1409', ['202040'], 100000, null, 'p');
$r = ctrib_r17($pdo, $e, 2026);
$a = linea($r, '1409')['ajuste'];
check($a['devengado'] === 8588293 + 356464 && $a['montoFinal'] === 8588293 + 356464 + 100000 - 71400, 'CXP: pagado = devengado con existencias + SI − SF');
check(linea($r, '1409')['monto'] === $a['montoFinal'] - 100000 && linea($r, '1818')['monto'] === 100000, 'FIFO: 100.000 de años anteriores al 1818');

// Una cuenta es de existencias o de ajuste, no de ambos.
falla(fn() => ctrib_ajuste_guardar($pdo, $e, 2026, '1400', ['101002'], null, null, 'p'), 'cuenta de existencias del 1409', 'ajuste con una cuenta de existencias rechazado');
ctrib_ajuste_guardar($pdo, $e, 2026, '1400', ['101060'], null, null, 'p');
falla(fn() => ctrib_existencias_guardar($pdo, $e, 2026, ['101002', '101060'], null, null, 'p'), 'ya es cuenta de ajuste del código 1400', 'existencias con una cuenta de ajuste rechazada');
ctrib_ajuste_guardar($pdo, $e, 2026, '1400', [], null, null, 'p');

$vista = ctrib_r17_ajustes($pdo, $e, 2026);
check($vista['existencias']['codigo'] === '1409' && $vista['existencias']['configurado'] && count($vista['ajustes']) === count(ctrib_codigos_con_ajuste()), 'la vista de ajustes trae los ajustes y las existencias del 1409');
$cuenta = array_values(array_filter($vista['cuentas'], fn($c) => $c['cuenta'] === '101002'))[0];
check($cuenta['existencias'] === true && $cuenta['codigo'] === null, 'la cuenta queda marcada como existencias');
check(str_contains(ctrib_documento_html($pdo, $e, 2026, 'R17'), 'Existencias: final'), 'PDF del R17 con el detalle de existencias');
check(in_array('R17 1409 existencias', array_column(ctrib_historial_listar($pdo, $e, 2026, 'ajuste'), 'referencia'), true), 'historial de existencias');

ctrib_ajuste_guardar($pdo, $e, 2026, '1409', [], null, null, 'p');
ctrib_existencias_guardar($pdo, $e, 2026, [], null, null, 'p');
check(ctrib_r17($pdo, $e, 2026)['totales'] === $base['totales'], 'sin existencias ni ajustes, el R17 vuelve al cálculo original');

ctrib_incentivo_guardar($pdo, $e, 2026, true, true, true, null, 'p');
ctrib_r17_finalizar($pdo, $e, 2026, 'p');
falla(fn() => ctrib_existencias_guardar($pdo, $e, 2026, ['101002'], null, null, 'p'), 'está finalizado', 'R17 finalizado: no se cambian las existencias');

ctrib_empresa_config_guardar($pdo, $e, 'EIRL', '14D3', 'p');
check(ctrib_r17($pdo, $e, 2026)['idpcEstimado']['monto'] === 431585, '14 D N°3: IDPC estimado');
ctrib_empresa_config_guardar($pdo, $e, 'EIRL', '14D8', 'p');
check(ctrib_r17($pdo, $e, 2026)['idpcEstimado'] === null, '14 D N°8: sin IDPC estimado (reglas por confirmar)');

echo "Cifrax Tributario existencias del 1409 (variación, piso en cero, con ajuste CXP y FIFO, rechazos, PDF) e IDPC estimado verificados\n";
