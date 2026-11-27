<?php
// Renta Presunta (art. 34 LIR) de Cifrax Tributario: Línea 57 (IDPC 25% por actividad) y Línea 4 / DJ 1943 / Cert. N° 58.
// Reglas: Suplemento Tributario F22 AT 2026 (pp. 27-31, 68-69, 189-193, 377-380).
// Sin ejercicio oficial AT 2026: cálculo base para validación del contador.
declare(strict_types=1);

require_once __DIR__ . '/ctrib-r4-r6.php';

const CTRIB_TASA_RENTA_PRESUNTA_DEFECTO = 25.0;
const CTRIB_UTA_DIC_2025_DEFECTO = 834504;
const CTRIB_MINERIA_BP_DEFECTO = 600; // 6,00% (art. 34 N° 2 letra c) iv LIR, minerales sin Cu/Au/Ag)

const CTRIB_L57_MANUALES = ['1924', '1925', '1927', '1929', '1931'];
const CTRIB_L57_BASES = ['BAGR', 'CAGR', 'BPAS', 'BCAR', 'BMIN', 'PMIN'];

/** Tasa IDPC Renta Presunta (25%, art. 20 inc. 1° LIR) y valor de 1 UTA dic. del año comercial. */
function ctrib_l57_parametros(int $anioComercial): array
{
    $tasa = CTRIB_TASA_RENTA_PRESUNTA_DEFECTO;
    $uta = CTRIB_UTA_DIC_2025_DEFECTO;
    $ruta = __DIR__ . '/ctrib/tasas-' . $anioComercial . '.json';
    if (is_file($ruta)) {
        $datos = json_decode((string) file_get_contents($ruta), true);
        if (isset($datos['tasaRentaPresunta'])) {
            $tasa = (float) $datos['tasaRentaPresunta'];
        }
        if (isset($datos['utaDiciembre'])) {
            $uta = (int) $datos['utaDiciembre'];
        }
    }
    return ['tasa' => $tasa, 'uta' => $uta];
}

/** Calcula una actividad de Renta Presunta: toma manual directo en L57 o porcentaje sobre la base de L57B. */
function ctrib_l57_base_actividad(array $manuales, string $codBase, int $baseIngresada, float $porcentaje, float $tasa): array
{
    $tieneManual = array_key_exists($codBase, $manuales);
    if ($tieneManual) {
        $baseImponible = $manuales[$codBase];
        $origen = 'MANUAL';
    } elseif ($baseIngresada > 0) {
        $baseImponible = (int) round($baseIngresada * ($porcentaje / 100), 0, PHP_ROUND_HALF_UP);
        $origen = 'CALCULADO';
    } else {
        $baseImponible = 0;
        $origen = 'MANUAL';
    }
    $idpc = (int) round(max(0, $baseImponible) * ($tasa / 100), 0, PHP_ROUND_HALF_UP);
    return ['base' => $baseImponible, 'idpc' => $idpc, 'origen' => $origen, 'manual' => $tieneManual];
}

/** Reparto de la renta presunta y crédito IDPC a propietarios para Línea 4 F22, DJ 1943 y Certificado N° 58. */
function ctrib_l57_propietarios(PDO $pdo, int $empresa, int $anio, int $rentaTotal, int $creditoTotal, string $tipo, array $ficha): array
{
    $esEI = $tipo === 'EI';
    $socios = ctrib_socios($pdo, $empresa, $anio);
    $avisos = [];
    if (!$socios && $esEI && ($ficha['rut'] !== '' || $ficha['razon_social'] !== '')) {
        $socios = [[
            'rut' => (string) $ficha['rut'],
            'nombre' => (string) $ficha['razon_social'],
            'participacion' => 100.0,
        ]];
    } elseif (!$socios) {
        $avisos[] = 'Registre los propietarios y su participación en «Propietarios y retiros» para asignar la Línea 4 y DJ 1943.';
    }

    $porcentajes = array_column($socios, 'participacion');
    if ($socios && abs(array_sum($porcentajes) - 100) > 0.01) {
        $avisos[] = 'La participación de los propietarios no suma 100%: corríjala en «Propietarios y retiros».';
    }
    if ($socios && array_filter($socios, fn($s) => ($s['rut'] ?? '') === '')) {
        $avisos[] = 'Falta el RUT de un propietario: es necesario para la DJ 1943 y Certificado N° 58.';
    }

    $retirado = [];
    foreach (ctrib_retiros_del_anio($pdo, $empresa, $anio)['retiros'] as $r) {
        $retirado[$r['rut']] = ($retirado[$r['rut']] ?? 0) + (int) $r['monto'];
    }
    $repRentas = $socios ? ctrib_repartir($rentaTotal, $porcentajes) : [];
    $repCreditos = $socios ? ctrib_repartir($creditoTotal, $porcentajes) : [];
    $filas = [];
    foreach ($socios as $i => $s) {
        $renta = $repRentas[$i] ?? 0;
        $retiros = $retirado[$s['rut'] ?? ''] ?? 0;
        $filas[] = [
            'rut' => (string) ($s['rut'] ?? ''),
            'nombre' => (string) ($s['nombre'] ?? ''),
            'participacion' => (float) ($s['participacion'] ?? 0),
            'rentaPresunta' => $renta,
            'creditoIdpc' => $repCreditos[$i] ?? 0,
            'retiros' => $retiros,
            'excesoRetiros' => max(0, $retiros - $renta),
        ];
    }

    return [
        'letra' => $esEI ? 'a) Propios' : 'b) Por participaciones',
        'codigoRenta' => $esEI ? '1921' : '1923',
        'codigoCredito' => $esEI ? '1920' : '1922',
        'totalRenta108' => $rentaTotal,
        'totalCredito603' => $creditoTotal,
        'requiereDj1943' => !$esEI,
        'requiereCertificado58' => !$esEI && $tipo !== 'EIRL',
        'filas' => $filas,
        'avisos' => $avisos,
    ];
}

/** Cálculo completo de Renta Presunta (F22 Línea 57 y Línea 4) del año. */
function ctrib_l57(PDO $pdo, int $empresa, int $anio): array
{
    $anioComercial = ctrib_anio_comercial($anio);
    $params = ctrib_l57_parametros($anioComercial);
    $tasa = $params['tasa'];
    $uta = $params['uta'];
    $config = ctrib_empresa_config($pdo, $empresa);
    $tipo = $config['tipo'];
    $esEI = $tipo === 'EI';
    $ficha = ctrib_empresa_ficha($pdo, $empresa);

    $bases = ctrib_valores_manuales($pdo, $empresa, $anio, 'L57B');
    $manuales = ctrib_valores_manuales($pdo, $empresa, $anio, 'L57');

    $avaluo = max(0, $bases['BAGR'] ?? 0);
    $contribucionesInput = max(0, $bases['CAGR'] ?? 0);
    $tasacionPasajeros = max(0, $bases['BPAS'] ?? 0);
    $tasacionCarga = max(0, $bases['BCAR'] ?? 0);
    $ventasMineria = max(0, $bases['BMIN'] ?? 0);
    $pctMineriaBp = array_key_exists('PMIN', $bases) ? max(0, $bases['PMIN']) : CTRIB_MINERIA_BP_DEFECTO;
    $pctMineria = $pctMineriaBp / 100;

    $agr = ctrib_l57_base_actividad($manuales, '1924', $avaluo, 10.0, $tasa);
    $pas = ctrib_l57_base_actividad($manuales, '1927', $tasacionPasajeros, 10.0, $tasa);
    $car = ctrib_l57_base_actividad($manuales, '1929', $tasacionCarga, 10.0, $tasa);
    $min = ctrib_l57_base_actividad($manuales, '1931', $ventasMineria, $pctMineria, $tasa);

    $manual1925 = array_key_exists('1925', $manuales);
    if ($manual1925) {
        $c1925 = $manuales['1925'];
        $origen1925 = 'MANUAL';
    } elseif ($contribucionesInput > 0) {
        $c1925 = min($contribucionesInput, $agr['idpc']);
        $origen1925 = 'CALCULADO_CON_TOPE';
    } else {
        $c1925 = 0;
        $origen1925 = 'MANUAL';
    }
    $c1926 = max(0, $agr['idpc'] - $c1925);

    $c187 = $agr['base'] + $pas['base'] + $car['base'] + $min['base'];
    $c188 = $c1925;
    $c189 = $c1926 + $pas['idpc'] + $car['idpc'] + $min['idpc'];

    $lineas = [
        ['codigo' => '1924', 'glosa' => 'a) Renta presunta explotación bienes raíces agrícolas (10% avalúo fiscal)', 'seccion' => 'Agrícola (letra a)', 'signo' => 'POSITIVO', 'naturaleza' => 'MANUAL', 'monto' => $agr['base'], 'origen' => $agr['origen']],
        ['codigo' => '1925', 'glosa' => 'Crédito por impuesto territorial bienes raíces agrícolas (propietario/usufructuario)', 'seccion' => 'Agrícola (letra a)', 'signo' => 'NEGATIVO', 'naturaleza' => 'MANUAL', 'monto' => $c1925, 'origen' => $origen1925],
        ['codigo' => '1926', 'glosa' => 'IDPC a declarar actividad agrícola (25% − código 1925)', 'seccion' => 'Agrícola (letra a)', 'signo' => 'IGUAL', 'naturaleza' => 'CALCULADO', 'monto' => $c1926, 'origen' => 'CALCULADO'],
        ['codigo' => '1927', 'glosa' => 'b) Renta presunta transporte de pasajeros (10% valor corriente en plaza)', 'seccion' => 'Transporte de pasajeros (letra b)', 'signo' => 'POSITIVO', 'naturaleza' => 'MANUAL', 'monto' => $pas['base'], 'origen' => $pas['origen']],
        ['codigo' => '1928', 'glosa' => 'IDPC a declarar transporte de pasajeros (25%)', 'seccion' => 'Transporte de pasajeros (letra b)', 'signo' => 'IGUAL', 'naturaleza' => 'CALCULADO', 'monto' => $pas['idpc'], 'origen' => 'CALCULADO'],
        ['codigo' => '1929', 'glosa' => 'c) Renta presunta transporte de carga ajena (10% valor corriente en plaza)', 'seccion' => 'Transporte de carga (letra c)', 'signo' => 'POSITIVO', 'naturaleza' => 'MANUAL', 'monto' => $car['base'], 'origen' => $car['origen']],
        ['codigo' => '1930', 'glosa' => 'IDPC a declarar transporte de carga ajena (25%)', 'seccion' => 'Transporte de carga (letra c)', 'signo' => 'IGUAL', 'naturaleza' => 'CALCULADO', 'monto' => $car['idpc'], 'origen' => 'CALCULADO'],
        ['codigo' => '1931', 'glosa' => 'd) Renta presunta actividad minera (% SII sobre ventas netas reajustadas)', 'seccion' => 'Actividad minera (letra d)', 'signo' => 'POSITIVO', 'naturaleza' => 'MANUAL', 'monto' => $min['base'], 'origen' => $min['origen']],
        ['codigo' => '1932', 'glosa' => 'IDPC a declarar actividad minera (25%)', 'seccion' => 'Actividad minera (letra d)', 'signo' => 'IGUAL', 'naturaleza' => 'CALCULADO', 'monto' => $min['idpc'], 'origen' => 'CALCULADO'],
        ['codigo' => '187', 'glosa' => 'Base imponible total rentas presuntas (Línea 57)', 'seccion' => 'Totales Línea 57 F22', 'signo' => 'IGUAL', 'naturaleza' => 'CALCULADO', 'monto' => $c187, 'origen' => 'CALCULADO'],
        ['codigo' => '188', 'glosa' => 'Total rebaja al impuesto por impuesto territorial (Línea 57)', 'seccion' => 'Totales Línea 57 F22', 'signo' => 'NEGATIVO', 'naturaleza' => 'CALCULADO', 'monto' => $c188, 'origen' => 'CALCULADO'],
        ['codigo' => '189', 'glosa' => 'Total IDPC sobre rentas presuntas a declarar (Línea 57)', 'seccion' => 'Totales Línea 57 F22', 'signo' => 'IGUAL', 'naturaleza' => 'CALCULADO', 'monto' => $c189, 'origen' => 'CALCULADO'],
    ];

    $bloqueos = [];
    if ($tipo === 'SA') {
        $bloqueos[] = 'Las Sociedades Anónimas (SA) no pueden acogerse al régimen de Renta Presunta (art. 34 LIR: solo EI, EIRL, SP, SpA, Cooperativas o CM de personas naturales).';
    }
    if ($c1925 > $agr['idpc']) {
        $bloqueos[] = 'El crédito por impuesto territorial (1925) no puede superar el IDPC de la actividad agrícola ($' . number_format($agr['idpc'], 0, ',', '.') . ').';
    }

    $avisos = [];
    if (!$manual1925 && $contribucionesInput > $agr['idpc']) {
        $excedente = $contribucionesInput - $agr['idpc'];
        $avisos[] = 'Las contribuciones agrícolas pagadas exceden el IDPC agrícola en $' . number_format($excedente, 0, ',', '.') . ': el excedente no se imputa a transporte ni minería ni es devolvible (art. 20 N° 1 letra a) LIR).';
    }
    if ($esEI && $c187 > 0 && $c187 <= $uta) {
        $avisos[] = 'Empresario individual con renta presunta <= 1 UTA ($' . number_format($uta, 0, ',', '.') . '): si sus rentas líquidas totales de 1ª categoría no superan 1 UTA, está exento de IDPC (art. 40 N° 6 LIR).';
    }

    // Combinamos L57 y L57B en el vector de snapshot para detectar cualquier cambio tras finalizar.
    $snapshotMontos = array_column($lineas, 'monto', 'codigo');
    foreach (CTRIB_L57_BASES as $kBase) {
        $snapshotMontos[$kBase] = (int) ($bases[$kBase] ?? ($kBase === 'PMIN' ? CTRIB_MINERIA_BP_DEFECTO : 0));
    }

    $estado = ctrib_estado_recuadro($pdo, $empresa, $anio, 'L57');
    $finalizado = $estado['estado'] === 'FINALIZADO';

    return [
        'recuadro' => 'L57',
        'anio' => $anio,
        'anioComercial' => $anioComercial,
        'tipoContribuyente' => $tipo,
        'tasaIdpc' => $tasa,
        'uta' => $uta,
        'bases' => [
            'avaluoAgricola' => $avaluo,
            'contribucionesAgricolas' => $contribucionesInput,
            'tasacionPasajeros' => $tasacionPasajeros,
            'tasacionCarga' => $tasacionCarga,
            'ventasMineria' => $ventasMineria,
            'porcentajeMineria' => $pctMineria,
            'porcentajeMineriaBp' => $pctMineriaBp,
        ],
        'lineas' => $lineas,
        'totales' => ['187' => $c187, '188' => $c188, '189' => $c189],
        'propietarios' => ctrib_l57_propietarios($pdo, $empresa, $anio, $c187, $c189, $tipo, $ficha),
        'estado' => $estado['estado'],
        'finalizado' => $estado['finalizado'],
        'usuario' => $estado['usuario'],
        'desactualizado' => ctrib_desactualizado($estado, $snapshotMontos),
        'snapshotMontos' => $snapshotMontos,
        'bloqueos' => $bloqueos,
        'avisos' => $avisos,
        'notaValidacion' => 'Sin ejercicio oficial SII AT 2026 para Renta Presunta: cálculo según Suplemento F22 AT 2026 (pp. 27–31 y 189–191). Por validar con el contador (topes anuales art. 34 LIR, normas de relación, tabla % minería SII y exención 1 UTA art. 40 N° 6).',
        'puedeFinalizar' => $bloqueos === [] && !$finalizado,
    ];
}

/** Guarda o borra un valor manual directo en los códigos de Línea 57 (1924, 1925, 1927, 1929, 1931). */
function ctrib_l57_guardar_valor(PDO $pdo, int $empresa, int $anio, string $codigo, mixed $monto, string $usuario): array
{
    if (is_numeric($monto) && (int) $monto < 0) {
        throw new CtribError('El monto no puede ser negativo.');
    }
    ctrib_guardar_valor_manual($pdo, $empresa, $anio, 'L57', $codigo, $monto, $usuario, CTRIB_L57_MANUALES);
    return ctrib_l57($pdo, $empresa, $anio);
}

/** Guarda una base declarativa (BAGR, CAGR, BPAS, BCAR, BMIN o PMIN en porcentaje). */
function ctrib_l57_guardar_base(PDO $pdo, int $empresa, int $anio, string $campo, mixed $valor, string $usuario): array
{
    if (!in_array($campo, CTRIB_L57_BASES, true)) {
        throw new CtribError("Base no válida: $campo.");
    }
    ctrib_recuadro_no_finalizado($pdo, $empresa, $anio, 'L57');

    if ($valor === null || (is_string($valor) && trim($valor) === '')) {
        ctrib_guardar_valor_manual($pdo, $empresa, $anio, 'L57B', $campo, null, $usuario, CTRIB_L57_BASES);
        return ctrib_l57($pdo, $empresa, $anio);
    }

    if ($campo === 'PMIN') {
        if (!is_numeric($valor) || (float) $valor < 0 || (float) $valor > 100) {
            throw new CtribError('El porcentaje de minería debe estar entre 0 y 100.');
        }
        $montoGuardado = (int) round((float) $valor * 100, 0, PHP_ROUND_HALF_UP);
    } else {
        $esEntero = is_int($valor) || (is_string($valor) && preg_match('/^\d+$/', trim($valor)));
        if (!$esEntero || (int) $valor < 0) {
            throw new CtribError('La base debe ser un número entero mayor o igual a cero.');
        }
        $montoGuardado = (int) $valor;
    }

    ctrib_guardar_valor_manual($pdo, $empresa, $anio, 'L57B', $campo, $montoGuardado, $usuario, CTRIB_L57_BASES);
    return ctrib_l57($pdo, $empresa, $anio);
}

function ctrib_l57_finalizar(PDO $pdo, int $empresa, int $anio, string $usuario): array
{
    $r = ctrib_l57($pdo, $empresa, $anio);
    if ($r['estado'] === 'FINALIZADO') {
        throw new CtribError('Renta Presunta (Línea 57) ya está finalizada.');
    }
    if ($r['bloqueos'] !== []) {
        throw new CtribError(implode(' ', $r['bloqueos']));
    }
    $lineasSnap = [];
    foreach ($r['snapshotMontos'] as $cod => $monto) {
        $lineasSnap[] = ['codigo' => (string) $cod, 'monto' => (int) $monto];
    }
    ctrib_recuadro_finalizar($pdo, $empresa, $anio, 'L57', $lineasSnap, $usuario);
    return ctrib_l57($pdo, $empresa, $anio);
}

function ctrib_l57_reabrir(PDO $pdo, int $empresa, int $anio, string $usuario, string $motivo): array
{
    if (ctrib_estado_recuadro($pdo, $empresa, $anio, 'L57')['estado'] !== 'FINALIZADO') {
        throw new CtribError('Renta Presunta (Línea 57) no está finalizada.');
    }
    ctrib_recuadro_reabrir($pdo, $empresa, $anio, 'L57', $usuario, $motivo);
    return ctrib_l57($pdo, $empresa, $anio);
}
