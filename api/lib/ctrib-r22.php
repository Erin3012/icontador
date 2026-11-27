<?php
// Recuadro 22 (base imponible del régimen de transparencia tributaria, art. 14 letra D) N° 8). Reglas: instrucciones
// del F22 AT 2026. Los conceptos que se homologan a códigos del R17 se trasladan al R22; las compras de activo fijo
// salen además de las cuentas de activo del balance; lo propio del 14 D N° 8 se escribe a mano.
declare(strict_types=1);

require_once __DIR__ . '/ctrib-r4-r6.php';

// Códigos que se pueden escribir a mano. El 1607, 1618 y 1627 tienen valor automático y el manual lo reemplaza.
const CTRIB_R22_MANUALES = ['1604', '1605', '1607', '1608', '1609', '1611', '1612', '1613', '1618', '1627'];
// Cuentas de activo que se toman como activo fijo, por su nombre.
const CTRIB_ACTIVO_FIJO = '/EQUIPO|MUEBLE|VEH[IÍ]CUL|MAQUINARIA|HERRAMIENTA|INSTALACI|COMPUTA|NOTEBOOK|EDIFICIO|CONSTRUCCI|ENSERES|ACTIVOS? FIJOS?/u';
const CTRIB_ACTIVO_FIJO_EXCLUIR = '/DEPREC|AMORTIZ|\bIVA\b|CR[EÉ]DITO|ANTICIPO|SEGURO|ARRIEND|LEASING|PR[EÉ]STAMO/u';

/** Compras de activo fijo según el balance: cargos del año en las cuentas de activo fijo, sin el saldo del año anterior. */
function ctrib_activo_fijo_del_anio(PDO $pdo, int $empresa, int $anio): array
{
    $balance = ctrib_balance_guardado($pdo, $empresa, $anio);
    $anterior = ctrib_balance_guardado($pdo, $empresa, $anio - 1);
    $saldoAnterior = array_column($anterior['cuentas'] ?? [], 'activo', 'codigo');
    $cuentas = [];
    foreach ($balance['cuentas'] ?? [] as $c) {
        $nombre = mb_strtoupper($c['nombre']);
        if ($c['tipo'] !== 'ACTIVO' || !preg_match(CTRIB_ACTIVO_FIJO, $nombre) || preg_match(CTRIB_ACTIVO_FIJO_EXCLUIR, $nombre)) {
            continue;
        }
        $compras = max(0, (int) $c['debitos'] - (int) ($saldoAnterior[$c['codigo']] ?? 0));
        if ($compras > 0) {
            $cuentas[] = ['cuenta' => $c['codigo'], 'nombre' => $c['nombre'], 'saldo' => $compras];
        }
    }
    return ['total' => array_sum(array_column($cuentas, 'saldo')), 'cuentas' => $cuentas, 'conAnterior' => $anterior !== null];
}

/** Líneas y totales del R22, sin la asignación a los propietarios (que a su vez usa estos totales). */
function ctrib_r22_calculo(PDO $pdo, int $empresa, int $anio): array
{
    $r17 = ctrib_r17($pdo, $empresa, $anio);
    $porR17 = array_column($r17['lineas'], null, 'codigo');
    $manuales = ctrib_valores_manuales($pdo, $empresa, $anio, 'R22');
    $activoFijo = ctrib_activo_fijo_del_anio($pdo, $empresa, $anio);
    $anterior = ctrib_estado_recuadro($pdo, $empresa, $anio - 1, 'R22');
    $perdidaAnterior = $anterior['estado'] === 'FINALIZADO' ? max(0, -(int) ($anterior['snapshot']['1630'] ?? 0)) : 0;

    $lineas = [];
    $montos = [];
    $totales = ['1610' => 0, '1629' => 0];
    foreach (ctrib_catalogo_recuadro('recuadro22.json') as $codigo => $c) {
        $codigo = (string) $codigo;
        $linea = ['codigo' => $codigo, 'glosa' => $c['glosa'], 'seccion' => $c['seccion'], 'signo' => $c['signo'],
            'naturaleza' => in_array($codigo, CTRIB_R22_MANUALES, true) ? 'MANUAL' : 'CALCULADO', 'monto' => 0, 'origen' => 'CALCULADO', 'cuentas' => []];
        if ($c['signo'] === '=') {
            $linea['monto'] = $codigo === '1630' ? $totales['1610'] - $totales['1629'] : $totales[$codigo];
        } else {
            foreach ($c['r17'] as $r17Codigo) {
                $origen = $porR17[$r17Codigo] ?? null;
                if ($origen !== null) {
                    $linea['monto'] += (int) $origen['monto'];
                    $linea['cuentas'] = array_merge($linea['cuentas'], $origen['cuentas']);
                }
            }
            if ($c['r17'] !== []) {
                $linea['origen'] = 'CUENTAS';
            } elseif ($linea['naturaleza'] === 'MANUAL') {
                $linea['origen'] = 'MANUAL';
            }
            if ($codigo === '1618' && $activoFijo['total'] > 0) {
                $linea['monto'] += $activoFijo['total'];
                $linea['cuentas'] = array_merge($linea['cuentas'], $activoFijo['cuentas']);
                $linea['origen'] = 'ACTIVO_FIJO';
            }
            if ($codigo === '1627' && $perdidaAnterior > 0) {
                $linea['monto'] = $perdidaAnterior;
                $linea['origen'] = 'AÑO_ANTERIOR';
            }
            if ($linea['naturaleza'] === 'MANUAL' && array_key_exists($codigo, $manuales)) {
                $linea['monto'] = $manuales[$codigo];
                $linea['origen'] = 'MANUAL';
                $linea['cuentas'] = [];
            }
            $totales[$c['seccion'] === 'INGRESOS' ? '1610' : '1629'] += $linea['monto'];
        }
        $montos[$codigo] = $linea['monto'];
        $lineas[] = $linea;
    }

    $homologacion = ctrib_homologacion($pdo, $empresa, $anio);
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
    // Instrucción del 1609: el crédito no excede el IDPC que habría gravado la base, y con pérdida no se anota.
    $tope = (int) round(max(0, $montos['1630']) * ctrib_tasa_pro_pyme(ctrib_anio_comercial($anio)) / 100, 0, PHP_ROUND_HALF_UP);
    if ($montos['1609'] > $tope) {
        $bloqueos[] = 'El crédito por activos fijos (1609) no puede superar $' . number_format($tope, 0, ',', '.')
            . ', el IDPC que habría gravado la base imponible; con pérdida tributaria no se anota.';
    }

    $avisos = [];
    if ($activoFijo['total'] > 0 && !array_key_exists('1618', $manuales)) {
        $avisos[] = 'El 1618 toma los cargos del año en las cuentas de activo fijo del balance'
            . ($activoFijo['conAnterior'] ? ', sin el saldo del año anterior' : '')
            . '. Si incluyen saldos de años anteriores o compras no pagadas, escriba el monto correcto.';
    }
    $rechazados = (int) ($porR17['1421']['monto'] ?? 0) + (int) ($porR17['1422']['monto'] ?? 0);
    if ($rechazados > 0) {
        $avisos[] = 'Los gastos rechazados del art. 21 ($' . number_format($rechazados, 0, ',', '.') . ') no se rebajan en el R22: se restan en el R23 (1577).';
    }

    return ['lineas' => $lineas, 'montos' => $montos, 'totales' => ['1610' => $totales['1610'], '1629' => $totales['1629'], '1630' => $montos['1630']],
        'bloqueos' => $bloqueos, 'avisos' => $avisos, 'gastosRechazados' => $rechazados];
}

/** Asignación de la base imponible y sus datos a cada propietario según su participación (datos de la DJ 1947). */
function ctrib_r22_propietarios(PDO $pdo, int $empresa, int $anio, array $montos): array
{
    $socios = ctrib_socios($pdo, $empresa, $anio);
    $porcentajes = array_column($socios, 'participacion');
    $retirado = [];
    foreach (ctrib_retiros_del_anio($pdo, $empresa, $anio)['retiros'] as $r) {
        $retirado[$r['rut']] = ($retirado[$r['rut']] ?? 0) + (int) $r['monto'];
    }
    $columnas = ['baseImponible' => max(0, $montos['1630']), 'ingresoDiferido' => $montos['1608'], 'creditoIdpc' => $montos['1605'], 'credito33bis' => $montos['1609']];
    $partes = array_map(fn($total) => ctrib_repartir((int) $total, $porcentajes), $columnas);
    $filas = [];
    foreach ($socios as $i => $s) {
        $fila = $s;
        foreach ($partes as $columna => $reparto) {
            $fila[$columna] = $reparto[$i] ?? 0;
        }
        $fila['retiros'] = $retirado[$s['rut']] ?? 0;
        $filas[] = $fila;
    }
    $avisos = [];
    if (!$socios) {
        $avisos[] = 'Registre los propietarios y su participación en «Propietarios y retiros» para asignar la base imponible.';
    } elseif (abs(array_sum($porcentajes) - 100) > 0.01) {
        $avisos[] = 'La participación de los propietarios no suma 100%: corríjala en «Propietarios y retiros».';
    } elseif (array_filter($socios, fn($s) => $s['rut'] === '')) {
        $avisos[] = 'Falta el RUT de un propietario: es necesario para la DJ 1947.';
    }
    return ['filas' => $filas, 'avisos' => $avisos];
}

/** Recuadro 22 completo del año. */
function ctrib_r22(PDO $pdo, int $empresa, int $anio): array
{
    $calculo = ctrib_r22_calculo($pdo, $empresa, $anio);
    $estado = ctrib_estado_recuadro($pdo, $empresa, $anio, 'R22');
    $finalizado = $estado['estado'] === 'FINALIZADO';
    return [
        'recuadro' => 'R22', 'anio' => $anio, 'anioComercial' => ctrib_anio_comercial($anio),
        'lineas' => $calculo['lineas'], 'totales' => $calculo['totales'],
        'propietarios' => ctrib_r22_propietarios($pdo, $empresa, $anio, $calculo['montos']),
        'estado' => $estado['estado'], 'finalizado' => $estado['finalizado'], 'usuario' => $estado['usuario'],
        'desactualizado' => ctrib_desactualizado($estado, $calculo['montos']),
        'avisos' => $calculo['avisos'], 'bloqueos' => $calculo['bloqueos'],
        'pendientesDeCalculo' => ['Dividendos (1604 y 1605), ingreso diferido (1608), crédito por activo fijo (1609) y gastos del cambio de régimen (1611 a 1613) se escriben a mano.'],
        'puedeFinalizar' => $calculo['bloqueos'] === [] && !$finalizado,
    ];
}

function ctrib_r22_guardar_valor(PDO $pdo, int $empresa, int $anio, string $codigo, mixed $monto, string $usuario): array
{
    if (is_numeric($monto) && (int) $monto < 0) {
        throw new CtribError('El monto no puede ser negativo: el signo lo pone el formulario.');
    }
    ctrib_guardar_valor_manual($pdo, $empresa, $anio, 'R22', $codigo, $monto, $usuario, CTRIB_R22_MANUALES);
    return ctrib_r22($pdo, $empresa, $anio);
}

function ctrib_r22_finalizar(PDO $pdo, int $empresa, int $anio, string $usuario): array
{
    $r = ctrib_r22($pdo, $empresa, $anio);
    if ($r['estado'] === 'FINALIZADO') {
        throw new CtribError('El Recuadro 22 ya está finalizado.');
    }
    if ($r['bloqueos'] !== []) {
        throw new CtribError(implode(' ', $r['bloqueos']));
    }
    ctrib_recuadro_finalizar($pdo, $empresa, $anio, 'R22', $r['lineas'], $usuario);
    return ctrib_r22($pdo, $empresa, $anio);
}

function ctrib_r22_reabrir(PDO $pdo, int $empresa, int $anio, string $usuario, string $motivo): array
{
    if (ctrib_estado_recuadro($pdo, $empresa, $anio, 'R22')['estado'] !== 'FINALIZADO') {
        throw new CtribError('El Recuadro 22 no está finalizado.');
    }
    ctrib_recuadro_reabrir($pdo, $empresa, $anio, 'R22', $usuario, $motivo);
    return ctrib_r22($pdo, $empresa, $anio);
}
