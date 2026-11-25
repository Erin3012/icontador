<?php
// RTRE (R20) de Cifrax Tributario, Registro SAC (R21) y DJ 1948 con certificados.
// Fórmulas de la ayuda legal del RTRE Pro Pyme: RAI (4.2), reverso del subtotal 3 (4.1), IDPC del ejercicio en el SAC (4.5),
// subtotales y remanente. Validado contra iTributario (ENYEL AT 2026, captura del 25-11-2026).
// Pendiente de validación del contador: saldos iniciales de años anteriores sin RTRE finalizado, capital aportado y FUR (4.2),
// retiros que exceden el RAI (imputación a REX y SAC), y DDAN, REX/INR del ejercicio y créditos de IDPC voluntario.
declare(strict_types=1);

require_once __DIR__ . '/ctrib-r19.php';

const CTRIB_RTRE_COLUMNA_IDPC = 'SAC_NO_RESTITUCION_DESDE_2020_CON_DEVOLUCION';
const CTRIB_RTRE_TASA_DEFECTO = 12.5; // tasa Pro Pyme vigente para 2025 (tasas-2025.json)

/** Filas del RTRE en orden del catálogo, indexadas por código de fila (1.1 … 6.4, SUBTOTAL_x, REMANENTE_FINAL). */
function ctrib_rtre_catalogo(): array
{
    static $catalogo = null;
    if ($catalogo === null) {
        $datos = json_decode((string) file_get_contents(__DIR__ . '/ctrib/rtre.json'), true);
        $catalogo = [];
        foreach ($datos ?: [] as $fila) {
            $catalogo[$fila['codigoFila']] = $fila;
        }
    }
    return $catalogo;
}

function ctrib_rtre_columnas(): array
{
    $columnas = [];
    foreach (ctrib_rtre_catalogo() as $fila) {
        foreach ($fila['celdas'] as $ce) {
            $columnas[$ce['columna']] = true;
        }
    }
    return array_keys($columnas);
}

function ctrib_tasa_pro_pyme(int $anioComercial): float
{
    $ruta = __DIR__ . '/ctrib/tasas-' . $anioComercial . '.json';
    if (is_file($ruta)) {
        $datos = json_decode((string) file_get_contents($ruta), true);
        if (isset($datos['tasaProPyme'])) {
            return (float) $datos['tasaProPyme'];
        }
    }
    return CTRIB_RTRE_TASA_DEFECTO;
}

/** Celdas escritas a mano: 'fila::columna' => monto. */
function ctrib_rtre_celdas_manuales(PDO $pdo, int $empresa, int $anio): array
{
    $st = $pdo->prepare('SELECT fila, columna, monto FROM ctrib_rtre_celdas WHERE empresa_id = ? AND anio = ?');
    $st->execute([$empresa, $anio]);
    $salida = [];
    foreach ($st as $f) {
        $salida[$f['fila'] . '::' . $f['columna']] = (int) $f['monto'];
    }
    return $salida;
}

/** Remanente final del RTRE del año anterior, si está finalizado. Clave 'REMANENTE_FINAL::columna'. */
function ctrib_rtre_anterior(PDO $pdo, int $empresa, int $anio): ?array
{
    $estado = ctrib_estado_recuadro($pdo, $empresa, $anio, 'R20');
    return $estado['estado'] === 'FINALIZADO' ? $estado['snapshot'] : null;
}

/** Cálculo completo del RTRE del año: celdas, subtotales, remanente, fórmulas del RAI y del IDPC. */
function ctrib_r20(PDO $pdo, int $empresa, int $anio): array
{
    $catalogo = ctrib_rtre_catalogo();
    $columnas = ctrib_rtre_columnas();
    $r17 = ctrib_r17($pdo, $empresa, $anio);
    $r19 = ctrib_r19($pdo, $empresa, $anio);
    $manuales = ctrib_rtre_celdas_manuales($pdo, $empresa, $anio);
    $anterior = ctrib_rtre_anterior($pdo, $empresa, $anio - 1);
    $retiros = ctrib_retiros_del_anio($pdo, $empresa, $anio)['total'];

    // Fórmula del RAI (4.2): capital propio tributario simplificado + retiros del ejercicio + saldo negativo REX, menos lo que corresponda.
    $cptPositivo = (int) $r19['totales']['1545'];
    $cptNegativo = (int) $r19['totales']['1546'];
    $rexNegativoAbs = 0;        // REX negativo: pendiente
    $rexPositivoPrevio = 0;     // pendiente
    $capitalAportado = 0;       // pendiente (1494 y variaciones)
    $saldoFur = 0;              // pendiente
    $sobreprecio = 0;           // pendiente
    $subtotalRai = $cptPositivo - $cptNegativo + $retiros + $rexNegativoAbs;
    $rai = max(0, $subtotalRai - $rexPositivoPrevio - $capitalAportado - $saldoFur - $sobreprecio);

    // IDPC del ejercicio (4.5): tasa Pro Pyme sobre la base imponible del 1440 del R17.
    $rli = max(0, (int) $r17['totales']['1440']);
    $tasa = ctrib_tasa_pro_pyme($anio - 1);
    $idpc = (int) round($rli * $tasa / 100, 0, PHP_ROUND_HALF_UP);

    // Celdas de las filas base.
    $celdas = [];
    foreach ($catalogo as $fila => $f) {
        foreach ($f['celdas'] as $ce) {
            $col = $ce['columna'];
            $clave = $fila . '::' . $col;
            if (array_key_exists($clave, $manuales)) {
                $monto = $manuales[$clave];
                $origen = 'MANUAL';
            } elseif ($fila === '1.1') {
                $monto = (int) ($anterior["REMANENTE_FINAL::$col"] ?? 0);
                $origen = $anterior !== null ? 'AÑO_ANTERIOR' : 'SIN_ANTERIOR_FINALIZADO';
            } elseif ($clave === '4.2::RAI') {
                $monto = $rai;
                $origen = 'AUTO';
            } elseif ($clave === '4.5::' . CTRIB_RTRE_COLUMNA_IDPC) {
                $monto = $idpc;
                $origen = 'AUTO';
            } elseif ($f['naturalezaConfiguracion'] === 'MANUAL') {
                $monto = 0;
                $origen = 'MANUAL';
            } else {
                $monto = 0;
                $origen = 'AUTO'; // sin fuente en Cifrax todavía (pendiente)
            }
            $celdas[$fila][$col] = ['monto' => (int) $monto, 'origen' => $origen];
        }
    }

    $suma = function (array $bloques, string $col) use (&$celdas, $catalogo): int {
        $total = 0;
        foreach ($catalogo as $fila => $f) {
            if (in_array((int) $f['bloque'], $bloques, true) && isset($celdas[$fila][$col])) {
                $total += $celdas[$fila][$col]['monto'];
            }
        }
        return $total;
    };

    // Reverso del subtotal N°3 (4.1): si el RAI del subtotal 3 es positivo, se reversa.
    $s3Rai = $suma([1, 2, 3], 'RAI');
    $celdas['4.1']['RAI'] = ['monto' => -max(0, $s3Rai), 'origen' => 'AUTO'];

    // Retiros del ejercicio imputados al RAI (6.1): hasta el saldo del RAI disponible tras los bloques 4 y 5.
    $s5Rai = $suma([1, 2, 3, 4, 5], 'RAI');
    $retirosRai = min($retiros, max(0, $s5Rai));
    $celdas['6.1']['RAI'] = ['monto' => $retirosRai, 'origen' => 'AUTO'];
    $retirosNoImputados = $retiros - $retirosRai;

    // Subtotales y remanente por columna.
    $subtotales = [];
    foreach ($columnas as $col) {
        $s1 = $suma([1], $col);
        $s2 = $s1 + $suma([2], $col);
        $s3 = $s2 + $suma([3], $col);
        $s5 = $s3 + $suma([4], $col) + $suma([5], $col);
        $remanente = $s5 - $suma([6], $col);
        $subtotales['SUBTOTAL_1'][$col] = $s1;
        $subtotales['SUBTOTAL_2'][$col] = $s2;
        $subtotales['SUBTOTAL_3'][$col] = $s3;
        $subtotales['SUBTOTAL_5'][$col] = $s5;
        $subtotales['REMANENTE_FINAL'][$col] = $remanente;
    }
    foreach ($subtotales as $fila => $porCol) {
        foreach ($porCol as $col => $monto) {
            $celdas[$fila][$col] = ['monto' => (int) $monto, 'origen' => 'RESULTADO'];
        }
    }

    // Lista completa (para el snapshot al finalizar) y lista de celdas no nulas (para mostrar).
    $todas = [];
    $noNulas = [];
    foreach ($catalogo as $fila => $f) {
        foreach ($f['celdas'] as $ce) {
            $col = $ce['columna'];
            $c = $celdas[$fila][$col] ?? ['monto' => 0, 'origen' => 'AUTO'];
            $todas[] = ['codigo' => $fila . '::' . $col, 'monto' => $c['monto']];
            if ($c['monto'] !== 0) {
                $noNulas[] = ['fila' => $fila, 'glosa' => $f['glosaCorta'], 'bloque' => (int) $f['bloque'], 'columna' => $col, 'monto' => $c['monto'], 'origen' => $c['origen'],
                    'naturaleza' => $f['naturalezaConfiguracion']];
            }
        }
    }

    $estado = ctrib_estado_recuadro($pdo, $empresa, $anio, 'R20');
    $snapshotActual = array_column($todas, 'monto', 'codigo');
    $bloqueos = [];
    if (ctrib_estado_recuadro($pdo, $empresa, $anio, 'R19')['estado'] !== 'FINALIZADO') {
        $bloqueos[] = 'Finalice el Recuadro 19 antes de finalizar el RTRE (sus valores alimentan el RAI).';
    }

    $pendientes = [
        'Capital aportado (1494), saldo FUR y sobreprecio de acciones: se toman como cero en el RAI (4.2).',
        'DDAN, REX/INR del ejercicio y créditos de IDPC voluntario (4.3 a 4.5): cero hasta cargarlos a mano.',
    ];
    if ($anterior === null) {
        $pendientes[] = 'Saldos iniciales (1.1): sin RTRE del año anterior finalizado, quedan en cero.';
    }
    if ($retirosNoImputados > 0) {
        $pendientes[] = 'Retiros por ' . number_format($retirosNoImputados, 0, ',', '.') . ' que exceden el RAI: su imputación a REX y SAC aún no se calcula.';
    }

    return [
        'recuadro' => 'R20', 'anio' => $anio, 'anioComercial' => ctrib_anio_comercial($anio),
        'celdas' => $noNulas,
        'rai' => $rai, 'idpc' => $idpc,
        'remanenteRai' => $celdas['REMANENTE_FINAL']['RAI']['monto'] ?? 0,
        'remanenteSac' => $celdas['REMANENTE_FINAL'][CTRIB_RTRE_COLUMNA_IDPC]['monto'] ?? 0,
        'formulaRai' => [
            'cptPositivo' => $cptPositivo, 'cptNegativo' => $cptNegativo, 'retirosEjercicio' => $retiros,
            'rexNegativoAbs' => $rexNegativoAbs, 'subtotalRai' => $subtotalRai, 'capitalAportado' => $capitalAportado,
            'saldoFur' => $saldoFur, 'sobreprecioAcciones' => $sobreprecio, 'rai' => $rai,
        ],
        'formulaSac' => [
            'rli' => $rli, 'tasaIdpc' => $tasa, 'idpcEjercicio' => $idpc, 'columnaSacPropio' => CTRIB_RTRE_COLUMNA_IDPC,
        ],
        'retirosImputadosRai' => $retirosRai,
        'estado' => $estado['estado'], 'finalizado' => $estado['finalizado'], 'usuario' => $estado['usuario'],
        'desactualizado' => ctrib_desactualizado($estado, $snapshotActual),
        'todas' => $todas,
        'pendientesDeCalculo' => $pendientes,
        'bloqueos' => $bloqueos,
        'puedeFinalizar' => $bloqueos === [] && $estado['estado'] !== 'FINALIZADO',
    ];
}

/** Guarda una celda escrita a mano (filas MANUAL o AUTO_CON_OVERRIDE). Con monto null quita la celda manual. */
function ctrib_r20_guardar_celda(PDO $pdo, int $empresa, int $anio, string $fila, string $columna, mixed $monto, string $usuario): array
{
    $catalogo = ctrib_rtre_catalogo();
    if (!isset($catalogo[$fila])) {
        throw new CtribError("La fila $fila no existe en el RTRE.");
    }
    $f = $catalogo[$fila];
    $celda = null;
    foreach ($f['celdas'] as $ce) {
        if ($ce['columna'] === $columna) {
            $celda = $ce;
        }
    }
    if ($celda === null) {
        throw new CtribError("La columna $columna no aplica a la fila $fila.");
    }
    if (!in_array($f['naturalezaConfiguracion'], ['MANUAL', 'AUTO_CON_OVERRIDE'], true)) {
        throw new CtribError("La fila $fila se calcula sola y no se escribe a mano.");
    }
    if (ctrib_estado_recuadro($pdo, $empresa, $anio, 'R20')['estado'] === 'FINALIZADO') {
        throw new CtribError('El RTRE está finalizado. Reábralo para modificarlo.');
    }
    $clave = "$fila::$columna";
    $anterior = ctrib_rtre_celdas_manuales($pdo, $empresa, $anio)[$clave] ?? null;
    $pdo->prepare('DELETE FROM ctrib_rtre_celdas WHERE empresa_id = ? AND anio = ? AND fila = ? AND columna = ?')->execute([$empresa, $anio, $fila, $columna]);
    if ($monto === null) {
        if ($anterior !== null) {
            ctrib_registrar_historial($pdo, $empresa, $anio, 'recuadro', "R20 $clave", (string) $anterior, 'automático', 'Celda manual quitada', $usuario);
        }
        return ctrib_r20($pdo, $empresa, $anio);
    }
    $esEntero = is_int($monto) || (is_string($monto) && preg_match('/^-?\d+$/', trim($monto)));
    if (!$esEntero) {
        throw new CtribError('El monto debe ser un número entero.');
    }
    $valor = (int) $monto;
    if ($celda['signo'] === 'SOLO_POSITIVO' && $valor < 0) {
        throw new CtribError("La celda $clave solo admite valores positivos.");
    }
    $pdo->prepare('INSERT INTO ctrib_rtre_celdas (empresa_id, anio, fila, columna, monto, usuario, actualizado) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$empresa, $anio, $fila, $columna, $valor, $usuario, ctrib_ahora()]);
    if ($anterior !== $valor) {
        ctrib_registrar_historial($pdo, $empresa, $anio, 'recuadro', "R20 $clave", $anterior !== null ? (string) $anterior : 'automático', (string) $valor, 'Celda manual', $usuario);
    }
    return ctrib_r20($pdo, $empresa, $anio);
}

function ctrib_r20_finalizar(PDO $pdo, int $empresa, int $anio, string $usuario): array
{
    $r20 = ctrib_r20($pdo, $empresa, $anio);
    if ($r20['estado'] === 'FINALIZADO') {
        throw new CtribError('El RTRE ya está finalizado.');
    }
    if ($r20['bloqueos'] !== []) {
        throw new CtribError(implode(' ', $r20['bloqueos']));
    }
    ctrib_recuadro_finalizar($pdo, $empresa, $anio, 'R20', $r20['todas'], $usuario);
    return ctrib_r20($pdo, $empresa, $anio);
}

function ctrib_r20_reabrir(PDO $pdo, int $empresa, int $anio, string $usuario, string $motivo): array
{
    if (ctrib_estado_recuadro($pdo, $empresa, $anio, 'R20')['estado'] !== 'FINALIZADO') {
        throw new CtribError('El RTRE no está finalizado.');
    }
    ctrib_recuadro_reabrir($pdo, $empresa, $anio, 'R20', $usuario, $motivo);
    return ctrib_r20($pdo, $empresa, $anio);
}

/** Registro SAC (R21): se vacía desde el RTRE. En esta versión se calculan 1513 y 1564. */
function ctrib_r21(PDO $pdo, int $empresa, int $anio): array
{
    $r20 = ctrib_r20($pdo, $empresa, $anio);
    return [
        'recuadro' => 'R21', 'anio' => $anio, 'anioComercial' => ctrib_anio_comercial($anio),
        'lineas' => [
            ['codigo' => '1513', 'glosa' => 'IDPC e IPE — RLI o base imponible generada en el ejercicio', 'monto' => $r20['idpc']],
            ['codigo' => '1564', 'glosa' => 'Remanente ejercicio siguiente (saldo positivo)', 'monto' => max(0, $r20['remanenteSac'])],
        ],
        'estado' => $r20['estado'],
        'pendientesDeCalculo' => ['El resto de las columnas del Registro SAC (1495 a 1371) todavía no se calcula en Cifrax.'],
    ];
}

/** DJ 1948 y Certificado 70: paso 1 exige el RTRE finalizado. Socios con retiros del año y su certificado. */
function ctrib_dj1948(PDO $pdo, int $empresa, int $anio): array
{
    $rtre = ctrib_estado_recuadro($pdo, $empresa, $anio, 'R20');
    $efectivos = ctrib_retiros_del_anio($pdo, $empresa, $anio);
    $agrupados = [];
    foreach ($efectivos['retiros'] as $r) {
        $s = $agrupados[$r['rut']] ?? ['rut' => $r['rut'], 'nombre' => $r['nombre'], 'cantidad' => 0, 'total' => 0, 'primera' => $r['fecha'], 'ultima' => $r['fecha']];
        $s['cantidad']++;
        $s['total'] += (int) $r['monto'];
        $s['primera'] = min($s['primera'], $r['fecha']);
        $s['ultima'] = max($s['ultima'], $r['fecha']);
        $s['nombre'] = $s['nombre'] !== '' ? $s['nombre'] : $r['nombre'];
        $agrupados[$r['rut']] = $s;
    }
    ksort($agrupados);
    $socios = array_values($agrupados);
    $certificados = $pdo->prepare('SELECT rut, numero, emitido FROM ctrib_certificados WHERE empresa_id = ? AND anio = ?');
    $certificados->execute([$empresa, $anio]);
    $porRut = [];
    foreach ($certificados as $c) {
        $porRut[$c['rut']] = $c;
    }
    $filas = [];
    foreach ($socios as $s) {
        $cert = $porRut[$s['rut']] ?? null;
        $filas[] = ['rut' => $s['rut'], 'nombre' => $s['nombre'], 'cantidad' => (int) $s['cantidad'], 'total' => (int) $s['total'],
            'primera' => $s['primera'], 'ultima' => $s['ultima'], 'certificado' => $cert ? (int) $cert['numero'] : null, 'emitido' => $cert['emitido'] ?? null];
    }
    $paso1 = $rtre['estado'] === 'FINALIZADO';
    return [
        'anio' => $anio, 'anioComercial' => ctrib_anio_comercial($anio),
        'paso1' => ['listo' => $paso1, 'mensaje' => $paso1 ? 'RTRE finalizado: puede emitir certificados.' : 'Paso 1 · Certificado 70: el RTRE (R20) debe estar finalizado antes de emitir certificados o la DJ 1948.'],
        'socios' => $filas,
        'modo' => $efectivos['modo'],
        'total' => $efectivos['total'],
        'sociosParticipacion' => ctrib_socios($pdo, $empresa, $anio),
        'pendientesDeCalculo' => ['Crédito por retiro y montos del Certificado 70 (imputación por socio): pendiente de cálculo en esta versión.'],
    ];
}

/** Emite el Certificado 70 de un socio (número correlativo por año). Si ya existe, devuelve el mismo número. */
function ctrib_certificado_emitir(PDO $pdo, int $empresa, int $anio, string $rut, string $usuario): array
{
    $rut = trim($rut);
    if ($rut === '') {
        throw new CtribError('Indique el RUT del socio.');
    }
    if (ctrib_estado_recuadro($pdo, $empresa, $anio, 'R20')['estado'] !== 'FINALIZADO') {
        throw new CtribError('El RTRE (R20) debe estar finalizado antes de emitir certificados.');
    }
    $existe = $pdo->prepare('SELECT numero, emitido FROM ctrib_certificados WHERE empresa_id = ? AND anio = ? AND rut = ?');
    $existe->execute([$empresa, $anio, $rut]);
    if ($f = $existe->fetch()) {
        return ['rut' => $rut, 'numero' => (int) $f['numero'], 'emitido' => $f['emitido'], 'nuevo' => false];
    }
    $ruts = array_column(ctrib_retiros_del_anio($pdo, $empresa, $anio)['retiros'], 'rut');
    if (!in_array($rut, $ruts, true)) {
        throw new CtribError("El RUT $rut no tiene retiros en el año.");
    }
    $max = $pdo->prepare('SELECT COALESCE(MAX(numero), 0) FROM ctrib_certificados WHERE empresa_id = ? AND anio = ?');
    $max->execute([$empresa, $anio]);
    $numero = (int) $max->fetchColumn() + 1;
    $ahora = ctrib_ahora();
    $pdo->prepare('INSERT INTO ctrib_certificados (empresa_id, anio, rut, numero, emitido, usuario) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$empresa, $anio, $rut, $numero, $ahora, $usuario]);
    ctrib_registrar_historial($pdo, $empresa, $anio, 'certificado', "Certificado 70 N° $numero", '', $rut, 'Certificado emitido', $usuario);
    return ['rut' => $rut, 'numero' => $numero, 'emitido' => $ahora, 'nuevo' => true];
}
