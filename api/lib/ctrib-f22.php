<?php
// Anverso del Formulario 22 AT 2026 de Cifrax Tributario: impuestos anuales (IGC según tabla, IDPC por régimen,
// IU 40% art. 21), créditos, PPM, retenciones, resultado (305), devolución (85-87) o impuesto a pagar (90-91).
// Reglas: Suplemento Tributario F22 AT 2026 (pp. 102, 177, 181-191, 210, 271-297, 308-316) y 14d8.xlsx (Tabla IGC y F22 Socio Estrada).
declare(strict_types=1);

require_once __DIR__ . '/ctrib-r1.php';
require_once __DIR__ . '/ctrib-r12.php';
require_once __DIR__ . '/ctrib-r17.php';
require_once __DIR__ . '/ctrib-presunta.php';
require_once __DIR__ . '/ctrib-socios.php';
require_once __DIR__ . '/sii.php';

// Tabla oficial del Impuesto Global Complementario AT 2026 (Suplemento p. 102 y 14d8.xlsx, UTA dic. 2025 = $834.504).
const CTRIB_IGC_TABLA_2025 = [
    ['tramo' => 1, 'desde' => 0.00,         'hasta' => 11265804.00,  'factor' => 0.00,  'rebaja' => 0.00],
    ['tramo' => 2, 'desde' => 11265804.01,  'hasta' => 25035120.00,  'factor' => 0.04,  'rebaja' => 450632.16],
    ['tramo' => 3, 'desde' => 25035120.01,  'hasta' => 41725200.00,  'factor' => 0.08,  'rebaja' => 1452036.96],
    ['tramo' => 4, 'desde' => 41725200.01,  'hasta' => 58415280.00,  'factor' => 0.135, 'rebaja' => 3746922.96],
    ['tramo' => 5, 'desde' => 58415280.01,  'hasta' => 75105360.00,  'factor' => 0.23,  'rebaja' => 9296374.56],
    ['tramo' => 6, 'desde' => 75105360.01,  'hasta' => 100140480.00, 'factor' => 0.304, 'rebaja' => 14854171.20],
    ['tramo' => 7, 'desde' => 100140480.01, 'hasta' => 258696240.00, 'factor' => 0.35,  'rebaja' => 19460633.28],
    ['tramo' => 8, 'desde' => 258696240.01, 'hasta' => null,         'factor' => 0.40,  'rebaja' => 32395445.28],
];

const CTRIB_F22_MANUALES = [
    // Rentas brutas afectas y rebajas personales (Líneas 1 a 23)
    '104', '105', '106', '108', '955', '1632', '110', '155', '161', '159',
    '166', '111', '751', '765',
    // Débitos y créditos al IGC (Líneas 29 a 48)
    '1035', '612', '1638', '895', '162', '610',
    // Rebajas a IDPC (Líneas 54, 55, 56) e IU 40% art. 21 (Línea 65)
    '19', '1111', '1641', '113', '1007',
    // Deducciones a impuestos (PPM, capacitación, retenciones, créditos puestos a disposición) y otros cargos
    '1904', '1905', '1906', '82', '198', '58', '1645', '900', '1796',
    // Resultado: saldo puesto a disposición socios (86) y reajuste art. 72 (39)
    '86', '39',
];

/** Tabla del IGC del año comercial (AT 2026 por defecto; permite sobrecarga futura vía tasas-AAAA.json). */
function ctrib_igc_tabla(int $anioComercial): array
{
    $ruta = __DIR__ . '/ctrib/tasas-' . $anioComercial . '.json';
    if (is_file($ruta)) {
        $datos = json_decode((string) file_get_contents($ruta), true);
        if (is_array($datos['tablaIgc'] ?? null) && $datos['tablaIgc'] !== []) {
            return $datos['tablaIgc'];
        }
    }
    return CTRIB_IGC_TABLA_2025;
}

/** Determina el IGC según tabla (Línea 25, código 157): tramo, factor, rebaja y monto entero. */
function ctrib_igc_calcular(int $baseImponible, int $anioComercial = 2025): array
{
    $tabla = ctrib_igc_tabla($anioComercial);
    if ($baseImponible <= 0) {
        return ['tramo' => 1, 'factor' => 0.0, 'rebaja' => 0.0, 'impuesto' => 0];
    }
    $elegido = end($tabla);
    foreach ($tabla as $t) {
        if ($t['hasta'] === null || $baseImponible <= (float) $t['hasta']) {
            $elegido = $t;
            break;
        }
    }
    $bruto = $baseImponible * (float) $elegido['factor'] - (float) $elegido['rebaja'];
    return [
        'tramo' => (int) $elegido['tramo'],
        'factor' => (float) $elegido['factor'],
        'rebaja' => (float) $elegido['rebaja'],
        'impuesto' => max(0, (int) round($bruto, 0, PHP_ROUND_HALF_UP)),
    ];
}

/** Mapa código -> valor declarado en la última consulta SII del F22 del año (si existe). */
function ctrib_f22_declarado_sii(PDO $pdo, int $empresa, int $anio): array
{
    try {
        $est = sii_consulta_estado($pdo, $empresa, 'f22', $anio);
    } catch (Throwable) {
        return ['disponible' => false, 'folio' => null, 'estado' => null, 'codigos' => []];
    }
    $u = $est['ultima'] ?? null;
    if (!$u || !is_array($u['datos'] ?? null)) {
        return ['disponible' => false, 'folio' => null, 'estado' => null, 'codigos' => []];
    }
    $mapa = [];
    foreach ($u['datos']['codigos'] ?? [] as $c) {
        $cod = (string) ($c['codigo'] ?? '');
        if ($cod !== '' && is_numeric($c['valor'] ?? null)) {
            $mapa[$cod] = (int) round((float) $c['valor']);
        }
    }
    return [
        'disponible' => true,
        'folio' => $u['datos']['folio'] ?? null,
        'estado' => $u['estado'] ?? null,
        'codigos' => $mapa,
    ];
}

/** Helper para construir una línea del anverso F22 con comparación SII opcional. */
function ctrib_f22_linea(string $lineaNum, string $codigo, string $glosa, string $seccion, string $signo, string $naturaleza, int $monto, string $origen, array $siiCodigos): array
{
    $valorSii = array_key_exists($codigo, $siiCodigos) ? $siiCodigos[$codigo] : null;
    return [
        'linea' => $lineaNum,
        'codigo' => $codigo,
        'glosa' => $glosa,
        'seccion' => $seccion,
        'signo' => $signo,
        'naturaleza' => $naturaleza,
        'monto' => $monto,
        'origen' => $origen,
        'valorSii' => $valorSii,
        'diferenciaSii' => $valorSii !== null ? $monto - $valorSii : null,
    ];
}

/** Cálculo completo del Anverso F22 (impuestos anuales, créditos, PPM, retenciones y resultado 305/87/91). */
function ctrib_f22(PDO $pdo, int $empresa, int $anio, ?array $empresasPermitidas = null): array
{
    $anioComercial = ctrib_anio_comercial($anio);
    $config = ctrib_empresa_config($pdo, $empresa);
    $tipo = $config['tipo'];
    $regimen = $config['regimen'] ?: '14D3';
    $esPersona = $regimen === 'PERSONA';
    $esEI = $tipo === 'EI';
    $incluyeIgc = $esPersona || $esEI;
    $incluyeEmpresa = !$esPersona;

    $manuales = ctrib_valores_manuales($pdo, $empresa, $anio, 'F22');
    $sii = ctrib_f22_declarado_sii($pdo, $empresa, $anio);
    $siiCod = $sii['codigos'];

    $lineas = [];
    $bloqueos = [];
    $avisos = [];

    $valManual = fn(string $c, int $auto = 0, string $origenAuto = 'MANUAL'): array =>
        array_key_exists($c, $manuales)
            ? ['monto' => $manuales[$c], 'origen' => 'MANUAL']
            : ['monto' => $auto, 'origen' => $auto > 0 ? $origenAuto : 'MANUAL'];

    // --- 1. Impuestos de Primera Categoría según régimen (Líneas 54 a 57) ---
    $idpcLineas = [];
    $idpcPagar = 0;
    $l57Cache = null;

    if ($incluyeEmpresa) {
        if ($regimen === '14D3') {
            $r17 = ctrib_r17($pdo, $empresa, $anio);
            $m17 = array_column($r17['lineas'], 'monto', 'codigo');
            $base18 = max(0, (int) ($m17['1440'] ?? 0));
            $tasa = (float) ($r17['idpcEstimado']['tasa'] ?? ctrib_tasa_pro_pyme($anioComercial));
            $idpcBruto = (int) round($base18 * $tasa / 100, 0, PHP_ROUND_HALF_UP);
            $reb19 = $manuales['19'] ?? 0;
            if ($reb19 > $idpcBruto) {
                $bloqueos[] = 'Las rebajas al IDPC (código 19) no pueden superar el IDPC determinado ($' . number_format($idpcBruto, 0, ',', '.') . ').';
            }
            $idpc20 = max(0, $idpcBruto - $reb19);
            $idpcPagar = $idpc20;
            $idpcLineas[] = ctrib_f22_linea('54', '18', 'Base imponible IDPC Pro Pyme (art. 14 D N°3, desde R17 cód. 1440)', 'Impuestos determinados', 'POSITIVO', 'CALCULADO', $base18, 'R17', $siiCod);
            $idpcLineas[] = ctrib_f22_linea('54', '19', 'Rebajas y créditos al IDPC Pro Pyme (Recuadro N° 8)', 'Impuestos determinados', 'NEGATIVO', 'MANUAL', $reb19, 'MANUAL', $siiCod);
            $idpcLineas[] = ctrib_f22_linea('54', '20', 'IDPC Pro Pyme a declarar (tasa ' . str_replace('.', ',', (string) $tasa) . '%)', 'Impuestos determinados', 'POSITIVO', 'CALCULADO', $idpc20, 'CALCULADO', $siiCod);
        } elseif ($regimen === '14A') {
            $r12 = ctrib_r12($pdo, $empresa, $anio);
            $m12 = array_column($r12['lineas'], 'monto', 'codigo');
            $base1109 = max(0, (int) ($m12['1690'] ?? 0));
            $tasa = (float) ($r12['idpcEstimado']['tasa'] ?? ctrib_tasa_semi_integrado($anioComercial, '14A'));
            $idpcBruto = (int) round($base1109 * $tasa / 100, 0, PHP_ROUND_HALF_UP);
            $reb1111 = $manuales['1111'] ?? 0;
            if ($reb1111 > $idpcBruto) {
                $bloqueos[] = 'Las rebajas al IDPC (código 1111) no pueden superar el IDPC determinado ($' . number_format($idpcBruto, 0, ',', '.') . ').';
            }
            $idpc1113 = max(0, $idpcBruto - $reb1111);
            $idpcPagar = $idpc1113;
            $idpcLineas[] = ctrib_f22_linea('55', '1109', 'Base imponible IDPC Semi Integrado (art. 14 A, desde R12 cód. 1690)', 'Impuestos determinados', 'POSITIVO', 'CALCULADO', $base1109, 'R12', $siiCod);
            $idpcLineas[] = ctrib_f22_linea('55', '1111', 'Rebajas y créditos al IDPC Semi Integrado (Recuadro N° 8)', 'Impuestos determinados', 'NEGATIVO', 'MANUAL', $reb1111, 'MANUAL', $siiCod);
            $idpcLineas[] = ctrib_f22_linea('55', '1113', 'IDPC Semi Integrado a declarar (tasa ' . str_replace('.', ',', (string) $tasa) . '%)', 'Impuestos determinados', 'POSITIVO', 'CALCULADO', $idpc1113, 'CALCULADO', $siiCod);
        } elseif ($regimen === '14G') {
            $r12 = ctrib_r12($pdo, $empresa, $anio);
            $m12 = array_column($r12['lineas'], 'monto', 'codigo');
            $base1640 = max(0, (int) ($m12['1690'] ?? 0));
            $tasa = (float) ($r12['idpcEstimado']['tasa'] ?? ctrib_tasa_semi_integrado($anioComercial, '14G'));
            $idpcBruto = (int) round($base1640 * $tasa / 100, 0, PHP_ROUND_HALF_UP);
            $reb1641 = $manuales['1641'] ?? 0;
            if ($reb1641 > $idpcBruto) {
                $bloqueos[] = 'Las rebajas al IDPC (código 1641) no pueden superar el IDPC determinado ($' . number_format($idpcBruto, 0, ',', '.') . ').';
            }
            $idpc1642 = max(0, $idpcBruto - $reb1641);
            $idpcPagar = $idpc1642;
            $idpcLineas[] = ctrib_f22_linea('56', '1640', 'Base imponible IDPC art. 14 G (desde R12 cód. 1690)', 'Impuestos determinados', 'POSITIVO', 'CALCULADO', $base1640, 'R12', $siiCod);
            $idpcLineas[] = ctrib_f22_linea('56', '1641', 'Rebajas y créditos al IDPC art. 14 G (Recuadro N° 8)', 'Impuestos determinados', 'NEGATIVO', 'MANUAL', $reb1641, 'MANUAL', $siiCod);
            $idpcLineas[] = ctrib_f22_linea('56', '1642', 'IDPC art. 14 G a declarar (tasa ' . str_replace('.', ',', (string) $tasa) . '%)', 'Impuestos determinados', 'POSITIVO', 'CALCULADO', $idpc1642, 'CALCULADO', $siiCod);
        } elseif ($regimen === 'PRESUNTA') {
            $l57Cache = ctrib_l57($pdo, $empresa, $anio);
            $idpcPagar = (int) $l57Cache['totales']['189'];
            $idpcLineas[] = ctrib_f22_linea('57', '187', 'Base imponible rentas presuntas (art. 34 LIR, desde L57)', 'Impuestos determinados', 'POSITIVO', 'CALCULADO', (int) $l57Cache['totales']['187'], 'L57', $siiCod);
            $idpcLineas[] = ctrib_f22_linea('57', '188', 'Rebaja por impuesto territorial agrícola (desde L57)', 'Impuestos determinados', 'NEGATIVO', 'CALCULADO', (int) $l57Cache['totales']['188'], 'L57', $siiCod);
            $idpcLineas[] = ctrib_f22_linea('57', '189', 'IDPC sobre rentas presuntas a declarar (tasa 25%)', 'Impuestos determinados', 'POSITIVO', 'CALCULADO', $idpcPagar, 'L57', $siiCod);
        } elseif ($regimen === '14D8') {
            $avisos[] = 'Régimen 14 D N°8 (Transparencia Tributaria): la empresa está liberada del IDPC y sus PPM se ponen en su totalidad a disposición de los propietarios mediante DJ 1947 (código 1645 del F22 del propietario, no en código 36 ni 86).';
        }
    }

    // --- 2. Base Imponible e IGC para personas naturales y Empresario Individual (Líneas 1 a 53) ---
    $igcDetalle = null;
    $igcPagar31 = 0;
    $remanenteDevolucion116 = 0;
    $participaciones = ['rut' => '', 'filas' => [], 'totales' => [], 'avisos' => []];
    $negocioEI = null;
    $r1Cache = null;

    if ($incluyeIgc) {
        $participaciones = ctrib_socio_participaciones($pdo, $empresa, $anio, $empresasPermitidas);
        $pt = $participaciones['totales'];
        $avisos = array_merge($avisos, $participaciones['avisos']);
        $r1Cache = ctrib_r1($pdo, $empresa, $anio);
        $auto110 = (int) ($r1Cache['totales']['618'] ?? 0);

        $ei = ['retiro104' => 0, 'rentaPresunta108' => 0, 'baseAtribuida1632' => 0, 'incremento159' => 0,
            'debito1035' => 0, 'creditoSinDev612' => 0, 'credito33bis1638' => 0, 'creditoConDev610' => 0, 'ppm1645' => 0];
        if ($esEI) {
            $resEi = ctrib_ei_negocio($pdo, $empresa, $anio, $regimen, $l57Cache);
            if ($resEi !== null) {
                $negocioEI = $resEi['fila'];
                $ei = $negocioEI;
                if ($resEi['aviso'] !== null) {
                    $avisos[] = $resEi['aviso'];
                }
            }
        }

        $orig = function (int $valEi, int $valPart, string $eiLabel = 'NEGOCIO_EI') use ($regimen): string {
            if ($valEi > 0 && $valPart === 0) {
                return $regimen === 'PRESUNTA' ? 'L57' : $eiLabel;
            }
            if ($valPart > 0) {
                return 'PARTICIPACIONES';
            }
            return $valEi > 0 ? $eiLabel : 'MANUAL';
        };

        $v104  = $valManual('104',  $ei['retiro104']        + (int) ($pt['104'] ?? 0),  $orig($ei['retiro104'],        (int) ($pt['104'] ?? 0)));
        $v105  = $valManual('105',  (int) ($pt['105'] ?? 0),  'PARTICIPACIONES');
        $v106  = $valManual('106');
        $v108  = $valManual('108',  $ei['rentaPresunta108']  + (int) ($pt['108'] ?? 0),  $orig($ei['rentaPresunta108'],  (int) ($pt['108'] ?? 0)));
        $v955  = $valManual('955');
        $v1632 = $valManual('1632', $ei['baseAtribuida1632'] + (int) ($pt['1632'] ?? 0), $orig($ei['baseAtribuida1632'], (int) ($pt['1632'] ?? 0)));
        $v110  = $valManual('110',  $auto110, 'R1');
        $v155  = $valManual('155');
        $v161  = $valManual('161');
        $v159  = $valManual('159',  $ei['incremento159']     + (int) ($pt['159'] ?? 0),  $orig($ei['incremento159'],     (int) ($pt['159'] ?? 0)));
        $v166  = $valManual('166');

        $subtotal158 = $v104['monto'] + $v105['monto'] + $v106['monto'] + $v108['monto'] + $v955['monto']
            + $v1632['monto'] + $v110['monto'] + $v155['monto'] + $v161['monto'] + $v159['monto'] - $v166['monto'];

        $v111 = $valManual('111');
        $v751 = $valManual('751');
        $v765 = $valManual('765');
        $base170 = max(0, $subtotal158 - $v111['monto'] - $v751['monto'] - $v765['monto']);

        $igcDetalle = ctrib_igc_calcular($base170, $anioComercial);
        $igc157 = $igcDetalle['impuesto'];

        $v1035 = $valManual('1035', $ei['debito1035']       + (int) ($pt['1035'] ?? 0), $orig($ei['debito1035'],       (int) ($pt['1035'] ?? 0)));
        $v612  = $valManual('612',  $ei['creditoSinDev612'] + (int) ($pt['612'] ?? 0),  $orig($ei['creditoSinDev612'], (int) ($pt['612'] ?? 0)));
        $v1638 = $valManual('1638', $ei['credito33bis1638'] + (int) ($pt['1638'] ?? 0), $orig($ei['credito33bis1638'], (int) ($pt['1638'] ?? 0)));
        $v895  = $valManual('895');
        $v162  = $valManual('162');
        $v610  = $valManual('610',  $ei['creditoConDev610'] + (int) ($pt['610'] ?? 0),  $orig($ei['creditoConDev610'], (int) ($pt['610'] ?? 0)));

        // Línea 52 (304): IGC + débito restitución - créditos (sin devolución y con devolución).
        $det304 = ($igc157 + $v1035['monto']) - ($v612['monto'] + $v1638['monto'] + $v895['monto'] + $v162['monto'] + $v610['monto']);
        if ($det304 >= 0) {
            $igcPagar31 = $det304;
        } else {
            // Suplemento p. 177: el remanente negativo solo se traslada a Línea 97 hasta el tope de créditos con derecho a devolución (40, 46, 48).
            $creditosConDevolucion = $v1638['monto'] + $v162['monto'] + $v610['monto'];
            $remanenteDevolucion116 = min(abs($det304), $creditosConDevolucion);
        }

        $lineas = array_merge($lineas, [
            ctrib_f22_linea('1', '104', 'Retiros o remesas afectos al IGC o IA (art. 14 A / 14 D N°3)', 'Base imponible IGC — Rentas brutas', 'POSITIVO', 'MANUAL', $v104['monto'], $v104['origen'], $siiCod),
            ctrib_f22_linea('2', '105', 'Dividendos afectos al IGC o IA (art. 14 A / 14 D N°3)', 'Base imponible IGC — Rentas brutas', 'POSITIVO', 'MANUAL', $v105['monto'], $v105['origen'], $siiCod),
            ctrib_f22_linea('3', '106', 'Gastos rechazados y otras partidas art. 21 inc. 3° LIR', 'Base imponible IGC — Rentas brutas', 'POSITIVO', 'MANUAL', $v106['monto'], $v106['origen'], $siiCod),
            ctrib_f22_linea('4', '108', 'Rentas presuntas propias o por participaciones (art. 34 LIR)', 'Base imponible IGC — Rentas brutas', 'POSITIVO', 'MANUAL', $v108['monto'], $v108['origen'], $siiCod),
            ctrib_f22_linea('5', '955', 'Rentas propias o por participaciones sin contabilidad completa (art. 14 B N°1)', 'Base imponible IGC — Rentas brutas', 'POSITIVO', 'MANUAL', $v955['monto'], $v955['origen'], $siiCod),
            ctrib_f22_linea('6', '1632', 'Rentas propias o por participaciones régimen transparencia (art. 14 D N°8)', 'Base imponible IGC — Rentas brutas', 'POSITIVO', 'MANUAL', $v1632['monto'], $v1632['origen'], $siiCod),
            ctrib_f22_linea('7', '110', 'Rentas percibidas de honorarios art. 42 N°2 (según Recuadro N° 1)', 'Base imponible IGC — Rentas brutas', 'POSITIVO', 'MANUAL', $v110['monto'], $v110['origen'], $siiCod),
            ctrib_f22_linea('8', '155', 'Rentas de capitales mobiliarios, ganancias de capital y retiros ELD', 'Base imponible IGC — Rentas brutas', 'POSITIVO', 'MANUAL', $v155['monto'], $v155['origen'], $siiCod),
            ctrib_f22_linea('13', '161', 'Sueldos, pensiones y otras rentas similares de fuente nacional', 'Base imponible IGC — Rentas brutas', 'POSITIVO', 'MANUAL', $v161['monto'], $v161['origen'], $siiCod),
            ctrib_f22_linea('14', '159', 'Incremento por IDPC (arts. 54 N°1 y 62 LIR)', 'Base imponible IGC — Rentas brutas', 'POSITIVO', 'MANUAL', $v159['monto'], $v159['origen'], $siiCod),
            ctrib_f22_linea('15', '166', 'Impuesto territorial pagado en el año (art. 55 letra a) LIR)', 'Base imponible IGC — Rebajas', 'NEGATIVO', 'MANUAL', $v166['monto'], $v166['origen'], $siiCod),
            ctrib_f22_linea('19', '158', 'SUB TOTAL rentas brutas', 'Base imponible IGC — Rebajas', 'IGUAL', 'CALCULADO', $subtotal158, 'CALCULADO', $siiCod),
            ctrib_f22_linea('20', '111', 'Cotizaciones previsionales empresario o socio (art. 55 letra b)', 'Base imponible IGC — Rebajas', 'NEGATIVO', 'MANUAL', $v111['monto'], $v111['origen'], $siiCod),
            ctrib_f22_linea('21', '751', 'Intereses créditos hipotecarios art. 55 bis / DFL 2', 'Base imponible IGC — Rebajas', 'NEGATIVO', 'MANUAL', $v751['monto'], $v751['origen'], $siiCod),
            ctrib_f22_linea('23', '765', 'Ahorro previsional voluntario (art. 42 bis inc. 1°)', 'Base imponible IGC — Rebajas', 'NEGATIVO', 'MANUAL', $v765['monto'], $v765['origen'], $siiCod),
            ctrib_f22_linea('24', '170', 'BASE IMPONIBLE ANUAL DE IUSC o IGC', 'Base imponible IGC — Rebajas', 'IGUAL', 'CALCULADO', $base170, 'CALCULADO', $siiCod),
            ctrib_f22_linea('25', '157', 'IGC o IUSC según tabla AT ' . $anio . ' (Tramo ' . $igcDetalle['tramo'] . ', factor ' . str_replace('.', ',', (string) ($igcDetalle['factor'] * 100)) . '%)', 'IGC, débitos y créditos', 'POSITIVO', 'CALCULADO', $igc157, 'CALCULADO', $siiCod),
            ctrib_f22_linea('29', '1035', 'Débito fiscal por restitución crédito IDPC (35%, art. 56 N°3 inc. final)', 'IGC, débitos y créditos', 'POSITIVO', 'MANUAL', $v1035['monto'], $v1035['origen'], $siiCod),
            ctrib_f22_linea('37', '612', 'Crédito al IGC por IDPC sin derecho a devolución', 'IGC, débitos y créditos', 'NEGATIVO', 'MANUAL', $v612['monto'], $v612['origen'], $siiCod),
            ctrib_f22_linea('40', '1638', 'Crédito al IGC por art. 33 bis LIR (art. 14 D N°8)', 'IGC, débitos y créditos', 'NEGATIVO', 'MANUAL', $v1638['monto'], $v1638['origen'], $siiCod),
            ctrib_f22_linea('41', '895', 'Crédito al IGC por gastos en educación (art. 55 ter)', 'IGC, débitos y créditos', 'NEGATIVO', 'MANUAL', $v895['monto'], $v895['origen'], $siiCod),
            ctrib_f22_linea('46', '162', 'Crédito al IGC por Impuesto Único de Segunda Categoría (sueldos)', 'IGC, débitos y créditos', 'NEGATIVO', 'MANUAL', $v162['monto'], $v162['origen'], $siiCod),
            ctrib_f22_linea('48', '610', 'Crédito al IGC por IDPC con derecho a devolución (art. 56 N°3)', 'IGC, débitos y créditos', 'NEGATIVO', 'MANUAL', $v610['monto'], $v610['origen'], $siiCod),
            ctrib_f22_linea('52', '304', 'IGC O IUSC, DÉBITO FISCAL Y/O TASA ADICIONAL DETERMINADO', 'IGC, débitos y créditos', 'IGUAL', 'CALCULADO', $det304, 'CALCULADO', $siiCod),
            ctrib_f22_linea('53', '31', 'Impuesto Global Complementario a declarar (desde cód. 304 positivo)', 'Impuestos determinados', 'POSITIVO', 'CALCULADO', $igcPagar31, 'CALCULADO', $siiCod),
        ]);
    }

    // Agregamos las líneas de IDPC del régimen
    $lineas = array_merge($lineas, $idpcLineas);

    // --- 3. Impuesto Único 40% art. 21 inc. 1° (Línea 65, solo 14D3 y 14A) ---
    $iu114 = 0;
    if (in_array($regimen, ['14D3', '14A'], true)) {
        $base113 = $manuales['113'] ?? 0;
        $reb1007 = $manuales['1007'] ?? 0;
        $iuBruto = (int) round($base113 * 0.40, 0, PHP_ROUND_HALF_UP);
        if ($reb1007 > $iuBruto) {
            $bloqueos[] = 'Las rebajas al IU 40% (código 1007) no pueden superar el impuesto determinado ($' . number_format($iuBruto, 0, ',', '.') . ').';
        }
        $iu114 = max(0, $iuBruto - $reb1007);
        $lineas[] = ctrib_f22_linea('65', '113', 'Base imponible IU 40% gastos rechazados art. 21 inc. 1° LIR', 'Impuestos determinados', 'POSITIVO', 'MANUAL', $base113, 'MANUAL', $siiCod);
        $lineas[] = ctrib_f22_linea('65', '1007', 'Créditos/rebajas imputables al IU 40%', 'Impuestos determinados', 'NEGATIVO', 'MANUAL', $reb1007, 'MANUAL', $siiCod);
        $lineas[] = ctrib_f22_linea('65', '114', 'Impuesto único 40% art. 21 inc. 1° a declarar', 'Impuestos determinados', 'POSITIVO', 'CALCULADO', $iu114, 'CALCULADO', $siiCod);
    }

    // --- 4. Deducciones a los impuestos (Líneas 85 a 99) y Otros cargos (106-107) ---
    $ppm1904 = ($incluyeEmpresa && $regimen !== '14D8') ? ($manuales['1904'] ?? 0) : 0;
    $ppm1905 = $incluyeIgc ? ($manuales['1905'] ?? 0) : 0;
    $ppm1906 = $regimen !== '14D8' ? ($manuales['1906'] ?? 0) : 0;
    $ppm36 = $ppm1904 + $ppm1905 + $ppm1906;

    if ($regimen !== '14D8') {
        if ($incluyeEmpresa) {
            $lineas[] = ctrib_f22_linea('85a', '1904', 'PPM obligatorios 1ª categoría reajustados (art. 84 a/c/e/h y 14 D N°3 k)', 'Deducciones y PPM', 'NEGATIVO', 'MANUAL', $ppm1904, 'MANUAL', $siiCod);
        }
        if ($incluyeIgc) {
            $lineas[] = ctrib_f22_linea('85b', '1905', 'PPM 2ª categoría reajustados (art. 84 letra b)', 'Deducciones y PPM', 'NEGATIVO', 'MANUAL', $ppm1905, 'MANUAL', $siiCod);
        }
        $lineas[] = ctrib_f22_linea('85c', '1906', 'PPM voluntarios reajustados (art. 88)', 'Deducciones y PPM', 'NEGATIVO', 'MANUAL', $ppm1906, 'MANUAL', $siiCod);
        $lineas[] = ctrib_f22_linea('85', '36', 'Total Pagos Provisionales Mensuales (PPM)', 'Deducciones y PPM', 'NEGATIVO', 'CALCULADO', $ppm36, 'CALCULADO', $siiCod);
    }

    $credCap82 = ($incluyeEmpresa && $regimen !== '14D8') ? ($manuales['82'] ?? 0) : 0;
    if ($incluyeEmpresa && $regimen !== '14D8') {
        $lineas[] = ctrib_f22_linea('87', '82', 'Crédito por gastos de capacitación (Ley N° 19.518)', 'Deducciones y PPM', 'NEGATIVO', 'MANUAL', $credCap82, 'MANUAL', $siiCod);
    }

    $ret198 = 0;
    $cred58 = 0;
    $ppm1645 = 0;
    $cot900 = 0;
    $prestamo1796 = 0;

    if ($regimen === '14D8') {
        $ppmInfo14d8 = $manuales['1904'] ?? 0;
        $lineas[] = ctrib_f22_linea('DJ1947', '1904', 'PPM reajustados puestos a disposición de propietarios (informativo para cód. 1645 de socios; no rebaja en empresa)', 'Deducciones y PPM', 'IGUAL', 'MANUAL', $ppmInfo14d8, 'MANUAL', $siiCod);
    }

    if ($incluyeIgc) {
        $auto198 = (int) ($r1Cache['totales']['619'] ?? 0);
        $v198 = $valManual('198', $auto198, 'R1');
        $ret198 = $v198['monto'];
        $cred58 = $manuales['58'] ?? 0;
        $ei1645 = (int) ($negocioEI['ppm1645'] ?? 0);
        $pt1645 = (int) ($participaciones['totales']['1645'] ?? 0);
        $orig1645 = ($ei1645 > 0 && $pt1645 === 0) ? 'NEGOCIO_EI' : 'PARTICIPACIONES';
        $v1645 = $valManual('1645', $ei1645 + $pt1645, $orig1645);
        $ppm1645 = $v1645['monto'];
        $cot900 = $manuales['900'] ?? 0;
        $retArt74N2 = array_key_exists('198', $manuales) ? $ret198 : (int) ($r1Cache['totales']['492'] ?? $ret198);
        $topeCot900 = $ppm1905 + $ppm1906 + $retArt74N2;
        if ($cot900 > $topeCot900 && $topeCot900 > 0) {
            $bloqueos[] = 'Las cotizaciones previsionales (900) no pueden superar la suma de PPM 2ª cat. y retenciones art. 74 N°2 ($' . number_format($topeCot900, 0, ',', '.') . ', Suplemento p. 308).';
        }
        $prestamo1796 = $manuales['1796'] ?? 0;
        $lineas[] = ctrib_f22_linea('91', '198', 'Retenciones por honorarios declaradas en cód. 110 (Recuadro N° 1)', 'Deducciones y PPM', 'NEGATIVO', 'MANUAL', $ret198, $v198['origen'], $siiCod);
        $lineas[] = ctrib_f22_linea('97', '116', 'Remanente de créditos con derecho a devolución (desde cód. 304 negativo)', 'Deducciones y PPM', 'NEGATIVO', 'CALCULADO', $remanenteDevolucion116, 'CALCULADO', $siiCod);
        $lineas[] = ctrib_f22_linea('98', '58', 'Créditos y PPM puestos a disposición por sociedades', 'Deducciones y PPM', 'NEGATIVO', 'MANUAL', $cred58, 'MANUAL', $siiCod);
        $lineas[] = ctrib_f22_linea('99', '1645', 'PPM puestos a disposición por empresas 14 D N°8 (Certificado N° 69)', 'Deducciones y PPM', 'NEGATIVO', 'MANUAL', $ppm1645, $v1645['origen'], $siiCod);
        $lineas[] = ctrib_f22_linea('106', '900', 'Cargo por cotizaciones previsionales trabajadores independientes (tope PPM/ret. art. 92 F)', 'Otros cargos', 'POSITIVO', 'MANUAL', $cot900, 'MANUAL', $siiCod);
        $lineas[] = ctrib_f22_linea('107', '1796', 'Cuota préstamo tasa 0% (préstamo solidario del Estado)', 'Otros cargos', 'POSITIVO', 'MANUAL', $prestamo1796, 'MANUAL', $siiCod);
    }

    // --- 5. Resultado Liquidación Anual (Líneas 109 a 115) ---
    $impuestosDeterminados = $igcPagar31 + $idpcPagar + $iu114;
    $otrosCargos = $cot900 + $prestamo1796;
    $deducciones = $ppm36 + $credCap82 + $ret198 + $remanenteDevolucion116 + $cred58 + $ppm1645;
    $resultado305 = ($impuestosDeterminados + $otrosCargos) - $deducciones;

    $saldoFavor85 = $resultado305 < 0 ? abs($resultado305) : 0;
    $puestoSocios86 = ($incluyeEmpresa && $regimen !== '14D8' && $saldoFavor85 > 0) ? ($manuales['86'] ?? 0) : 0;
    if ($puestoSocios86 > $saldoFavor85) {
        $bloqueos[] = 'El saldo puesto a disposición de los socios (código 86) no puede superar el saldo a favor ($' . number_format($saldoFavor85, 0, ',', '.') . ').';
    }
    $devolucion87 = max(0, $saldoFavor85 - $puestoSocios86);

    $adeudado90 = $resultado305 > 0 ? $resultado305 : 0;
    $reajuste39 = $adeudado90 > 0 ? ($manuales['39'] ?? 0) : 0;
    $totalPagar91 = $adeudado90 + $reajuste39;

    $lineas[] = ctrib_f22_linea('109', '305', 'RESULTADO LIQUIDACIÓN ANUAL IMPUESTO A LA RENTA', 'Resultado de la declaración', 'IGUAL', 'CALCULADO', $resultado305, 'CALCULADO', $siiCod);
    $lineas[] = ctrib_f22_linea('110', '85', 'Saldo a favor', 'Resultado de la declaración', 'POSITIVO', 'CALCULADO', $saldoFavor85, 'CALCULADO', $siiCod);
    if ($incluyeEmpresa && $regimen !== '14D8') {
        $lineas[] = ctrib_f22_linea('111', '86', 'Menos: saldo puesto a disposición de los socios (DJ 1837 / Cert. 18)', 'Resultado de la declaración', 'NEGATIVO', 'MANUAL', $puestoSocios86, 'MANUAL', $siiCod);
    }
    $lineas[] = ctrib_f22_linea('112', '87', 'Monto devolución solicitada', 'Resultado de la declaración', 'IGUAL', 'CALCULADO', $devolucion87, 'CALCULADO', $siiCod);
    $lineas[] = ctrib_f22_linea('113', '90', 'Impuesto adeudado', 'Resultado de la declaración', 'POSITIVO', 'CALCULADO', $adeudado90, 'CALCULADO', $siiCod);
    $lineas[] = ctrib_f22_linea('114', '39', 'Reajuste art. 72 LIR (porcentaje informado por SII en abril)', 'Resultado de la declaración', 'POSITIVO', 'MANUAL', $reajuste39, 'MANUAL', $siiCod);
    $lineas[] = ctrib_f22_linea('115', '91', 'TOTAL A PAGAR (códigos 90 + 39)', 'Resultado de la declaración', 'IGUAL', 'CALCULADO', $totalPagar91, 'CALCULADO', $siiCod);

    $montos = array_column($lineas, 'monto', 'codigo');
    $estado = ctrib_estado_recuadro($pdo, $empresa, $anio, 'F22');
    $finalizado = $estado['estado'] === 'FINALIZADO';

    return [
        'recuadro' => 'F22',
        'anio' => $anio,
        'anioComercial' => $anioComercial,
        'regimen' => $regimen,
        'tipoContribuyente' => $tipo,
        'incluyeIgc' => $incluyeIgc,
        'esEmpresarioIndividual' => $esEI,
        'negocioEI' => $negocioEI,
        'igcDetalle' => $igcDetalle,
        'participaciones' => $participaciones,
        'lineas' => $lineas,
        'resumen' => [
            'impuestosDeterminados' => $impuestosDeterminados,
            'otrosCargos' => $otrosCargos,
            'deducciones' => $deducciones,
            'resultado305' => $resultado305,
            'devolucion87' => $devolucion87,
            'totalPagar91' => $totalPagar91,
        ],
        'sii' => ['disponible' => $sii['disponible'], 'folio' => $sii['folio'], 'estado' => $sii['estado']],
        'estado' => $estado['estado'],
        'finalizado' => $estado['finalizado'],
        'usuario' => $estado['usuario'],
        'desactualizado' => ctrib_desactualizado($estado, $montos),
        'bloqueos' => $bloqueos,
        'avisos' => $avisos,
        'puedeFinalizar' => $bloqueos === [] && !$finalizado,
    ];
}

function ctrib_f22_guardar_valor(PDO $pdo, int $empresa, int $anio, string $codigo, mixed $monto, string $usuario): array
{
    if (is_numeric($monto) && (int) $monto < 0) {
        throw new CtribError('El monto no puede ser negativo: el signo lo aplica el formulario.');
    }
    ctrib_guardar_valor_manual($pdo, $empresa, $anio, 'F22', $codigo, $monto, $usuario, CTRIB_F22_MANUALES);
    return ctrib_f22($pdo, $empresa, $anio);
}

function ctrib_f22_finalizar(PDO $pdo, int $empresa, int $anio, string $usuario): array
{
    $r = ctrib_f22($pdo, $empresa, $anio);
    if ($r['estado'] === 'FINALIZADO') {
        throw new CtribError('El resumen del Formulario 22 ya está finalizado.');
    }
    if ($r['bloqueos'] !== []) {
        throw new CtribError(implode(' ', $r['bloqueos']));
    }
    ctrib_recuadro_finalizar($pdo, $empresa, $anio, 'F22', $r['lineas'], $usuario);
    return ctrib_f22($pdo, $empresa, $anio);
}

function ctrib_f22_reabrir(PDO $pdo, int $empresa, int $anio, string $usuario, string $motivo): array
{
    if (ctrib_estado_recuadro($pdo, $empresa, $anio, 'F22')['estado'] !== 'FINALIZADO') {
        throw new CtribError('El resumen del Formulario 22 no está finalizado.');
    }
    ctrib_recuadro_reabrir($pdo, $empresa, $anio, 'F22', $usuario, $motivo);
    return ctrib_f22($pdo, $empresa, $anio);
}
