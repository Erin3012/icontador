<?php
// Recuadro N° 1 (Honorarios art. 42 N° 2 y directores S.A. art. 48 LIR) de Cifrax Tributario.
// Reglas: Suplemento Tributario F22 AT 2026 (pp. 40-43, 280, 308, 317-326).
// Lee boletas emitidas (honorarios_boletas, solo lectura) + factores IPC mensuales (factores-AAAA.json).
declare(strict_types=1);

require_once __DIR__ . '/ctrib-r4-r6.php';

const CTRIB_R1_UTA_DEFECTO = 834504;
const CTRIB_R1_UF_DEFECTO = 39727.96;
const CTRIB_R1_MESES = [
    1 => 'ENERO', 2 => 'FEBRERO', 3 => 'MARZO', 4 => 'ABRIL', 5 => 'MAYO', 6 => 'JUNIO',
    7 => 'JULIO', 8 => 'AGOSTO', 9 => 'SEPTIEMBRE', 10 => 'OCTUBRE', 11 => 'NOVIEMBRE', 12 => 'DICIEMBRE',
];
const CTRIB_R1_MANUALES = ['461', '492', '545', '1650', '856', '617', '770', '872', '465', '850', '479', '491', '896'];

/** Factores IPC mensuales, UTA y UF al 31.12 del año comercial desde factores-AAAA.json. */
function ctrib_r1_parametros(int $anioComercial): array
{
    $uta = CTRIB_R1_UTA_DEFECTO;
    $uf = CTRIB_R1_UF_DEFECTO;
    $factores = array_fill(1, 12, 1.0);

    $ruta = __DIR__ . '/ctrib/factores-' . $anioComercial . '.json';
    if (is_file($ruta)) {
        $datos = json_decode((string) file_get_contents($ruta), true);
        if (isset($datos['uta']) && (int) $datos['uta'] > 0) {
            $uta = (int) $datos['uta'];
        }
        if (isset($datos['uf31Diciembre']) && (float) $datos['uf31Diciembre'] > 0) {
            $uf = (float) $datos['uf31Diciembre'];
        }
        $mapaMes = array_flip(CTRIB_R1_MESES);
        foreach ($datos['correccionMonetaria'] ?? [] as $row) {
            $mes = $mapaMes[(string) ($row['mesOrigen'] ?? '')] ?? null;
            if ($mes !== null && isset($row['factorActualizacion'])) {
                $factores[$mes] = (float) $row['factorActualizacion'];
            }
        }
    }

    return [
        'uta' => $uta,
        'uf' => $uf,
        'topeGastosPresuntos' => (int) round(15 * $uta, 0, PHP_ROUND_HALF_UP),
        'topeApv' => (int) round(600 * $uf, 0, PHP_ROUND_HALF_UP),
        'factores' => $factores,
    ];
}

/**
 * Resume las boletas emitidas del año comercial (solo lectura desde honorarios_boletas, excluyendo anuladas)
 * aplicando el factor IPC correspondiente a cada mes.
 */
function ctrib_r1_boletas_emitidas(PDO $pdo, int $empresa, int $anioComercial, array $factores): array
{
    $mensual = [];
    foreach (CTRIB_R1_MESES as $m => $nombreMes) {
        $mensual[$m] = [
            'mes' => $m, 'nombre' => ucfirst(strtolower($nombreMes)), 'factor' => $factores[$m] ?? 1.0,
            'boletas' => 0, 'anuladas' => 0,
            'brutoConRet' => 0, 'retenido' => 0, 'brutoSinRet' => 0,
            'brutoConRetAct' => 0, 'retenidoAct' => 0, 'brutoSinRetAct' => 0,
        ];
    }

    try {
        $st = $pdo->prepare("SELECT periodo, estado, bruto, retenido FROM honorarios_boletas WHERE empresa_id = ? AND libro = 'emitidas' AND periodo LIKE ? ORDER BY periodo, id");
        $st->execute([$empresa, $anioComercial . '-%']);
        $rows = $st->fetchAll();
    } catch (Throwable) {
        $rows = [];
    }

    $totalBoletas = 0;
    $totalAnuladas = 0;
    foreach ($rows as $r) {
        $m = (int) substr((string) $r['periodo'], 5, 2);
        if ($m < 1 || $m > 12) {
            continue;
        }
        $mensual[$m]['boletas']++;
        $totalBoletas++;
        if (stripos((string) ($r['estado'] ?? ''), 'NUL') !== false) {
            $mensual[$m]['anuladas']++;
            $totalAnuladas++;
            continue;
        }
        $bruto = (int) $r['bruto'];
        $ret = (int) $r['retenido'];
        if ($ret > 0) {
            $mensual[$m]['brutoConRet'] += $bruto;
            $mensual[$m]['retenido'] += $ret;
        } else {
            $mensual[$m]['brutoSinRet'] += $bruto;
        }
    }

    $sum461 = 0;
    $sum492 = 0;
    $sum545 = 0;
    foreach ($mensual as $m => &$fila) {
        $f = (float) $fila['factor'];
        $fila['brutoConRetAct'] = (int) round($fila['brutoConRet'] * $f, 0, PHP_ROUND_HALF_UP);
        $fila['retenidoAct'] = (int) round($fila['retenido'] * $f, 0, PHP_ROUND_HALF_UP);
        $fila['brutoSinRetAct'] = (int) round($fila['brutoSinRet'] * $f, 0, PHP_ROUND_HALF_UP);
        $sum461 += $fila['brutoConRetAct'];
        $sum492 += $fila['retenidoAct'];
        $sum545 += $fila['brutoSinRetAct'];
    }
    unset($fila);

    return [
        'totalBoletas' => $totalBoletas,
        'totalAnuladas' => $totalAnuladas,
        'vigentes' => $totalBoletas - $totalAnuladas,
        'mensual' => array_values($mensual),
        'c461' => $sum461,
        'c492' => $sum492,
        'c545' => $sum545,
    ];
}

/** Cálculo completo del Recuadro N° 1 (Honorarios y directores S.A.) del año. */
function ctrib_r1(PDO $pdo, int $empresa, int $anio): array
{
    $anioComercial = ctrib_anio_comercial($anio);
    $params = ctrib_r1_parametros($anioComercial);
    $boletas = ctrib_r1_boletas_emitidas($pdo, $empresa, $anioComercial, $params['factores']);
    $manuales = ctrib_valores_manuales($pdo, $empresa, $anio, 'R1');

    // GMOD: 0 = PRESUNTOS (30% tope 15 UTA, por defecto), 1 = EFECTIVOS.
    $gastosModo = (($manuales['GMOD'] ?? 0) === 1) ? 'EFECTIVOS' : 'PRESUNTOS';

    $campo = function (string $cod, int $auto = 0) use ($manuales, $boletas): array {
        if (array_key_exists($cod, $manuales)) {
            return ['monto' => $manuales[$cod], 'origen' => 'MANUAL'];
        }
        return ['monto' => $auto, 'origen' => $auto > 0 && $boletas['vigentes'] > 0 ? 'AUTOMATICO' : 'MANUAL'];
    };

    $v461  = $campo('461', $boletas['c461']);
    $v492  = $campo('492', $boletas['c492']);
    $v545  = $campo('545', $boletas['c545']);
    $v1650 = $campo('1650');
    $v856  = $campo('856');

    $c547 = $v461['monto'] + $v545['monto'] + $v1650['monto'] + $v856['monto'];
    $v617 = $campo('617');
    $v770 = $campo('770');
    $v872 = $campo('872');
    $v465 = $campo('465');
    $v850 = $campo('850');

    if ($gastosModo === 'PRESUNTOS') {
        $c494 = min((int) round($c547 * 0.30, 0, PHP_ROUND_HALF_UP), $params['topeGastosPresuntos']);
        $origen494 = 'CALCULADO_CON_TOPE';
        $c465 = $v465['monto']; // En presuntos suele ser 0 salvo donaciones art. 7 Ley 16.282 / DL 45 (Suplemento p. 320).
    } else {
        $c494 = 0;
        $origen494 = 'CALCULADO';
        $c465 = $v465['monto'];
    }

    // Código 467: (547 + 617) - (770 + 872 + 465 + 494 + 850); si es negativo no se traslada al 618 (Suplemento p. 323).
    $c467 = ($c547 + $v617['monto']) - ($v770['monto'] + $v872['monto'] + $c465 + $c494 + $v850['monto']);

    $v479 = $campo('479');
    $v491 = $campo('491');
    $c618 = max(0, $c467) + $v479['monto'];
    $c619 = $v492['monto'] + $v491['monto'];
    $v896 = $campo('896');

    $lineas = [
        ['codigo' => '461', 'glosa' => 'Honorarios anuales con retención (renta actualizada)', 'seccion' => 'Ingresos brutos 2ª categoría', 'signo' => 'POSITIVO', 'naturaleza' => 'MANUAL', 'monto' => $v461['monto'], 'origen' => $v461['origen']],
        ['codigo' => '492', 'glosa' => 'Impuesto retenido actualizado por honorarios con retención', 'seccion' => 'Ingresos brutos 2ª categoría', 'signo' => 'POSITIVO', 'naturaleza' => 'MANUAL', 'monto' => $v492['monto'], 'origen' => $v492['origen']],
        ['codigo' => '545', 'glosa' => 'Honorarios anuales sin retención (renta actualizada)', 'seccion' => 'Ingresos brutos 2ª categoría', 'signo' => 'POSITIVO', 'naturaleza' => 'MANUAL', 'monto' => $v545['monto'], 'origen' => $v545['origen']],
        ['codigo' => '1650', 'glosa' => 'Honorarios líquidos percibidos de fuente extranjera', 'seccion' => 'Ingresos brutos 2ª categoría', 'signo' => 'POSITIVO', 'naturaleza' => 'MANUAL', 'monto' => $v1650['monto'], 'origen' => $v1650['origen']],
        ['codigo' => '856', 'glosa' => 'Incremento por impuestos soportados en el extranjero (art. 41 A)', 'seccion' => 'Ingresos brutos 2ª categoría', 'signo' => 'POSITIVO', 'naturaleza' => 'MANUAL', 'monto' => $v856['monto'], 'origen' => $v856['origen']],
        ['codigo' => '547', 'glosa' => 'Total ingresos brutos actualizados', 'seccion' => 'Ingresos brutos 2ª categoría', 'signo' => 'IGUAL', 'naturaleza' => 'CALCULADO', 'monto' => $c547, 'origen' => 'CALCULADO'],
        ['codigo' => '617', 'glosa' => 'Participación en sociedades de profesionales de 2ª categoría', 'seccion' => 'Deducciones y determinación', 'signo' => 'POSITIVO', 'naturaleza' => 'MANUAL', 'monto' => $v617['monto'], 'origen' => $v617['origen']],
        ['codigo' => '770', 'glosa' => 'Monto ahorro previsional voluntario (art. 42 bis inc. 1°, tope 600 UF)', 'seccion' => 'Deducciones y determinación', 'signo' => 'NEGATIVO', 'naturaleza' => 'MANUAL', 'monto' => $v770['monto'], 'origen' => $v770['origen']],
        ['codigo' => '872', 'glosa' => 'Gastos por donaciones para fines sociales (Ley 19.885 / Ley 21.450)', 'seccion' => 'Deducciones y determinación', 'signo' => 'NEGATIVO', 'naturaleza' => 'MANUAL', 'monto' => $v872['monto'], 'origen' => $v872['origen']],
        ['codigo' => '465', 'glosa' => 'Gastos efectivos (solo rebajables del código 547)', 'seccion' => 'Deducciones y determinación', 'signo' => 'NEGATIVO', 'naturaleza' => 'MANUAL', 'monto' => $c465, 'origen' => $v465['origen']],
        ['codigo' => '494', 'glosa' => 'Gastos presuntos (30% sobre código 547, con tope de 15 UTA)', 'seccion' => 'Deducciones y determinación', 'signo' => 'NEGATIVO', 'naturaleza' => 'CALCULADO', 'monto' => $c494, 'origen' => $origen494],
        ['codigo' => '850', 'glosa' => 'Rebaja por presunción de asignación de zona (DL N° 889/1975)', 'seccion' => 'Deducciones y determinación', 'signo' => 'NEGATIVO', 'naturaleza' => 'MANUAL', 'monto' => $v850['monto'], 'origen' => $v850['origen']],
        ['codigo' => '467', 'glosa' => 'Total honorarios netos (Línea 7 si positivo)', 'seccion' => 'Deducciones y determinación', 'signo' => 'IGUAL', 'naturaleza' => 'CALCULADO', 'monto' => $c467, 'origen' => 'CALCULADO'],
        ['codigo' => '479', 'glosa' => 'Total remuneraciones directores de S.A. (art. 48 LIR)', 'seccion' => 'Directores S.A. y totales', 'signo' => 'POSITIVO', 'naturaleza' => 'MANUAL', 'monto' => $v479['monto'], 'origen' => $v479['origen']],
        ['codigo' => '491', 'glosa' => 'Impuesto retenido actualizado directores de S.A.', 'seccion' => 'Directores S.A. y totales', 'signo' => 'POSITIVO', 'naturaleza' => 'MANUAL', 'monto' => $v491['monto'], 'origen' => $v491['origen']],
        ['codigo' => '618', 'glosa' => 'Total rentas actualizadas (trasladar a Línea 7, código 110)', 'seccion' => 'Directores S.A. y totales', 'signo' => 'IGUAL', 'naturaleza' => 'CALCULADO', 'monto' => $c618, 'origen' => 'CALCULADO'],
        ['codigo' => '619', 'glosa' => 'Total retenciones actualizadas (trasladar a Línea 91, código 198)', 'seccion' => 'Directores S.A. y totales', 'signo' => 'IGUAL', 'naturaleza' => 'CALCULADO', 'monto' => $c619, 'origen' => 'CALCULADO'],
        ['codigo' => '896', 'glosa' => 'Participaciones en ingresos brutos soc. profesionales 2ª cat. (informativo)', 'seccion' => 'Directores S.A. y totales', 'signo' => 'IGUAL', 'naturaleza' => 'MANUAL', 'monto' => $v896['monto'], 'origen' => $v896['origen']],
    ];

    $bloqueos = [];
    if ($v770['monto'] > $params['topeApv']) {
        $bloqueos[] = 'El ahorro previsional (770) no puede superar el tope legal de 600 UF ($' . number_format($params['topeApv'], 0, ',', '.') . ').';
    }
    $regimen = ctrib_empresa_config($pdo, $empresa)['regimen'];
    $avisos = [];
    if ($gastosModo === 'PRESUNTOS' && $c465 > 0) {
        $avisos[] = 'Con gastos presuntos (494), el código 465 solo admite donaciones del art. 7° Ley 16.282 o art. 3° DL 45; si opta por gastos efectivos, cambie la modalidad a Efectivos.';
    }
    if ($c467 < 0) {
        $avisos[] = 'El total de honorarios (467) resultó negativo ($' . number_format($c467, 0, ',', '.') . '): no se traslada al código 618 ni a la Línea 7 del F22 (Suplemento p. 323).';
    }
    if (!in_array($regimen, ['PERSONA', ''], true)) {
        $avisos[] = 'Recuadro N° 1 aplica a personas naturales de 2ª categoría (art. 42 N° 2 / art. 48) o sociedades de profesionales de 2ª categoría.';
    }

    $snapshotMontos = array_column($lineas, 'monto', 'codigo');
    $snapshotMontos['GMOD'] = $gastosModo === 'EFECTIVOS' ? 1 : 0;

    $estado = ctrib_estado_recuadro($pdo, $empresa, $anio, 'R1');
    $finalizado = $estado['estado'] === 'FINALIZADO';

    return [
        'recuadro' => 'R1',
        'anio' => $anio,
        'anioComercial' => $anioComercial,
        'gastosModo' => $gastosModo,
        'parametros' => [
            'uta' => $params['uta'],
            'uf31Diciembre' => $params['uf'],
            'topeGastosPresuntos' => $params['topeGastosPresuntos'],
            'topeApv' => $params['topeApv'],
        ],
        'boletas' => [
            'totalBoletas' => $boletas['totalBoletas'],
            'anuladas' => $boletas['totalAnuladas'],
            'vigentes' => $boletas['vigentes'],
            'mensual' => $boletas['mensual'],
        ],
        'cotizacionesReferencia' => [
            'baseImponiblePrevisional' => (int) round($c547 * 0.80, 0, PHP_ROUND_HALF_UP),
            'retencionesArt74N2' => $v492['monto'],
            'nota' => 'Ley 21.133 / art. 92 F DL 3.500: la base imponible previsional anual corresponde al 80% de los honorarios brutos; las retenciones (492) y PPM 2ª cat. (1905/1906) se imputan con preeminencia al código 900 determinado por el SII.',
        ],
        'totales' => ['547' => $c547, '494' => $c494, '467' => $c467, '492' => $v492['monto'], '618' => $c618, '619' => $c619],
        'lineas' => $lineas,
        'snapshotMontos' => $snapshotMontos,
        'estado' => $estado['estado'],
        'finalizado' => $estado['finalizado'],
        'usuario' => $estado['usuario'],
        'desactualizado' => ctrib_desactualizado($estado, $snapshotMontos),
        'bloqueos' => $bloqueos,
        'avisos' => $avisos,
        'puedeFinalizar' => $bloqueos === [] && !$finalizado,
    ];
}

function ctrib_r1_guardar_valor(PDO $pdo, int $empresa, int $anio, string $codigo, mixed $monto, string $usuario): array
{
    if (is_numeric($monto) && (int) $monto < 0) {
        throw new CtribError('El monto no puede ser negativo: el signo lo aplica el formulario.');
    }
    ctrib_guardar_valor_manual($pdo, $empresa, $anio, 'R1', $codigo, $monto, $usuario, CTRIB_R1_MANUALES);
    return ctrib_r1($pdo, $empresa, $anio);
}

function ctrib_r1_modo_gastos(PDO $pdo, int $empresa, int $anio, string $modo, string $usuario): array
{
    if (!in_array($modo, ['PRESUNTOS', 'EFECTIVOS'], true)) {
        throw new CtribError('Modalidad de gastos inválida: use PRESUNTOS o EFECTIVOS.');
    }
    $val = $modo === 'EFECTIVOS' ? 1 : 0;
    ctrib_guardar_valor_manual($pdo, $empresa, $anio, 'R1', 'GMOD', $val, $usuario, ['GMOD']);
    return ctrib_r1($pdo, $empresa, $anio);
}

function ctrib_r1_finalizar(PDO $pdo, int $empresa, int $anio, string $usuario): array
{
    $r = ctrib_r1($pdo, $empresa, $anio);
    if ($r['estado'] === 'FINALIZADO') {
        throw new CtribError('El Recuadro 1 ya está finalizado.');
    }
    if ($r['bloqueos'] !== []) {
        throw new CtribError(implode(' ', $r['bloqueos']));
    }
    $lineasSnap = [];
    foreach ($r['snapshotMontos'] as $cod => $monto) {
        $lineasSnap[] = ['codigo' => (string) $cod, 'monto' => (int) $monto];
    }
    ctrib_recuadro_finalizar($pdo, $empresa, $anio, 'R1', $lineasSnap, $usuario);
    return ctrib_r1($pdo, $empresa, $anio);
}

function ctrib_r1_reabrir(PDO $pdo, int $empresa, int $anio, string $usuario, string $motivo): array
{
    if (ctrib_estado_recuadro($pdo, $empresa, $anio, 'R1')['estado'] !== 'FINALIZADO') {
        throw new CtribError('El Recuadro 1 no está finalizado.');
    }
    ctrib_recuadro_reabrir($pdo, $empresa, $anio, 'R1', $usuario, $motivo);
    return ctrib_r1($pdo, $empresa, $anio);
}
