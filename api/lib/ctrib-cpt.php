<?php
// Capital Propio Tributario a partir del Activo (CPT Activo) de Cifrax Tributario, como en iTributario:
// cada cuenta de balance se asigna a Valor INTO, Ajuste Tributario o Pasivo Exigible; la determinación es
// total activo − valores INTO − ajustes = activo depurado; activo depurado − pasivos exigibles = capital propio.
declare(strict_types=1);

require_once __DIR__ . '/ctrib-r19.php';

const CTRIB_CPT_CONCEPTOS = ['INTO' => 'Valor INTO', 'AJUSTE' => 'Ajuste Tributario', 'PASIVO' => 'Pasivo Exigible'];

/** Configuración de cuentas del año; si no hay, la configuración maestra de la empresa (sugerida). */
function ctrib_cpt_configuracion(PDO $pdo, int $empresa, int $anio): array
{
    $st = $pdo->prepare('SELECT cuenta, concepto FROM ctrib_cpt_cuentas WHERE empresa_id = ? AND anio = ?');
    $st->execute([$empresa, $anio]);
    $anual = array_column($st->fetchAll(), 'concepto', 'cuenta');
    if ($anual) {
        return ['origen' => 'ANIO', 'conceptos' => array_map('strval', $anual)];
    }
    $st = $pdo->prepare('SELECT cuenta, concepto FROM ctrib_cpt_maestro WHERE empresa_id = ?');
    $st->execute([$empresa]);
    $maestro = array_column($st->fetchAll(), 'concepto', 'cuenta');
    return $maestro ? ['origen' => 'MAESTRO', 'conceptos' => array_map('strval', $maestro)] : ['origen' => 'NINGUNA', 'conceptos' => []];
}

function ctrib_cpt(PDO $pdo, int $empresa, int $anio): array
{
    $balance = ctrib_balance_guardado($pdo, $empresa, $anio);
    $config = ctrib_cpt_configuracion($pdo, $empresa, $anio);
    $cuentas = [];
    $grupos = ['INTO' => [], 'AJUSTE' => [], 'PASIVO' => []];
    foreach ($balance['cuentas'] ?? [] as $c) {
        if (!in_array($c['tipo'], CTRIB_TIPOS_BALANCE, true)) {
            continue;
        }
        $saldo = $c['tipo'] === 'ACTIVO' ? $c['deudor'] - $c['acreedor'] : $c['acreedor'] - $c['deudor'];
        $concepto = $config['conceptos'][(string) $c['codigo']] ?? null;
        $cuentas[] = ['cuenta' => (string) $c['codigo'], 'nombre' => $c['nombre'], 'tipo' => $c['tipo'], 'saldo' => $saldo, 'concepto' => $concepto];
        if ($concepto !== null) {
            $grupos[$concepto][] = ['cuenta' => (string) $c['codigo'], 'nombre' => $c['nombre'], 'monto' => $saldo];
        }
    }
    $totalActivo = (int) ($balance['totales']['activo'] ?? 0);
    $subtotal = fn(string $g) => array_sum(array_column($grupos[$g], 'monto'));
    $activoDepurado = $totalActivo - $subtotal('INTO') - $subtotal('AJUSTE');
    $capitalPropio = $activoDepurado - $subtotal('PASIVO');
    $r19 = ctrib_r19($pdo, $empresa, $anio);
    $cpts = (int) $r19['totales']['1545'] - (int) $r19['totales']['1546'];
    return [
        'anio' => $anio, 'anioComercial' => ctrib_anio_comercial($anio),
        'origenConfiguracion' => $config['origen'],
        'configuradas' => count(array_filter($cuentas, fn($c) => $c['concepto'] !== null)),
        'cuentas' => $cuentas,
        'totalActivo' => $totalActivo,
        'valoresInto' => $grupos['INTO'], 'subtotalInto' => $subtotal('INTO'),
        'ajustesTributarios' => $grupos['AJUSTE'], 'subtotalAjustes' => $subtotal('AJUSTE'),
        'totalActivoDepurado' => $activoDepurado,
        'pasivosExigibles' => $grupos['PASIVO'], 'subtotalPasivos' => $subtotal('PASIVO'),
        'capitalPropioDeterminado' => $capitalPropio,
        'cptsRegimen' => $cpts, 'diferencia' => $capitalPropio - $cpts,
        'avisos' => array_values(array_filter([
            $config['origen'] === 'NINGUNA' ? 'Debe configurar las cuentas primero. Asigne cada cuenta a Valor INTO, Ajuste Tributario o Pasivo Exigible.' : null,
            $config['origen'] === 'MAESTRO' ? 'Se sugiere la configuración maestra de la empresa: guárdela para este año.' : null,
        ])),
    ];
}

/** Guarda la configuración de cuentas del año (concepto vacío = sin asignar) y, si se pide, también como maestra. */
function ctrib_cpt_guardar(PDO $pdo, int $empresa, int $anio, array $asignaciones, bool $maestro, string $usuario): array
{
    $balance = ctrib_balance_guardado($pdo, $empresa, $anio);
    if (!$balance) {
        throw new CtribError('Aún no hay balance para este año.');
    }
    $validas = [];
    foreach ($balance['cuentas'] as $c) {
        if (in_array($c['tipo'], CTRIB_TIPOS_BALANCE, true)) {
            $validas[(string) $c['codigo']] = true;
        }
    }
    $limpias = [];
    foreach ($asignaciones as $cuenta => $concepto) {
        $cuenta = (string) $cuenta;
        $concepto = (string) ($concepto ?? '');
        if (!isset($validas[$cuenta])) {
            throw new CtribError("La cuenta $cuenta no es una cuenta de balance del año.");
        }
        if ($concepto === '') {
            continue;
        }
        if (!isset(CTRIB_CPT_CONCEPTOS[$concepto])) {
            throw new CtribError("Concepto no válido para la cuenta $cuenta.");
        }
        $limpias[$cuenta] = $concepto;
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM ctrib_cpt_cuentas WHERE empresa_id = ? AND anio = ?')->execute([$empresa, $anio]);
        $ins = $pdo->prepare('INSERT INTO ctrib_cpt_cuentas (empresa_id, anio, cuenta, concepto) VALUES (?, ?, ?, ?)');
        foreach ($limpias as $cuenta => $concepto) {
            $ins->execute([$empresa, $anio, $cuenta, $concepto]);
        }
        if ($maestro) {
            $pdo->prepare('DELETE FROM ctrib_cpt_maestro WHERE empresa_id = ?')->execute([$empresa]);
            $insM = $pdo->prepare('INSERT INTO ctrib_cpt_maestro (empresa_id, cuenta, concepto) VALUES (?, ?, ?)');
            foreach ($limpias as $cuenta => $concepto) {
                $insM->execute([$empresa, $cuenta, $concepto]);
            }
        }
        ctrib_registrar_historial($pdo, $empresa, $anio, 'cpt', 'configuración', '', count($limpias) . ' cuentas' . ($maestro ? ' (también maestro)' : ''), 'Configuración de CPT Activo', $usuario);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ctrib_cpt($pdo, $empresa, $anio);
}
