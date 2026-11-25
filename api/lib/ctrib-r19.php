<?php
// Recuadro 19 (CPTS Régimen Pro Pyme) de Cifrax Tributario: códigos automáticos desde el R17 y los retiros,
// valores manuales y de año anterior con sobrescritura, CPTS final (1545/1546), finalizar y reabrir.
// Fórmulas según los instructivos 19.1 a 19.21 del catálogo (recuadro19.json).
declare(strict_types=1);

require_once __DIR__ . '/ctrib-r17.php';

// Códigos que se pueden escribir a mano. Los de año anterior (1445/1446) y los pendientes (1377/1378/1380/1381) se sobrescriben.
const CTRIB_R19_MANUALES = ['1374', '1375', '1376', '1726', '1377', '1378', '1380', '1381', '1445', '1446'];
// Códigos cuyo valor automático aún no tiene fuente en Cifrax (informados en pendientesDeCalculo).
const CTRIB_R19_PENDIENTES = [
    '1377' => 'rentas exentas e ingresos no renta (instructivo 19.9)',
    '1378' => 'pérdidas por rentas exentas (instructivo 19.10)',
    '1380' => 'otras partidas a agregar: activos, pasivos y otros ingresos del RLI (instructivo 19.18)',
    '1381' => 'otras partidas a deducir: mismo cálculo que 1380 (instructivo 19.19)',
];
const CTRIB_R19_POSITIVOS = ['1445', '1374', '1375', '1705', '1707', '1377', '1726', '1710', '1711', '1380'];
const CTRIB_R19_NEGATIVOS = ['1446', '1376', '1706', '1378', '1479', '1708', '1709', '1379', '1381'];

/** Snapshot del R19 de un año, si está finalizado. Devuelve código => monto o null. */
function ctrib_r19_snapshot_anterior(PDO $pdo, int $empresa, int $anio): ?array
{
    $estado = ctrib_estado_recuadro($pdo, $empresa, $anio, 'R19');
    return $estado['estado'] === 'FINALIZADO' ? $estado['snapshot'] : null;
}

/** Cálculo completo del R19 del año. Requiere el cálculo del R17 (que se recalcula aquí). */
function ctrib_r19(PDO $pdo, int $empresa, int $anio): array
{
    $r17 = ctrib_r17($pdo, $empresa, $anio);
    $m17 = [];
    foreach ($r17['lineas'] as $l) {
        $m17[$l['codigo']] = $l['monto'];
    }
    $anterior = ctrib_r19_snapshot_anterior($pdo, $empresa, $anio - 1);
    $manuales = ctrib_valores_manuales($pdo, $empresa, $anio, 'R19');
    $retiros = ctrib_retiros_del_anio($pdo, $empresa, $anio)['total'];
    $m1440 = $m17['1440'] ?? 0;

    $auto = [
        '1705' => max(0, $m1440),
        '1706' => max(0, -$m1440),
        '1707' => ($m17['1408'] ?? 0) + ($m17['1426'] ?? 0),
        '1708' => $m17['1422'] ?? 0,
        '1709' => $m17['1404'] ?? 0,
        '1379' => $m17['1401'] ?? 0,
        '1710' => $m17['1432'] ?? 0,
        '1711' => $m17['1433'] ?? 0,
        '1479' => $retiros,
    ];

    $montos = [];
    $origen = [];
    $lineas = [];
    $catalogo = ctrib_catalogo_r19();
    foreach ($catalogo as $codigo => $c) {
        $codigo = (string) $codigo;
        if ($codigo === '1545' || $codigo === '1546') {
            continue;
        }
        if (in_array($codigo, CTRIB_R19_MANUALES, true)) {
            if (array_key_exists($codigo, $manuales)) {
                $monto = $manuales[$codigo];
                $origen[$codigo] = 'MANUAL';
            } elseif ($codigo === '1445' || $codigo === '1446') {
                $fuente = $anterior !== null ? ($anterior[$codigo === '1445' ? '1545' : '1546'] ?? 0) : 0;
                $monto = $fuente;
                $origen[$codigo] = $anterior !== null ? 'AÑO_ANTERIOR' : 'SIN_ANTERIOR_FINALIZADO';
            } else {
                $monto = 0;
                $origen[$codigo] = isset(CTRIB_R19_PENDIENTES[$codigo]) ? 'PENDIENTE' : 'MANUAL';
            }
        } else {
            $monto = $auto[$codigo] ?? 0;
            $origen[$codigo] = 'AUTOMATICO';
        }
        $montos[$codigo] = (int) $monto;
    }
    $sumaPositivos = array_sum(array_map(fn($c) => $montos[$c] ?? 0, CTRIB_R19_POSITIVOS));
    $sumaNegativos = array_sum(array_map(fn($c) => $montos[$c] ?? 0, CTRIB_R19_NEGATIVOS));
    $montos['1545'] = max(0, $sumaPositivos - $sumaNegativos);
    $montos['1546'] = max(0, $sumaNegativos - $sumaPositivos);
    $origen['1545'] = 'RESULTADO';
    $origen['1546'] = 'RESULTADO';

    foreach ($catalogo as $codigo => $c) {
        $codigo = (string) $codigo;
        $lineas[] = [
            'codigo' => $codigo, 'glosa' => $c['glosa'], 'signo' => $c['signo'], 'naturaleza' => $c['naturalezaConfiguracion'],
            'monto' => $montos[$codigo] ?? 0, 'origen' => $origen[$codigo] ?? 'AUTOMATICO',
        ];
    }

    $estado = ctrib_estado_recuadro($pdo, $empresa, $anio, 'R19');
    $actual = $montos;
    $bloqueos = [];
    if ($r17['estado'] !== 'FINALIZADO') {
        $bloqueos[] = 'Finalice el Recuadro 17 antes de finalizar el 19 (sus valores alimentan este recuadro).';
    }
    if ($r17['desactualizado']) {
        $bloqueos[] = 'El Recuadro 17 cambió después de finalizarlo: finalícelo nuevamente.';
    }

    return [
        'recuadro' => 'R19', 'anio' => $anio, 'anioComercial' => ctrib_anio_comercial($anio),
        'lineas' => $lineas,
        'totales' => ['1545' => $montos['1545'], '1546' => $montos['1546']],
        'retiros' => $retiros,
        'anterior' => $anterior !== null ? ['1545' => $anterior['1545'] ?? 0, '1546' => $anterior['1546'] ?? 0] : null,
        'estado' => $estado['estado'], 'finalizado' => $estado['finalizado'], 'usuario' => $estado['usuario'],
        'desactualizado' => ctrib_desactualizado($estado, $actual),
        'pendientesDeCalculo' => array_values(CTRIB_R19_PENDIENTES),
        'bloqueos' => $bloqueos,
        'puedeFinalizar' => $bloqueos === [] && $estado['estado'] !== 'FINALIZADO',
    ];
}

function ctrib_catalogo_r19(): array
{
    static $catalogo = null;
    if ($catalogo === null) {
        $datos = json_decode((string) file_get_contents(__DIR__ . '/ctrib/recuadro19.json'), true);
        $catalogo = [];
        foreach ($datos ?: [] as $fila) {
            $catalogo[$fila['codigo']] = $fila;
        }
    }
    return $catalogo;
}

/** Guarda la sobrescritura de un código del R19. Con monto null quita la sobrescritura y vuelve al automático. */
function ctrib_r19_guardar_valor(PDO $pdo, int $empresa, int $anio, string $codigo, mixed $monto, string $usuario): array
{
    if (!in_array($codigo, CTRIB_R19_MANUALES, true)) {
        throw new CtribError("El código $codigo no se ingresa a mano en el Recuadro 19.");
    }
    if (ctrib_estado_recuadro($pdo, $empresa, $anio, 'R19')['estado'] === 'FINALIZADO') {
        throw new CtribError('El Recuadro 19 está finalizado. Reábralo para modificarlo.');
    }
    $anterior = ctrib_valores_manuales($pdo, $empresa, $anio, 'R19');
    $pdo->prepare('DELETE FROM ctrib_valores WHERE empresa_id = ? AND anio = ? AND recuadro = ? AND codigo = ?')->execute([$empresa, $anio, 'R19', $codigo]);
    if ($monto === null) {
        ctrib_registrar_historial($pdo, $empresa, $anio, 'recuadro', "R19 $codigo", (string) ($anterior[$codigo] ?? ''), 'automático', 'Sobrescritura quitada', $usuario);
        return ctrib_r19($pdo, $empresa, $anio);
    }
    $esEntero = is_int($monto) || (is_string($monto) && preg_match('/^\d+$/', trim($monto)));
    if (!$esEntero || (int) $monto < 0) {
        throw new CtribError('El monto debe ser un número entero mayor o igual a cero.');
    }
    $valor = (int) $monto;
    $pdo->prepare('INSERT INTO ctrib_valores (empresa_id, anio, recuadro, codigo, monto, usuario, actualizado) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$empresa, $anio, 'R19', $codigo, $valor, $usuario, ctrib_ahora()]);
    if (($anterior[$codigo] ?? null) !== $valor) {
        ctrib_registrar_historial($pdo, $empresa, $anio, 'recuadro', "R19 $codigo", (string) ($anterior[$codigo] ?? 'automático'), (string) $valor, 'Valor manual', $usuario);
    }
    return ctrib_r19($pdo, $empresa, $anio);
}

function ctrib_r19_finalizar(PDO $pdo, int $empresa, int $anio, string $usuario): array
{
    $r19 = ctrib_r19($pdo, $empresa, $anio);
    if ($r19['estado'] === 'FINALIZADO') {
        throw new CtribError('El Recuadro 19 ya está finalizado.');
    }
    if ($r19['bloqueos'] !== []) {
        throw new CtribError(implode(' ', $r19['bloqueos']));
    }
    ctrib_recuadro_finalizar($pdo, $empresa, $anio, 'R19', $r19['lineas'], $usuario);
    return ctrib_r19($pdo, $empresa, $anio);
}

function ctrib_r19_reabrir(PDO $pdo, int $empresa, int $anio, string $usuario, string $motivo): array
{
    if (ctrib_estado_recuadro($pdo, $empresa, $anio, 'R19')['estado'] !== 'FINALIZADO') {
        throw new CtribError('El Recuadro 19 no está finalizado.');
    }
    ctrib_recuadro_reabrir($pdo, $empresa, $anio, 'R19', $usuario, $motivo);
    return ctrib_r19($pdo, $empresa, $anio);
}
