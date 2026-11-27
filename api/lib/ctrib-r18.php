<?php
// Recuadro 18 (Determinación del RAI, art. 14 letra D) N° 3). El RAI se calcula en el RTRE (R20); aquí se presenta
// con los códigos del formulario del SII y se verifica que sus sumas cuadren antes de entregarlo.
declare(strict_types=1);

require_once __DIR__ . '/ctrib-r20.php';

/**
 * Líneas del R18 desde la fórmula del RAI del RTRE. Cada subtotal se comprueba: si no cuadra, se lanza un error
 * en vez de entregar un recuadro incorrecto. Instrucciones del F22: con CPTS negativo el 1720 no baja de cero, y
 * el 1500 tampoco.
 */
function ctrib_r18_desde_formula(array $f): array
{
    $subtotal = (int) $f['cptPositivo'] - (int) $f['cptNegativo'] + (int) $f['rexNegativoAbs'] + (int) $f['retirosEjercicio'];
    if ($subtotal !== (int) $f['subtotalRai']) {
        throw new CtribError('El subtotal del RAI no cuadra con sus partidas (R18).');
    }
    $m1720 = (int) $f['cptNegativo'] > 0 ? max(0, $subtotal) : $subtotal;
    $rai = max(0, $m1720 - (int) $f['rexPositivoPrevio'] - (int) $f['capitalAportado'] - (int) $f['saldoFur'] - (int) $f['sobreprecioAcciones']);
    if ($rai !== (int) $f['rai']) {
        throw new CtribError('El RAI no cuadra con sus partidas (R18).');
    }
    return [
        ['codigo' => '1703', 'glosa' => 'CPTS positivo final (recuadro 19)', 'signo' => '+', 'monto' => (int) $f['cptPositivo']],
        ['codigo' => '1719', 'glosa' => 'CPTS negativo final (recuadro 19)', 'signo' => '-', 'monto' => (int) $f['cptNegativo']],
        ['codigo' => '1492', 'glosa' => 'Saldo negativo del registro REX al término del ejercicio', 'signo' => '+', 'monto' => (int) $f['rexNegativoAbs']],
        ['codigo' => '1704', 'glosa' => 'Remesas, retiros o dividendos repartidos en el ejercicio, históricos', 'signo' => '+', 'monto' => (int) $f['retirosEjercicio']],
        ['codigo' => '1720', 'glosa' => 'Subtotal', 'signo' => '=', 'monto' => $m1720],
        ['codigo' => '1493', 'glosa' => 'Saldo positivo del registro REX al término del ejercicio, antes de imputaciones', 'signo' => '-', 'monto' => (int) $f['rexPositivoPrevio']],
        ['codigo' => '1494', 'glosa' => 'Capital aportado, histórico (incluye aumentos y disminuciones efectivas)', 'signo' => '-', 'monto' => (int) $f['capitalAportado']],
        ['codigo' => '1725', 'glosa' => 'Saldo FUR (cuando no haya sido considerado dentro del capital aportado)', 'signo' => '-', 'monto' => (int) $f['saldoFur']],
        ['codigo' => '1727', 'glosa' => 'Sobreprecio obtenido en la colocación de acciones de propia emisión, histórico', 'signo' => '-', 'monto' => (int) $f['sobreprecioAcciones']],
        ['codigo' => '1500', 'glosa' => 'Rentas afectas a IGC o IA (RAI) del ejercicio', 'signo' => '=', 'monto' => $rai],
    ];
}

/** Recuadro 18 del año: el RAI del RTRE, presentado con sus códigos. */
function ctrib_r18(PDO $pdo, int $empresa, int $anio): array
{
    $rtre = ctrib_r20($pdo, $empresa, $anio);
    return [
        'recuadro' => 'R18',
        'anio' => $anio,
        'anioComercial' => ctrib_anio_comercial($anio),
        'lineas' => ctrib_r18_desde_formula($rtre['formulaRai']),
        'rai' => (int) $rtre['formulaRai']['rai'],
        'estado' => $rtre['estado'], 'desactualizado' => $rtre['desactualizado'],
        'nota' => 'El RAI se calcula en el Registro Tributario de Rentas Empresariales (R20). Este recuadro lo muestra con los códigos del formulario.',
    ];
}
