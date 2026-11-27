<?php
// Cierre anual de Cifrax Tributario: sella el año cuando los recuadros del régimen están finalizados.
// Pro Pyme: R4, R6, R17, R19 y el RTRE (R20); el R21 sigue al RTRE y el R18 sale de él. 14 D N° 8: R4, R6, R22 y R23.
declare(strict_types=1);

require_once __DIR__ . '/ctrib-r4-r6.php';
require_once __DIR__ . '/ctrib-r23.php';
require_once __DIR__ . '/ctrib-dj-14a.php';
require_once __DIR__ . '/ctrib-presunta.php';
require_once __DIR__ . '/ctrib-f22.php';
require_once __DIR__ . '/ctrib-r1.php';

const CTRIB_CIERRE_RECUADROS = [
    'R4' => 'Enajenación de acciones', 'R6' => 'Datos informativos', 'R17' => 'Base imponible',
    'R19' => 'CPTS Pro Pyme', 'R20' => 'RTRE', 'R21' => 'Registro SAC',
];
const CTRIB_CIERRE_RECUADROS_14D8 = [
    'R4' => 'Enajenación de acciones', 'R6' => 'Datos informativos', 'R22' => 'Base imponible', 'R23' => 'CPTS transparencia',
];
const CTRIB_CIERRE_RECUADROS_14A = [
    'R4' => 'Enajenación de acciones', 'R6' => 'Datos informativos', 'R12' => 'Base imponible (RLI)',
    'R14' => 'Razonabilidad del CPT', 'R13' => 'Determinación del RAI', 'R15' => 'RTRE (14 A)', 'R16' => 'Registro SAC (14 A)',
];
const CTRIB_CIERRE_RECUADROS_14G = [
    'R4' => 'Enajenación de acciones', 'R6' => 'Datos informativos', 'R12' => 'Base imponible (RLI)',
    'R14' => 'Razonabilidad del CPT',
];
const CTRIB_CIERRE_RECUADROS_PRESUNTA = [
    'L57' => 'Renta presunta (Línea 57 y Línea 4 F22)',
];

function ctrib_cierre(PDO $pdo, int $empresa, int $anio): array
{
    $recuadros = [];
    $pendientes = [];
    $calculos = ['R4' => 'ctrib_r4', 'R6' => 'ctrib_r6', 'R17' => 'ctrib_r17', 'R19' => 'ctrib_r19', 'R20' => 'ctrib_r20',
        'R22' => 'ctrib_r22', 'R23' => 'ctrib_r23', 'R12' => 'ctrib_r12', 'R13' => 'ctrib_r13', 'R14' => 'ctrib_r14', 'R15' => 'ctrib_r15',
        'L57' => 'ctrib_l57'];
    $regimen = ctrib_empresa_config($pdo, $empresa)['regimen'];
    $transparente = $regimen === '14D8';
    $semiIntegrado = $regimen === '14A';
    $reg14G = $regimen === '14G';
    $presunta = $regimen === 'PRESUNTA';
    $lista = match ($regimen) {
        '14D8' => CTRIB_CIERRE_RECUADROS_14D8,
        '14A' => CTRIB_CIERRE_RECUADROS_14A,
        '14G' => CTRIB_CIERRE_RECUADROS_14G,
        'PRESUNTA' => CTRIB_CIERRE_RECUADROS_PRESUNTA,
        default => CTRIB_CIERRE_RECUADROS,
    };
    $desactualizados = [];
    foreach ($lista as $codigo => $nombre) {
        $referencia = match ($codigo) { 'R21' => 'R20', 'R16' => 'R15', default => $codigo };
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
        'nota' => $transparente ? 'El R7 (ingreso diferido) no está en esta versión y no bloquea el cierre.'
            : ($semiIntegrado ? 'El R16 (Registro SAC) sale del RTRE (R15) y no se finaliza aparte.'
                : ($reg14G ? 'Régimen 14 G (entidades no sujetas al Art. 14): no determina R13 (RAI) ni RTRE/SAC.'
                    : ($presunta ? 'Régimen Renta Presunta (Art. 34 LIR): determina bases y IDPC en Línea 57 y asignación Línea 4 / DJ 1943 (por validar con el contador).'
                        : 'El R18 (Determinación del RAI) sale del RTRE y no se finaliza aparte.'))),
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
    if (in_array($accion, ['regimen_config', 'composicion_guardar'], true)) {
        $actual = ctrib_empresa_config($pdo, $empresa)['regimen'];
        if ($actual !== '' && (string) ($datos['regimen'] ?? '') !== $actual) {
            throw new CtribError('Hay años cerrados (AT ' . implode(', ', $cerrados) . '): reabra sus cierres antes de cambiar el régimen de la empresa.');
        }
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
        'R22', 'DJ1947' => 'ctrib_r22', 'R23' => 'ctrib_r23',
        'R12' => 'ctrib_r12', 'R13' => 'ctrib_r13', 'R14' => 'ctrib_r14',
        'R15', 'R16' => 'ctrib_r15',
        'DJ1847' => 'ctrib_dj1847', 'DJ1926' => 'ctrib_dj1926',
        'L57' => 'ctrib_l57',
        'R1' => 'ctrib_r1',
        'F22' => 'ctrib_f22',
        default => null,
    };
    return $calculo !== null && $calculo($pdo, $empresa, $anio)['desactualizado'];
}
