<?php
// Cuentas de ajuste CXC / CXP del Recuadro 17 (Pro Pyme, base percibida o pagada), como iTributario:
// por código, percibido o pagado = devengado + saldo inicial − saldo final de sus cuentas de ajuste (piso en cero).
// Reparto FIFO: lo cobrado o pagado se aplica primero al saldo inicial (lo devengado en años anteriores);
// esa parte se informa en el 1817 (ingresos, desde el 1400) o en el 1818 (egresos, desde 1409, 1411, 1412,
// 1413, 1415, 1416, 1424 y 1429) y el código queda con el resto.
declare(strict_types=1);

require_once __DIR__ . '/ctrib.php';

const CTRIB_FIFO_1817 = ['1400'];
const CTRIB_FIFO_1818 = ['1409', '1411', '1412', '1413', '1415', '1416', '1424', '1429'];

/** Códigos del R17 que admiten cuentas de ajuste, con su tipo (CXC o CXP). */
function ctrib_codigos_con_ajuste(): array
{
    $salida = [];
    foreach (ctrib_catalogo_r17() as $codigo => $c) {
        if (!empty($c['requiereCuentaAjuste']) && in_array($c['cuentaAjusteTipo'] ?? '', ['CXC', 'CXP'], true)) {
            $salida[(string) $codigo] = ['glosa' => $c['glosa'], 'tipo' => $c['cuentaAjusteTipo']];
        }
    }
    return $salida;
}

/** Saldo de una cuenta de ajuste en un balance: CXC (activo) deudor − acreedor; CXP (pasivo) acreedor − deudor. */
function ctrib_saldo_ajuste(?array $balance, string $cuenta, string $tipo): int
{
    foreach ($balance['cuentas'] ?? [] as $c) {
        if ((string) $c['codigo'] === $cuenta) {
            return $tipo === 'CXC' ? (int) ($c['deudor'] - $c['acreedor']) : (int) ($c['acreedor'] - $c['deudor']);
        }
    }
    return 0;
}

/**
 * Ajustes del año por código: cuentas, saldo inicial (sugerido desde el balance del año anterior) y final (sugerido
 * desde el balance del año), con su valor escrito a mano si lo hay. $devengados = código => devengado de sus cuentas.
 */
function ctrib_ajustes(PDO $pdo, int $empresa, int $anio, array $devengados = []): array
{
    $st = $pdo->prepare('SELECT cuenta, codigo FROM ctrib_ajuste_cuentas WHERE empresa_id = ? AND anio = ? ORDER BY cuenta');
    $st->execute([$empresa, $anio]);
    $cuentasPorCodigo = [];
    foreach ($st as $f) {
        $cuentasPorCodigo[(string) $f['codigo']][] = (string) $f['cuenta'];
    }
    $st = $pdo->prepare('SELECT codigo, saldo_inicial, saldo_final FROM ctrib_ajuste_saldos WHERE empresa_id = ? AND anio = ?');
    $st->execute([$empresa, $anio]);
    $manuales = [];
    foreach ($st as $f) {
        $manuales[(string) $f['codigo']] = [
            'inicial' => $f['saldo_inicial'] === null ? null : (int) $f['saldo_inicial'],
            'final' => $f['saldo_final'] === null ? null : (int) $f['saldo_final'],
        ];
    }
    $balance = ctrib_balance_guardado($pdo, $empresa, $anio);
    $anterior = ctrib_balance_guardado($pdo, $empresa, $anio - 1);
    $nombres = array_column($balance['cuentas'] ?? [], 'nombre', 'codigo');

    $salida = [];
    foreach (ctrib_codigos_con_ajuste() as $codigo => $info) {
        $codigo = (string) $codigo; // las claves numéricas de PHP vuelven como enteros
        $cuentas = $cuentasPorCodigo[$codigo] ?? [];
        $siSugerido = array_sum(array_map(fn($c) => ctrib_saldo_ajuste($anterior, $c, $info['tipo']), $cuentas));
        $sfSugerido = array_sum(array_map(fn($c) => ctrib_saldo_ajuste($balance, $c, $info['tipo']), $cuentas));
        $m = $manuales[$codigo] ?? ['inicial' => null, 'final' => null];
        $si = $m['inicial'] ?? $siSugerido;
        $sf = $m['final'] ?? $sfSugerido;
        $devengado = (int) ($devengados[$codigo] ?? 0);
        $bruto = $devengado + $si - $sf;
        $montoFinal = max(0, $bruto);
        $fifo = in_array($codigo, CTRIB_FIFO_1817, true) || in_array($codigo, CTRIB_FIFO_1818, true);
        $reparto = $fifo ? min(max(0, $si), $montoFinal) : 0;
        $salida[$codigo] = [
            'codigo' => $codigo, 'glosa' => $info['glosa'], 'tipo' => $info['tipo'],
            'cuentas' => array_map(fn($c) => ['cuenta' => $c, 'nombre' => (string) ($nombres[$c] ?? ''),
                'saldoInicial' => ctrib_saldo_ajuste($anterior, $c, $info['tipo']), 'saldoFinal' => ctrib_saldo_ajuste($balance, $c, $info['tipo'])], $cuentas),
            'saldoInicialSugerido' => $siSugerido, 'saldoFinalSugerido' => $sfSugerido,
            'saldoInicialManual' => $m['inicial'], 'saldoFinalManual' => $m['final'],
            'saldoInicial' => $si, 'saldoFinal' => $sf,
            'devengado' => $devengado, 'montoFinal' => $montoFinal, 'huboPisoEnCero' => $bruto < 0,
            'repartoAnioAnterior' => $reparto, 'montoCodigo' => $montoFinal - $reparto,
            'destinoReparto' => in_array($codigo, CTRIB_FIFO_1817, true) ? '1817' : (in_array($codigo, CTRIB_FIFO_1818, true) ? '1818' : null),
            'configurado' => $cuentas !== [] || $m['inicial'] !== null || $m['final'] !== null,
        ];
    }
    return $salida;
}

/** Cuentas de balance que pueden ser de ajuste: CXC entre las de activo, CXP entre las de pasivo. */
function ctrib_ajuste_cuentas_disponibles(PDO $pdo, int $empresa, int $anio): array
{
    $balance = ctrib_balance_guardado($pdo, $empresa, $anio);
    $st = $pdo->prepare('SELECT cuenta, codigo FROM ctrib_ajuste_cuentas WHERE empresa_id = ? AND anio = ?');
    $st->execute([$empresa, $anio]);
    $usadas = array_column($st->fetchAll(), 'codigo', 'cuenta');
    $st = $pdo->prepare('SELECT cuenta FROM ctrib_existencias_cuentas WHERE empresa_id = ? AND anio = ?');
    $st->execute([$empresa, $anio]);
    $existencias = array_flip(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN)));
    $salida = [];
    foreach ($balance['cuentas'] ?? [] as $c) {
        if (!in_array($c['tipo'], ['ACTIVO', 'PASIVO'], true)) {
            continue;
        }
        $tipo = $c['tipo'] === 'ACTIVO' ? 'CXC' : 'CXP';
        $salida[] = ['cuenta' => (string) $c['codigo'], 'nombre' => $c['nombre'], 'tipo' => $tipo,
            'saldo' => ctrib_saldo_ajuste($balance, (string) $c['codigo'], $tipo), 'codigo' => isset($usadas[$c['codigo']]) ? (string) $usadas[$c['codigo']] : null,
            'existencias' => isset($existencias[(string) $c['codigo']])];
    }
    return $salida;
}

/**
 * Guarda las cuentas de ajuste de un código y sus saldos escritos a mano (null = el sugerido).
 * Una cuenta de ajuste pertenece a un solo código; las CXC son de activo y las CXP de pasivo.
 */
function ctrib_ajuste_guardar(PDO $pdo, int $empresa, int $anio, string $codigo, array $cuentas, mixed $saldoInicial, mixed $saldoFinal, string $usuario): void
{
    $codigos = ctrib_codigos_con_ajuste();
    if (!isset($codigos[$codigo])) {
        throw new CtribError("El código $codigo no admite cuentas de ajuste.");
    }
    $estado = $pdo->prepare('SELECT estado FROM ctrib_recuadros WHERE empresa_id = ? AND anio = ? AND recuadro = ?');
    $estado->execute([$empresa, $anio, 'R17']);
    if ($estado->fetchColumn() === 'FINALIZADO') {
        throw new CtribError('El Recuadro 17 está finalizado. Reábralo para modificar las cuentas de ajuste.');
    }
    $tipo = $codigos[$codigo]['tipo'];
    $disponibles = [];
    foreach (ctrib_ajuste_cuentas_disponibles($pdo, $empresa, $anio) as $d) {
        $disponibles[$d['cuenta']] = $d;
    }
    $limpias = [];
    foreach ($cuentas as $cuenta) {
        $cuenta = trim((string) $cuenta);
        if ($cuenta === '') {
            continue;
        }
        $d = $disponibles[$cuenta] ?? null;
        if ($d === null) {
            throw new CtribError("La cuenta $cuenta no es una cuenta de activo o pasivo del balance del año.");
        }
        if ($d['tipo'] !== $tipo) {
            throw new CtribError("La cuenta $cuenta no sirve como ajuste $tipo: " . ($tipo === 'CXC' ? 'debe ser de activo.' : 'debe ser de pasivo.'));
        }
        if ($d['codigo'] !== null && $d['codigo'] !== $codigo) {
            throw new CtribError("La cuenta $cuenta ya es cuenta de ajuste del código {$d['codigo']}.");
        }
        if ($d['existencias']) {
            throw new CtribError("La cuenta $cuenta es cuenta de existencias del 1409.");
        }
        $limpias[$cuenta] = true;
    }
    $entero = function (mixed $v, string $nombre): ?int {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v) || (is_string($v) && preg_match('/^-?\d+$/', trim($v)))) {
            return (int) $v;
        }
        throw new CtribError("El $nombre debe ser un número entero.");
    };
    $si = $entero($saldoInicial, 'saldo inicial');
    $sf = $entero($saldoFinal, 'saldo final');
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM ctrib_ajuste_cuentas WHERE empresa_id = ? AND anio = ? AND codigo = ?')->execute([$empresa, $anio, $codigo]);
        $ins = $pdo->prepare('INSERT INTO ctrib_ajuste_cuentas (empresa_id, anio, cuenta, codigo) VALUES (?, ?, ?, ?)');
        foreach (array_keys($limpias) as $cuenta) {
            $ins->execute([$empresa, $anio, (string) $cuenta, $codigo]);
        }
        $pdo->prepare('DELETE FROM ctrib_ajuste_saldos WHERE empresa_id = ? AND anio = ? AND codigo = ?')->execute([$empresa, $anio, $codigo]);
        if ($si !== null || $sf !== null) {
            $pdo->prepare('INSERT INTO ctrib_ajuste_saldos (empresa_id, anio, codigo, saldo_inicial, saldo_final) VALUES (?, ?, ?, ?, ?)')
                ->execute([$empresa, $anio, $codigo, $si, $sf]);
        }
        $detalle = (count($limpias) ? implode(', ', array_keys($limpias)) : 'sin cuentas')
            . ($si !== null ? " · SI $si" : '') . ($sf !== null ? " · SF $sf" : '');
        ctrib_registrar_historial($pdo, $empresa, $anio, 'ajuste', "R17 $codigo", '', $detalle, "Cuentas de ajuste $tipo", $usuario);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ---------- Cuentas de existencias del 1409 (como iTributario, PRD_Recuadro17_Codigo1409Existencias) ----------
// El 1409 admite, además de sus cuentas de resultado, cuentas de existencias (activo). Su devengado suma la variación
// del año: compras = costo de ventas + existencia final − existencia inicial. Si el 1409 tiene cuentas de ajuste CXP,
// el pagado se calcula sobre ese devengado.

/** Existencias del año: cuentas, saldos sugeridos (balance anterior y actual), manuales y la variación. */
function ctrib_existencias(PDO $pdo, int $empresa, int $anio): array
{
    $st = $pdo->prepare('SELECT cuenta FROM ctrib_existencias_cuentas WHERE empresa_id = ? AND anio = ? ORDER BY cuenta');
    $st->execute([$empresa, $anio]);
    $cuentas = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    $st = $pdo->prepare('SELECT saldo_inicial, saldo_final FROM ctrib_existencias_saldos WHERE empresa_id = ? AND anio = ?');
    $st->execute([$empresa, $anio]);
    $f = $st->fetch() ?: [];
    $siManual = isset($f['saldo_inicial']) && $f['saldo_inicial'] !== null ? (int) $f['saldo_inicial'] : null;
    $sfManual = isset($f['saldo_final']) && $f['saldo_final'] !== null ? (int) $f['saldo_final'] : null;
    $balance = ctrib_balance_guardado($pdo, $empresa, $anio);
    $anterior = ctrib_balance_guardado($pdo, $empresa, $anio - 1);
    $nombres = array_column($balance['cuentas'] ?? [], 'nombre', 'codigo');
    $siSugerido = array_sum(array_map(fn($c) => ctrib_saldo_ajuste($anterior, $c, 'CXC'), $cuentas));
    $sfSugerido = array_sum(array_map(fn($c) => ctrib_saldo_ajuste($balance, $c, 'CXC'), $cuentas));
    $si = $siManual ?? $siSugerido;
    $sf = $sfManual ?? $sfSugerido;
    return [
        'codigo' => '1409',
        'cuentas' => array_map(fn($c) => ['cuenta' => $c, 'nombre' => (string) ($nombres[$c] ?? ''),
            'saldoInicial' => ctrib_saldo_ajuste($anterior, $c, 'CXC'), 'saldoFinal' => ctrib_saldo_ajuste($balance, $c, 'CXC')], $cuentas),
        'saldoInicialSugerido' => $siSugerido, 'saldoFinalSugerido' => $sfSugerido,
        'saldoInicialManual' => $siManual, 'saldoFinalManual' => $sfManual,
        'saldoInicial' => $si, 'saldoFinal' => $sf, 'variacion' => $sf - $si,
        'configurado' => $cuentas !== [] || $siManual !== null || $sfManual !== null,
    ];
}

/** Motivo que impide finalizar: sin balance anterior ni saldo escrito, la existencia inicial no se conoce. */
function ctrib_existencias_bloqueo(PDO $pdo, int $empresa, int $anio): ?string
{
    $e = ctrib_existencias($pdo, $empresa, $anio);
    if ($e['cuentas'] === [] || $e['saldoInicialManual'] !== null || ctrib_balance_guardado($pdo, $empresa, $anio - 1) !== null) {
        return null;
    }
    return 'No hay balance del año anterior: escriba la existencia inicial del 1409 (0 si no había) o importe ese balance.';
}

/** Guarda las cuentas de existencias del 1409 (de activo, que no sean cuentas de ajuste CXC) y sus saldos manuales. */
function ctrib_existencias_guardar(PDO $pdo, int $empresa, int $anio, array $cuentas, mixed $saldoInicial, mixed $saldoFinal, string $usuario): void
{
    $estado = $pdo->prepare('SELECT estado FROM ctrib_recuadros WHERE empresa_id = ? AND anio = ? AND recuadro = ?');
    $estado->execute([$empresa, $anio, 'R17']);
    if ($estado->fetchColumn() === 'FINALIZADO') {
        throw new CtribError('El Recuadro 17 está finalizado. Reábralo para modificar las cuentas de existencias.');
    }
    $disponibles = [];
    foreach (ctrib_ajuste_cuentas_disponibles($pdo, $empresa, $anio) as $d) {
        $disponibles[$d['cuenta']] = $d;
    }
    $limpias = [];
    foreach ($cuentas as $cuenta) {
        $cuenta = trim((string) $cuenta);
        if ($cuenta === '') {
            continue;
        }
        $d = $disponibles[$cuenta] ?? null;
        if ($d === null || $d['tipo'] !== 'CXC') {
            throw new CtribError("La cuenta $cuenta no es una cuenta de activo del balance del año: las existencias son de activo.");
        }
        if ($d['codigo'] !== null) {
            throw new CtribError("La cuenta $cuenta ya es cuenta de ajuste del código {$d['codigo']}.");
        }
        $limpias[$cuenta] = true;
    }
    $entero = function (mixed $v, string $nombre): ?int {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v) || (is_string($v) && preg_match('/^-?\d+$/', trim($v)))) {
            return (int) $v;
        }
        throw new CtribError("El $nombre de existencias debe ser un número entero.");
    };
    $si = $entero($saldoInicial, 'saldo inicial');
    $sf = $entero($saldoFinal, 'saldo final');
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM ctrib_existencias_cuentas WHERE empresa_id = ? AND anio = ?')->execute([$empresa, $anio]);
        $ins = $pdo->prepare('INSERT INTO ctrib_existencias_cuentas (empresa_id, anio, cuenta) VALUES (?, ?, ?)');
        foreach (array_keys($limpias) as $cuenta) {
            $ins->execute([$empresa, $anio, (string) $cuenta]);
        }
        $pdo->prepare('DELETE FROM ctrib_existencias_saldos WHERE empresa_id = ? AND anio = ?')->execute([$empresa, $anio]);
        if ($si !== null || $sf !== null) {
            $pdo->prepare('INSERT INTO ctrib_existencias_saldos (empresa_id, anio, saldo_inicial, saldo_final) VALUES (?, ?, ?, ?)')
                ->execute([$empresa, $anio, $si, $sf]);
        }
        $detalle = (count($limpias) ? implode(', ', array_keys($limpias)) : 'sin cuentas')
            . ($si !== null ? " · SI $si" : '') . ($sf !== null ? " · SF $sf" : '');
        ctrib_registrar_historial($pdo, $empresa, $anio, 'ajuste', 'R17 1409 existencias', '', $detalle, 'Cuentas de existencias del 1409', $usuario);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
