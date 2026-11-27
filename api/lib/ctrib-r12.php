<?php
// Recuadro 12 (Base Imponible / RLI régimen Semi Integrado, art. 14 letra A) LIR) de Cifrax Tributario.
// Reglas: instrucciones del F22 AT 2026 y ejercicio oficial 14 A (Sociedad Fénix Ltda).
declare(strict_types=1);

require_once __DIR__ . '/ctrib-r17.php';

const CTRIB_TASA_SEMI_INTEGRADO_DEFECTO = 27.0;

/** Códigos manuales del R12 (todos los ajustes y rebajas de RLI). */
function ctrib_r12_manuales(): array
{
    static $manuales = null;
    if ($manuales === null) {
        $manuales = [];
        foreach (ctrib_catalogo_r12() as $codigo => $c) {
            if ($c['naturalezaConfiguracion'] === 'MANUAL') {
                $manuales[] = (string) $codigo;
            }
        }
    }
    return $manuales;
}

/** Tasa del IDPC Semi Integrado del año comercial (tasas-AAAA.json), o 27% por defecto. */
function ctrib_tasa_semi_integrado(int $anioComercial): float
{
    $ruta = __DIR__ . '/ctrib/tasas-' . $anioComercial . '.json';
    if (is_file($ruta)) {
        $datos = json_decode((string) file_get_contents($ruta), true);
        if (isset($datos['tasaSemiIntegrado'])) {
            return (float) $datos['tasaSemiIntegrado'];
        }
    }
    return CTRIB_TASA_SEMI_INTEGRADO_DEFECTO;
}

/** Cálculo completo del R12 del año: resultado financiero, ajustes, incentivo al ahorro 14 E, RLI e IDPC estimado. */
function ctrib_r12(PDO $pdo, int $empresa, int $anio): array
{
    $catalogo = ctrib_catalogo_r12();
    $homologacion = ctrib_homologacion($pdo, $empresa, $anio);
    $manuales = ctrib_valores_manuales($pdo, $empresa, $anio, 'R12');
    $cuentasPorCodigo = [];
    foreach ($homologacion['codigos'] as $c) {
        $cuentasPorCodigo[$c['codigo']] = $c['cuentas'];
    }

    $montos = [];
    $lineas = [];
    foreach ($catalogo as $codigo => $c) {
        $codigo = (string) $codigo;
        $linea = [
            'codigo' => $codigo, 'glosa' => $c['glosa'], 'seccion' => $c['seccion'], 'grupo' => $c['grupo'],
            'signo' => $c['signo'], 'naturaleza' => $c['naturalezaConfiguracion'],
            'monto' => 0, 'origen' => 'CALCULADO', 'cuentas' => [],
        ];
        if ($c['naturalezaConfiguracion'] === 'CUENTA_DEPENDIENTE') {
            $cuentas = $cuentasPorCodigo[$codigo] ?? [];
            $linea['monto'] = array_sum(array_column($cuentas, 'saldo'));
            $linea['origen'] = 'CUENTAS';
            $linea['cuentas'] = array_map(fn($x) => ['cuenta' => $x['cuenta'], 'nombre' => $x['nombre'], 'saldo' => $x['saldo']], $cuentas);
        } elseif ($c['naturalezaConfiguracion'] === 'MANUAL') {
            $linea['monto'] = $manuales[$codigo] ?? 0;
            $linea['origen'] = 'MANUAL';
        }
        $montos[$codigo] = $linea['monto'];
        $lineas[$codigo] = $linea;
    }

    // Si no se ingresaron manualmente, la depreciación financiera (1675) y donaciones (1677) se reponen desde resultado (1663, 1665).
    foreach (['1675' => '1663', '1677' => '1665'] as $ajuste => $gasto) {
        if (!array_key_exists($ajuste, $manuales) && ($montos[$gasto] ?? 0) > 0) {
            $montos[$ajuste] = $montos[$gasto];
            $lineas[$ajuste]['monto'] = $montos[$gasto];
            $lineas[$ajuste]['origen'] = 'AUTOMATICO';
        }
    }

    $resultadoFin = 0;
    $ajustesNeto = 0;
    foreach ($catalogo as $codigo => $c) {
        $codigo = (string) $codigo;
        if ($c['signo'] === '=') {
            continue;
        }
        $val = $c['signo'] === '+' ? $montos[$codigo] : -$montos[$codigo];
        if ($c['seccion'] === 'RESULTADO_FINANCIERO') {
            $resultadoFin += $val;
        } elseif ($c['seccion'] === 'AJUSTES') {
            $ajustesNeto += $val;
        }
    }

    $m1728 = $resultadoFin + $ajustesNeto;
    $retiros = ctrib_retiros_total($pdo, $empresa, $anio);
    $uf = ctrib_uf(ctrib_anio_comercial($anio));
    $baseReinvertida = max(0, $m1728 - ($montos['1144'] ?? 0) - $retiros);
    $topeMitad = $baseReinvertida * 0.5;
    $topeUf = $uf !== null ? CTRIB_R17_TOPE_UF * $uf : null;
    $topeIncentivo = (int) round($topeUf !== null ? min($topeMitad, $topeUf) : $topeMitad, 0, PHP_ROUND_HALF_UP);

    $m1154 = $montos['1154'] ?? 0;
    $m1157 = $montos['1157'] ?? 0;
    $m1690 = $m1728 - $m1154 - $m1157;

    foreach (['1672' => $resultadoFin, '1728' => $m1728, '1690' => $m1690] as $codigo => $valor) {
        $montos[$codigo] = $valor;
        $lineas[$codigo]['monto'] = $valor;
    }

    $tasa = ctrib_tasa_semi_integrado(ctrib_anio_comercial($anio));
    $baseIdpc = max(0, $m1690);
    $idpcEstimado = ['tasa' => $tasa, 'base' => $baseIdpc, 'monto' => (int) round($baseIdpc * $tasa / 100, 0, PHP_ROUND_HALF_UP)];

    $estado = ctrib_estado_recuadro($pdo, $empresa, $anio, 'R12');
    $actual = array_map(fn($x) => $x['monto'], $lineas);
    $desactualizado = ctrib_desactualizado($estado, $actual);

    $bloqueos = [];
    if (ctrib_balance_guardado($pdo, $empresa, $anio) === null) {
        $bloqueos[] = 'Importe el balance del año: sin balance no se calcula el recuadro.';
    }
    if ($homologacion['pendientes'] > 0) {
        $bloqueos[] = "Hay {$homologacion['pendientes']} cuenta(s) de resultado sin código: asígnelas en la homologación.";
    }
    if ($homologacion['alertas'] > 0) {
        $bloqueos[] = "Hay {$homologacion['alertas']} cuenta(s) con código incompatible: corríjalas en la homologación.";
    }
    if ($m1154 > 0 && $topeUf !== null && $m1154 > (int) round($topeUf, 0, PHP_ROUND_HALF_UP)) {
        $bloqueos[] = 'El incentivo al ahorro (1154) excede el tope legal de 5.000 UF ($' . number_format((int) round($topeUf, 0, PHP_ROUND_HALF_UP), 0, ',', '.') . ').';
    }

    return [
        'recuadro' => 'R12', 'anio' => $anio, 'anioComercial' => ctrib_anio_comercial($anio),
        'lineas' => array_values($lineas),
        'totales' => ['1672' => $resultadoFin, '1728' => $m1728, '1154' => $m1154, '1690' => $m1690],
        'incentivoTope' => $topeIncentivo,
        'idpcEstimado' => $idpcEstimado,
        'estado' => $estado['estado'], 'finalizado' => $estado['finalizado'], 'usuario' => $estado['usuario'],
        'desactualizado' => $desactualizado,
        'bloqueos' => $bloqueos,
        'puedeFinalizar' => $bloqueos === [] && $estado['estado'] !== 'FINALIZADO',
    ];
}

function ctrib_r12_guardar_valor(PDO $pdo, int $empresa, int $anio, string $codigo, mixed $monto, string $usuario): array
{
    if (is_numeric($monto) && (int) $monto < 0) {
        throw new CtribError('El monto no puede ser negativo: el signo lo pone el formulario.');
    }
    ctrib_guardar_valor_manual($pdo, $empresa, $anio, 'R12', $codigo, $monto, $usuario, ctrib_r12_manuales());
    return ctrib_r12($pdo, $empresa, $anio);
}

function ctrib_r12_finalizar(PDO $pdo, int $empresa, int $anio, string $usuario): array
{
    $r = ctrib_r12($pdo, $empresa, $anio);
    if ($r['estado'] === 'FINALIZADO') {
        throw new CtribError('El Recuadro 12 ya está finalizado.');
    }
    if ($r['bloqueos'] !== []) {
        throw new CtribError(implode(' ', $r['bloqueos']));
    }
    ctrib_recuadro_finalizar($pdo, $empresa, $anio, 'R12', $r['lineas'], $usuario);
    return ctrib_r12($pdo, $empresa, $anio);
}

function ctrib_r12_reabrir(PDO $pdo, int $empresa, int $anio, string $usuario, string $motivo): array
{
    if (ctrib_estado_recuadro($pdo, $empresa, $anio, 'R12')['estado'] !== 'FINALIZADO') {
        throw new CtribError('El Recuadro 12 no está finalizado.');
    }
    ctrib_recuadro_reabrir($pdo, $empresa, $anio, 'R12', $usuario, $motivo);
    return ctrib_r12($pdo, $empresa, $anio);
}
