<?php
// Recuadro 4 (enajenación de acciones y derechos sociales) y Recuadro 6 (datos informativos Pro Pyme) de Cifrax Tributario.
// Códigos según los catálogos recuadro4.json y recuadro6.json. Validado contra la captura de iTributario (ENYEL AT 2026).
// Pendiente de validación del contador: cuentas seleccionadas para caja, bancos, existencias y activo inmovilizado (R6),
// capital efectivo sin las exclusiones de intangibles y nominales (102), y los códigos de RTRE (1191, 1192, 1195, 1196).
declare(strict_types=1);

require_once __DIR__ . '/ctrib-r20.php';

/** Catálogo de un recuadro (R4 o R6) indexado por código. */
function ctrib_catalogo_recuadro(string $archivo): array
{
    static $cache = [];
    if (!isset($cache[$archivo])) {
        $datos = json_decode((string) file_get_contents(__DIR__ . '/ctrib/' . $archivo), true);
        $cache[$archivo] = [];
        foreach ($datos ?: [] as $fila) {
            $cache[$archivo][(string) $fila['codigo']] = $fila;
        }
    }
    return $cache[$archivo];
}

function ctrib_recuadro_no_finalizado(PDO $pdo, int $empresa, int $anio, string $recuadro): void
{
    if (ctrib_estado_recuadro($pdo, $empresa, $anio, $recuadro)['estado'] === 'FINALIZADO') {
        throw new CtribError("El Recuadro " . str_replace('R', '', $recuadro) . ' está finalizado. Reábralo para modificarlo.');
    }
}

/** Guarda o quita un valor manual de un recuadro. Solo códigos permitidos y montos enteros. */
function ctrib_guardar_valor_manual(PDO $pdo, int $empresa, int $anio, string $recuadro, string $codigo, mixed $monto, string $usuario, array $permitidos): void
{
    if (!in_array($codigo, $permitidos, true)) {
        throw new CtribError("El código $codigo no se ingresa a mano en el $recuadro.");
    }
    ctrib_recuadro_no_finalizado($pdo, $empresa, $anio, $recuadro);
    $anterior = ctrib_valores_manuales($pdo, $empresa, $anio, $recuadro)[$codigo] ?? null;
    $pdo->prepare('DELETE FROM ctrib_valores WHERE empresa_id = ? AND anio = ? AND recuadro = ? AND codigo = ?')->execute([$empresa, $anio, $recuadro, $codigo]);
    if ($monto === null) {
        if ($anterior !== null) {
            ctrib_registrar_historial($pdo, $empresa, $anio, 'recuadro', "$recuadro $codigo", (string) $anterior, 'automático', 'Valor manual quitado', $usuario);
        }
        return;
    }
    $esEntero = is_int($monto) || (is_string($monto) && preg_match('/^-?\d+$/', trim($monto)));
    if (!$esEntero) {
        throw new CtribError('El monto debe ser un número entero.');
    }
    $valor = (int) $monto;
    $pdo->prepare('INSERT INTO ctrib_valores (empresa_id, anio, recuadro, codigo, monto, usuario, actualizado) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$empresa, $anio, $recuadro, $codigo, $valor, $usuario, ctrib_ahora()]);
    if ($anterior !== $valor) {
        ctrib_registrar_historial($pdo, $empresa, $anio, 'recuadro', "$recuadro $codigo", $anterior !== null ? (string) $anterior : 'automático', (string) $valor, 'Valor manual', $usuario);
    }
}

/** Forma común de las líneas de R4 y R6 para la API y el snapshot. */
function ctrib_lineas_recuadro(array $catalogo, array $montos, array $origen): array
{
    $lineas = [];
    foreach ($catalogo as $codigo => $c) {
        $lineas[] = ['codigo' => (string) $codigo, 'glosa' => $c['glosa'], 'naturaleza' => $c['naturalezaConfiguracion'],
            'subBloque' => $c['subBloque'] ?? null, 'monto' => (int) ($montos[(string) $codigo] ?? 0), 'origen' => $origen[(string) $codigo] ?? 'MANUAL'];
    }
    return $lineas;
}

function ctrib_recuadro_resumen(PDO $pdo, int $empresa, int $anio, string $recuadro, array $montos, array $pendientes, array $lineas): array
{
    $estado = ctrib_estado_recuadro($pdo, $empresa, $anio, $recuadro);
    return [
        'recuadro' => $recuadro, 'anio' => $anio, 'anioComercial' => ctrib_anio_comercial($anio),
        'lineas' => $lineas,
        'estado' => $estado['estado'], 'finalizado' => $estado['finalizado'], 'usuario' => $estado['usuario'],
        'desactualizado' => ctrib_desactualizado($estado, $montos),
        'pendientesDeCalculo' => $pendientes,
        'bloqueos' => [],
        'puedeFinalizar' => $estado['estado'] !== 'FINALIZADO',
    ];
}

// ---------- Recuadro 4 ----------

const CTRIB_R4_MANUALES = ['1070', '1074', '1079', '1083', '1087', '1131', '1809', '1813'];

function ctrib_r4(PDO $pdo, int $empresa, int $anio): array
{
    $catalogo = ctrib_catalogo_recuadro('recuadro4.json');
    $manuales = ctrib_valores_manuales($pdo, $empresa, $anio, 'R4');
    $anterior = ctrib_estado_recuadro($pdo, $empresa, $anio - 1, 'R4');
    $montos = [];
    $origen = [];
    foreach ($catalogo as $codigo => $c) {
        if (in_array((string) $codigo, CTRIB_R4_MANUALES, true)) {
            $montos[(string) $codigo] = $manuales[(string) $codigo] ?? 0;
            $origen[(string) $codigo] = 'MANUAL';
        }
    }
    // 1814 = 1809 + 1813; 1815 = pérdida de arrastre (1816 del año anterior, si es negativa); 1816 = 1814 + 1815.
    $montos['1814'] = ($montos['1809'] ?? 0) + ($montos['1813'] ?? 0);
    $perdidaAnterior = $anterior['snapshot']['1816'] ?? 0;
    $montos['1815'] = $perdidaAnterior < 0 ? (int) $perdidaAnterior : 0;
    $montos['1816'] = $montos['1814'] + $montos['1815'];
    foreach (['1814', '1815', '1816'] as $c) {
        $origen[$c] = $c === '1815' && $perdidaAnterior < 0 ? 'AÑO_ANTERIOR' : 'CALCULADO';
    }
    $lineas = ctrib_lineas_recuadro($catalogo, $montos, $origen);
    return ctrib_recuadro_resumen($pdo, $empresa, $anio, 'R4', $montos, [
        'Los montos de enajenación (1070 a 1131, 1809 y 1813) se ingresan a mano con la información de la operación.',
        'La pérdida de arrastre (1815) toma la base del año anterior sin reajuste en esta versión.',
    ], $lineas);
}

function ctrib_r4_guardar_valor(PDO $pdo, int $empresa, int $anio, string $codigo, mixed $monto, string $usuario): array
{
    ctrib_guardar_valor_manual($pdo, $empresa, $anio, 'R4', $codigo, $monto, $usuario, CTRIB_R4_MANUALES);
    return ctrib_r4($pdo, $empresa, $anio);
}

function ctrib_r4_finalizar(PDO $pdo, int $empresa, int $anio, string $usuario): array
{
    $r = ctrib_r4($pdo, $empresa, $anio);
    if ($r['estado'] === 'FINALIZADO') {
        throw new CtribError('El Recuadro 4 ya está finalizado.');
    }
    ctrib_recuadro_finalizar($pdo, $empresa, $anio, 'R4', $r['lineas'], $usuario);
    return ctrib_r4($pdo, $empresa, $anio);
}

function ctrib_r4_reabrir(PDO $pdo, int $empresa, int $anio, string $usuario, string $motivo): array
{
    if (ctrib_estado_recuadro($pdo, $empresa, $anio, 'R4')['estado'] !== 'FINALIZADO') {
        throw new CtribError('El Recuadro 4 no está finalizado.');
    }
    ctrib_recuadro_reabrir($pdo, $empresa, $anio, 'R4', $usuario, $motivo);
    return ctrib_r4($pdo, $empresa, $anio);
}

// ---------- Recuadro 6 ----------

// Códigos que se calculan y no se escriben a mano.
const CTRIB_R6_CALCULADOS = ['122', '123', '102', '1193'];
// Códigos que dependen del RTRE y aún no se calculan: quedan en cero hasta cargarlos a mano.
const CTRIB_R6_RTRE = ['1191', '1192', '1195', '1196'];

function ctrib_r6(PDO $pdo, int $empresa, int $anio): array
{
    $catalogo = ctrib_catalogo_recuadro('recuadro6.json');
    $balance = ctrib_balance_guardado($pdo, $empresa, $anio);
    $activo = (int) ($balance['totales']['activo'] ?? 0);
    $pasivo = (int) ($balance['totales']['pasivo'] ?? 0);
    $manuales = ctrib_valores_manuales($pdo, $empresa, $anio, 'R6');
    $r20 = ctrib_r20($pdo, $empresa, $anio);
    $retirosExcedentes = max(0, ctrib_retiros_del_anio($pdo, $empresa, $anio)['total'] - (int) $r20['retirosImputadosRai']);

    $montos = [];
    $origen = [];
    foreach ($catalogo as $codigo => $c) {
        $codigo = (string) $codigo;
        if ($codigo === '122') {
            $montos[$codigo] = $activo; $origen[$codigo] = 'BALANCE';
        } elseif ($codigo === '123') {
            $montos[$codigo] = $pasivo; $origen[$codigo] = 'BALANCE';
        } elseif ($codigo === '102') {
            $montos[$codigo] = $activo; $origen[$codigo] = 'CALCULADO'; // sin exclusiones de intangibles y nominales
        } elseif ($codigo === '1193') {
            $montos[$codigo] = $retirosExcedentes; $origen[$codigo] = 'RTRE';
        } elseif ($codigo === '843' && !array_key_exists($codigo, $manuales)) {
            $montos[$codigo] = $activo - $pasivo; $origen[$codigo] = 'CALCULADO'; // activo menos pasivo con todas las cuentas
        } elseif (array_key_exists($codigo, $manuales)) {
            $montos[$codigo] = $manuales[$codigo]; $origen[$codigo] = 'MANUAL';
        } elseif (in_array($codigo, CTRIB_R6_RTRE, true)) {
            $montos[$codigo] = 0; $origen[$codigo] = 'PENDIENTE';
        } else {
            $montos[$codigo] = 0; $origen[$codigo] = 'MANUAL';
        }
    }
    $pendientes = [
        'Cuentas seleccionadas para caja (101), bancos (784), existencias (129) y activo inmovilizado (647): se ingresan a mano.',
        'Capital efectivo (102) toma el total de activo sin las exclusiones de intangibles y nominales.',
        'Saldos del RTRE (1191, 1192, 1195 y 1196): pendientes de cálculo en esta versión.',
    ];
    if ($balance === null) {
        $pendientes[] = 'Sin balance para este año: los datos del balance (122, 123, 102 y 843) quedan en cero.';
    }
    $lineas = ctrib_lineas_recuadro($catalogo, $montos, $origen);
    return ctrib_recuadro_resumen($pdo, $empresa, $anio, 'R6', $montos, $pendientes, $lineas);
}

function ctrib_r6_permitidos(): array
{
    $codigos = array_map('strval', array_keys(ctrib_catalogo_recuadro('recuadro6.json')));
    return array_values(array_diff($codigos, CTRIB_R6_CALCULADOS));
}

function ctrib_r6_guardar_valor(PDO $pdo, int $empresa, int $anio, string $codigo, mixed $monto, string $usuario): array
{
    ctrib_guardar_valor_manual($pdo, $empresa, $anio, 'R6', $codigo, $monto, $usuario, ctrib_r6_permitidos());
    return ctrib_r6($pdo, $empresa, $anio);
}

function ctrib_r6_finalizar(PDO $pdo, int $empresa, int $anio, string $usuario): array
{
    $r = ctrib_r6($pdo, $empresa, $anio);
    if ($r['estado'] === 'FINALIZADO') {
        throw new CtribError('El Recuadro 6 ya está finalizado.');
    }
    ctrib_recuadro_finalizar($pdo, $empresa, $anio, 'R6', $r['lineas'], $usuario);
    return ctrib_r6($pdo, $empresa, $anio);
}

function ctrib_r6_reabrir(PDO $pdo, int $empresa, int $anio, string $usuario, string $motivo): array
{
    if (ctrib_estado_recuadro($pdo, $empresa, $anio, 'R6')['estado'] !== 'FINALIZADO') {
        throw new CtribError('El Recuadro 6 no está finalizado.');
    }
    ctrib_recuadro_reabrir($pdo, $empresa, $anio, 'R6', $usuario, $motivo);
    return ctrib_r6($pdo, $empresa, $anio);
}
