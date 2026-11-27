<?php
// Recuadro 23 (capital propio tributario simplificado del régimen de transparencia, art. 14 letra D) N° 8).
// Reglas: instrucciones del F22 AT 2026. Sale del R22, de los retiros del año y de los valores escritos a mano.
declare(strict_types=1);

require_once __DIR__ . '/ctrib-r22.php';

// Códigos que se pueden escribir a mano. Los que tienen valor automático se reemplazan con el manual.
const CTRIB_R23_MANUALES = ['1580', '1582', '1573', '1574', '1575', '1576', '1577', '1584', '1585'];

/** CPT final del año anterior: del R23 (14 D N° 8) o del R19 (14 D N° 3) finalizados. */
function ctrib_r23_anterior(PDO $pdo, int $empresa, int $anio): array
{
    foreach ([['R23', '1581', '1583'], ['R19', '1545', '1546']] as [$recuadro, $positivo, $negativo]) {
        $estado = ctrib_estado_recuadro($pdo, $empresa, $anio - 1, $recuadro);
        if ($estado['estado'] === 'FINALIZADO') {
            return ['1580' => (int) ($estado['snapshot'][$positivo] ?? 0), '1582' => (int) ($estado['snapshot'][$negativo] ?? 0), 'recuadro' => $recuadro];
        }
    }
    return ['1580' => 0, '1582' => 0, 'recuadro' => null];
}

/** Recuadro 23 completo del año. */
function ctrib_r23(PDO $pdo, int $empresa, int $anio): array
{
    $r22 = ctrib_r22_calculo($pdo, $empresa, $anio);
    $m22 = $r22['montos'];
    $anterior = ctrib_r23_anterior($pdo, $empresa, $anio);
    $retiros = ctrib_retiros_del_anio($pdo, $empresa, $anio);
    $enajenaciones = (int) (array_column(ctrib_r4($pdo, $empresa, $anio)['lineas'], 'monto', 'codigo')['1814'] ?? 0);
    $manuales = ctrib_valores_manuales($pdo, $empresa, $anio, 'R23');
    $origenAnterior = $anterior['recuadro'] ? 'AÑO_ANTERIOR' : 'MANUAL';

    $automaticos = [
        '1580' => [$anterior['1580'], $origenAnterior],
        '1582' => [$anterior['1582'], $origenAnterior],
        '1712' => [max(0, $m22['1630']), 'R22'],
        '1713' => [max(0, -$m22['1630']), 'R22'],
        '1714' => [$m22['1613'] + $m22['1627'], 'R22'],
        '1576' => [(int) $retiros['total'], 'RETIROS'],
        '1715' => [$m22['1608'], 'R22'],
        '1577' => [$r22['gastosRechazados'], 'CUENTAS'],
        '1716' => [$m22['1609'], 'R22'],
        '1578' => [$m22['1605'], 'R22'],
        '1584' => [max(0, $enajenaciones), $enajenaciones > 0 ? 'R4' : 'MANUAL'],
        '1585' => [max(0, -$enajenaciones), $enajenaciones < 0 ? 'R4' : 'MANUAL'],
    ];

    $catalogo = ctrib_catalogo_recuadro('recuadro23.json');
    $lineas = [];
    $montos = [];
    $suma = 0;
    foreach ($catalogo as $codigo => $c) {
        $codigo = (string) $codigo;
        if ($c['signo'] === '=') {
            continue;
        }
        [$monto, $origen] = $automaticos[$codigo] ?? [0, 'MANUAL'];
        if (in_array($codigo, CTRIB_R23_MANUALES, true) && array_key_exists($codigo, $manuales)) {
            [$monto, $origen] = [$manuales[$codigo], 'MANUAL'];
        }
        $montos[$codigo] = (int) $monto;
        $suma += $c['signo'] === '+' ? (int) $monto : -(int) $monto;
        $lineas[] = ['codigo' => $codigo, 'glosa' => $c['glosa'], 'signo' => $c['signo'], 'monto' => (int) $monto, 'origen' => $origen,
            'naturaleza' => in_array($codigo, CTRIB_R23_MANUALES, true) ? 'MANUAL' : 'CALCULADO'];
    }
    foreach (['1581' => max(0, $suma), '1583' => max(0, -$suma)] as $codigo => $monto) {
        $codigo = (string) $codigo;
        $montos[$codigo] = $monto;
        $lineas[] = ['codigo' => $codigo, 'glosa' => $catalogo[$codigo]['glosa'], 'signo' => '=', 'monto' => $monto, 'origen' => 'CALCULADO', 'naturaleza' => 'CALCULADO'];
    }

    $avisos = ['El SII pide este recuadro cuando los ingresos del año comercial anterior superan 50.000 UF; para las demás empresas es informativo.'];
    if ($retiros['modo'] === 'totalidad' && !array_key_exists('1576', $manuales) && $montos['1576'] > 0) {
        $avisos[] = 'Está marcado que se retira la totalidad: por eso el 1576 resta toda la base asignada. Si no se repartió, escriba el monto efectivamente repartido.';
    }

    $estado = ctrib_estado_recuadro($pdo, $empresa, $anio, 'R23');
    $finalizado = $estado['estado'] === 'FINALIZADO';
    return [
        'recuadro' => 'R23', 'anio' => $anio, 'anioComercial' => ctrib_anio_comercial($anio),
        'lineas' => $lineas, 'totales' => ['1581' => $montos['1581'], '1583' => $montos['1583']],
        'estado' => $estado['estado'], 'finalizado' => $estado['finalizado'], 'usuario' => $estado['usuario'],
        'desactualizado' => ctrib_desactualizado($estado, $montos),
        'avisos' => $avisos, 'bloqueos' => $r22['bloqueos'],
        'pendientesDeCalculo' => ['Capital aportado, aumentos y disminuciones (1573 a 1575) se escriben a mano.'],
        'puedeFinalizar' => $r22['bloqueos'] === [] && !$finalizado,
    ];
}

function ctrib_r23_guardar_valor(PDO $pdo, int $empresa, int $anio, string $codigo, mixed $monto, string $usuario): array
{
    if (is_numeric($monto) && (int) $monto < 0) {
        throw new CtribError('El monto no puede ser negativo: el signo lo pone el formulario.');
    }
    ctrib_guardar_valor_manual($pdo, $empresa, $anio, 'R23', $codigo, $monto, $usuario, CTRIB_R23_MANUALES);
    return ctrib_r23($pdo, $empresa, $anio);
}

function ctrib_r23_finalizar(PDO $pdo, int $empresa, int $anio, string $usuario): array
{
    $r = ctrib_r23($pdo, $empresa, $anio);
    if ($r['estado'] === 'FINALIZADO') {
        throw new CtribError('El Recuadro 23 ya está finalizado.');
    }
    if ($r['bloqueos'] !== []) {
        throw new CtribError(implode(' ', $r['bloqueos']));
    }
    ctrib_recuadro_finalizar($pdo, $empresa, $anio, 'R23', $r['lineas'], $usuario);
    return ctrib_r23($pdo, $empresa, $anio);
}

function ctrib_r23_reabrir(PDO $pdo, int $empresa, int $anio, string $usuario, string $motivo): array
{
    if (ctrib_estado_recuadro($pdo, $empresa, $anio, 'R23')['estado'] !== 'FINALIZADO') {
        throw new CtribError('El Recuadro 23 no está finalizado.');
    }
    ctrib_recuadro_reabrir($pdo, $empresa, $anio, 'R23', $usuario, $motivo);
    return ctrib_r23($pdo, $empresa, $anio);
}
