<?php
// Cierre anual de Cifrax Tributario: sella el año cuando R4, R6, R17, R19 y el RTRE (R20) están finalizados.
// El R21 (Registro SAC) se vacía desde el RTRE y sigue su estado. El R18 (Determinación del RAI) no está en esta versión.
declare(strict_types=1);

require_once __DIR__ . '/ctrib-r4-r6.php';

const CTRIB_CIERRE_RECUADROS = [
    'R4' => 'Enajenación de acciones', 'R6' => 'Datos informativos', 'R17' => 'Base imponible',
    'R19' => 'CPTS Pro Pyme', 'R20' => 'RTRE', 'R21' => 'Registro SAC',
];

function ctrib_cierre(PDO $pdo, int $empresa, int $anio): array
{
    $recuadros = [];
    $pendientes = [];
    $calculos = ['R4' => 'ctrib_r4', 'R6' => 'ctrib_r6', 'R17' => 'ctrib_r17', 'R19' => 'ctrib_r19', 'R20' => 'ctrib_r20'];
    $desactualizados = [];
    foreach (CTRIB_CIERRE_RECUADROS as $codigo => $nombre) {
        $referencia = $codigo === 'R21' ? 'R20' : $codigo; // el R21 sigue al RTRE
        $finalizado = ctrib_estado_recuadro($pdo, $empresa, $anio, $referencia)['estado'] === 'FINALIZADO';
        $desactualizado = $finalizado && ($desactualizados[$referencia] ??= $calculos[$referencia]($pdo, $empresa, $anio)['desactualizado']);
        $recuadros[] = ['codigo' => $codigo, 'nombre' => $nombre, 'estado' => $finalizado ? 'FINALIZADO' : 'BORRADOR', 'desactualizado' => $desactualizado];
        if (!$finalizado || $desactualizado) {
            $pendientes[] = $codigo;
        }
    }
    $cierre = ctrib_estado_recuadro($pdo, $empresa, $anio, 'CIERRE');
    $cerrado = $cierre['estado'] === 'FINALIZADO';
    $avisos = [];
    if ($cerrado && $pendientes !== []) {
        $avisos[] = 'Hay recuadros reabiertos o desactualizados después del cierre: reabra el cierre, actualícelos y ciérrelo nuevamente.';
    }
    return [
        'anio' => $anio, 'anioComercial' => ctrib_anio_comercial($anio),
        'recuadros' => $recuadros, 'pendientes' => $pendientes, 'completo' => $pendientes === [],
        'cerrado' => $cerrado, 'cerradoEn' => $cierre['finalizado'], 'usuario' => $cierre['usuario'],
        'avisos' => $avisos,
        'nota' => 'El R18 (Determinación del RAI) no está en esta versión y no bloquea el cierre.',
    ];
}

function ctrib_cierre_sellar(PDO $pdo, int $empresa, int $anio, string $usuario): array
{
    $cierre = ctrib_cierre($pdo, $empresa, $anio);
    if ($cierre['cerrado']) {
        throw new CtribError('El año ya está cerrado.');
    }
    if (!$cierre['completo']) {
        throw new CtribError('Faltan recuadros por finalizar o actualizar (los desactualizados se reabren y finalizan de nuevo): ' . implode(', ', $cierre['pendientes']) . '.');
    }
    $lineas = array_map(fn($r) => ['codigo' => $r['codigo'], 'monto' => 1], $cierre['recuadros']);
    ctrib_recuadro_finalizar($pdo, $empresa, $anio, 'CIERRE', $lineas, $usuario);
    return ctrib_cierre($pdo, $empresa, $anio);
}

function ctrib_cierre_reabrir(PDO $pdo, int $empresa, int $anio, string $usuario, string $motivo): array
{
    if (ctrib_estado_recuadro($pdo, $empresa, $anio, 'CIERRE')['estado'] !== 'FINALIZADO') {
        throw new CtribError('El año no está cerrado.');
    }
    ctrib_recuadro_reabrir($pdo, $empresa, $anio, 'CIERRE', $usuario, $motivo);
    return ctrib_cierre($pdo, $empresa, $anio);
}

/** Acciones que sí se permiten con el año cerrado: no cambian sus cifras. */
const CTRIB_ACCIONES_CON_ANIO_CERRADO = ['cierre_sellar', 'cierre_reabrir', 'certificado_emitir'];

/**
 * Rechaza los cambios sobre un año cerrado: primero hay que reabrir el cierre. El mapeo de dígitos es de toda la
 * empresa: con algún año cerrado se pueden agregar dígitos, pero no cambiar ni quitar los ya asignados.
 */
function ctrib_exigir_cambio_permitido(PDO $pdo, int $empresa, string $accion, array $datos): void
{
    if (in_array($accion, CTRIB_ACCIONES_CON_ANIO_CERRADO, true)) {
        return;
    }
    $st = $pdo->prepare("SELECT anio FROM ctrib_recuadros WHERE empresa_id = ? AND recuadro = 'CIERRE' AND estado = 'FINALIZADO' ORDER BY anio");
    $st->execute([$empresa]);
    $cerrados = array_map('intval', array_column($st->fetchAll(), 'anio'));
    if ($cerrados === []) {
        return;
    }
    if ($accion === 'mapeo_guardar') {
        $nuevo = is_array($datos['mapeo'] ?? null) ? $datos['mapeo'] : [];
        foreach (ctrib_mapeo($pdo, $empresa) as $digito => $tipo) {
            if (($nuevo[$digito] ?? null) !== $tipo) {
                throw new CtribError('Hay años cerrados (AT ' . implode(', ', $cerrados) . '): reabra sus cierres antes de cambiar el tipo del dígito ' . $digito . '.');
            }
        }
        return;
    }
    $anio = (int) (is_numeric($datos['anio'] ?? null) ? $datos['anio'] : 0);
    if (in_array($anio, $cerrados, true)) {
        throw new CtribError("El año tributario $anio está cerrado. Reabra el cierre para modificarlo.");
    }
}

/** Indica si el recuadro del documento cambió después de finalizarlo. */
function ctrib_documento_desactualizado(PDO $pdo, int $empresa, int $anio, string $doc): bool
{
    $calculo = match ($doc) {
        'R4' => 'ctrib_r4', 'R6' => 'ctrib_r6', 'R17' => 'ctrib_r17', 'R19' => 'ctrib_r19',
        'R18', 'R20', 'R21', 'RTRE' => 'ctrib_r20',
        default => null,
    };
    return $calculo !== null && $calculo($pdo, $empresa, $anio)['desactualizado'];
}
