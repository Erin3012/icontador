<?php
// Recuadro 17 (Base Imponible Pro Pyme) de Cifrax Tributario: cálculo por código, campos manuales, incentivo al ahorro,
// finalizar y reabrir. Reglas según el catálogo y el cálculo de iTributario (ENYEL AT 2026, ver itributario_inventario.md).
declare(strict_types=1);

require_once __DIR__ . '/ctrib-ajustes.php';

const CTRIB_R17_MANUALES = ['1404', '1405', '1406', '1407', '1408', '1426', '1433'];
const CTRIB_R17_TOPE_UF = 5000; // tope del incentivo al ahorro (art. 14 E LIR) en UF
const CTRIB_TASA_PRO_PYME_DEFECTO = 12.5; // tasa Pro Pyme vigente para 2025 (tasas-2025.json)

/** Tasa del IDPC Pro Pyme del año comercial (tasas-AAAA.json), o la de defecto si no está la tabla. */
function ctrib_tasa_pro_pyme(int $anioComercial): float
{
    $ruta = __DIR__ . '/ctrib/tasas-' . $anioComercial . '.json';
    if (is_file($ruta)) {
        $datos = json_decode((string) file_get_contents($ruta), true);
        if (isset($datos['tasaProPyme'])) {
            return (float) $datos['tasaProPyme'];
        }
    }
    return CTRIB_TASA_PRO_PYME_DEFECTO;
}

/** Valores manuales del recuadro: código => monto (magnitud positiva; el signo lo da el catálogo). */
function ctrib_valores_manuales(PDO $pdo, int $empresa, int $anio, string $recuadro): array
{
    $st = $pdo->prepare('SELECT codigo, monto FROM ctrib_valores WHERE empresa_id = ? AND anio = ? AND recuadro = ?');
    $st->execute([$empresa, $anio, $recuadro]);
    $salida = [];
    foreach ($st as $f) {
        $salida[(string) $f['codigo']] = (int) $f['monto'];
    }
    return $salida;
}

function ctrib_estado_recuadro(PDO $pdo, int $empresa, int $anio, string $recuadro): array
{
    $st = $pdo->prepare('SELECT estado, finalizado, usuario, snapshot FROM ctrib_recuadros WHERE empresa_id = ? AND anio = ? AND recuadro = ?');
    $st->execute([$empresa, $anio, $recuadro]);
    $f = $st->fetch();
    if (!$f) {
        return ['estado' => 'BORRADOR', 'finalizado' => null, 'usuario' => null, 'snapshot' => null];
    }
    return ['estado' => $f['estado'], 'finalizado' => $f['finalizado'] ?: null, 'usuario' => $f['usuario'] ?: null,
        'snapshot' => $f['snapshot'] ? json_decode($f['snapshot'], true) : null];
}

/** Un recuadro finalizado está desactualizado si alguna de sus líneas cambió desde el snapshot. */
function ctrib_desactualizado(array $estado, array $montosActuales): bool
{
    return $estado['estado'] === 'FINALIZADO' && $estado['snapshot'] !== null
        && (array_diff_assoc($montosActuales, $estado['snapshot']) !== [] || array_diff_assoc($estado['snapshot'], $montosActuales) !== []);
}

/** Guarda el recuadro como FINALIZADO con el snapshot de sus líneas y lo registra en el historial. */
function ctrib_recuadro_finalizar(PDO $pdo, int $empresa, int $anio, string $recuadro, array $lineas, string $usuario): void
{
    $snapshot = [];
    foreach ($lineas as $l) {
        $snapshot[$l['codigo']] = $l['monto'];
    }
    $pdo->prepare('DELETE FROM ctrib_recuadros WHERE empresa_id = ? AND anio = ? AND recuadro = ?')->execute([$empresa, $anio, $recuadro]);
    $pdo->prepare('INSERT INTO ctrib_recuadros (empresa_id, anio, recuadro, estado, finalizado, usuario, snapshot) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$empresa, $anio, $recuadro, 'FINALIZADO', ctrib_ahora(), $usuario, json_encode($snapshot)]);
    ctrib_registrar_historial($pdo, $empresa, $anio, 'recuadro', $recuadro, 'BORRADOR', 'FINALIZADO', 'Recuadro finalizado', $usuario);
}

function ctrib_recuadro_reabrir(PDO $pdo, int $empresa, int $anio, string $recuadro, string $usuario, string $motivo): void
{
    $pdo->prepare('UPDATE ctrib_recuadros SET estado = ?, finalizado = ?, usuario = ? WHERE empresa_id = ? AND anio = ? AND recuadro = ?')
        ->execute(['BORRADOR', '', '', $empresa, $anio, $recuadro]);
    ctrib_registrar_historial($pdo, $empresa, $anio, 'recuadro', $recuadro, 'FINALIZADO', 'BORRADOR', $motivo !== '' ? $motivo : 'Recuadro reabierto', $usuario);
}

/** UF al 31 de diciembre del año comercial, si la tabla de factores del año está en el repositorio. */
function ctrib_uf(int $anioComercial): ?float
{
    $ruta = __DIR__ . '/ctrib/factores-' . $anioComercial . '.json';
    if (!is_file($ruta)) {
        return null;
    }
    $datos = json_decode((string) file_get_contents($ruta), true);
    return isset($datos['uf31Diciembre']) ? (float) $datos['uf31Diciembre'] : null;
}

/** Cálculo completo del R17 del año: líneas por código en orden del catálogo, totales, incentivo y estado. */
function ctrib_r17(PDO $pdo, int $empresa, int $anio): array
{
    $catalogo = ctrib_catalogo_r17();
    $homologacion = ctrib_homologacion($pdo, $empresa, $anio);
    $manuales = ctrib_valores_manuales($pdo, $empresa, $anio, 'R17');
    $cuentasPorCodigo = [];
    foreach ($homologacion['codigos'] as $c) {
        $cuentasPorCodigo[$c['codigo']] = $c['cuentas'];
    }

    $montos = [];   // código => monto final (positivo)
    $lineas = [];
    foreach ($catalogo as $codigo => $c) {
        $linea = [
            'codigo' => (string) $codigo, 'glosa' => $c['glosa'], 'seccion' => $c['seccion'], 'signo' => $c['signo'],
            'naturaleza' => $c['naturalezaConfiguracion'], 'monto' => 0, 'origen' => 'CALCULADO', 'cuentas' => [], 'huboPisoEnCero' => false,
        ];
        if ($c['naturalezaConfiguracion'] === 'CUENTA_DEPENDIENTE') {
            $cuentas = $cuentasPorCodigo[$codigo] ?? [];
            $suma = array_sum(array_column($cuentas, 'saldo'));
            $linea['monto'] = max(0, $suma);
            $linea['huboPisoEnCero'] = $suma < 0;
            $linea['origen'] = 'CUENTAS';
            $linea['cuentas'] = array_map(fn($x) => ['cuenta' => $x['cuenta'], 'nombre' => $x['nombre'], 'saldo' => $x['saldo']], $cuentas);
        } elseif ($c['naturalezaConfiguracion'] === 'MANUAL') {
            $linea['monto'] = $manuales[$codigo] ?? 0;
            $linea['origen'] = 'MANUAL';
        }
        $montos[$codigo] = $linea['monto'];
        $lineas[$codigo] = $linea;
    }

    // Cuentas de ajuste CXC / CXP: de devengado a percibido o pagado, y reparto FIFO al 1817 y al 1818.
    $devengados = [];
    foreach ($lineas as $codigo => $l) {
        if ($l['origen'] === 'CUENTAS') {
            $devengados[(string) $codigo] = array_sum(array_column($l['cuentas'], 'saldo'));
        }
    }
    // Existencias del 1409: su devengado suma la variación del año (existencia final − inicial). Si el 1409 también tiene
    // cuentas de ajuste CXP, el pagado se calcula sobre este devengado.
    $existencias = ctrib_existencias($pdo, $empresa, $anio);
    $devengado1409 = (int) ($devengados['1409'] ?? 0);
    if ($existencias['configurado'] && isset($lineas['1409'])) {
        $devengados['1409'] = $devengado1409 + $existencias['variacion'];
        $lineas['1409']['monto'] = max(0, $devengados['1409']);
        $lineas['1409']['huboPisoEnCero'] = $devengados['1409'] < 0;
        $lineas['1409']['origen'] = 'CUENTAS_EXISTENCIAS';
        $montos['1409'] = $lineas['1409']['monto'];
    }
    if (isset($lineas['1409'])) {
        $lineas['1409']['existencias'] = $existencias + ['devengadoCuentas' => $devengado1409, 'devengadoConExistencias' => (int) ($devengados['1409'] ?? 0)];
    }
    $ajustes = ctrib_ajustes($pdo, $empresa, $anio, $devengados);
    $reparto = ['1817' => 0, '1818' => 0];
    foreach ($ajustes as $codigo => $a) {
        if (!$a['configurado'] || !isset($lineas[$codigo])) {
            continue;
        }
        $lineas[$codigo]['monto'] = $a['montoCodigo'];
        $lineas[$codigo]['huboPisoEnCero'] = $a['huboPisoEnCero'];
        $lineas[$codigo]['origen'] = 'CUENTAS_AJUSTADAS';
        $montos[$codigo] = $a['montoCodigo'];
        if ($a['destinoReparto'] !== null) {
            $reparto[$a['destinoReparto']] += $a['repartoAnioAnterior'];
        }
    }
    foreach ($reparto as $codigo => $monto) {
        if (isset($lineas[$codigo])) {
            $montos[$codigo] = $monto;
            $lineas[$codigo]['monto'] = $monto;
            $lineas[$codigo]['origen'] = 'REPARTO_FIFO';
        }
    }
    foreach ($ajustes as $codigo => $a) {
        if (isset($lineas[$codigo])) {
            $lineas[$codigo]['ajuste'] = $a;
        }
    }

    // Códigos calculados a partir de otros.
    $m1422 = $montos['1422'] ?? 0;
    $montos['1431'] = $m1422;                       // copia del 1422: anula su efecto en la base
    $lineas['1431']['monto'] = $m1422;
    $lineas['1431']['origen'] = 'COPIA_1422';

    $total = static function (string $seccion, string $signo) use ($catalogo, $montos): int {
        $suma = 0;
        foreach ($catalogo as $codigo => $c) {
            if ($c['seccion'] === $seccion && $c['signo'] === $signo && isset($montos[$codigo])) {
                $suma += $montos[$codigo];
            }
        }
        return $suma;
    };
    $t1410 = $total('INGRESOS', 'POSITIVO');
    $t1430 = $total('EGRESOS', 'NEGATIVO') - $montos['1431'];
    $t1729 = $t1410 - $t1430;

    // Incentivo al ahorro (art. 14 E): tope del 50% de la base reinvertida y 5.000 UF.
    $modoRetiros = ctrib_retiros_modo($pdo, $empresa, $anio);
    $retiros = $modoRetiros === 'importados' ? ctrib_retiros_total($pdo, $empresa, $anio) : 0;
    $baseReinvertida = $t1729 - $m1422 - $retiros;
    if ($modoRetiros === 'totalidad') {
        $baseReinvertida = 0; // se retira todo: no queda base reinvertida ni incentivo al ahorro
    }
    $topeMitad = $baseReinvertida * 0.5;
    $uf = ctrib_uf($anio - 1);
    $topeUf = $uf !== null ? CTRIB_R17_TOPE_UF * $uf : null;
    $tope = $topeUf !== null ? min($topeMitad, $topeUf) : $topeMitad;
    // Sugerido: el menor entre el 50% de la base reinvertida y 5.000 UF. Se puede usar un monto menor (no mayor).
    $sugerido = $baseReinvertida > 0 ? $tope : 0.0;
    $sugeridoRedondeado = (int) round($sugerido, 0, PHP_ROUND_HALF_UP);
    $incentivo = ctrib_incentivo($pdo, $empresa, $anio);
    $m1432 = $incentivo['monto'] !== null ? min($incentivo['monto'], $sugeridoRedondeado) : $sugeridoRedondeado;
    $m1433 = $montos['1433'] ?? 0;
    $t1440 = $t1729 - $m1432 - $m1433;

    $asignar = function (string $codigo, int $monto) use (&$montos, &$lineas): void {
        $montos[$codigo] = $monto;
        $lineas[$codigo]['monto'] = $monto;
    };
    $asignar('1410', $t1410);
    $asignar('1430', $t1430);
    $asignar('1729', $t1729);
    $asignar('1432', $m1432);
    $asignar('1440', $t1440);
    $lineas['1432']['origen'] = $incentivo['monto'] !== null ? 'MANUAL' : 'CALCULADO_CON_TOPE';

    // IDPC estimado sobre el 1440, solo en 14 D N°3 (o sin régimen configurado). Las reglas de 14 D N°8 esperan al contador.
    $regimen = ctrib_empresa_config($pdo, $empresa)['regimen'];
    $idpcEstimado = null;
    if (in_array($regimen, ['14D3', ''], true)) {
        $tasa = ctrib_tasa_pro_pyme(ctrib_anio_comercial($anio));
        $baseIdpc = max(0, $t1440);
        $idpcEstimado = ['tasa' => $tasa, 'base' => $baseIdpc, 'monto' => (int) round($baseIdpc * $tasa / 100, 0, PHP_ROUND_HALF_UP)];
    }

    $estado = ctrib_estado_recuadro($pdo, $empresa, $anio, 'R17');
    $actual = array_map(fn($x) => $x['monto'], $lineas);
    $desactualizado = ctrib_desactualizado($estado, $actual);

    $bloqueos = [];
    if ($homologacion['pendientes'] > 0) {
        $bloqueos[] = "Hay {$homologacion['pendientes']} cuenta(s) de resultado sin código: asígnelas en la homologación.";
    }
    if ($homologacion['alertas'] > 0) {
        $bloqueos[] = "Hay {$homologacion['alertas']} cuenta(s) con código incompatible: corríjalas en la homologación.";
    }
    $confirmado = $incentivo['elegibilidad'] && $incentivo['limiteIngresos'] && $incentivo['limiteRentasPasivas'];
    if ($m1432 > 0 && $uf === null && $estado['estado'] !== 'FINALIZADO') {
        $bloqueos[] = 'No se puede comprobar el tope de ' . number_format(CTRIB_R17_TOPE_UF, 0, ',', '.') . ' UF del incentivo al ahorro: falta la UF al 31-12-'
            . ($anio - 1) . ' (archivo factores-' . ($anio - 1) . '.json). Cárguela o declare monto 0 en el código 1432.';
    }
    if ($m1432 > 0 && !$confirmado && $estado['estado'] !== 'FINALIZADO') {
        $bloqueos[] = 'No se puede finalizar: el incentivo al ahorro (código 1432) se está aplicando sin confirmar los requisitos. '
            . 'En el código 1432 declare la elegibilidad, confirme que el promedio anual de ingresos del giro de los tres años comerciales '
            . 'anteriores no excede de 100.000 UF y que los ingresos por rentas pasivas no superan el 20% del total de ingresos del ejercicio, y guarde.';
    }

    return [
        'recuadro' => 'R17', 'anio' => $anio, 'anioComercial' => ctrib_anio_comercial($anio), 'modoRetiros' => $modoRetiros,
        'lineas' => array_values($lineas),
        'totales' => ['1410' => $t1410, '1430' => $t1430, '1729' => $t1729, '1432' => $m1432, '1440' => $t1440],
        'incentivoAhorro' => [
            'base1729' => $t1729, 'retiros' => $modoRetiros === 'totalidad' ? null : $retiros, 'monto1422' => $m1422,
            'baseReinvertida' => $baseReinvertida, 'topeMitad' => $topeMitad, 'topeUf' => $topeUf,
            'ufUsada' => $uf, 'sugerido' => $sugerido, 'sugeridoRedondeado' => $sugeridoRedondeado,
            'montoManual' => $incentivo['monto'], 'montoUtilizado' => $m1432, 'topeUfAplicado' => $topeUf !== null && $topeUf < $topeMitad,
            'elegibilidad' => $incentivo['elegibilidad'], 'limiteIngresos' => $incentivo['limiteIngresos'],
            'limiteRentasPasivas' => $incentivo['limiteRentasPasivas'], 'confirmado' => $confirmado,
        ],
        'regimen' => $regimen, 'idpcEstimado' => $idpcEstimado,
        'estado' => $estado['estado'], 'finalizado' => $estado['finalizado'], 'usuario' => $estado['usuario'],
        'desactualizado' => $desactualizado,
        'pendientesDeCalculo' => [],
        'bloqueos' => $bloqueos,
        'puedeFinalizar' => $bloqueos === [] && $estado['estado'] !== 'FINALIZADO',
    ];
}

/** Confirmaciones del incentivo al ahorro (1432) y monto elegido (null = el sugerido). */
function ctrib_incentivo(PDO $pdo, int $empresa, int $anio): array
{
    $st = $pdo->prepare('SELECT elegibilidad, limite_ingresos, limite_rentas_pasivas, monto FROM ctrib_incentivo WHERE empresa_id = ? AND anio = ?');
    $st->execute([$empresa, $anio]);
    $f = $st->fetch();
    return [
        'elegibilidad' => (bool) ($f['elegibilidad'] ?? false), 'limiteIngresos' => (bool) ($f['limite_ingresos'] ?? false),
        'limiteRentasPasivas' => (bool) ($f['limite_rentas_pasivas'] ?? false),
        'monto' => isset($f['monto']) && $f['monto'] !== null ? (int) $f['monto'] : null,
    ];
}

/**
 * Guarda las confirmaciones del incentivo al ahorro y el monto a utilizar (null o vacío = el sugerido).
 * El monto no puede superar el sugerido (el menor entre el 50% de la base reinvertida y 5.000 UF).
 */
function ctrib_incentivo_guardar(PDO $pdo, int $empresa, int $anio, bool $elegibilidad, bool $limiteIngresos, bool $limiteRentasPasivas, mixed $monto, string $usuario): array
{
    if (ctrib_estado_recuadro($pdo, $empresa, $anio, 'R17')['estado'] === 'FINALIZADO') {
        throw new CtribError('El Recuadro 17 está finalizado. Reábralo para modificar el incentivo al ahorro.');
    }
    $valor = null;
    if ($monto !== null && $monto !== '') {
        if (!(is_int($monto) || (is_string($monto) && preg_match('/^\d+$/', trim($monto)))) || (int) $monto < 0) {
            throw new CtribError('El monto a utilizar debe ser un número entero mayor o igual a cero.');
        }
        $valor = (int) $monto;
        $sugerido = ctrib_r17($pdo, $empresa, $anio)['incentivoAhorro']['sugeridoRedondeado'];
        if ($valor > $sugerido) {
            throw new CtribError('El monto a utilizar no puede superar el sugerido ($' . number_format($sugerido, 0, ',', '.') . ').');
        }
    }
    $antes = ctrib_incentivo($pdo, $empresa, $anio);
    $pdo->prepare('DELETE FROM ctrib_incentivo WHERE empresa_id = ? AND anio = ?')->execute([$empresa, $anio]);
    $pdo->prepare('INSERT INTO ctrib_incentivo (empresa_id, anio, elegibilidad, limite_ingresos, limite_rentas_pasivas, monto, usuario, actualizado) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$empresa, $anio, (int) $elegibilidad, (int) $limiteIngresos, (int) $limiteRentasPasivas, $valor, $usuario, ctrib_ahora()]);
    $describe = fn(array $i) => 'elegibilidad ' . ($i['elegibilidad'] ? 'sí' : 'no') . ', ingresos ' . ($i['limiteIngresos'] ? 'sí' : 'no')
        . ', rentas pasivas ' . ($i['limiteRentasPasivas'] ? 'sí' : 'no') . ', monto ' . ($i['monto'] === null ? 'sugerido' : $i['monto']);
    $despues = ['elegibilidad' => $elegibilidad, 'limiteIngresos' => $limiteIngresos, 'limiteRentasPasivas' => $limiteRentasPasivas, 'monto' => $valor];
    if ($describe($antes) !== $describe($despues)) {
        ctrib_registrar_historial($pdo, $empresa, $anio, 'recuadro', 'R17 1432', $describe($antes), $describe($despues), 'Incentivo al ahorro', $usuario);
    }
    return ctrib_r17($pdo, $empresa, $anio);
}

/** Guarda un campo manual del R17. No se edita un recuadro finalizado. */
function ctrib_r17_guardar_manual(PDO $pdo, int $empresa, int $anio, string $codigo, mixed $monto, string $usuario): array
{
    if (!in_array($codigo, CTRIB_R17_MANUALES, true)) {
        throw new CtribError("El código $codigo no se ingresa a mano.");
    }
    $esEntero = is_int($monto) || (is_string($monto) && preg_match('/^\d+$/', trim($monto)));
    if (!$esEntero || (int) $monto < 0) {
        throw new CtribError('El monto debe ser un número entero mayor o igual a cero.');
    }
    if (ctrib_estado_recuadro($pdo, $empresa, $anio, 'R17')['estado'] === 'FINALIZADO') {
        throw new CtribError('El Recuadro 17 está finalizado. Reábralo para modificarlo.');
    }
    $valor = (int) $monto;
    $anterior = ctrib_valores_manuales($pdo, $empresa, $anio, 'R17')[$codigo] ?? 0;
    $pdo->prepare('DELETE FROM ctrib_valores WHERE empresa_id = ? AND anio = ? AND recuadro = ? AND codigo = ?')->execute([$empresa, $anio, 'R17', $codigo]);
    $pdo->prepare('INSERT INTO ctrib_valores (empresa_id, anio, recuadro, codigo, monto, usuario, actualizado) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$empresa, $anio, 'R17', $codigo, $valor, $usuario, ctrib_ahora()]);
    if ($anterior !== $valor) {
        ctrib_registrar_historial($pdo, $empresa, $anio, 'recuadro', "R17 $codigo", (string) $anterior, (string) $valor, 'Valor manual', $usuario);
    }
    return ctrib_r17($pdo, $empresa, $anio);
}

/** Finaliza el R17: exige homologación completa y sin incompatibilidades. Guarda el snapshot de las líneas. */
function ctrib_r17_finalizar(PDO $pdo, int $empresa, int $anio, string $usuario): array
{
    $r17 = ctrib_r17($pdo, $empresa, $anio);
    if ($r17['estado'] === 'FINALIZADO') {
        throw new CtribError('El Recuadro 17 ya está finalizado.');
    }
    if ($r17['bloqueos'] !== []) {
        throw new CtribError(implode(' ', $r17['bloqueos']));
    }
    ctrib_recuadro_finalizar($pdo, $empresa, $anio, 'R17', $r17['lineas'], $usuario);
    return ctrib_r17($pdo, $empresa, $anio);
}

/** Reabre el R17 finalizado para corregirlo. Queda en el historial. */
function ctrib_r17_reabrir(PDO $pdo, int $empresa, int $anio, string $usuario, string $motivo): array
{
    if (ctrib_estado_recuadro($pdo, $empresa, $anio, 'R17')['estado'] !== 'FINALIZADO') {
        throw new CtribError('El Recuadro 17 no está finalizado.');
    }
    ctrib_recuadro_reabrir($pdo, $empresa, $anio, 'R17', $usuario, $motivo);
    return ctrib_r17($pdo, $empresa, $anio);
}

/**
 * Retiros del año según el modo: los importados, ninguno ('sin_retiros'), o en 'totalidad' la base imponible
 * completa (1440 del R17, o 1630 del R22 en 14 D N° 8) atribuida a los socios según su participación, al 31-12.
 * La usan R19 (1479), RTRE, R6 (1193) y la DJ 1948.
 */
function ctrib_retiros_del_anio(PDO $pdo, int $empresa, int $anio): array
{
    $modo = ctrib_retiros_modo($pdo, $empresa, $anio);
    if ($modo === 'sin_retiros') {
        return ['modo' => $modo, 'total' => 0, 'retiros' => []];
    }
    if ($modo === 'importados') {
        $lista = ctrib_retiros_listar($pdo, $empresa, $anio);
        return ['modo' => $modo, 'total' => array_sum(array_column($lista, 'monto')), 'retiros' => $lista];
    }
    if (ctrib_empresa_config($pdo, $empresa)['regimen'] === '14D8') {
        require_once __DIR__ . '/ctrib-r22.php';
        $base = max(0, (int) ctrib_r22_calculo($pdo, $empresa, $anio)['totales']['1630']);
    } else {
        $base = max(0, (int) ctrib_r17($pdo, $empresa, $anio)['totales']['1440']);
    }
    $socios = ctrib_socios($pdo, $empresa, $anio);
    $partes = ctrib_repartir($base, array_column($socios, 'participacion'));
    $fecha = ctrib_anio_comercial($anio) . '-12-31';
    $retiros = [];
    foreach ($socios as $i => $s) {
        if ($partes[$i] > 0) {
            $retiros[] = ['rut' => $s['rut'], 'nombre' => $s['nombre'], 'fecha' => $fecha, 'monto' => $partes[$i],
                'tipo' => 'Atribución total', 'participacion' => $s['participacion']];
        }
    }
    return ['modo' => $modo, 'total' => array_sum(array_column($retiros, 'monto')), 'retiros' => $retiros];
}

/**
 * Cuadro de composición y retiros del año: socios (o titular/accionistas) con su participación, la utilidad del
 * ejercicio que les corresponde según el balance, y lo retirado en el año según el modo de retiros.
 */
function ctrib_composicion_retiros(PDO $pdo, int $empresa, int $anio): array
{
    $config = ctrib_empresa_config($pdo, $empresa);
    $balance = ctrib_balance_guardado($pdo, $empresa, $anio);
    $utilidad = $balance ? max(0, (int) $balance['resultado']) : 0;
    $efectivos = ctrib_retiros_del_anio($pdo, $empresa, $anio);
    $retiradoPorRut = [];
    foreach ($efectivos['retiros'] as $r) {
        $retiradoPorRut[$r['rut']] = ($retiradoPorRut[$r['rut']] ?? 0) + (int) $r['monto'];
    }
    $socios = ctrib_socios($pdo, $empresa, $anio);
    $utilidades = ctrib_repartir($utilidad, array_column($socios, 'participacion'));
    $filas = [];
    foreach ($socios as $i => $s) {
        $filas[] = $s + ['utilidad' => $utilidades[$i] ?? 0, 'retirado' => $retiradoPorRut[$s['rut']] ?? 0];
    }
    $avisos = [];
    if (array_filter($socios, fn($s) => $s['rut'] === '')) {
        $avisos[] = 'Falta el RUT de un socio o titular: es necesario para la DJ 1948 y el Certificado 70.';
    }
    $enCuadro = array_column($socios, 'rut');
    $fuera = [];
    foreach ($efectivos['retiros'] as $r) {
        if (!in_array($r['rut'], $enCuadro, true)) {
            $quien = trim($r['rut'] . ' ' . $r['nombre']);
            $fuera[$quien] = "Hay retiros de $quien, que no está en el cuadro: agréguelo como socio o corrija el archivo de retiros.";
        }
    }
    $avisos = array_merge($avisos, array_values($fuera));
    return [
        'tipo' => $config['tipo'], 'regimen' => $config['regimen'], 'modo' => $efectivos['modo'],
        'utilidad' => $utilidad, 'socios' => $filas, 'totalRetirado' => $efectivos['total'], 'avisos' => $avisos,
    ];
}

/**
 * Aplica los montos del cuadro a los retiros del año, socio por socio. Solo se reemplaza lo que el usuario cambió:
 * si el total coincide se conservan las fechas importadas; un socio nuevo en el cuadro con monto cero no borra
 * sus retiros; los de personas que nunca estuvieron en el cuadro se conservan; quitar un socio borra los suyos.
 */
function ctrib_composicion_retiros_guardar(PDO $pdo, int $empresa, int $anio, array $socios, array $previos, string $fecha, string $usuario): void
{
    $ruts = array_column($socios, 'rut');
    $totalAntes = ctrib_retiros_total($pdo, $empresa, $anio);
    // Retiros cargados sin RUT: pasan al socio del mismo nombre cuando este ya tiene RUT.
    if (!in_array('', $ruts, true)) {
        $sinRut = $pdo->prepare("SELECT id, nombre FROM ctrib_retiros WHERE empresa_id = ? AND anio = ? AND rut = ''");
        $sinRut->execute([$empresa, $anio]);
        $asignar = $pdo->prepare('UPDATE ctrib_retiros SET rut = ? WHERE id = ?');
        foreach ($sinRut->fetchAll() as $r) {
            foreach ($socios as $s) {
                if (mb_strtolower(trim($s['nombre'])) === mb_strtolower(trim($r['nombre']))) {
                    $asignar->execute([$s['rut'], $r['id']]);
                    $previos[] = $s['rut'];
                    break;
                }
            }
        }
    }
    $actuales = [];
    foreach (ctrib_retiros_listar($pdo, $empresa, $anio) as $r) {
        $actuales[$r['rut']] = ($actuales[$r['rut']] ?? 0) + (int) $r['monto'];
    }
    $borrar = $pdo->prepare('DELETE FROM ctrib_retiros WHERE empresa_id = ? AND anio = ? AND rut = ?');
    $insertar = $pdo->prepare('INSERT INTO ctrib_retiros (empresa_id, anio, rut, nombre, fecha, monto, tipo) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $cambio = false;
    foreach ($socios as $s) {
        $antes = $actuales[$s['rut']] ?? 0;
        if ($antes === $s['monto'] || ($s['monto'] === 0 && !in_array($s['rut'], $previos, true))) {
            continue;
        }
        $borrar->execute([$empresa, $anio, $s['rut']]);
        if ($s['monto'] > 0) {
            $insertar->execute([$empresa, $anio, $s['rut'], $s['nombre'], $fecha, $s['monto'], 'Retiro']);
        }
        $cambio = true;
    }
    foreach (array_diff(array_unique($previos), $ruts) as $quitado) {
        $borrar->execute([$empresa, $anio, $quitado]);
        $cambio = $cambio || $borrar->rowCount() > 0;
    }
    if ($cambio) {
        ctrib_registrar_historial($pdo, $empresa, $anio, 'retiros', 'cuadro', (string) $totalAntes, (string) ctrib_retiros_total($pdo, $empresa, $anio), 'Retiros por socio desde el visor', $usuario);
    }
}

/**
 * Guarda el cuadro: tipo y régimen, socios con participación y monto retirado en el año.
 * EIRL: un solo titular con el 100%. 14 D N°8: se retira la totalidad (modo 'totalidad').
 * Sin retiros: modo 'sin_retiros'. En otro caso los retiros del año quedan como un total por socio al 31-12,
 * salvo que coincidan con los retiros ya cargados (por ejemplo, desde un archivo con varias fechas), que se conservan.
 */
function ctrib_composicion_guardar(PDO $pdo, int $empresa, int $anio, string $tipo, string $regimen, array $socios, bool $sinRetiros, string $usuario): array
{
    $limpios = [];
    $suma = 0.0;
    foreach ($socios as $i => $s) {
        $rut = trim((string) ($s['rut'] ?? ''));
        $nombre = mb_substr(trim((string) ($s['nombre'] ?? '')), 0, 120);
        $p = $s['participacion'] ?? null;
        $monto = $s['monto'] ?? 0;
        if ($rut === '' && $nombre === '' && ($p === null || $p === '') && in_array($monto, [0, '0', '', null], true)) {
            continue; // fila vacía
        }
        $n = $i + 1;
        if ($nombre === '') {
            throw new CtribError("Socio $n: falta el nombre.");
        }
        if ($rut !== '') {
            $normalizado = normalizarRut($rut);
            if ($normalizado === null) {
                throw new CtribError("Socio $n ($nombre): el RUT $rut no es válido; revise el dígito verificador.");
            }
            $rut = $normalizado;
        }
        if (!is_numeric($p) || (float) $p <= 0 || (float) $p > 100) {
            throw new CtribError("Socio $n ($nombre): la participación debe ser mayor que 0 y hasta 100.");
        }
        if ($monto === '' || $monto === null) {
            $monto = 0;
        }
        if (!(is_int($monto) || (is_string($monto) && preg_match('/^\d+$/', trim($monto)))) || (int) $monto < 0) {
            throw new CtribError("Socio $n ($nombre): el monto retirado debe ser un entero mayor o igual a cero.");
        }
        if (isset($limpios[$rut])) {
            throw new CtribError($rut === '' ? 'Solo un socio puede quedar sin RUT por ahora.' : "El socio $rut aparece más de una vez.");
        }
        $limpios[$rut] = ['rut' => $rut, 'nombre' => $nombre, 'participacion' => round((float) $p, 4), 'monto' => (int) $monto];
        $suma += (float) $p;
    }
    if (!$limpios) {
        throw new CtribError('Indique al menos un socio o titular.');
    }
    if (abs($suma - 100) > 0.01) {
        throw new CtribError('La participación debe sumar 100% (suma ' . rtrim(rtrim(number_format($suma, 4, ',', ''), '0'), ',') . '%).');
    }
    if ($tipo === 'EIRL' && count($limpios) !== 1) {
        throw new CtribError('Una EIRL tiene un solo titular con el 100% de participación.');
    }
    $ac = ctrib_anio_comercial($anio);
    $previos = array_column(ctrib_socios($pdo, $empresa, $anio), 'rut'); // socios del cuadro antes de este guardado
    $pdo->beginTransaction();
    try {
        ctrib_empresa_config_guardar($pdo, $empresa, $tipo, $regimen, $usuario);
        $pdo->prepare('DELETE FROM ctrib_socios WHERE empresa_id = ? AND anio = ?')->execute([$empresa, $anio]);
        $insertar = $pdo->prepare('INSERT INTO ctrib_socios (empresa_id, anio, rut, nombre, participacion) VALUES (?, ?, ?, ?, ?)');
        foreach ($limpios as $s) {
            $insertar->execute([$empresa, $anio, $s['rut'], $s['nombre'], $s['participacion']]);
        }
        if ($sinRetiros) {
            $pdo->prepare('DELETE FROM ctrib_retiros WHERE empresa_id = ? AND anio = ?')->execute([$empresa, $anio]);
            ctrib_retiros_modo_fijar($pdo, $empresa, $anio, 'sin_retiros', $usuario);
        } elseif ($regimen === '14D8') {
            ctrib_retiros_modo_fijar($pdo, $empresa, $anio, 'totalidad', $usuario);
        } else {
            ctrib_composicion_retiros_guardar($pdo, $empresa, $anio, array_values($limpios), $previos, "$ac-12-31", $usuario);
            ctrib_retiros_modo_fijar($pdo, $empresa, $anio, 'importados', $usuario);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ctrib_composicion_retiros($pdo, $empresa, $anio);
}

// ---------- Informe de Renta Líquida Imponible (RLI) ----------
const CTRIB_RLI_TIPOS = ['AGREGADO' => 'Agregado', 'DEDUCIDO' => 'Deducido'];

function ctrib_rli_partidas(PDO $pdo, int $empresa, int $anio): array
{
    $st = $pdo->prepare('SELECT id, tipo, glosa, monto FROM ctrib_rli_partidas WHERE empresa_id = ? AND anio = ? ORDER BY id');
    $st->execute([$empresa, $anio]);
    return array_map(fn($f) => ['id' => (int) $f['id'], 'tipo' => $f['tipo'], 'glosa' => $f['glosa'], 'monto' => (int) $f['monto']], $st->fetchAll());
}

/** Informe RLI como iTributario: resultado del balance + agregados − deducidos − 1432, comparado con el 1440 del R17. */
function ctrib_rli(PDO $pdo, int $empresa, int $anio): array
{
    $balance = ctrib_balance_guardado($pdo, $empresa, $anio);
    $r17 = ctrib_r17($pdo, $empresa, $anio);
    $partidas = ctrib_rli_partidas($pdo, $empresa, $anio);
    $agregados = array_values(array_filter($partidas, fn($p) => $p['tipo'] === 'AGREGADO'));
    $deducidos = array_values(array_filter($partidas, fn($p) => $p['tipo'] === 'DEDUCIDO'));
    $resultado = (int) ($balance['resultado'] ?? 0);
    $determinada = $resultado + array_sum(array_column($agregados, 'monto')) - array_sum(array_column($deducidos, 'monto'));
    $incentivo = (int) $r17['totales']['1432'];
    $final = $determinada - $incentivo;
    $codigo1440 = (int) $r17['totales']['1440'];
    return [
        'anio' => $anio, 'anioComercial' => ctrib_anio_comercial($anio), 'hayBalance' => $balance !== null,
        'resultado' => $resultado, 'agregados' => $agregados, 'deducidos' => $deducidos,
        'totalAgregados' => array_sum(array_column($agregados, 'monto')), 'totalDeducidos' => array_sum(array_column($deducidos, 'monto')),
        'determinada' => $determinada, 'incentivo' => $incentivo, 'final' => $final,
        'codigo1440' => $codigo1440, 'diferencia' => $final - $codigo1440, 'cuadra' => $final === $codigo1440,
        'estadoR17' => $r17['estado'],
    ];
}

function ctrib_rli_partida_agregar(PDO $pdo, int $empresa, int $anio, string $tipo, string $glosa, mixed $monto, string $usuario): array
{
    $tipo = strtoupper(trim($tipo));
    $glosa = trim($glosa);
    if (!isset(CTRIB_RLI_TIPOS[$tipo])) {
        throw new CtribError('Indique si la partida es un agregado o un deducido.');
    }
    if ($glosa === '' || mb_strlen($glosa) > 160) {
        throw new CtribError('Escriba el concepto de la partida (hasta 160 caracteres).');
    }
    if (!(is_int($monto) || (is_string($monto) && preg_match('/^\d+$/', trim($monto)))) || (int) $monto <= 0) {
        throw new CtribError('El monto de la partida debe ser un número entero mayor que cero.');
    }
    $pdo->prepare('INSERT INTO ctrib_rli_partidas (empresa_id, anio, tipo, glosa, monto) VALUES (?, ?, ?, ?, ?)')
        ->execute([$empresa, $anio, $tipo, $glosa, (int) $monto]);
    ctrib_registrar_historial($pdo, $empresa, $anio, 'rli', $tipo, '', $glosa . ' ' . (int) $monto, 'Partida del informe RLI', $usuario);
    return ctrib_rli($pdo, $empresa, $anio);
}

function ctrib_rli_partida_quitar(PDO $pdo, int $empresa, int $anio, int $id, string $usuario): array
{
    $st = $pdo->prepare('SELECT tipo, glosa, monto FROM ctrib_rli_partidas WHERE id = ? AND empresa_id = ? AND anio = ?');
    $st->execute([$id, $empresa, $anio]);
    $f = $st->fetch();
    if (!$f) {
        throw new CtribError('La partida no existe.');
    }
    $pdo->prepare('DELETE FROM ctrib_rli_partidas WHERE id = ?')->execute([$id]);
    ctrib_registrar_historial($pdo, $empresa, $anio, 'rli', $f['tipo'], $f['glosa'] . ' ' . $f['monto'], '', 'Partida del informe RLI quitada', $usuario);
    return ctrib_rli($pdo, $empresa, $anio);
}

// ---------- Factores de actualización (corrección monetaria) del año comercial ----------
const CTRIB_MESES = [1 => 'ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO', 'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE'];

/** Factor de actualización al 31 de diciembre por mes (1 a 12), o null si no está la tabla del año. */
function ctrib_factores_mes(int $anioComercial): ?array
{
    $ruta = __DIR__ . '/ctrib/factores-' . $anioComercial . '.json';
    if (!is_file($ruta)) {
        return null;
    }
    $datos = json_decode((string) file_get_contents($ruta), true);
    $porMes = array_column($datos['correccionMonetaria'] ?? [], 'factorActualizacion', 'mesOrigen');
    $salida = [];
    foreach (CTRIB_MESES as $n => $nombre) {
        if (!isset($porMes[$nombre])) {
            return null;
        }
        $salida[$n] = (float) $porMes[$nombre];
    }
    return $salida;
}

/**
 * Ventanas de ajustes del R17: los ajustes CXC / CXP con el devengado de las cuentas homologadas de cada código,
 * las existencias del 1409 y las cuentas de balance disponibles.
 */
function ctrib_r17_ajustes(PDO $pdo, int $empresa, int $anio): array
{
    $r17 = ctrib_r17($pdo, $empresa, $anio);
    $ajustes = [];
    $existencias = null;
    foreach ($r17['lineas'] as $l) {
        if (isset($l['ajuste'])) {
            $ajustes[] = $l['ajuste'];
        }
        if (isset($l['existencias'])) {
            $existencias = $l['existencias'];
        }
    }
    return ['ajustes' => $ajustes, 'existencias' => $existencias, 'cuentas' => ctrib_ajuste_cuentas_disponibles($pdo, $empresa, $anio)];
}
