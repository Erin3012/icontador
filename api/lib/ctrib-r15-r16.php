<?php
// Recuadros 15 (RTRE y movimiento STUT) y 16 (Registro SAC) para el régimen Semi Integrado (Art. 14 letra A) LIR).
// Reglas: instrucciones F22 AT 2026 y ejercicio oficial 14a.xlsx (Sociedad Fénix Ltda).
declare(strict_types=1);

require_once __DIR__ . '/ctrib-r13-r14.php';

const CTRIB_R15_COLS = [
    ['id' => 'RAI', 'label' => 'RAI'],
    ['id' => 'DDAN', 'label' => 'DDAN'],
    ['id' => 'REX_RAP', 'label' => 'REX · RAP y dif. inicial art. 14 ter A)'],
    ['id' => 'REX_ISFUT', 'label' => 'REX · ISFUT / ISIF'],
    ['id' => 'REX_OTRAS', 'label' => 'REX · Otras'],
    ['id' => 'REX_EXENTAS', 'label' => 'REX · Rentas exentas'],
    ['id' => 'REX_INR', 'label' => 'REX · INR'],
    ['id' => 'STUT', 'label' => 'STUT'],
];

const CTRIB_R16_COLS = [
    ['id' => 'SAC_NO_REST_SIN_DEV', 'label' => 'Desde 2017 · No sujeto a rest. · Sin dev.'],
    ['id' => 'SAC_NO_REST_CON_DEV', 'label' => 'Desde 2017 · No sujeto a rest. · Con dev.'],
    ['id' => 'SAC_REST_SIN_DEV', 'label' => 'Desde 2017 · Sujeto a rest. · Sin dev.'],
    ['id' => 'SAC_REST_CON_DEV', 'label' => 'Desde 2017 · Sujeto a rest. · Con dev.'],
    ['id' => 'SAC_IPE_DESDE_2017', 'label' => 'Desde 2017 · IPE'],
    ['id' => 'SAC_2016_SIN_DEV', 'label' => 'Hasta 2016 · Sin dev.'],
    ['id' => 'SAC_2016_CON_DEV', 'label' => 'Hasta 2016 · Con dev.'],
    ['id' => 'SAC_2016_IPE', 'label' => 'Hasta 2016 · IPE'],
];

const CTRIB_14A_REX_ORDEN = ['REX_RAP', 'REX_ISFUT', 'REX_OTRAS', 'REX_EXENTAS', 'REX_INR'];
const CTRIB_14A_SAC_ORDEN = [
    'SAC_NO_REST_SIN_DEV',
    'SAC_NO_REST_CON_DEV',
    'SAC_REST_SIN_DEV',
    'SAC_REST_CON_DEV',
    'SAC_2016_SIN_DEV',
    'SAC_2016_CON_DEV',
];

const CTRIB_14A_RTRE_FILAS = [
    ['fila' => '1.1', 'bloque' => 1, 'glosa' => 'Remanente ejercicio anterior (histórico)', 'editable' => true],
    ['fila' => '1.4', 'bloque' => 1, 'glosa' => 'Reajuste VIPC de saldos iniciales', 'editable' => true],
    ['fila' => '1.5', 'bloque' => 2, 'glosa' => 'Monto acogido / imputado al ISIF (Ley N° 21.681)', 'editable' => true],
    ['fila' => '3.1', 'bloque' => 3, 'glosa' => 'Aumentos del ejercicio (por reorganizaciones)', 'editable' => true],
    ['fila' => '3.2', 'bloque' => 3, 'glosa' => 'Disminuciones del ejercicio (por reorganizaciones)', 'editable' => true],
    ['fila' => '4.1', 'bloque' => 4, 'glosa' => 'Reverso RAI ejercicio anterior / disminuciones propias', 'editable' => true],
    ['fila' => '4.2', 'bloque' => 4, 'glosa' => 'RAI / aumentos propios del ejercicio', 'editable' => true],
    ['fila' => '4.5', 'bloque' => 4, 'glosa' => 'IDPC e IPE sobre RLI del ejercicio', 'editable' => true],
    ['fila' => '4.6', 'bloque' => 4, 'glosa' => 'IDPC e IPE por retiros o dividendos percibidos', 'editable' => true],
    ['fila' => '5.1', 'bloque' => 5, 'glosa' => 'Otros aumentos del ejercicio', 'editable' => true],
    ['fila' => '5.2', 'bloque' => 5, 'glosa' => 'Otras disminuciones del ejercicio', 'editable' => true],
    ['fila' => '6.1', 'bloque' => 6, 'glosa' => 'Imputado a remesas, retiros o dividendos del ejercicio', 'editable' => true],
    ['fila' => '6.2', 'bloque' => 6, 'glosa' => 'Imputado a retiros en exceso y devoluciones de capital', 'editable' => true],
    ['fila' => '6.4', 'bloque' => 6, 'glosa' => 'Crédito SAC asignado a gastos rechazados art. 21', 'editable' => true],
];

// [glosa, filas, modo ('pos'|'neg'|'abs'|'val'), códigos por columna (CTRIB_R15_COLS)]
const CTRIB_R15_FILAS = [
    ['Remanente ejercicio anterior o saldo inicial reajustado (saldo positivo)', ['1.1', '1.4'], 'pos', [1200, 1211, 1221, 1730, 1731, 1234, 1246, 1260]],
    ['Remanente ejercicio anterior o saldo inicial reajustado (saldo negativo)', ['1.1', '1.4'], 'neg', [null, null, 1222, null, 1843, 1235, 1247, null]],
    ['Monto acogido al ISIF, según art. 10 Ley N° 21.681, reajustado', ['1.5'], 'val', [1933, null, null, 1934, null, null, null, 1935]],
    ['Aumentos del ejercicio (por reorganizaciones)', ['3.1'], 'abs', [1202, 1212, 1224, 1733, 1734, 1236, 1248, 1262]],
    ['Disminuciones del ejercicio (por reorganizaciones)', ['3.2'], 'abs', [1203, 1213, 1225, 1735, 1736, 1237, 1249, 1263]],
    ['Reversos y/o disminuciones del ejercicio (propios)', ['4.1'], 'abs', [1204, 1214, 1226, 1737, 1738, 1238, 1250, 1264]],
    ['Aumentos del ejercicio (propios)', ['4.2'], 'abs', [1205, 1215, null, null, 1740, 1239, 1251, null]],
    ['Otros aumentos del ejercicio', ['5.1'], 'abs', [1206, 1216, 1228, 1741, 1742, 1240, 1252, 1265]],
    ['Otras disminuciones del ejercicio', ['5.2'], 'abs', [1207, 1217, 1229, 1743, 1744, 1241, 1253, 1266]],
    ['Remesas, retiros o dividendos imputados a los RTRE, reajustados', ['6.1'], 'abs', [1208, 1218, 1230, 1745, 1746, 1242, 1254, 1267]],
    ['Retiros en exceso y devoluciones de capital imputados en el ejercicio, reajustados', ['6.2'], 'abs', [1209, 1219, 1231, 1747, 1748, 1243, 1255, 1268]],
    ['Remanente ejercicio siguiente (saldo positivo)', ['REMANENTE_FINAL'], 'pos', [1210, 1220, 1232, 1749, 1750, 1244, 1256, 1269]],
    ['Remanente ejercicio siguiente (saldo negativo)', ['REMANENTE_FINAL'], 'neg', [null, null, 1233, null, 1844, 1245, 1257, null]],
];

// [glosa, filas, modo, códigos por columna (CTRIB_R16_COLS)]
const CTRIB_R16_FILAS = [
    ['Remanente ejercicio anterior o saldo inicial (saldo positivo)', ['1.1', '1.4'], 'pos', [1270, 1279, 1288, 1301, 1313, 1324, 1335, 1346]],
    ['Remanente ejercicio anterior o saldo inicial (saldo negativo)', ['1.1', '1.4'], 'neg', [1821, 1822, 1289, 1302, null, null, null, null]],
    ['Monto imputado al ISIF art. 10 Ley N° 21.681, reajustado', ['1.5'], 'abs', [1936, 1937, 1938, 1939, null, 1940, 1941, null]],
    ['Aumentos del ejercicio (por reorganizaciones)', ['3.1'], 'abs', [1271, 1280, 1290, 1303, 1314, 1326, 1337, 1347]],
    ['Disminuciones del ejercicio (por reorganizaciones)', ['3.2'], 'abs', [1272, 1281, 1291, 1304, 1315, 1327, 1338, 1348]],
    ['IDPC e IPE RLI generada en el ejercicio', ['4.5'], 'abs', [null, null, 1292, 1305, 1316, null, null, null]],
    ['IDPC e IPE retiros o dividendos percibidos', ['4.6'], 'abs', [1273, 1282, 1293, 1306, 1317, 1328, 1339, 1349]],
    ['Otros aumentos del ejercicio', ['5.1'], 'abs', [1274, 1283, 1294, 1307, 1318, 1329, 1340, 1350]],
    ['Otras disminuciones del ejercicio', ['5.2'], 'abs', [1275, 1284, 1295, 1308, 1319, 1330, 1341, 1351]],
    ['Asignado a remesas, retiros o dividendos efectuados en el ejercicio, reajustados', ['6.1'], 'abs', [1276, 1285, 1296, 1309, 1320, 1331, 1342, 1352]],
    ['Asignado a retiros en exceso y devoluciones de capital efectuados en el ejercicio, reajustados', ['6.2'], 'abs', [1277, 1286, 1297, 1310, 1321, 1332, 1343, 1353]],
    ['IDPC e IPE asignado a gastos rechazados del art. 21 inc. 1° no afectos a IU 40% y del inc. 2° LIR', ['6.4'], 'abs', [null, null, 1298, 1311, 1322, 1333, 1344, 1354]],
    ['Remanente ejercicio siguiente (saldo positivo)', ['REMANENTE_FINAL'], 'pos', [1278, 1287, 1312, 1300, 1323, 1334, 1345, 1355]],
    ['Remanente ejercicio siguiente (saldo negativo)', ['REMANENTE_FINAL'], 'neg', [1723, 1724, 1299, 1373, null, null, null, null]],
];

function ctrib_14a_todas_columnas(): array
{
    return array_merge(array_column(CTRIB_R15_COLS, 'id'), array_column(CTRIB_R16_COLS, 'id'));
}

/** Celdas escritas a mano del RTRE 14 A (prefijo 'A:' en fila para no colisionar con el R20 de Pro Pyme). */
function ctrib_r15_celdas_manuales(PDO $pdo, int $empresa, int $anio): array
{
    $st = $pdo->prepare("SELECT fila, columna, monto FROM ctrib_rtre_celdas WHERE empresa_id = ? AND anio = ? AND fila LIKE 'A:%'");
    $st->execute([$empresa, $anio]);
    $salida = [];
    foreach ($st as $f) {
        $fila = substr((string) $f['fila'], 2);
        $salida[$fila . '::' . $f['columna']] = (int) $f['monto'];
    }
    return $salida;
}

function ctrib_r15_formulario(array $celdas, array $colsDef, array $filasDef): array
{
    $filas = [];
    $codigos = [];
    foreach ($filasDef as [$glosa, $filasRtre, $modo, $codigosFila]) {
        $celdasFila = [];
        foreach ($colsDef as $i => $colDef) {
            $codigo = $codigosFila[$i] ?? null;
            if ($codigo === null) {
                $celdasFila[] = null;
                continue;
            }
            $colId = $colDef['id'];
            $suma = 0;
            foreach ($filasRtre as $fr) {
                $suma += (int) ($celdas[$fr][$colId]['monto'] ?? 0);
            }
            $monto = match ($modo) {
                'pos' => max(0, $suma),
                'neg' => max(0, -$suma),
                'abs' => abs($suma),
                default => $suma,
            };
            $celdasFila[] = ['codigo' => (string) $codigo, 'monto' => $monto];
            $codigos[(string) $codigo] = $monto;
        }
        $filas[] = ['glosa' => $glosa, 'celdas' => $celdasFila];
    }
    return ['columnas' => array_column($colsDef, 'label'), 'filas' => $filas, 'codigos' => $codigos];
}

/** Total de retiros del ejercicio reajustados con los factores mensuales (o tomados de R13/R14 si no se importaron). */
function ctrib_r15_retiros_reajustados(PDO $pdo, int $empresa, int $anio, int $fallbackManual): int
{
    $delAnio = ctrib_retiros_del_anio($pdo, $empresa, $anio);
    if ($delAnio['modo'] === 'sin_retiros') {
        return 0;
    }
    if (!empty($delAnio['retiros'])) {
        $factores = ctrib_factores_mes(ctrib_anio_comercial($anio)) ?? [];
        $total = 0;
        foreach ($delAnio['retiros'] as $r) {
            $mes = (int) substr((string) ($r['fecha'] ?? ''), 5, 2);
            $factor = (float) ($factores[$mes] ?? 1.0);
            $total += (int) round(((int) $r['monto']) * $factor, 0, PHP_ROUND_HALF_UP);
        }
        return $total;
    }
    return $fallbackManual;
}

/** Cálculo completo del RTRE 14 A (R15 y R16): reajuste anual, reverso RAI, crédito IDPC/IPE, imputación de retiros y remanentes. */
function ctrib_r15(PDO $pdo, int $empresa, int $anio): array
{
    $cols = ctrib_14a_todas_columnas();
    $r12 = ctrib_r12($pdo, $empresa, $anio);
    $r14 = ctrib_r14($pdo, $empresa, $anio);
    $r13 = ctrib_r13($pdo, $empresa, $anio);
    $m12 = array_column($r12['lineas'], 'monto', 'codigo');
    $m14 = array_column($r14['lineas'], 'monto', 'codigo');
    $m13 = array_column($r13['lineas'], 'monto', 'codigo');

    $manuales = ctrib_r15_celdas_manuales($pdo, $empresa, $anio);
    $anteriorEstado = ctrib_estado_recuadro($pdo, $empresa, $anio - 1, 'R15');
    $anterior = $anteriorEstado['estado'] === 'FINALIZADO' ? ($anteriorEstado['snapshot'] ?? null) : null;

    $anioComercial = ctrib_anio_comercial($anio);
    $factor = ctrib_factor_capital_inicial($anioComercial);
    $vipc = $factor !== null ? ($factor - 1.0) : 0.0;
    $tasa = ctrib_tasa_semi_integrado($anioComercial);
    $factorSac = round($tasa / (100.0 - $tasa), 6);

    $celdas = [];
    foreach (CTRIB_14A_RTRE_FILAS as $f) {
        $fila = $f['fila'];
        foreach ($cols as $col) {
            $clave = $fila . '::' . $col;
            if (array_key_exists($clave, $manuales)) {
                $celdas[$fila][$col] = ['monto' => $manuales[$clave], 'origen' => 'MANUAL'];
            } elseif ($fila === '1.1') {
                $celdas[$fila][$col] = [
                    'monto' => (int) ($anterior["REMANENTE_FINAL::$col"] ?? 0),
                    'origen' => $anterior !== null ? 'AÑO_ANTERIOR' : 'MANUAL',
                ];
            } else {
                $celdas[$fila][$col] = ['monto' => 0, 'origen' => 'AUTO'];
            }
        }
    }

    // 1.4 Reajuste anual (VIPC de capital inicial sobre los saldos iniciales 1.1).
    foreach ($cols as $col) {
        if (!array_key_exists("1.4::$col", $manuales)) {
            $ini = $celdas['1.1'][$col]['monto'];
            $reaj = ($ini !== 0 && $vipc != 0.0) ? (int) round($ini * $vipc, 0, PHP_ROUND_HALF_UP) : 0;
            $celdas['1.4'][$col] = ['monto' => $reaj, 'origen' => $factor !== null ? 'AUTOMATICO' : 'MANUAL'];
        }
    }

    // 4.1 Reverso RAI anterior = -max(0, RAI inicial reajustado + bloque 1..3).
    $raiAntes4 = $celdas['1.1']['RAI']['monto'] + $celdas['1.4']['RAI']['monto']
        + $celdas['1.5']['RAI']['monto'] + $celdas['3.1']['RAI']['monto'] - abs($celdas['3.2']['RAI']['monto']);
    if (!array_key_exists('4.1::RAI', $manuales)) {
        $celdas['4.1']['RAI'] = ['monto' => -max(0, $raiAntes4), 'origen' => 'R13'];
    }

    // 4.2 RAI del ejercicio desde R13 (código 1199).
    $raiEjercicio = (int) ($m13['1199'] ?? 0);
    if (!array_key_exists('4.2::RAI', $manuales)) {
        $celdas['4.2']['RAI'] = ['monto' => $raiEjercicio, 'origen' => 'R13'];
    }

    // 4.5 IDPC sobre RLI (R12) e IPE imputable al IDPC / IF (R12 1676 y R14 1188).
    $idpcTotal = (int) $r12['idpcEstimado']['monto'];
    $ipeIf = max(0, (int) ($m14['1188'] ?? 0));
    $ctdIpe = max(0, (int) ($m12['1676'] ?? 0));
    $ipeIdpc = $ipeIf > 0 ? min($idpcTotal, max(0, $ctdIpe - $ipeIf)) : 0;
    $idpcConDev = max(0, $idpcTotal - $ipeIdpc);

    if (!array_key_exists('4.5::SAC_REST_SIN_DEV', $manuales)) {
        $celdas['4.5']['SAC_REST_SIN_DEV'] = ['monto' => $ipeIdpc, 'origen' => $ipeIdpc > 0 ? 'R12' : 'AUTO'];
    }
    if (!array_key_exists('4.5::SAC_REST_CON_DEV', $manuales)) {
        $celdas['4.5']['SAC_REST_CON_DEV'] = ['monto' => $idpcConDev, 'origen' => 'R12'];
    }
    if (!array_key_exists('4.5::SAC_IPE_DESDE_2017', $manuales)) {
        $celdas['4.5']['SAC_IPE_DESDE_2017'] = ['monto' => $ipeIf, 'origen' => $ipeIf > 0 ? 'R14' : 'AUTO'];
    }

    // Subtotales antes de imputaciones (bloques 1..5). Disminuciones en 3.2, 4.1 (no RAI), 5.2 restan al sumar.
    $disponible = [];
    foreach ($cols as $col) {
        $val = $celdas['1.1'][$col]['monto'] + $celdas['1.4'][$col]['monto']
            + $celdas['1.5'][$col]['monto']
            + abs($celdas['3.1'][$col]['monto']) - abs($celdas['3.2'][$col]['monto'])
            + ($col === 'RAI' ? $celdas['4.1'][$col]['monto'] : -abs($celdas['4.1'][$col]['monto']))
            + abs($celdas['4.2'][$col]['monto'])
            + abs($celdas['4.5'][$col]['monto']) + abs($celdas['4.6'][$col]['monto'])
            + abs($celdas['5.1'][$col]['monto']) - abs($celdas['5.2'][$col]['monto']);
        $disponible[$col] = $val;
    }

    // 6.1 Imputación de retiros/dividendos del ejercicio reajustados (RAI -> DDAN -> REX en orden legal).
    $retiros = ctrib_r15_retiros_reajustados($pdo, $empresa, $anio, (int) ($m13['1699'] ?? $m14['1182'] ?? 0));
    $manualRetiros = false;
    foreach (array_merge(['RAI', 'DDAN'], CTRIB_14A_REX_ORDEN) as $c) {
        if (array_key_exists("6.1::$c", $manuales)) {
            $manualRetiros = true;
            break;
        }
    }
    if (!$manualRetiros) {
        $resto = $retiros;
        foreach (array_merge(['RAI', 'DDAN'], CTRIB_14A_REX_ORDEN) as $c) {
            $imp = min($resto, max(0, $disponible[$c]));
            $celdas['6.1'][$c] = ['monto' => -$imp, 'origen' => $imp > 0 ? 'RETIROS' : 'AUTO'];
            $resto -= $imp;
        }
    }
    $retirosAfectos = abs($celdas['6.1']['RAI']['monto']) + abs($celdas['6.1']['DDAN']['monto']);
    $retirosRex = 0;
    foreach (CTRIB_14A_REX_ORDEN as $c) {
        $retirosRex += abs($celdas['6.1'][$c]['monto']);
    }
    $retirosNoImputados = max(0, $retiros - $retirosAfectos - $retirosRex);

    // Crédito SAC e IPE asignado a retiros afectos (6.1): primero IPE (hasta 8% sobre renta bruta 35%), luego SAC orden legal.
    $manualSac = false;
    foreach (array_merge(CTRIB_14A_SAC_ORDEN, ['SAC_IPE_DESDE_2017']) as $c) {
        if (array_key_exists("6.1::$c", $manuales)) {
            $manualSac = true;
            break;
        }
    }
    if (!$manualSac && $retirosAfectos > 0) {
        $ipeMax = (int) round($retirosAfectos / 0.65 * 0.08, 0, PHP_ROUND_HALF_UP);
        $ipeDisp = max(0, $disponible['SAC_IPE_DESDE_2017']);
        $ipeAsig = min($ipeMax, $ipeDisp);
        $celdas['6.1']['SAC_IPE_DESDE_2017'] = ['monto' => -$ipeAsig, 'origen' => $ipeAsig > 0 ? 'RETIROS' : 'AUTO'];

        $credPendiente = (int) round(($retirosAfectos + $ipeAsig) * $factorSac, 0, PHP_ROUND_HALF_UP);
        foreach (CTRIB_14A_SAC_ORDEN as $c) {
            $disp = max(0, $disponible[$c]);
            $asig = min($credPendiente, $disp);
            $celdas['6.1'][$c] = ['monto' => -$asig, 'origen' => $asig > 0 ? 'RETIROS' : 'AUTO'];
            $credPendiente -= $asig;
        }
    }

    // Remanente ejercicio siguiente por columna.
    foreach ($cols as $col) {
        $imp = -abs($celdas['6.1'][$col]['monto']) - abs($celdas['6.2'][$col]['monto']) - abs($celdas['6.4'][$col]['monto']);
        $celdas['REMANENTE_FINAL'][$col] = ['monto' => $disponible[$col] + $imp, 'origen' => 'CALCULADO'];
    }

    $rexDisponible = 0;
    $rexFinal = 0;
    foreach (CTRIB_14A_REX_ORDEN as $c) {
        $rexDisponible += $disponible[$c];
        $rexFinal += $celdas['REMANENTE_FINAL'][$c]['monto'];
    }
    $sacFinal = 0;
    foreach (array_merge(CTRIB_14A_SAC_ORDEN, ['SAC_IPE_DESDE_2017', 'SAC_2016_IPE']) as $c) {
        $sacFinal += $celdas['REMANENTE_FINAL'][$c]['monto'];
    }

    $formR15 = ctrib_r15_formulario($celdas, CTRIB_R15_COLS, CTRIB_R15_FILAS);
    $formR16 = ctrib_r15_formulario($celdas, CTRIB_R16_COLS, CTRIB_R16_FILAS);

    $todas = [];
    foreach ($cols as $col) {
        foreach (array_merge(array_column(CTRIB_14A_RTRE_FILAS, 'fila'), ['REMANENTE_FINAL']) as $fila) {
            $todas[] = ['codigo' => $fila . '::' . $col, 'monto' => (int) ($celdas[$fila][$col]['monto'] ?? 0)];
        }
    }
    $snapshotActual = array_column($todas, 'monto', 'codigo');
    $estado = ctrib_estado_recuadro($pdo, $empresa, $anio, 'R15');

    $bloqueos = [];
    foreach (['R12' => '12', 'R14' => '14', 'R13' => '13'] as $cod => $num) {
        if (ctrib_estado_recuadro($pdo, $empresa, $anio, $cod)['estado'] !== 'FINALIZADO') {
            $bloqueos[] = "Finalice el Recuadro $num antes de finalizar el RTRE (R15).";
        }
    }
    if ($r12['desactualizado'] || $r14['desactualizado'] || $r13['desactualizado']) {
        $bloqueos[] = 'Alguno de los recuadros R12, R13 o R14 cambió después de finalizarlo: reábralo y finalícelo nuevamente.';
    }

    $avisos = [];
    if ($anterior === null) {
        $avisos[] = 'Sin R15 del año anterior finalizado: si trae remanentes iniciales o ajustes al SAC art. 21 (fila 6.4), ingréselos manualmente.';
    }
    if (abs($rexDisponible - (int) ($m13['1693'] ?? 0)) > 0 && $rexDisponible > 0) {
        $avisos[] = 'El REX disponible antes de imputaciones (' . number_format($rexDisponible, 0, ',', '.') . ') difiere del código 1693 en R13 (' . number_format((int) ($m13['1693'] ?? 0), 0, ',', '.') . ').';
    }

    return [
        'recuadro' => 'R15',
        'anio' => $anio,
        'anioComercial' => $anioComercial,
        'columnasR15' => CTRIB_R15_COLS,
        'columnasR16' => CTRIB_R16_COLS,
        'filasDefinicion' => CTRIB_14A_RTRE_FILAS,
        'celdas' => $celdas,
        'disponibles' => $disponible,
        'formularioR15' => $formR15,
        'formularioR16' => $formR16,
        'resumen' => [
            'raiFinal' => $celdas['REMANENTE_FINAL']['RAI']['monto'],
            'ddanFinal' => $celdas['REMANENTE_FINAL']['DDAN']['monto'],
            'rexFinal' => $rexFinal,
            'sacFinal' => $sacFinal,
            'stutFinal' => $celdas['REMANENTE_FINAL']['STUT']['monto'],
            'retirosEjercicio' => $retiros,
            'retirosAfectos' => $retirosAfectos,
            'retirosRex' => $retirosRex,
            'retirosNoImputados' => $retirosNoImputados,
        ],
        'idpc' => ['total' => $idpcTotal, 'ipeIdpc' => $ipeIdpc, 'conDevolucion' => $idpcConDev, 'ipeIf' => $ipeIf, 'factorSac' => $factorSac],
        'estado' => $estado['estado'],
        'finalizado' => $estado['finalizado'],
        'usuario' => $estado['usuario'],
        'desactualizado' => ctrib_desactualizado($estado, $snapshotActual),
        'avisos' => $avisos,
        'bloqueos' => $bloqueos,
        'puedeFinalizar' => $bloqueos === [] && $estado['estado'] !== 'FINALIZADO',
        'todas' => $todas,
    ];
}

function ctrib_r16(PDO $pdo, int $empresa, int $anio): array
{
    $r15 = ctrib_r15($pdo, $empresa, $anio);
    $form = $r15['formularioR16'];
    $lineas = [];
    foreach ($form['filas'] as $fila) {
        foreach ($fila['celdas'] as $i => $c) {
            if ($c !== null) {
                $lineas[] = ['codigo' => $c['codigo'], 'glosa' => $fila['glosa'] . ' — ' . $form['columnas'][$i], 'monto' => $c['monto']];
            }
        }
    }
    return [
        'recuadro' => 'R16',
        'anio' => $anio,
        'anioComercial' => $r15['anioComercial'],
        'formulario' => $form,
        'lineas' => $lineas,
        'estado' => $r15['estado'],
        'desactualizado' => $r15['desactualizado'],
        'resumen' => $r15['resumen'],
    ];
}

function ctrib_r15_guardar_celda(PDO $pdo, int $empresa, int $anio, string $fila, string $columna, mixed $monto, string $usuario): array
{
    $filasValidas = array_column(CTRIB_14A_RTRE_FILAS, 'fila');
    if (!in_array($fila, $filasValidas, true)) {
        throw new CtribError("La fila $fila no es editable en el RTRE 14 A.");
    }
    if (!in_array($columna, ctrib_14a_todas_columnas(), true)) {
        throw new CtribError("La columna $columna no existe en el RTRE 14 A.");
    }
    if (ctrib_estado_recuadro($pdo, $empresa, $anio, 'R15')['estado'] === 'FINALIZADO') {
        throw new CtribError('El Recuadro 15 está finalizado. Reábralo para modificarlo.');
    }
    if ($monto !== null && $monto !== '' && !(is_int($monto) || (is_string($monto) && preg_match('/^-?\d+$/', trim($monto))))) {
        throw new CtribError('El monto debe ser un número entero.');
    }
    $filaDb = 'A:' . $fila;
    $anterior = ctrib_r15_celdas_manuales($pdo, $empresa, $anio)["$fila::$columna"] ?? null;
    $pdo->prepare('DELETE FROM ctrib_rtre_celdas WHERE empresa_id = ? AND anio = ? AND fila = ? AND columna = ?')
        ->execute([$empresa, $anio, $filaDb, $columna]);
    if ($monto === null || $monto === '') {
        if ($anterior !== null) {
            ctrib_registrar_historial($pdo, $empresa, $anio, 'recuadro', "R15 $fila::$columna", (string) $anterior, 'automático', 'Celda manual quitada', $usuario);
        }
        return ctrib_r15($pdo, $empresa, $anio);
    }
    $valor = (int) $monto;
    $pdo->prepare('INSERT INTO ctrib_rtre_celdas (empresa_id, anio, fila, columna, monto, usuario, actualizado) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$empresa, $anio, $filaDb, $columna, $valor, $usuario, ctrib_ahora()]);
    if ($anterior !== $valor) {
        ctrib_registrar_historial($pdo, $empresa, $anio, 'recuadro', "R15 $fila::$columna", $anterior !== null ? (string) $anterior : 'automático', (string) $valor, 'Celda manual 14A', $usuario);
    }
    return ctrib_r15($pdo, $empresa, $anio);
}

function ctrib_r15_finalizar(PDO $pdo, int $empresa, int $anio, string $usuario): array
{
    $r = ctrib_r15($pdo, $empresa, $anio);
    if ($r['estado'] === 'FINALIZADO') {
        throw new CtribError('El Recuadro 15 ya está finalizado.');
    }
    if ($r['bloqueos'] !== []) {
        throw new CtribError(implode(' ', $r['bloqueos']));
    }
    ctrib_recuadro_finalizar($pdo, $empresa, $anio, 'R15', $r['todas'], $usuario);
    return ctrib_r15($pdo, $empresa, $anio);
}

function ctrib_r15_reabrir(PDO $pdo, int $empresa, int $anio, string $usuario, string $motivo): array
{
    if (ctrib_estado_recuadro($pdo, $empresa, $anio, 'R15')['estado'] !== 'FINALIZADO') {
        throw new CtribError('El Recuadro 15 no está finalizado.');
    }
    ctrib_recuadro_reabrir($pdo, $empresa, $anio, 'R15', $usuario, $motivo);
    return ctrib_r15($pdo, $empresa, $anio);
}
