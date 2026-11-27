<?php
// Recuadros 14 (Razonabilidad del CPT) y 13 (Determinación del RAI) para el régimen Semi Integrado (Art. 14 letra A) LIR).
declare(strict_types=1);

require_once __DIR__ . '/ctrib-r12.php';

const CTRIB_R14_MANUALES = ['1145', '1146', '1177', '893', '894', '1178', '1179', '1180', '1182', '1186', '1187', '1188', '1189', '1190'];
const CTRIB_R13_MANUALES = ['1698', '1717', '1692', '1699', '1693', '844', '982', '1198'];

/** Factor de actualización para CAPITAL_INICIAL desde factores-AAAA.json, o null si no existe. */
function ctrib_factor_capital_inicial(int $anioComercial): ?float
{
    $ruta = __DIR__ . '/ctrib/factores-' . $anioComercial . '.json';
    if (!is_file($ruta)) {
        return null;
    }
    $datos = json_decode((string) file_get_contents($ruta), true);
    foreach ($datos['correccionMonetaria'] ?? [] as $fila) {
        if (($fila['mesOrigen'] ?? '') === 'CAPITAL_INICIAL' && isset($fila['factorActualizacion'])) {
            return (float) $fila['factorActualizacion'];
        }
    }
    return null;
}

/** Recuadro 14: Razonabilidad del Capital Propio Tributario (Art. 14 A). */
function ctrib_r14(PDO $pdo, int $empresa, int $anio): array
{
    $catalogo = json_decode((string) file_get_contents(__DIR__ . '/ctrib/recuadro14.json'), true);
    $r12 = ctrib_r12($pdo, $empresa, $anio);
    $m12 = array_column($r12['lineas'], 'monto', 'codigo');
    $manuales = ctrib_valores_manuales($pdo, $empresa, $anio, 'R14');
    $anterior = ctrib_estado_recuadro($pdo, $empresa, $anio - 1, 'R14');
    $regimen = ctrib_empresa_config($pdo, $empresa)['regimen'];
    $retiros = $regimen === '14G' ? 0 : ctrib_retiros_total($pdo, $empresa, $anio);

    $cptIniPos = array_key_exists('1145', $manuales) ? $manuales['1145'] : (int) ($anterior['snapshot']['645'] ?? 0);
    $cptIniNeg = array_key_exists('1146', $manuales) ? $manuales['1146'] : (int) ($anterior['snapshot']['646'] ?? 0);
    $factor = ctrib_factor_capital_inicial(ctrib_anio_comercial($anio));
    $cmAuto = ($cptIniPos > 0 && $factor !== null) ? (int) round($cptIniPos * ($factor - 1.0), 0, PHP_ROUND_HALF_UP) : 0;

    $m1690 = (int) ($m12['1690'] ?? 0);
    $automaticos = [
        '1145' => [$cptIniPos, array_key_exists('1145', $manuales) ? 'MANUAL' : ($anterior['estado'] === 'FINALIZADO' ? 'AÑO_ANTERIOR' : 'MANUAL')],
        '1146' => [$cptIniNeg, array_key_exists('1146', $manuales) ? 'MANUAL' : ($anterior['estado'] === 'FINALIZADO' ? 'AÑO_ANTERIOR' : 'MANUAL')],
        '1177' => [$cmAuto, $factor !== null ? 'AUTOMATICO' : 'MANUAL'],
        '1694' => [max(0, $m1690), 'R12'],
        '1695' => [max(0, -$m1690), 'R12'],
        '1696' => [(int) ($m12['1689'] ?? 0), 'R12'],
        '1182' => [$retiros, 'RETIROS'],
        '1697' => [(int) ($m12['1144'] ?? 0), 'R12'],
        '1700' => [(int) ($m12['1150'] ?? 0), 'R12'],
        '1701' => [(int) ($m12['1154'] ?? 0), 'R12'],
        '1702' => [(int) ($m12['1157'] ?? 0), 'R12'],
    ];

    $lineas = [];
    $montos = [];
    $suma = 0;
    foreach ($catalogo as $c) {
        $codigo = (string) $c['codigo'];
        if ($c['signo'] === '=') {
            continue;
        }
        [$monto, $origen] = $automaticos[$codigo] ?? [0, 'MANUAL'];
        if (in_array($codigo, CTRIB_R14_MANUALES, true) && array_key_exists($codigo, $manuales)) {
            [$monto, $origen] = [$manuales[$codigo], 'MANUAL'];
        }
        $montos[$codigo] = (int) $monto;
        $suma += $c['signo'] === '+' ? (int) $monto : -(int) $monto;
        $lineas[] = ['codigo' => $codigo, 'glosa' => $c['glosa'], 'signo' => $c['signo'], 'monto' => (int) $monto,
            'origen' => $origen, 'naturaleza' => in_array($codigo, CTRIB_R14_MANUALES, true) ? 'MANUAL' : 'CALCULADO'];
    }
    $totales = ['645' => max(0, $suma), '646' => max(0, -$suma)];
    foreach ($catalogo as $c) {
        $codigo = (string) $c['codigo'];
        if ($c['signo'] !== '=') {
            continue;
        }
        $montos[$codigo] = $totales[$codigo];
        $lineas[] = ['codigo' => $codigo, 'glosa' => $c['glosa'], 'signo' => '=', 'monto' => $totales[$codigo],
            'origen' => 'CALCULADO', 'naturaleza' => 'CALCULADO'];
    }

    $estado = ctrib_estado_recuadro($pdo, $empresa, $anio, 'R14');
    $bloqueos = $r12['estado'] === 'FINALIZADO' ? [] : ['Finalice el Recuadro 12 antes de finalizar el 14 (sus valores alimentan este recuadro).'];
    return [
        'recuadro' => 'R14', 'anio' => $anio, 'anioComercial' => ctrib_anio_comercial($anio),
        'lineas' => $lineas, 'totales' => $totales,
        'estado' => $estado['estado'], 'finalizado' => $estado['finalizado'], 'usuario' => $estado['usuario'],
        'desactualizado' => ctrib_desactualizado($estado, $montos),
        'bloqueos' => $bloqueos, 'puedeFinalizar' => $bloqueos === [] && $estado['estado'] !== 'FINALIZADO',
    ];
}

function ctrib_r14_guardar_valor(PDO $pdo, int $empresa, int $anio, string $codigo, mixed $monto, string $usuario): array
{
    if (is_numeric($monto) && (int) $monto < 0) {
        throw new CtribError('El monto no puede ser negativo: el signo lo pone el formulario.');
    }
    ctrib_guardar_valor_manual($pdo, $empresa, $anio, 'R14', $codigo, $monto, $usuario, CTRIB_R14_MANUALES);
    return ctrib_r14($pdo, $empresa, $anio);
}

function ctrib_r14_finalizar(PDO $pdo, int $empresa, int $anio, string $usuario): array
{
    $r = ctrib_r14($pdo, $empresa, $anio);
    if ($r['estado'] === 'FINALIZADO') {
        throw new CtribError('El Recuadro 14 ya está finalizado.');
    }
    if ($r['bloqueos'] !== []) {
        throw new CtribError(implode(' ', $r['bloqueos']));
    }
    ctrib_recuadro_finalizar($pdo, $empresa, $anio, 'R14', $r['lineas'], $usuario);
    return ctrib_r14($pdo, $empresa, $anio);
}

function ctrib_r14_reabrir(PDO $pdo, int $empresa, int $anio, string $usuario, string $motivo): array
{
    if (ctrib_estado_recuadro($pdo, $empresa, $anio, 'R14')['estado'] !== 'FINALIZADO') {
        throw new CtribError('El Recuadro 14 no está finalizado.');
    }
    ctrib_recuadro_reabrir($pdo, $empresa, $anio, 'R14', $usuario, $motivo);
    return ctrib_r14($pdo, $empresa, $anio);
}

/** Recuadro 13: Determinación del RAI (Art. 14 A). */
function ctrib_r13(PDO $pdo, int $empresa, int $anio): array
{
    $catalogo = json_decode((string) file_get_contents(__DIR__ . '/ctrib/recuadro13.json'), true);
    $r14 = ctrib_r14($pdo, $empresa, $anio);
    $m14 = array_column($r14['lineas'], 'monto', 'codigo');
    $manuales = ctrib_valores_manuales($pdo, $empresa, $anio, 'R13');

    $automaticos = [
        '1698' => [(int) ($m14['645'] ?? 0), 'R14'],
        '1717' => [(int) ($m14['646'] ?? 0), 'R14'],
        '1699' => [(int) ($m14['1182'] ?? 0), 'R14'],
    ];

    $montos = [];
    $origen = [];
    foreach ($catalogo as $c) {
        $codigo = (string) $c['codigo'];
        if ($c['signo'] === '=') {
            continue;
        }
        if (array_key_exists($codigo, $manuales)) {
            $montos[$codigo] = $manuales[$codigo];
            $origen[$codigo] = 'MANUAL';
        } elseif (isset($automaticos[$codigo])) {
            [$montos[$codigo], $origen[$codigo]] = $automaticos[$codigo];
        } else {
            $montos[$codigo] = 0;
            $origen[$codigo] = 'MANUAL';
        }
    }

    $subtotal = $montos['1698'] - $montos['1717'] + $montos['1692'] + $montos['1699'];
    $montos['1718'] = $montos['1717'] > 0 ? max(0, $subtotal) : $subtotal;
    $montos['1199'] = max(0, $montos['1718'] - $montos['1693'] - $montos['844'] - $montos['982'] - $montos['1198']);
    $origen['1718'] = 'CALCULADO';
    $origen['1199'] = 'CALCULADO';

    $lineas = [];
    foreach ($catalogo as $c) {
        $codigo = (string) $c['codigo'];
        $lineas[] = ['codigo' => $codigo, 'glosa' => $c['glosa'], 'signo' => $c['signo'],
            'monto' => $montos[$codigo], 'origen' => $origen[$codigo],
            'naturaleza' => in_array($codigo, CTRIB_R13_MANUALES, true) ? 'MANUAL' : 'CALCULADO'];
    }

    $estado = ctrib_estado_recuadro($pdo, $empresa, $anio, 'R13');
    $bloqueos = $r14['estado'] === 'FINALIZADO' ? [] : ['Finalice el Recuadro 14 antes de finalizar el 13 (el CPT final alimenta el RAI).'];
    return [
        'recuadro' => 'R13', 'anio' => $anio, 'anioComercial' => ctrib_anio_comercial($anio),
        'lineas' => $lineas, 'totales' => ['1718' => $montos['1718'], '1199' => $montos['1199']],
        'estado' => $estado['estado'], 'finalizado' => $estado['finalizado'], 'usuario' => $estado['usuario'],
        'desactualizado' => ctrib_desactualizado($estado, $montos),
        'bloqueos' => $bloqueos, 'puedeFinalizar' => $bloqueos === [] && $estado['estado'] !== 'FINALIZADO',
    ];
}

function ctrib_r13_guardar_valor(PDO $pdo, int $empresa, int $anio, string $codigo, mixed $monto, string $usuario): array
{
    if (is_numeric($monto) && (int) $monto < 0) {
        throw new CtribError('El monto no puede ser negativo: el signo lo pone el formulario.');
    }
    ctrib_guardar_valor_manual($pdo, $empresa, $anio, 'R13', $codigo, $monto, $usuario, CTRIB_R13_MANUALES);
    return ctrib_r13($pdo, $empresa, $anio);
}

function ctrib_r13_finalizar(PDO $pdo, int $empresa, int $anio, string $usuario): array
{
    $r = ctrib_r13($pdo, $empresa, $anio);
    if ($r['estado'] === 'FINALIZADO') {
        throw new CtribError('El Recuadro 13 ya está finalizado.');
    }
    if ($r['bloqueos'] !== []) {
        throw new CtribError(implode(' ', $r['bloqueos']));
    }
    ctrib_recuadro_finalizar($pdo, $empresa, $anio, 'R13', $r['lineas'], $usuario);
    return ctrib_r13($pdo, $empresa, $anio);
}

function ctrib_r13_reabrir(PDO $pdo, int $empresa, int $anio, string $usuario, string $motivo): array
{
    if (ctrib_estado_recuadro($pdo, $empresa, $anio, 'R13')['estado'] !== 'FINALIZADO') {
        throw new CtribError('El Recuadro 13 no está finalizado.');
    }
    ctrib_recuadro_reabrir($pdo, $empresa, $anio, 'R13', $usuario, $motivo);
    return ctrib_r13($pdo, $empresa, $anio);
}
