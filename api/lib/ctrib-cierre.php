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
    foreach (CTRIB_CIERRE_RECUADROS as $codigo => $nombre) {
        $referencia = $codigo === 'R21' ? 'R20' : $codigo; // el R21 sigue al RTRE
        $finalizado = ctrib_estado_recuadro($pdo, $empresa, $anio, $referencia)['estado'] === 'FINALIZADO';
        $recuadros[] = ['codigo' => $codigo, 'nombre' => $nombre, 'estado' => $finalizado ? 'FINALIZADO' : 'BORRADOR'];
        if (!$finalizado) {
            $pendientes[] = $codigo;
        }
    }
    $cierre = ctrib_estado_recuadro($pdo, $empresa, $anio, 'CIERRE');
    $cerrado = $cierre['estado'] === 'FINALIZADO';
    $avisos = [];
    if ($cerrado && $pendientes !== []) {
        $avisos[] = 'Hay recuadros reabiertos después del cierre: ciérrelo nuevamente.';
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
        throw new CtribError('Faltan recuadros por finalizar: ' . implode(', ', $cierre['pendientes']) . '.');
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
