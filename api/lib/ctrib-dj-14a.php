<?php
// Declaraciones Juradas del régimen Semi Integrado (Art. 14 A): DJ 1847 (Balance 8 columnas) y DJ 1926 (RLI).
// Reglas: Anexo N° 1 SII y ejercicio oficial 14a.xlsx (Sociedad Fénix Ltda).
declare(strict_types=1);

require_once __DIR__ . '/ctrib-r15-r16.php';

const CTRIB_R12_A_ANEXO1926 = [
    '1673' => '5.01.15.01', '1674' => '5.01.15.03', '1144' => '5.03.05.14', '1675' => '5.01.05.05',
    '1175' => '5.01.03.02', '1676' => '5.01.08.11', '1677' => '5.03.05.10', '1678' => '5.03.04.98',
    '1150' => '5.03.04.30', '1147' => '5.03.01.98', '1148' => '5.03.01.99', '1149' => '5.03.04.11',
    '1151' => '5.03.04.31', '1991' => '5.03.04.98', '1152' => '5.03.04.32', '1176' => '5.01.03.03',
    '1679' => '5.01.05.06', '1680' => '5.01.09.03', '1681' => '5.01.12.03', '1974' => '5.01.12.99',
    '1975' => '5.01.12.99', '1682' => '5.03.05.02', '1683' => '5.03.04.99', '1684' => '5.03.04.99',
    '1685' => '5.03.02.01', '1686' => '5.01.01.05', '1183' => '5.01.01.05', '1687' => '5.03.04.99',
    '1688' => '5.03.01.10', '1689' => '5.04.01.01', '1154' => '5.03.04.20', '1157' => '5.03.04.22',
];

function ctrib_ifrs_catalogo(bool $incluirAjustes = false): array
{
    static $todos = null;
    if ($todos === null) {
        $raw = json_decode((string) file_get_contents(__DIR__ . '/ctrib/clasificador-ifrs.json'), true) ?: [];
        $todos = [];
        foreach ($raw as $fila) {
            if (!empty($fila['esEncabezado'])) {
                continue;
            }
            $todos[$fila['codigo']] = $fila;
        }
    }
    if ($incluirAjustes) {
        return $todos;
    }
    return array_filter($todos, fn($x) => ($x['grupo'] ?? '') !== 'AJUSTE_TRIBUTARIO');
}

function ctrib_ifrs_sugerir(string $tipo, string $nombre): string
{
    $n = mb_strtolower($nombre);
    if ($tipo === 'ACTIVO') {
        if (str_contains($n, 'caja') || str_contains($n, 'banco')) {
            return '1.01.01.00';
        }
        if (str_contains($n, 'cliente') || str_contains($n, 'deudor')) {
            return '1.01.20.00';
        }
        if (str_contains($n, 'mercader') || str_contains($n, 'existencia') || str_contains($n, 'inventario')) {
            return '1.01.50.00';
        }
        if (str_contains($n, 'iva') || str_contains($n, 'crédito fiscal') || str_contains($n, 'credito fiscal')) {
            return '1.01.59.00';
        }
        if (str_contains($n, 'ppm') || str_contains($n, 'impuesto')) {
            return '1.01.60.00';
        }
        return '1.01.99.00';
    }
    if ($tipo === 'PASIVO') {
        if (str_contains($n, 'proveedor')) {
            return '2.01.11.00';
        }
        if (str_contains($n, 'iva') || str_contains($n, 'débito') || str_contains($n, 'debito')) {
            return '2.01.59.00';
        }
        if (str_contains($n, 'impuesto') && str_contains($n, 'renta')) {
            return '2.01.60.00';
        }
        if (str_contains($n, 'remuner') || str_contains($n, 'vacacion')) {
            return '2.01.51.00';
        }
        return '2.01.99.00';
    }
    if ($tipo === 'CAPITAL') {
        if (str_contains($n, 'acumulad') || str_contains($n, 'resultado')) {
            return '2.03.06.00';
        }
        return '2.03.01.00';
    }
    if ($tipo === 'GANANCIA') {
        if (str_contains($n, 'financ') || str_contains($n, 'interés') || str_contains($n, 'interes')) {
            return '3.02.01.00';
        }
        return str_contains($n, 'venta') || str_contains($n, 'giro') || str_contains($n, 'ingreso') ? '3.01.01.00' : '3.02.03.00';
    }
    if (str_contains($n, 'costo')) {
        return '3.01.02.00';
    }
    if (str_contains($n, 'impuesto') && str_contains($n, 'renta')) {
        return '3.06.01.00';
    }
    if (str_contains($n, 'depreciaci')) {
        return '3.03.01.00';
    }
    return '3.01.03.00';
}

/** DJ 1847: Balance de 8 columnas clasificado al Anexo N° 1 SII y resumen patrimonial/tributario. */
function ctrib_dj1847(PDO $pdo, int $empresa, int $anio): array
{
    $balance = ctrib_balance_guardado($pdo, $empresa, $anio);
    $catalogo = ctrib_ifrs_catalogo(false);
    $st = $pdo->prepare('SELECT cuenta, codigo_ifrs FROM ctrib_ifrs_cuentas WHERE empresa_id = ? AND anio = ?');
    $st->execute([$empresa, $anio]);
    $guardadas = array_column($st->fetchAll(), 'codigo_ifrs', 'cuenta');

    $cuentas = [];
    foreach ($balance['cuentas'] ?? [] as $c) {
        $cod = (string) $c['codigo'];
        $sug = ctrib_ifrs_sugerir((string) $c['tipo'], (string) $c['nombre']);
        $ifrs = $guardadas[$cod] ?? $sug;
        $cuentas[] = [
            'codigo' => $cod,
            'nombre' => $c['nombre'],
            'tipo' => $c['tipo'],
            'codigoIfrs' => $ifrs,
            'nombreIfrs' => $catalogo[$ifrs]['descripcion'] ?? '',
            'origenIfrs' => isset($guardadas[$cod]) ? 'MANUAL' : 'SUGERIDO',
            'debitos' => (int) $c['debitos'],
            'creditos' => (int) $c['creditos'],
            'deudor' => (int) $c['deudor'],
            'acreedor' => (int) $c['acreedor'],
            'activo' => (int) $c['activo'],
            'pasivo' => (int) $c['pasivo'],
            'perdida' => (int) $c['perdida'],
            'ganancia' => (int) $c['ganancia'],
        ];
    }

    $r14 = ctrib_r14($pdo, $empresa, $anio);
    $t = $balance['totales'] ?? array_fill_keys(['debitos', 'creditos', 'deudor', 'acreedor', 'activo', 'pasivo', 'perdida', 'ganancia'], 0);
    $resultadoEjercicio = (int) ($t['ganancia'] ?? 0) - (int) ($t['perdida'] ?? 0);
    $totales = [
        'debitos' => (int) ($t['debitos'] ?? 0),
        'creditos' => (int) ($t['creditos'] ?? 0),
        'deudor' => (int) ($t['deudor'] ?? 0),
        'acreedor' => (int) ($t['acreedor'] ?? 0),
        'activo' => (int) ($t['activo'] ?? 0),
        'pasivo' => (int) ($t['pasivo'] ?? 0),
        'perdida' => (int) ($t['perdida'] ?? 0),
        'ganancia' => (int) ($t['ganancia'] ?? 0),
        'resultadoEjercicio' => $resultadoEjercicio,
        'cuentasInformadas' => count($cuentas),
        'cptPositivo' => (int) $r14['totales']['645'],
        'cptNegativo' => (int) $r14['totales']['646'],
    ];

    $estado = ctrib_estado_recuadro($pdo, $empresa, $anio, 'DJ1847');
    $snapshotLineas = array_map(fn($c) => ['codigo' => $c['codigo'], 'monto' => $c['debitos'] + $c['creditos'] + crc32($c['codigoIfrs'])], $cuentas);
    $actual = array_column($snapshotLineas, 'monto', 'codigo');
    $bloqueos = $balance === null ? ['Importe el balance del año para preparar la DJ 1847.'] : [];

    return [
        'declaracion' => 'DJ1847',
        'anio' => $anio,
        'anioComercial' => ctrib_anio_comercial($anio),
        'cuentas' => $cuentas,
        'clasificador' => array_values(array_map(fn($x) => ['codigo' => $x['codigo'], 'descripcion' => $x['descripcion'], 'grupo' => $x['grupo']], $catalogo)),
        'totales' => $totales,
        'estado' => $estado['estado'],
        'finalizado' => $estado['finalizado'],
        'usuario' => $estado['usuario'],
        'desactualizado' => ctrib_desactualizado($estado, $actual),
        'bloqueos' => $bloqueos,
        'puedeFinalizar' => $bloqueos === [] && $estado['estado'] !== 'FINALIZADO',
        'snapshotLineas' => $snapshotLineas,
    ];
}

function ctrib_dj1847_guardar_cuenta(PDO $pdo, int $empresa, int $anio, string $cuenta, string $codigoIfrs, string $usuario): array
{
    $balance = ctrib_balance_guardado($pdo, $empresa, $anio);
    if (!$balance || !in_array($cuenta, array_column($balance['cuentas'], 'codigo'), true)) {
        throw new CtribError("La cuenta $cuenta no existe en el balance del año.");
    }
    if (ctrib_estado_recuadro($pdo, $empresa, $anio, 'DJ1847')['estado'] === 'FINALIZADO') {
        throw new CtribError('La DJ 1847 está finalizada. Reábrala para modificarla.');
    }
    $catalogo = ctrib_ifrs_catalogo(false);
    if ($codigoIfrs !== '' && !isset($catalogo[$codigoIfrs])) {
        throw new CtribError("El código IFRS $codigoIfrs no existe en el clasificador del balance.");
    }
    $pdo->prepare('DELETE FROM ctrib_ifrs_cuentas WHERE empresa_id = ? AND anio = ? AND cuenta = ?')->execute([$empresa, $anio, $cuenta]);
    if ($codigoIfrs !== '') {
        $pdo->prepare('INSERT INTO ctrib_ifrs_cuentas (empresa_id, anio, cuenta, codigo_ifrs) VALUES (?, ?, ?, ?)')->execute([$empresa, $anio, $cuenta, $codigoIfrs]);
    }
    ctrib_registrar_historial($pdo, $empresa, $anio, 'dj1847', $cuenta, '', $codigoIfrs ?: 'sugerido', 'Clasificación IFRS DJ 1847', $usuario);
    return ctrib_dj1847($pdo, $empresa, $anio);
}

function ctrib_dj1847_finalizar(PDO $pdo, int $empresa, int $anio, string $usuario): array
{
    $d = ctrib_dj1847($pdo, $empresa, $anio);
    if ($d['estado'] === 'FINALIZADO') {
        throw new CtribError('La DJ 1847 ya está finalizada.');
    }
    if ($d['bloqueos'] !== []) {
        throw new CtribError(implode(' ', $d['bloqueos']));
    }
    ctrib_recuadro_finalizar($pdo, $empresa, $anio, 'DJ1847', $d['snapshotLineas'], $usuario);
    return ctrib_dj1847($pdo, $empresa, $anio);
}

function ctrib_dj1847_reabrir(PDO $pdo, int $empresa, int $anio, string $usuario, string $motivo): array
{
    if (ctrib_estado_recuadro($pdo, $empresa, $anio, 'DJ1847')['estado'] !== 'FINALIZADO') {
        throw new CtribError('La DJ 1847 no está finalizada.');
    }
    ctrib_recuadro_reabrir($pdo, $empresa, $anio, 'DJ1847', $usuario, $motivo);
    return ctrib_dj1847($pdo, $empresa, $anio);
}

/** DJ 1926: Base Imponible de Primera Categoría (Sección B y Cuadro Resumen Sección D) desde el Recuadro 12. */
function ctrib_dj1926(PDO $pdo, int $empresa, int $anio): array
{
    $r12 = ctrib_r12($pdo, $empresa, $anio);
    $anexo = ctrib_ifrs_catalogo(true);
    $filas = [];
    $totalAgregados = 0;
    $totalDeducciones = 0;
    $beneficio14E = 0;

    $resultadoFin = (int) ($r12['totales']['1672'] ?? 0);
    $filas[] = [
        'numero' => 1,
        'codigoR12' => '1672',
        'codigoAnexo' => '',
        'nombreAnexo' => 'Resultado financiero según balance',
        'descripcion' => 'Resultado financiero (código 1672 del Recuadro 12)',
        'monto' => $resultadoFin,
        'tipoAjuste' => 9,
        'tipoNombre' => 'Resultado Financiero [9]',
    ];

    $n = 1;
    foreach ($r12['lineas'] as $l) {
        $cod = (string) $l['codigo'];
        $monto = (int) $l['monto'];
        if ($monto === 0 || !isset(CTRIB_R12_A_ANEXO1926[$cod])) {
            continue;
        }
        $codAnexo = CTRIB_R12_A_ANEXO1926[$cod];
        $nombreAnexo = $anexo[$codAnexo]['descripcion'] ?? $l['glosa'];
        if ($cod === '1154') {
            $tipo = 4;
            $tipoNombre = 'Beneficio 14 E [4]';
            $beneficio14E += $monto;
        } elseif ($l['signo'] === '+') {
            $tipo = 1;
            $tipoNombre = 'Agregado [1]';
            $totalAgregados += $monto;
        } else {
            $tipo = 2;
            $tipoNombre = 'Deducción [2]';
            $totalDeducciones += $monto;
        }
        $n++;
        $filas[] = [
            'numero' => $n,
            'codigoR12' => $cod,
            'codigoAnexo' => $codAnexo,
            'nombreAnexo' => $nombreAnexo,
            'descripcion' => $l['glosa'],
            'monto' => $monto,
            'tipoAjuste' => $tipo,
            'tipoNombre' => $tipoNombre,
        ];
    }

    $rli = $resultadoFin + $totalAgregados - $totalDeducciones - $beneficio14E;
    $estado = ctrib_estado_recuadro($pdo, $empresa, $anio, 'DJ1926');
    $snapshotLineas = array_map(fn($f) => ['codigo' => (string) $f['numero'] . '_' . $f['codigoR12'], 'monto' => $f['monto']], $filas);
    $actual = array_column($snapshotLineas, 'monto', 'codigo');
    $bloqueos = $r12['estado'] === 'FINALIZADO' ? [] : ['Finalice el Recuadro 12 antes de finalizar la DJ 1926 (sus ajustes alimentan la Sección B).'];

    return [
        'declaracion' => 'DJ1926',
        'anio' => $anio,
        'anioComercial' => ctrib_anio_comercial($anio),
        'seccionB' => $filas,
        'resumen' => [
            'resultadoFinanciero' => $resultadoFin,
            'totalAgregados' => $totalAgregados,
            'totalDeducciones' => $totalDeducciones,
            'beneficio14E' => $beneficio14E,
            'rli' => $rli,
            'rliCodigo1690' => (int) ($r12['totales']['1690'] ?? 0),
            'casosInformados' => count($filas),
        ],
        'estado' => $estado['estado'],
        'finalizado' => $estado['finalizado'],
        'usuario' => $estado['usuario'],
        'desactualizado' => ctrib_desactualizado($estado, $actual),
        'bloqueos' => $bloqueos,
        'puedeFinalizar' => $bloqueos === [] && $estado['estado'] !== 'FINALIZADO',
        'snapshotLineas' => $snapshotLineas,
    ];
}

function ctrib_dj1926_finalizar(PDO $pdo, int $empresa, int $anio, string $usuario): array
{
    $d = ctrib_dj1926($pdo, $empresa, $anio);
    if ($d['estado'] === 'FINALIZADO') {
        throw new CtribError('La DJ 1926 ya está finalizada.');
    }
    if ($d['bloqueos'] !== []) {
        throw new CtribError(implode(' ', $d['bloqueos']));
    }
    ctrib_recuadro_finalizar($pdo, $empresa, $anio, 'DJ1926', $d['snapshotLineas'], $usuario);
    return ctrib_dj1926($pdo, $empresa, $anio);
}

function ctrib_dj1926_reabrir(PDO $pdo, int $empresa, int $anio, string $usuario, string $motivo): array
{
    if (ctrib_estado_recuadro($pdo, $empresa, $anio, 'DJ1926')['estado'] !== 'FINALIZADO') {
        throw new CtribError('La DJ 1926 no está finalizada.');
    }
    ctrib_recuadro_reabrir($pdo, $empresa, $anio, 'DJ1926', $usuario, $motivo);
    return ctrib_dj1926($pdo, $empresa, $anio);
}
