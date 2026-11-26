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

// ---------- Vista del RTRE agrupada (RAI, REX, SAC, STUT) y formularios R20 y R21 ----------

// Orden de imputación de los retiros que exceden el RAI dentro del registro REX (rentas exentas e INR).
const CTRIB_RTRE_ORDEN_REX = [
    'REX_CON_PRIORIDAD', 'REX_SIN_PRIORIDAD_RENTA_PRESUNTA', 'REX_DISTRIBUIBLE_ISFUT_LEY20780', 'REX_DISTRIBUIBLE_ISFUT_LEY21210',
    'REX_SIN_PRIORIDAD_ISFUT_DESPROPORCIONADO', 'REX_DISTRIBUIBLE_PRE_1984', 'REX_DISTRIBUIBLE_EXCESO_N9', 'REX_SIN_PRIORIDAD_IUSCAPT',
    'REX_EXENTA_IGC_O_IA', 'REX_EXENTA_AFECTA_IA', 'INR',
];
// Parámetros del RAI que se escriben a mano (en ctrib_valores, recuadro R20): capital aportado, saldo FUR y sobreprecio.
const CTRIB_RTRE_PARAMETROS = ['1494' => 'Capital aportado efectivamente, con aumentos y disminuciones (histórico)', '1725' => 'Saldo FUR', 'SOBR' => 'Sobreprecio en colocación de acciones'];

function ctrib_rtre_parametros(PDO $pdo, int $empresa, int $anio): array
{
    $manuales = ctrib_valores_manuales($pdo, $empresa, $anio, 'R20');
    $salida = [];
    foreach (CTRIB_RTRE_PARAMETROS as $codigo => $_) {
        $salida[(string) $codigo] = (int) ($manuales[(string) $codigo] ?? 0);
    }
    return $salida;
}

const CTRIB_RTRE_SECCIONES = [
    1 => 'Saldos iniciales o remanentes', 2 => 'Reclasificaciones del ejercicio', 3 => 'Reorganizaciones a la fecha del evento',
    4 => 'Determinación del año comercial', 5 => 'Otras reclasificaciones', 6 => 'Imputación de retiros',
];
// Columna del RTRE donde se escribe a mano cada grupo de la vista.
const CTRIB_RTRE_COLUMNA_GRUPO = ['RAI' => 'RAI', 'REX' => 'REX_CON_PRIORIDAD', 'SAC' => CTRIB_RTRE_COLUMNA_IDPC, 'STUT' => 'STUT_AFECTAS'];

function ctrib_rtre_grupo(string $columna): string
{
    if ($columna === 'RAI') {
        return 'RAI';
    }
    if (str_starts_with($columna, 'STUT')) {
        return 'STUT';
    }
    if (str_starts_with($columna, 'REX') || $columna === 'INR') {
        return 'REX';
    }
    return 'SAC'; // SAC_* y CREDITO_ART21_IF
}

function ctrib_rtre_sumar_grupos(array $porColumna): array
{
    $g = ['RAI' => 0, 'REX' => 0, 'SAC' => 0, 'STUT' => 0];
    foreach ($porColumna as $col => $c) {
        $g[ctrib_rtre_grupo($col)] += is_array($c) ? $c['monto'] : (int) $c;
    }
    return $g;
}

/** Tarjetas del RTRE: RAI final, REX final y SAC disponible (remanente para el ejercicio siguiente). */
function ctrib_rtre_resumen(array $celdas): array
{
    $g = ctrib_rtre_sumar_grupos($celdas['REMANENTE_FINAL'] ?? []);
    return ['raiFinal' => $g['RAI'], 'rexFinal' => $g['REX'], 'sacDisponible' => $g['SAC'], 'stutFinal' => $g['STUT']];
}

/** Secciones 1 a 6 con sus filas agrupadas en RAI, REX, SAC y STUT, como la hoja de trabajo de iTributario. */
function ctrib_rtre_secciones(array $catalogo, array $celdas): array
{
    $secciones = [];
    foreach (CTRIB_RTRE_SECCIONES as $bloque => $titulo) {
        $filas = [];
        $total = ['RAI' => 0, 'REX' => 0, 'SAC' => 0, 'STUT' => 0];
        foreach ($catalogo as $fila => $f) {
            if ((int) $f['bloque'] !== $bloque) {
                continue;
            }
            $presentes = [];
            foreach ($f['celdas'] as $ce) {
                $presentes[ctrib_rtre_grupo($ce['columna'])][] = $ce['columna'];
            }
            $valores = ctrib_rtre_sumar_grupos(array_intersect_key($celdas[$fila] ?? [], array_flip(array_column($f['celdas'], 'columna'))));
            $editable = in_array($f['naturalezaConfiguracion'], ['MANUAL', 'AUTO_CON_OVERRIDE'], true);
            $grupos = [];
            foreach (['RAI', 'REX', 'SAC', 'STUT'] as $g) {
                $columna = CTRIB_RTRE_COLUMNA_GRUPO[$g];
                $grupos[$g] = [
                    'presente' => isset($presentes[$g]),
                    'monto' => $valores[$g],
                    'columnaEditable' => $editable && in_array($columna, $presentes[$g] ?? [], true) ? $columna : null,
                ];
                $total[$g] += $valores[$g];
            }
            $filas[] = ['fila' => $fila, 'glosa' => $f['glosaCorta'], 'naturaleza' => $f['naturalezaConfiguracion'], 'grupos' => $grupos];
        }
        $secciones[] = ['bloque' => $bloque, 'titulo' => $titulo, 'total' => $total, 'filas' => $filas];
    }
    return [
        'secciones' => $secciones,
        'disponibles' => ctrib_rtre_sumar_grupos($celdas['SUBTOTAL_5'] ?? []),
        'saldoFinal' => ctrib_rtre_sumar_grupos($celdas['REMANENTE_FINAL'] ?? []),
    ];
}

// Formulario R20: columnas del F22 y columnas del RTRE que suman en cada una (la correspondencia de REX queda por validar).
const CTRIB_R20_COLUMNAS = [
    ['RAI', ['RAI']],
    ['REX · RAP y diferencia inicial ex art. 14 ter A)', ['REX_SIN_PRIORIDAD_RENTA_PRESUNTA', 'REX_DISTRIBUIBLE_PRE_1984', 'REX_DISTRIBUIBLE_EXCESO_N9', 'REX_SIN_PRIORIDAD_IUSCAPT']],
    ['REX · ISFUT/ISIF', ['REX_DISTRIBUIBLE_ISFUT_LEY20780', 'REX_DISTRIBUIBLE_ISFUT_LEY21210', 'REX_SIN_PRIORIDAD_ISFUT_DESPROPORCIONADO']],
    ['REX · Otras', ['REX_CON_PRIORIDAD']],
    ['Rentas exentas', ['REX_EXENTA_IGC_O_IA', 'REX_EXENTA_AFECTA_IA']],
    ['INR', ['INR']],
    ['STUT', ['STUT_AFECTAS', 'STUT_EXENTAS']],
];
const CTRIB_RTRE_FILAS_REORG = ['1.2', '1.3', '3.1', '3.2'];
const CTRIB_RTRE_FILAS_OTRAS = ['2.1', '2.2', '2.3', '5.1', '5.2', '5.3', '5.4'];
// [glosa, filas del RTRE, modo (pos: parte positiva, neg: parte negativa en valor absoluto, val: valor), códigos por columna]
const CTRIB_R20_FILAS = [
    ['Remanente ejercicio anterior o saldo inicial (saldo positivo)', ['1.1'], 'pos', [1451, 1452, 1752, 1753, 1453, 1454, 1382]],
    ['Remanente ejercicio anterior o saldo inicial (saldo negativo)', ['1.1'], 'neg', [null, 1589, null, 1845, 1455, 1456, null]],
    ['Monto acogido al ISIF, según arts. 10 y 11 Ley N° 21.681', [], 'val', [1942, null, 1943, null, null, null, 1944]],
    ['Aumentos del ejercicio (por reorganizaciones)', CTRIB_RTRE_FILAS_REORG, 'pos', [1392, 1393, 1755, 1756, 1394, 1395, 1384]],
    ['Disminuciones del ejercicio (por reorganizaciones)', CTRIB_RTRE_FILAS_REORG, 'neg', [1396, 1397, 1757, 1758, 1398, 1399, 1385]],
    ['Reversos y/o disminuciones del ejercicio (propios)', ['4.1'], 'neg', [1459, 1460, 1759, 1760, 1461, 1462, 1386]],
    ['Aumentos del ejercicio (propios)', ['4.2', '4.4'], 'pos', [1463, null, null, 1762, 1465, 1466, null]],
    ['Otros aumentos del ejercicio', CTRIB_RTRE_FILAS_OTRAS, 'pos', [1467, 1468, 1763, 1764, 1469, 1470, 1387]],
    ['Otras disminuciones del ejercicio', CTRIB_RTRE_FILAS_OTRAS, 'neg', [1471, 1472, 1765, 1766, 1473, 1474, 1388]],
    ['Remesas, retiros o dividendos imputados a los RTRE', ['6.1'], 'val', [1475, 1476, 1767, 1768, 1477, 1478, 1389]],
    ['Retiros en exceso y devoluciones de capital imputados en el ejercicio', ['6.2', '6.3'], 'val', [1480, 1481, 1769, 1770, 1482, 1483, 1390]],
    ['Remanente ejercicio siguiente (saldo positivo)', ['REMANENTE_FINAL'], 'pos', [1484, 1485, 1771, 1772, 1486, 1487, 1391]],
    ['Remanente ejercicio siguiente (saldo negativo)', ['REMANENTE_FINAL'], 'neg', [null, 1489, null, 1846, 1490, 1491, null]],
];
// Formulario R21 (Registro SAC): columnas del F22 y columnas SAC del RTRE.
const CTRIB_R21_COLUMNAS = [
    ['Desde 2017 · No sujeto a restitución · sin derecho a devolución', ['SAC_NO_RESTITUCION_DESDE_2020_SIN_DEVOLUCION', 'SAC_NO_RESTITUCION_HASTA_2019_SIN_DEVOLUCION']],
    ['Desde 2017 · No sujeto a restitución · con derecho a devolución', ['SAC_NO_RESTITUCION_DESDE_2020_CON_DEVOLUCION', 'SAC_NO_RESTITUCION_HASTA_2019_CON_DEVOLUCION']],
    ['Desde 2017 · Sujeto a restitución · sin derecho a devolución', ['SAC_RESTITUCION_AFECTAS_SIN_DEVOLUCION', 'SAC_RESTITUCION_EXENTAS_SIN_DEVOLUCION']],
    ['Desde 2017 · Sujeto a restitución · con derecho a devolución', ['SAC_RESTITUCION_AFECTAS_CON_DEVOLUCION', 'SAC_RESTITUCION_EXENTAS_CON_DEVOLUCION']],
    ['Desde 2017 · IPE', ['SAC_IPE_DESDE_2017']],
    ['Hasta 2016 · sin derecho a devolución', ['SAC_HASTA_2016_AFECTAS_SIN_DEVOLUCION', 'SAC_HASTA_2016_EXENTAS_SIN_DEVOLUCION']],
    ['Hasta 2016 · con derecho a devolución', ['SAC_HASTA_2016_AFECTAS_CON_DEVOLUCION', 'SAC_HASTA_2016_EXENTAS_CON_DEVOLUCION']],
    ['Hasta 2016 · IPE', ['SAC_IPE_HASTA_2016']],
];
const CTRIB_R21_FILAS = [
    ['Remanente ejercicio anterior o saldo inicial (saldo positivo)', ['1.1'], 'pos', [1495, 1496, 1497, 1498, 1499, 1501, 1502, 1503]],
    ['Remanente ejercicio anterior o saldo inicial (saldo negativo)', ['1.1'], 'neg', [1655, 1656, 1504, 1505, null, null, null, null]],
    ['Monto imputado al ISIF, según arts. 10 y 11 Ley N° 21.681', [], 'val', [1945, 1946, 1947, 1948, null, 1949, 1950, null]],
    ['Aumentos del ejercicio (por reorganizaciones)', CTRIB_RTRE_FILAS_REORG, 'pos', [1590, 1436, 1437, 1438, 1439, 1441, 1442, 1443]],
    ['Disminuciones del ejercicio (por reorganizaciones)', CTRIB_RTRE_FILAS_REORG, 'neg', [1444, 1447, 1448, 1449, 1508, 1509, 1510, 1511]],
    ['IDPC e IPE — RLI o base imponible generada en el ejercicio', ['4.5'], 'pos', [null, 1513, null, null, 1514, null, null, null]],
    ['IDPC e IPE — retiros o dividendos percibidos', [], 'val', [1515, 1516, 1517, 1518, 1519, 1520, 1521, 1522]],
    ['Otros aumentos del ejercicio', CTRIB_RTRE_FILAS_OTRAS, 'pos', [1523, 1524, 1525, 1526, 1527, 1528, 1529, 1530]],
    ['Otras disminuciones del ejercicio', CTRIB_RTRE_FILAS_OTRAS, 'neg', [1531, 1532, 1533, 1534, 1535, 1536, 1537, 1538]],
    ['Asignado a remesas, retiros o dividendos efectuados en el ejercicio', ['6.1'], 'val', [1539, 1540, 1541, 1542, 1543, 1544, 1547, 1548]],
    ['Asignado a retiros en exceso y devoluciones de capital efectuados en el ejercicio', ['6.2', '6.3'], 'val', [1549, 1550, 1551, 1552, 1553, 1554, 1555, 1556]],
    ['IDPC e IPE asignado a gastos rechazados del art. 21', ['6.4'], 'val', [1557, 1558, null, null, 1559, 1560, 1561, 1562]],
    ['Remanente ejercicio siguiente (saldo positivo)', ['REMANENTE_FINAL'], 'pos', [1563, 1564, 1565, 1566, 1567, 1568, 1569, 1570]],
    ['Remanente ejercicio siguiente (saldo negativo)', ['REMANENTE_FINAL'], 'neg', [1368, 1371, 1571, 1572, null, null, null, null]],
];

/** Formulario del F22 (R20 o R21) calculado desde las celdas del RTRE: filas con sus códigos y montos. */
function ctrib_formulario(array $celdas, array $columnas, array $filas): array
{
    $salida = [];
    $codigos = [];
    foreach ($filas as [$glosa, $filasRtre, $modo, $codigosFila]) {
        $celdasFila = [];
        foreach ($columnas as $i => [, $columnasRtre]) {
            $codigo = $codigosFila[$i] ?? null;
            if ($codigo === null) {
                $celdasFila[] = null;
                continue;
            }
            $valor = 0;
            foreach ($filasRtre as $fr) {
                foreach ($columnasRtre as $cr) {
                    $valor += (int) ($celdas[$fr][$cr]['monto'] ?? 0);
                }
            }
            $monto = match ($modo) { 'pos' => max(0, $valor), 'neg' => max(0, -$valor), default => $valor };
            $celdasFila[] = ['codigo' => (string) $codigo, 'monto' => $monto];
            $codigos[(string) $codigo] = $monto;
        }
        $salida[] = ['glosa' => $glosa, 'celdas' => $celdasFila];
    }
    return ['columnas' => array_column($columnas, 0), 'filas' => $salida, 'codigos' => $codigos];
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

    // Fórmula del RAI (4.2): CPTS + retiros del ejercicio + saldo negativo REX − saldo positivo REX − capital aportado
    // − saldo FUR − sobreprecio. Capital, FUR y sobreprecio se escriben a mano (parámetros del RTRE); el REX sale del registro.
    $cptPositivo = (int) $r19['totales']['1545'];
    $cptNegativo = (int) $r19['totales']['1546'];
    $parametros = ctrib_rtre_parametros($pdo, $empresa, $anio);
    $capitalAportado = $parametros['1494'];
    $saldoFur = $parametros['1725'];
    $sobreprecio = $parametros['SOBR'];

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
                $monto = 0; // se calcula después, con el REX del registro
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

    // Saldo del registro REX antes de imputaciones (bloques 1 a 5): el positivo se resta y el negativo se suma en el RAI.
    $rexAntes = 0;
    foreach (CTRIB_RTRE_ORDEN_REX as $col) {
        $rexAntes += $suma([1, 2, 3, 4, 5], $col);
    }
    $rexPositivoPrevio = max(0, $rexAntes);
    $rexNegativoAbs = max(0, -$rexAntes);
    $subtotalRai = $cptPositivo - $cptNegativo + $retiros + $rexNegativoAbs;
    $rai = max(0, $subtotalRai - $rexPositivoPrevio - $capitalAportado - $saldoFur - $sobreprecio);
    $celdas['4.2']['RAI'] = ['monto' => $rai, 'origen' => 'AUTO'];

    // Reverso del subtotal N°3 (4.1): si el RAI del subtotal 3 es positivo, se reversa.
    $s3Rai = $suma([1, 2, 3], 'RAI');
    $celdas['4.1']['RAI'] = ['monto' => -max(0, $s3Rai), 'origen' => 'AUTO'];

    // Retiros del ejercicio imputados al RAI (6.1): hasta el saldo del RAI disponible tras los bloques 4 y 5.
    $s5Rai = $suma([1, 2, 3, 4, 5], 'RAI');
    $retirosRai = min($retiros, max(0, $s5Rai));
    $celdas['6.1']['RAI'] = ['monto' => $retirosRai, 'origen' => 'AUTO'];
    // El exceso sobre el RAI se imputa a las rentas exentas e ingresos no renta (REX), en el orden del registro.
    $resto = $retiros - $retirosRai;
    $retirosRex = 0;
    foreach (CTRIB_RTRE_ORDEN_REX as $col) {
        if (!isset($celdas['6.1'][$col])) {
            continue;
        }
        $imputado = min($resto, max(0, $suma([1, 2, 3, 4, 5], $col)));
        $celdas['6.1'][$col] = ['monto' => $imputado, 'origen' => 'AUTO'];
        $resto -= $imputado;
        $retirosRex += $imputado;
    }
    $retirosNoImputados = $resto;

    // Crédito SAC asignado a los retiros (6.1): retiro × tasa / (100 − tasa), hasta el SAC disponible (como iTributario).
    $factorSac = round($tasa / (100 - $tasa), 6);
    $s5Sac = $suma([1, 2, 3, 4, 5], CTRIB_RTRE_COLUMNA_IDPC);
    $creditoSac = min((int) round($retirosRai * $factorSac, 0, PHP_ROUND_HALF_UP), max(0, $s5Sac));
    $celdas['6.1'][CTRIB_RTRE_COLUMNA_IDPC] = ['monto' => $creditoSac, 'origen' => 'AUTO'];

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
    if ($r17['desactualizado'] || $r19['desactualizado']) {
        $bloqueos[] = 'El Recuadro 17 o el 19 cambió después de finalizarlo: reábralo y finalícelo nuevamente.';
    }

    $pendientes = [
        'DDAN, REX/INR del ejercicio y créditos de IDPC voluntario (4.3 a 4.5): cero hasta cargarlos a mano.',
    ];
    if ($anterior === null) {
        $pendientes[] = 'Saldos iniciales (1.1): no hay RTRE del año anterior finalizado; escríbalos a mano en la fila 1.1 si corresponde.';
    }
    if ($retirosNoImputados > 0) {
        $pendientes[] = 'Retiros por ' . number_format($retirosNoImputados, 0, ',', '.') . ' exceden el RAI y el REX: no se imputan a los registros y se informan en el código 1193 del R6.';
    }

    return [
        'recuadro' => 'R20', 'anio' => $anio, 'anioComercial' => ctrib_anio_comercial($anio),
        'celdas' => $noNulas,
        'rai' => $rai, 'idpc' => $idpc,
        'remanenteRai' => $celdas['REMANENTE_FINAL']['RAI']['monto'] ?? 0,
        'remanenteSac' => $celdas['REMANENTE_FINAL'][CTRIB_RTRE_COLUMNA_IDPC]['monto'] ?? 0,
        'formulaRai' => [
            'cptPositivo' => $cptPositivo, 'cptNegativo' => $cptNegativo, 'retirosEjercicio' => $retiros,
            'rexNegativoAbs' => $rexNegativoAbs, 'rexPositivoPrevio' => $rexPositivoPrevio, 'subtotalRai' => $subtotalRai, 'capitalAportado' => $capitalAportado,
            'saldoFur' => $saldoFur, 'sobreprecioAcciones' => $sobreprecio, 'rai' => $rai,
        ],
        'formulaSac' => [
            'rli' => $rli, 'tasaIdpc' => $tasa, 'idpcEjercicio' => $idpc, 'columnaSacPropio' => CTRIB_RTRE_COLUMNA_IDPC,
            'factorSac' => $factorSac, 'creditoRetiros' => $creditoSac,
        ],
        'retirosImputadosRai' => $retirosRai,
        'retirosImputadosRex' => $retirosRex,
        'retirosNoImputados' => $retirosNoImputados,
        'parametros' => $parametros,
        'retirosEjercicio' => $retiros,
        'resumen' => ctrib_rtre_resumen($celdas),
        'secciones' => ctrib_rtre_secciones($catalogo, $celdas),
        'formularioR20' => ctrib_formulario($celdas, CTRIB_R20_COLUMNAS, CTRIB_R20_FILAS),
        'formularioR21' => ctrib_formulario($celdas, CTRIB_R21_COLUMNAS, CTRIB_R21_FILAS),
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
    // Se valida antes de borrar la celda guardada.
    if ($monto !== null) {
        if (!(is_int($monto) || (is_string($monto) && preg_match('/^-?\d+$/', trim($monto))))) {
            throw new CtribError('El monto debe ser un número entero.');
        }
        if ($celda['signo'] === 'SOLO_POSITIVO' && (int) $monto < 0) {
            throw new CtribError("La celda $clave solo admite valores positivos.");
        }
    }
    $anterior = ctrib_rtre_celdas_manuales($pdo, $empresa, $anio)[$clave] ?? null;
    $pdo->prepare('DELETE FROM ctrib_rtre_celdas WHERE empresa_id = ? AND anio = ? AND fila = ? AND columna = ?')->execute([$empresa, $anio, $fila, $columna]);
    if ($monto === null) {
        if ($anterior !== null) {
            ctrib_registrar_historial($pdo, $empresa, $anio, 'recuadro', "R20 $clave", (string) $anterior, 'automático', 'Celda manual quitada', $usuario);
        }
        return ctrib_r20($pdo, $empresa, $anio);
    }
    $valor = (int) $monto;
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

/** Registro SAC (R21): se vacía desde el RTRE en la grilla del formulario (códigos 1495 a 1572). */
function ctrib_r21(PDO $pdo, int $empresa, int $anio): array
{
    $r20 = ctrib_r20($pdo, $empresa, $anio);
    $formulario = $r20['formularioR21'];
    $lineas = [];
    foreach ($formulario['filas'] as $fila) {
        foreach ($fila['celdas'] as $i => $c) {
            if ($c !== null) {
                $lineas[] = ['codigo' => $c['codigo'], 'glosa' => $fila['glosa'] . ' — ' . $formulario['columnas'][$i], 'monto' => $c['monto']];
            }
        }
    }
    return [
        'recuadro' => 'R21', 'anio' => $anio, 'anioComercial' => ctrib_anio_comercial($anio),
        'formulario' => $formulario,
        'lineas' => $lineas,
        'estado' => $r20['estado'],
        'pendientesDeCalculo' => ['Créditos de IDPC voluntario y del art. 21 (CREDITO_ART21) no tienen columna en el formulario en esta versión.'],
    ];
}

/** El RTRE sirve para certificar solo si está finalizado y no cambió desde entonces. */
function ctrib_rtre_vigente(PDO $pdo, int $empresa, int $anio): bool
{
    return ctrib_estado_recuadro($pdo, $empresa, $anio, 'R20')['estado'] === 'FINALIZADO'
        && !ctrib_r20($pdo, $empresa, $anio)['desactualizado'];
}

/** DJ 1948 y Certificado 70: paso 1 exige el RTRE finalizado. Socios con retiros del año y su certificado. */
function ctrib_dj1948(PDO $pdo, int $empresa, int $anio): array
{
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
    $paso1 = ctrib_rtre_vigente($pdo, $empresa, $anio);
    return [
        'anio' => $anio, 'anioComercial' => ctrib_anio_comercial($anio),
        'paso1' => ['listo' => $paso1, 'mensaje' => $paso1 ? 'RTRE finalizado: puede emitir certificados.' : 'Paso 1 · Certificado 70: el RTRE (R20) debe estar finalizado y al día antes de emitir certificados o la DJ 1948.'],
        'socios' => $filas,
        'modo' => $efectivos['modo'],
        'total' => $efectivos['total'],
        'sociosParticipacion' => ctrib_socios($pdo, $empresa, $anio),
        'composicion' => ctrib_composicion_retiros($pdo, $empresa, $anio),
        'pendientesDeCalculo' => ['Crédito por retiro y montos del Certificado 70 (imputación por socio): pendiente de cálculo en esta versión.'],
    ];
}

/** Emite el Certificado 70 de un socio (número correlativo por año). Si ya existe, devuelve el mismo número. */
function ctrib_certificado_emitir(PDO $pdo, int $empresa, int $anio, string $rut, string $usuario): array
{
    $rut = ctrib_rut($rut);
    if ($rut === '') {
        throw new CtribError('Falta el RUT del socio o titular: ingréselo en "Composición y retiros del año" y guarde antes de emitir el certificado.');
    }
    if (!ctrib_rtre_vigente($pdo, $empresa, $anio)) {
        throw new CtribError('El RTRE (R20) debe estar finalizado y al día antes de emitir certificados: si cambió, reábralo y finalícelo nuevamente.');
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
