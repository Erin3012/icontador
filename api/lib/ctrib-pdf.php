<?php
// Documentos imprimibles de Cifrax Tributario con el formato de los PDF de iTributario (tamaño carta):
// membrete con razón social, RUT, giro y dirección; año tributario, fecha de emisión y sello BORRADOR;
// título numerado del recuadro; tabla con banda naranja "Recuadro N° X", columnas código, monto y signo;
// marca de agua BORRADOR mientras el recuadro no esté finalizado. Como api/pdf-generator.php, se entregan
// en HTML con estilos de impresión y el navegador los guarda como PDF.
declare(strict_types=1);

require_once __DIR__ . '/ctrib-cierre.php';
require_once __DIR__ . '/ctrib-cpt.php';
require_once __DIR__ . '/ctrib-r18.php';

const CTRIB_DOCUMENTOS = [
    'BALANCE' => 'Balance General 8 Columnas',
    'HOMOLOGACION' => 'Homologación de cuentas',
    'RETIROS' => 'Retiros y Dividendos',
    'R4' => 'Recuadro N° 4. Enajenación de acciones, derechos sociales, cuotas de fondos mutuos y/o de inversión',
    'R6' => 'Recuadro N° 6. Datos informativos',
    'R17' => 'Recuadro N° 17. Base imponible régimen Pro Pyme (art. 14 letra D) N° {N} LIR)',
    'RLI' => 'Informe de Renta Líquida Imponible',
    'R18' => 'Recuadro N° 18. Determinación del RAI (art. 14 letra D) N° {N} LIR)',
    'R19' => 'Recuadro N° 19. CPTS régimen Pro Pyme (art. 14 letra D) N° {N} LIR)',
    'CPT' => 'Capital Propio Tributario a partir del Activo',
    'R20' => 'Recuadro N° 20. Registro tributario de rentas empresariales y movimiento de STUT (art. 14 letra D) N° {N} LIR)',
    'RTRE' => 'Registro Tributario de Rentas Empresariales — Hoja de trabajo',
    'R21' => 'Recuadro N° 21. Registro SAC (art. 14 letra D) N° {N} LIR)',
    'DJ1948' => 'DJ 1948 — Retiros y Dividendos (Certificado 70)',
    'R22' => 'Recuadro N° 22. Base imponible régimen de transparencia tributaria (art. 14 letra D) N° 8 LIR)',
    'R23' => 'Recuadro N° 23. CPTS régimen de transparencia tributaria (art. 14 letra D) N° 8 LIR)',
    'DJ1947' => 'Base imponible asignada a los propietarios (datos para la DJ 1947)',
];
// Documentos en hoja horizontal, como en iTributario.
const CTRIB_DOC_HORIZONTAL = ['BALANCE', 'HOMOLOGACION', 'RETIROS', 'R20', 'RTRE', 'R21', 'DJ1947'];
const CTRIB_REGIMEN_TEXTO = [
    '14D3' => 'Pro Pyme General (Art. 14D N°3)', '14D8' => 'Pro Pyme Transparente (Art. 14D N°8)', '14A' => 'Régimen General (Art. 14A)', 'PERSONA' => 'Persona natural (honorarios)', '14G' => 'Fundación o régimen especial (Art. 14 G)',
];
const CTRIB_SIGNOS = ['POSITIVO' => '+', 'NEGATIVO' => '-', 'IGUAL' => '='];
// Subtítulos del R4 (el catálogo no los trae) por el primer código de cada grupo.
const CTRIB_R4_GRUPOS = [
    '1070' => 'Enajenación de acciones', '1079' => 'Enajenación de derechos sociales',
    '1087' => 'Enajenación o rescate de cuotas de fondos mutuos y/o fondos de inversión',
    '1809' => 'Enajenación o rescate de instrumentos según art. 107 LIR', '1814' => 'Determinación del resultado',
];
const CTRIB_R6_GRUPOS = [
    'OPERACIONES_INTERNACIONALES' => 'Operaciones internacionales', 'DATOS_BALANCE' => 'Datos de balance',
    'OTROS_ANTECEDENTES' => 'Otros antecedentes', 'SALDOS' => 'Saldos',
];
// Signo de cada fila de los formularios R20 y R21 (columna final del PDF de iTributario).
const CTRIB_SIGNOS_R20 = ['+', '-', '+/-', '+', '-', '-', '+', '+', '-', '-', '-', '=', '='];
const CTRIB_SIGNOS_R21 = ['+', '-', '+/-', '+', '-', '+', '+', '+', '-', '-', '-', '-', '=', '='];

function ctrib_h(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function ctrib_pesos(int|float $n): string
{
    return ($n < 0 ? '-$' : '$') . number_format(abs((float) $n), 0, ',', '.');
}

/** Monto de las tablas de recuadro: en blanco si es cero, sin signo $ (como iTributario). */
function ctrib_num(int|float $n, bool $ceroEnBlanco = true): string
{
    return $n == 0 && $ceroEnBlanco ? '' : number_format((float) $n, 0, ',', '.');
}

/** RUT con puntos y guion: 78072579-6 → 78.072.579-6. */
function ctrib_rut_formato(string $rut): string
{
    $limpio = strtoupper(preg_replace('/[^0-9kK]/', '', $rut) ?? '');
    if (strlen($limpio) < 2) {
        return $rut;
    }
    return number_format((int) substr($limpio, 0, -1), 0, ',', '.') . '-' . substr($limpio, -1);
}

function ctrib_empresa_ficha(PDO $pdo, int $empresa): array
{
    $st = $pdo->prepare('SELECT razon_social, datos_json FROM empresas WHERE id = ?');
    $st->execute([$empresa]);
    $f = $st->fetch() ?: [];
    $datos = json_decode((string) ($f['datos_json'] ?? ''), true) ?: [];
    $texto = fn(string $k) => trim((string) ($datos[$k] ?? ''));
    $direccion = implode(', ', array_filter([$texto('direccion'), $texto('comuna'), $texto('ciudad')], fn($v) => $v !== ''));
    $regimen = ctrib_empresa_config($pdo, $empresa)['regimen'];
    return [
        'razon_social' => (string) ($f['razon_social'] ?? ''), 'rut' => $texto('rut') !== '' ? ctrib_rut_formato($texto('rut')) : '',
        'giro' => $texto('giro'), 'direccion' => $direccion, 'regimen' => $regimen,
        'regimenTexto' => CTRIB_REGIMEN_TEXTO[$regimen] ?? 'Pro Pyme General (Art. 14D N°3)',
    ];
}

/** Tabla de recuadro como iTributario: banda naranja, concepto, código, monto y signo. Grupos opcionales. */
function ctrib_pdf_recuadro(string $numero, array $lineas, ?callable $grupo = null, string $subtitulo = ''): string
{
    $html = '<table class="rec"><thead><tr><th class="banda" colspan="4">Recuadro N° ' . ctrib_h($numero) . '</th></tr>';
    if ($subtitulo !== '') {
        $html .= '<tr><th></th><th></th><th class="sub">' . ctrib_h($subtitulo) . '</th><th></th></tr>';
    }
    $html .= '</thead><tbody>';
    $actual = null;
    foreach ($lineas as $l) {
        if ($grupo !== null) {
            $g = $grupo($l);
            if ($g !== null && $g !== $actual) {
                $html .= '<tr class="sec"><td colspan="4">' . ctrib_h(mb_strtoupper($g)) . '</td></tr>';
                $actual = $g;
            }
        }
        $glosa = ctrib_h($l['glosa']);
        if (!empty($l['detalle'])) {
            $glosa .= '<div class="detalle">' . $l['detalle'] . '</div>';
        }
        $monto = isset($l['texto']) ? ctrib_h($l['texto']) : ctrib_num((int) $l['monto']);
        $html .= '<tr><td>' . $glosa . '</td><td class="cod">' . ctrib_h($l['codigo']) . '</td><td class="mon">' . $monto
            . '</td><td class="sig">' . ctrib_h(CTRIB_SIGNOS[$l['signo'] ?? ''] ?? ($l['signo'] ?? '')) . '</td></tr>';
    }
    return $html . '</tbody></table>';
}

/** Tabla de reporte (balance, homologación, retiros): $columnas = [[título, clase]], filas con celdas ya escapadas. */
function ctrib_pdf_tabla(array $columnas, array $filas, array $clasesFila = []): string
{
    $html = '<table class="rep"><thead><tr>';
    foreach ($columnas as [$titulo, $clase]) {
        $html .= '<th class="' . $clase . '">' . ctrib_h($titulo) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($filas as $i => $fila) {
        $html .= '<tr' . (isset($clasesFila[$i]) ? ' class="' . $clasesFila[$i] . '"' : '') . '>';
        foreach ($fila as $j => $celda) {
            $html .= '<td class="' . ($columnas[$j][1] ?? '') . '">' . $celda . '</td>';
        }
        $html .= '</tr>';
    }
    return $html . '</tbody></table>';
}

function ctrib_pdf_notas(array $notas): string
{
    if (!$notas) {
        return '';
    }
    return '<div class="notas"><strong>Pendiente de cálculo en esta versión</strong><ul>' . implode('', array_map(fn($n) => '<li>' . ctrib_h($n) . '</li>', $notas)) . '</ul></div>';
}

/** Formularios R20 y R21: cada celda con su código y monto, banda naranja y columna de signo. */
function ctrib_pdf_formulario(string $tipo, array $formulario): string
{
    $banda = '<th class="banda" rowspan="3">Recuadro N° ' . substr($tipo, 1) . '</th>';
    if ($tipo === 'R20') {
        $cab = '<tr>' . $banda . '<th rowspan="3" colspan="2">RAI</th><th colspan="10">REX</th><th rowspan="3" colspan="2">STUT</th><th rowspan="3" class="sig"></th></tr>'
            . '<tr><th colspan="6">Rentas con tributación cumplida</th><th rowspan="2" colspan="2">Rentas exentas</th><th rowspan="2" colspan="2">INR</th></tr>'
            . '<tr><th colspan="2">RAP y diferencia inicial ex art. 14 ter A) LIR</th><th colspan="2">ISFUT/ISIF</th><th colspan="2">Otras</th></tr>';
        $signos = CTRIB_SIGNOS_R20;
    } else {
        $cab = '<tr>' . $banda . '<th colspan="10">Acumulados a contar desde el 01.01.2017</th><th colspan="6">Acumulados hasta el 31.12.2016</th><th rowspan="3" class="sig"></th></tr>'
            . '<tr><th colspan="4">No sujeto a restitución</th><th colspan="4">Sujeto a restitución</th><th rowspan="2" colspan="2">IPE</th>'
            . '<th rowspan="2" colspan="2">Sin dº devolución</th><th rowspan="2" colspan="2">Con dº devolución</th><th rowspan="2" colspan="2">IPE</th></tr>'
            . '<tr><th colspan="2">Sin dº devolución</th><th colspan="2">Con dº devolución</th><th colspan="2">Sin dº devolución</th><th colspan="2">Con dº devolución</th></tr>';
        $signos = CTRIB_SIGNOS_R21;
    }
    $html = '<table class="rec grilla"><thead>' . $cab . '</thead><tbody>';
    foreach ($formulario['filas'] as $i => $fila) {
        $html .= '<tr><td class="glosa">' . ctrib_h($fila['glosa']) . '</td>';
        foreach ($fila['celdas'] as $c) {
            $html .= $c === null ? '<td class="nulo" colspan="2"></td>'
                : '<td class="cod">' . ctrib_h($c['codigo']) . '</td><td class="mon">' . ctrib_num($c['monto']) . '</td>';
        }
        $html .= '<td class="sig">' . ($signos[$i] ?? '') . '</td></tr>';
    }
    return $html . '</tbody></table>';
}

/** Cuerpo del documento: html, estado del recuadro (para la marca BORRADOR) y si lleva título numerado. */
function ctrib_documento_cuerpo(PDO $pdo, int $empresa, int $anio, string $doc, array $ficha): array
{
    switch ($doc) {
        case 'BALANCE':
            $b = ctrib_balance_guardado($pdo, $empresa, $anio);
            if (!$b) {
                return ['html' => '<p>Aún no hay balance para este año.</p>'];
            }
            $cols = [['Cod. Cuenta', ''], ['Nom. Cuenta', ''], ['Débitos', 'num'], ['Créditos', 'num'], ['Deudor', 'num'], ['Acreedor', 'num'], ['Activo', 'num'], ['Pasivo', 'num'], ['Pérdidas', 'num'], ['Ganancias', 'num']];
            $claves = ['debitos', 'creditos', 'deudor', 'acreedor', 'activo', 'pasivo', 'perdida', 'ganancia'];
            $filas = [];
            foreach ($b['cuentas'] as $c) {
                $filas[] = array_merge([ctrib_h($c['codigo']), ctrib_h($c['nombre'])], array_map(fn($k) => ctrib_num($c[$k], false), $claves));
            }
            $t = $b['totales'];
            $a = $b['ajuste'];
            $s = $b['sumasIguales'];
            $pie = [
                ['Totales Iguales', [$t['debitos'], $t['creditos'], $t['deudor'], $t['acreedor'], $t['activo'], $t['pasivo'], $t['perdida'], $t['ganancia']]],
                ['Utilidad/Pérdida del Ejercicio', [0, 0, 0, 0, $a['activo'], $a['pasivo'], $a['perdida'], $a['ganancia']]],
                ['Totales Generales', [$t['debitos'], $t['creditos'], $t['deudor'], $t['acreedor'], $s['activo'], $s['pasivo'], $s['perdida'], $s['ganancia']]],
            ];
            $clases = [];
            foreach ($pie as [$etiqueta, $valores]) {
                $filas[] = array_merge([ctrib_h($etiqueta), ''], array_map(fn($v) => ctrib_num($v, false), $valores));
                $clases[count($filas) - 1] = 'total';
            }
            $origen = ['vouchers' => 'vouchers de contabilidad', 'itributario' => 'captura de iTributario', 'excel' => 'Excel', 'csv' => 'CSV'][$b['origen']] ?? $b['origen'];
            return ['html' => ctrib_pdf_tabla($cols, $filas, $clases) . '<p class="nota-pie">Origen del balance: ' . ctrib_h($origen) . '.</p>', 'centrado' => true];

        case 'HOMOLOGACION':
            $b = ctrib_balance_guardado($pdo, $empresa, $anio);
            if (!$b) {
                return ['html' => '<p>Aún no hay balance para este año.</p>'];
            }
            $asignadas = ctrib_asignaciones($pdo, $empresa, $anio);
            $ajusteDe = [];
            foreach (ctrib_ajustes($pdo, $empresa, $anio) as $a) {
                foreach ($a['cuentas'] as $c) {
                    $ajusteDe[$c['cuenta']] = $a['tipo'] . ' ' . $a['codigo'];
                }
            }
            $filas = [];
            $resultado = 0;
            $configuradas = 0;
            foreach ($b['cuentas'] as $c) {
                $esResultado = in_array($c['tipo'], CTRIB_TIPOS_RESULTADO, true);
                $saldo = $c['deudor'] > 0 ? ctrib_num($c['deudor'], false) . ' D' : ($c['acreedor'] > 0 ? ctrib_num($c['acreedor'], false) . ' A' : '0 D');
                $codigo = $asignadas[$c['codigo']] ?? '';
                if ($esResultado) {
                    $resultado++;
                    $configuradas += $codigo !== '' ? 1 : 0;
                }
                $estado = !$esResultado ? 'No aplica' : ($codigo !== '' ? 'Configurada' : 'Sin configurar');
                $filas[] = [ctrib_h($c['codigo']), ctrib_h($c['nombre']), $esResultado ? 'RESULTADO' : ctrib_h($c['tipo']), $saldo, ctrib_h($codigo), ctrib_h($ajusteDe[$c['codigo']] ?? ''), $estado];
            }
            $avance = $resultado ? (int) floor($configuradas * 100 / $resultado) : 100;
            $resumen = '<p class="resumen"><strong>Régimen:</strong> ' . ctrib_h($ficha['regimenTexto']) . ' — <strong>Avance:</strong> ' . $avance . '% (' . $configuradas . ' de ' . $resultado
                . ' cuentas resueltas) — Configuradas: ' . $configuradas . ', Excluidas: 0, Sin configurar: ' . ($resultado - $configuradas) . '.</p>';
            return ['html' => $resumen . ctrib_pdf_tabla([['Cuenta', 'c'], ['Nombre', ''], ['Tipo', 'c'], ['Saldo', 'num'], ['Código Recuadro 17', 'c'], ['Ajuste CxC / CxP', 'c'], ['Estado', 'c']], $filas)];

        case 'RETIROS':
            $efectivos = ctrib_retiros_del_anio($pdo, $empresa, $anio);
            $factores = ctrib_factores_mes(ctrib_anio_comercial($anio));
            $socios = [];
            foreach ($efectivos['retiros'] as $r) {
                $s = $socios[$r['rut']] ?? ['rut' => $r['rut'], 'nombre' => $r['nombre'], 'meses' => array_fill(1, 12, 0), 'total' => 0, 'reajustado' => 0.0];
                $mes = (int) substr((string) $r['fecha'], 5, 2);
                if ($mes >= 1 && $mes <= 12) {
                    $s['meses'][$mes] += (int) $r['monto'];
                }
                $s['total'] += (int) $r['monto'];
                $s['reajustado'] += (int) $r['monto'] * ($factores[$mes] ?? 1.0);
                $socios[$r['rut']] = $s;
            }
            foreach ($socios as &$s) {
                $s['reajustado'] = (int) round($s['reajustado'], 0, PHP_ROUND_HALF_UP);
            }
            unset($s);
            $total = array_sum(array_column($socios, 'total'));
            $totalReajustado = array_sum(array_column($socios, 'reajustado'));
            $meses = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
            $cols = array_merge([['RUT', 'c'], ['Socio', '']], array_map(fn($m) => [$m, 'num'], $meses), [['Total histórico', 'num'], ['Total reajustado', 'num'], ['%', 'num']]);
            $filas = [];
            foreach ($socios as $s) {
                $filas[] = array_merge([ctrib_h(ctrib_rut_formato($s['rut'])), ctrib_h($s['nombre'])], array_map(fn($v) => ctrib_num($v), array_values($s['meses'])),
                    [ctrib_num($s['total'], false), ctrib_num($s['reajustado'], false), $total ?number_format($s['total'] * 100 / $total, 1, ',', '.') . '%' : '']);
            }
            $porMes = array_fill(1, 12, 0);
            foreach ($socios as $s) {
                foreach ($s['meses'] as $m => $v) {
                    $porMes[$m] += $v;
                }
            }
            $filas[] = array_merge(['<strong>Total</strong>', ''], array_map(fn($v) => ctrib_num($v), array_values($porMes)), [ctrib_num($total, false), ctrib_num($totalReajustado, false), '']);
            $modo = ['sin_retiros' => ' Se declaró que no hubo retiros en el año.', 'totalidad' => ' Régimen con retiro de la totalidad de la base imponible.'][$efectivos['modo']] ?? '';
            return ['html' => '<p class="resumen">Año comercial ' . ctrib_anio_comercial($anio) . '. Montos históricos por mes; el total reajustado aplica el Factor de Actualización de cada mes.' . ctrib_h($modo) . '</p>'
                . ctrib_pdf_tabla($cols, $filas, [count($filas) - 1 => 'total'])
                . ($factores === null ? ctrib_pdf_notas(['No están los factores de actualización del año comercial: el total reajustado se muestra igual al histórico.'])
                    : '<p class="nota-pie">Factores de actualización al 31 de diciembre: ' . implode(' · ', array_map(fn($n, $f) => mb_substr(ucfirst(mb_strtolower(CTRIB_MESES[$n])), 0, 3) . ' ' . str_replace('.', ',', (string) $f), array_keys($factores), $factores)) . '.</p>')];

        case 'R4':
            $r = ctrib_r4($pdo, $empresa, $anio);
            $grupo = null;
            $html = ctrib_pdf_recuadro('4', $r['lineas'], function (array $l) use (&$grupo) {
                return $grupo = CTRIB_R4_GRUPOS[$l['codigo']] ?? $grupo;
            });
            return ['estado' => $r['estado'], 'numero' => '4', 'html' => $html . ctrib_pdf_notas($r['pendientesDeCalculo'])];

        case 'R6':
            $r = ctrib_r6($pdo, $empresa, $anio);
            $html = ctrib_pdf_recuadro('6', $r['lineas'], fn(array $l) => CTRIB_R6_GRUPOS[$l['subBloque'] ?? ''] ?? null);
            return ['estado' => $r['estado'], 'numero' => '6', 'html' => $html . ctrib_pdf_notas($r['pendientesDeCalculo'])];

        case 'R17':
            $r = ctrib_r17($pdo, $empresa, $anio);
            $lineas = array_map(function (array $l) {
                $partes = [];
                $x = $l['existencias'] ?? null;
                if ($x && $x['configurado']) {
                    $partes[] = 'Existencias: final ' . ctrib_pesos($x['saldoFinal']) . ' − inicial ' . ctrib_pesos($x['saldoInicial']) . ' = variación ' . ctrib_pesos($x['variacion']);
                }
                $a = $l['ajuste'] ?? null;
                if ($a && $a['configurado']) {
                    $partes[] = 'Devengado ' . ctrib_pesos($a['devengado']) . ' + saldo inicial ' . $a['tipo'] . ' ' . ctrib_pesos($a['saldoInicial'])
                        . ' − saldo final ' . ctrib_pesos($a['saldoFinal']) . ($a['repartoAnioAnterior'] ? ' · ' . ctrib_pesos($a['repartoAnioAnterior']) . ' al ' . $a['destinoReparto'] : '');
                }
                if ($partes) {
                    $l['detalle'] = ctrib_h(implode(' · ', $partes));
                }
                return $l;
            }, $r['lineas']);
            return ['estado' => $r['estado'], 'numero' => '17', 'html' => ctrib_pdf_recuadro('17', $lineas, null, 'Percibido o pagado') . ctrib_pdf_notas($r['pendientesDeCalculo'])];

        case 'RLI':
            // Informe de RLI como iTributario: resultado del balance ± agregados y deducidos, menos el incentivo (1432),
            // y una observación si no cuadra con el código 1440 del recuadro.
            $rli = ctrib_rli($pdo, $empresa, $anio);
            ['resultado' => $resultado, 'agregados' => $agregados, 'deducidos' => $deducidos, 'determinada' => $determinada,
                'incentivo' => $incentivo, 'final' => $final, 'codigo1440' => $codigo1440] = $rli;
            $lista = fn(array $partidas) => $partidas
                ? '<table class="rli">' . implode('', array_map(fn($p) => '<tr><td>' . ctrib_h($p['glosa']) . '</td><td class="num">' . ctrib_pesos($p['monto']) . '</td></tr>', $partidas)) . '</table>'
                : '<p class="vacio">Sin partidas.</p>';
            $html = '<div class="informe"><h2 class="centro">INFORME DE RENTA LÍQUIDA IMPONIBLE</h2><p class="centro suave">Recuadro 17 — Base Imponible Pro Pyme</p>'
                . '<table class="rli caja"><tr><td>Resultado del Balance</td><td class="num">' . ctrib_pesos($resultado) . '</td></tr></table>'
                . '<h3>AGREGADOS</h3>' . $lista($agregados) . '<h3>DEDUCIDOS</h3>' . $lista($deducidos)
                . '<table class="rli linea"><tr><td>Renta Líquida Imponible Determinada</td><td class="num">' . ctrib_pesos($determinada) . '</td></tr>'
                . '<tr><td>1432 — Incentivo al ahorro según art. 14 letra E) LIR</td><td class="num">' . ctrib_pesos($incentivo) . '</td></tr></table>'
                . '<table class="rli final"><tr><td>RENTA LÍQUIDA IMPONIBLE FINAL</td><td class="num">' . ctrib_pesos($final) . '</td></tr></table>'
                . ($final === $codigo1440
                    ? '<div class="obs ok">Este resultado cuadra con el código 1440 del recuadro (' . ctrib_pesos($codigo1440) . ').</div>'
                    : '<div class="obs">OBSERVACIÓN: este resultado NO CUADRA con el código 1440 del recuadro (' . ctrib_pesos($codigo1440) . '). Diferencia: ' . ctrib_pesos($final - $codigo1440) . '.</div>')
                . '<p class="nota-pie">Informe generado automáticamente a partir del balance importado y la homologación de cuentas vigente para el año tributario ' . $anio . '.</p></div>';
            return ['html' => $html];

        case 'R18':
            $r = ctrib_r20($pdo, $empresa, $anio);
            return ['estado' => $r['estado'], 'numero' => '18',
                'html' => ctrib_pdf_recuadro('18', ctrib_r18_desde_formula($r['formulaRai'])) . ctrib_pdf_notas(['El R18 se arma con la fórmula del RAI del RTRE (4.2).'])];

        case 'R22':
            $r = ctrib_r22($pdo, $empresa, $anio);
            $grupo = fn(array $l) => ['INGRESOS' => 'Ingresos', 'EGRESOS' => 'Egresos', 'RESULTADO' => 'Resultado'][$l['seccion']];
            return ['estado' => $r['estado'], 'numero' => '22', 'html' => ctrib_pdf_recuadro('22', $r['lineas'], $grupo) . ctrib_pdf_notas($r['avisos'])];

        case 'R23':
            $r = ctrib_r23($pdo, $empresa, $anio);
            return ['estado' => $r['estado'], 'numero' => '23', 'html' => ctrib_pdf_recuadro('23', $r['lineas']) . ctrib_pdf_notas($r['avisos'])];

        case 'DJ1947':
            $r = ctrib_r22($pdo, $empresa, $anio);
            $columnas = [['RUT', ''], ['Propietario', ''], ['%', 'num'], ['Base imponible', 'num'], ['Ingreso diferido incluido', 'num'],
                ['Retiros del año', 'num'], ['Crédito IDPC', 'num'], ['Crédito art. 33 bis', 'num']];
            $claves = ['baseImponible', 'ingresoDiferido', 'retiros', 'creditoIdpc', 'credito33bis'];
            $filas = array_map(fn($p) => array_merge([ctrib_h($p['rut']), ctrib_h($p['nombre']), ctrib_h(str_replace('.', ',', (string) $p['participacion']))],
                array_map(fn($k) => ctrib_num((int) $p[$k]), $claves)), $r['propietarios']['filas']);
            $filas[] = array_merge(['', 'Totales', ''], array_map(fn($k) => ctrib_num((int) array_sum(array_column($r['propietarios']['filas'], $k))), $claves));
            return ['estado' => $r['estado'], 'html' => ctrib_pdf_tabla($columnas, $filas, [count($filas) - 1 => 'total'])
                . ctrib_pdf_notas(array_merge($r['propietarios']['avisos'], ['Resumen de apoyo para preparar la declaración jurada: no reemplaza el formulario del SII.']))];

        case 'R19':
            $r = ctrib_r19($pdo, $empresa, $anio);
            return ['estado' => $r['estado'], 'numero' => '19', 'html' => ctrib_pdf_recuadro('19', $r['lineas']) . ctrib_pdf_notas($r['pendientesDeCalculo'])];

        case 'CPT':
            $c = ctrib_cpt($pdo, $empresa, $anio);
            $grupo = function (string $titulo, array $cuentas, string $subtitulo, int $subtotal): string {
                $h = '<tr class="grupo"><td colspan="2">' . $titulo . '</td></tr>';
                $h .= $cuentas ? implode('', array_map(fn($x) => '<tr><td class="sangria">' . ctrib_h($x['cuenta'] . ' ' . $x['nombre']) . '</td><td class="num">' . ctrib_pesos($x['monto']) . '</td></tr>', $cuentas))
                    : '<tr><td class="sangria vacio" colspan="2">Sin cuentas configuradas.</td></tr>';
                return $h . '<tr class="sub"><td>' . $subtitulo . '</td><td class="num">' . ctrib_pesos($subtotal) . '</td></tr>';
            };
            $avisos = implode('', array_map(fn($a) => '<div class="obs aviso">' . ctrib_h($a) . '</div>', $c['avisos']));
            $html = $avisos . '<h3>DETERMINACIÓN DE CAPITAL PROPIO TRIBUTARIO a partir del ACTIVO</h3><table class="cpt">'
                . '<tr class="sub"><td>TOTAL ACTIVO SEGÚN BALANCE</td><td class="num">' . ctrib_pesos($c['totalActivo']) . '</td></tr>'
                . $grupo('(-) VALORES INTO', $c['valoresInto'], 'Subtotal Valores INTO', $c['subtotalInto'])
                . $grupo('(-) AJUSTES TRIBUTARIOS', $c['ajustesTributarios'], 'Subtotal Ajustes Tributarios', $c['subtotalAjustes'])
                . '<tr class="sub resaltado"><td>= TOTAL ACTIVO DEPURADO</td><td class="num">' . ctrib_pesos($c['totalActivoDepurado']) . '</td></tr>'
                . $grupo('PASIVOS EXIGIBLES', $c['pasivosExigibles'], 'TOTAL PASIVOS EXIGIBLE', $c['subtotalPasivos'])
                . '<tr class="final"><td>CAPITAL PROPIO DETERMINADO</td><td class="num">' . ctrib_pesos($c['capitalPropioDeterminado']) . '</td></tr></table>'
                . '<div class="obs nota"><strong>Nota:</strong> comparando con el Capital Propio Tributario del régimen (R19, códigos 1545 − 1546 = ' . ctrib_pesos($c['cptsRegimen']) . '): '
                . ($c['diferencia'] === 0 ? 'coinciden.' : 'diferencia de ' . ctrib_pesos($c['diferencia']) . '.') . '</div>';
            return ['html' => $html];

        case 'R20':
        case 'R21':
            $r = ctrib_r20($pdo, $empresa, $anio);
            $formulario = $doc === 'R20' ? $r['formularioR20'] : $r['formularioR21'];
            return ['estado' => $r['estado'], 'numero' => substr($doc, 1), 'html' => ctrib_pdf_formulario($doc, $formulario)];

        case 'RTRE':
            $r = ctrib_r20($pdo, $empresa, $anio);
            $res = $r['resumen'];
            $tarjeta = fn(string $t, int $v) => '<div class="tarjeta"><small>' . $t . '</small><strong>' . ctrib_pesos($v) . '</strong></div>';
            $grupos = ['RAI', 'REX', 'SAC', 'STUT'];
            $html = '<p class="suave">Régimen ' . ctrib_h(str_replace('Art. ', '', $ficha['regimenTexto'])) . '</p><div class="tarjetas">'
                . $tarjeta('RAI FINAL', $res['raiFinal']) . $tarjeta('REX FINAL', $res['rexFinal']) . $tarjeta('SAC DISPONIBLE', $res['sacDisponible'])
                . $tarjeta('RETIROS DEL EJERCICIO', $r['retirosEjercicio']) . '</div><h3>HOJA DE TRABAJO</h3>'
                . '<table class="rep hoja-trabajo"><thead><tr><th>Movimiento</th>' . implode('', array_map(fn($g) => '<th class="num">' . $g . '</th>', $grupos)) . '</tr></thead><tbody>';
            foreach ($r['secciones']['secciones'] as $s) {
                $html .= '<tr class="seccion"><td>' . $s['bloque'] . '. ' . ctrib_h($s['titulo']) . '</td>'
                    . implode('', array_map(fn($g) => '<td class="num">' . ($s['bloque'] === 4 ? '' : ctrib_pesos($s['total'][$g])) . '</td>', $grupos)) . '</tr>';
                foreach ($s['filas'] as $f) {
                    $html .= '<tr><td class="sangria"><span class="suave">' . ctrib_h($f['fila']) . '</span>&nbsp; ' . ctrib_h($f['glosa']) . '</td>'
                        . implode('', array_map(fn($g) => '<td class="num">' . ($f['grupos'][$g]['presente'] ? ctrib_pesos($f['grupos'][$g]['monto']) : '—') . '</td>', $grupos)) . '</tr>';
                }
                if ($s['bloque'] === 5) {
                    $html .= '<tr class="seccion"><td>Rentas o cantidades disponibles para ser retiradas, remesadas o distribuidas</td>'
                        . implode('', array_map(fn($g) => '<td class="num">' . ctrib_pesos($r['secciones']['disponibles'][$g]) . '</td>', $grupos)) . '</tr>';
                }
            }
            $html .= '<tr class="total"><td>Saldo final — remanente para el ejercicio siguiente</td>'
                . implode('', array_map(fn($g) => '<td class="num">' . ctrib_pesos($r['secciones']['saldoFinal'][$g]) . '</td>', $grupos)) . '</tr></tbody></table>';
            return ['estado' => $r['estado'], 'html' => $html . ctrib_pdf_notas($r['pendientesDeCalculo']), 'estadoTexto' => true];

        case 'DJ1948':
            $r = ctrib_dj1948($pdo, $empresa, $anio);
            $c = $r['composicion'];
            $html = '<p>' . ctrib_h($r['paso1']['mensaje']) . '</p>';
            $html .= '<h3>Composición y retiros del año</h3>' . ctrib_pdf_tabla(
                [['RUT', ''], ['Nombre', ''], ['% participación', 'num'], ['Utilidad que le corresponde', 'num'], ['Retirado en el año', 'num']],
                array_map(fn($s) => [ctrib_h($s['rut'] ? ctrib_rut_formato($s['rut']) : '—'), ctrib_h($s['nombre']), ctrib_h(rtrim(rtrim(number_format($s['participacion'], 4, ',', '.'), '0'), ',')) . '%', ctrib_pesos($s['utilidad']), ctrib_pesos($s['retirado'])], $c['socios'])
            );
            $html .= '<h3>Retiros y certificados</h3>' . ($r['socios'] ? ctrib_pdf_tabla(
                [['RUT', ''], ['Nombre', ''], ['Retiros', 'num'], ['N° de retiros', 'num'], ['Certificado 70', 'num']],
                array_map(fn($s) => [ctrib_h($s['rut'] ? ctrib_rut_formato($s['rut']) : '—'), ctrib_h($s['nombre']), ctrib_pesos($s['total']), (string) $s['cantidad'], $s['certificado'] ? 'N° ' . $s['certificado'] : '—'], $r['socios'])
            ) : '<p>Sin retiros en el año.</p>');
            return ['estado' => $r['paso1']['listo'] ? 'FINALIZADO' : 'BORRADOR', 'html' => $html . ctrib_pdf_notas($r['pendientesDeCalculo'])];
    }
    throw new CtribError('Documento no válido.');
}

// Documentos que se imprimen como "Informe SII" en hojas foliadas (como iTributario).
const CTRIB_DOC_INFORME_SII = ['BALANCE', 'RTRE', 'RLI', 'R19'];

/** Valida las opciones del Informe SII: folio desde y hasta (hasta 999 hojas) y orientación H o V. */
function ctrib_opciones_folio(string $doc, mixed $desde, mixed $hasta, mixed $orientacion): array
{
    if (!in_array($doc, CTRIB_DOC_INFORME_SII, true)) {
        throw new CtribError('Este documento no tiene Informe SII foliado.');
    }
    $d = filter_var($desde, FILTER_VALIDATE_INT);
    $h = filter_var($hasta, FILTER_VALIDATE_INT);
    if ($d === false || $h === false || $d < 1 || $h < $d) {
        throw new CtribError('Indique folio desde y folio hasta (enteros, hasta mayor o igual que desde).');
    }
    if ($h - $d > 998) {
        throw new CtribError('El rango de folios no puede superar 999 hojas.');
    }
    $o = strtoupper((string) ($orientacion ?: 'H'));
    if (!in_array($o, ['H', 'V'], true)) {
        throw new CtribError('Elija impresión horizontal o vertical.');
    }
    return ['folioDesde' => $d, 'folioHasta' => $h, 'orientacion' => $o];
}

/** Documento HTML completo, listo para imprimir o guardar como PDF. Con $folio, Informe SII en hojas foliadas. */
function ctrib_documento_html(PDO $pdo, int $empresa, int $anio, string $doc, ?array $folio = null): string
{
    if (!isset(CTRIB_DOCUMENTOS[$doc])) {
        throw new CtribError('Documento no válido.');
    }
    $ficha = ctrib_empresa_ficha($pdo, $empresa);
    $cuerpo = ctrib_documento_cuerpo($pdo, $empresa, $anio, $doc, $ficha);
    if (isset($cuerpo['estado']) && ctrib_documento_desactualizado($pdo, $empresa, $anio, $doc)) {
        $cuerpo['estado'] = 'BORRADOR';
    }
    $titulo = str_replace('{N}', $ficha['regimen'] === '14D8' ? '8' : '3', CTRIB_DOCUMENTOS[$doc]);
    $horizontal = $folio !== null ? $folio['orientacion'] === 'H' : in_array($doc, CTRIB_DOC_HORIZONTAL, true);
    $borrador = isset($cuerpo['estado']) && $cuerpo['estado'] !== 'FINALIZADO';
    $hoy = (new DateTimeImmutable('now', new DateTimeZone('America/Santiago')))->format('d-m-Y');

    $membrete = '<div class="membrete"><div class="izq"><div class="razon">' . ctrib_h($ficha['razon_social']) . '</div>';
    $datos = [
        '<strong>RUT:</strong> ' . ctrib_h($ficha['rut'] ?: '—'),
        '<strong>Giro:</strong> ' . ctrib_h($ficha['giro'] ?: '—'),
        '<strong>Dirección:</strong> ' . ctrib_h($ficha['direccion'] ?: '—'),
    ];
    $membrete .= $horizontal ? '<p>' . $datos[0] . ' &nbsp;&nbsp; ' . $datos[1] . '</p><p>' . $datos[2] . '</p>' : '<p>' . implode('</p><p>', $datos) . '</p>';
    $membrete .= '</div><div class="der"><p><strong>Año Tributario:</strong> ' . $anio . (!empty($cuerpo['estadoTexto']) ? ' (Año comercial ' . ctrib_anio_comercial($anio) . ')' : '') . '</p>';
    if (!empty($cuerpo['estadoTexto'])) {
        $membrete .= '<p><strong>Estado:</strong> ' . ($borrador ? 'Borrador (cálculo con datos vigentes)' : 'Finalizado') . '</p>';
    }
    $membrete .= '<p><strong>Fecha de emisión:</strong> ' . $hoy . '</p>'
        . ($folio !== null ? '<p><strong>Hojas foliadas:</strong> N° ' . $folio['folioDesde'] . ' a ' . $folio['folioHasta'] . '</p>' : '') . ($borrador ? '<span class="sello">BORRADOR</span>' : '') . '</div></div>';

    if (isset($cuerpo['numero'])) {
        $encabezado = '<div class="titulo-num"><span>' . ctrib_h($cuerpo['numero']) . '.</span><span>' . ctrib_h($titulo) . '</span></div>';
    } elseif ($doc === 'RLI') {
        $encabezado = '';
    } else {
        $encabezado = '<h1 class="titulo' . (!empty($cuerpo['centrado']) ? ' centro' : '') . '">' . ctrib_h($doc === 'RTRE' ? mb_strtoupper($titulo) : $titulo) . '</h1>';
    }

    return '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . ctrib_h($titulo . ' — ' . $ficha['razon_social'] . ' — AT ' . $anio) . '</title><style>'
        . '@page{size:letter' . ($horizontal ? ' landscape' : '') . ';margin:' . ($folio !== null ? '16mm' : '12mm') . ' 14mm'
        . ($folio !== null ? ';@top-right{content:"Folio N° " counter(page);font:700 10px Arial,sans-serif;color:#111827}' : '') . '}'
        . ($folio !== null ? '@page:first{counter-increment:page ' . $folio['folioDesde'] . '}' : '')
        . 'body{font-family:"Noto Sans","Segoe UI",Arial,sans-serif;font-size:10.5px;color:#111827;margin:0;background:#eef1f6}'
        . '.hoja{position:relative;background:#fff;max-width:' . ($horizontal ? '1040px' : '780px') . ';margin:18px auto;padding:30px 34px;box-shadow:0 2px 12px rgba(0,0,0,.12);overflow:hidden}'
        . '.barra{position:sticky;top:0;z-index:5;background:#111827;color:#fff;padding:10px 16px;display:flex;gap:10px;align-items:center;justify-content:space-between;font-size:12px}'
        . '.barra button{background:#fff;color:#111827;border:0;border-radius:6px;padding:7px 14px;font:inherit;font-weight:700;cursor:pointer}'
        . '.agua{position:absolute;top:38%;left:50%;transform:translate(-50%,-50%) rotate(-32deg);font-size:' . ($horizontal ? '150' : '120') . 'px;font-weight:800;letter-spacing:.18em;color:rgba(239,68,68,.09);pointer-events:none;white-space:nowrap;z-index:0}'
        . '.membrete{display:flex;justify-content:space-between;gap:24px;border-bottom:2px solid #374151;padding-bottom:10px;margin-bottom:14px}'
        . '.membrete p{margin:2px 0}.membrete .razon{font-weight:800;font-size:12.5px;text-transform:uppercase;margin-bottom:4px}.membrete .der{text-align:right;white-space:nowrap}'
        . '.sello{display:inline-block;margin-top:6px;border:1.5px solid #dc2626;color:#dc2626;font-weight:800;letter-spacing:.22em;padding:2px 12px;font-size:9.5px}'
        . '.titulo-num{display:flex;gap:42px;font-size:14px;font-weight:800;border-bottom:2px solid #2563eb;padding-bottom:5px;margin:16px 0 12px}'
        . 'h1.titulo{font-size:15px;margin:14px 0 10px;border-bottom:2px solid #2563eb;padding-bottom:5px}h1.centro,.centro{text-align:center}'
        . 'h2{font-size:14px;margin:10px 0 2px}h3{font-size:11px;margin:14px 0 4px}.suave{color:#6b7280}'
        . 'table{width:100%;border-collapse:collapse;position:relative;z-index:1}tr{page-break-inside:avoid}'
        . '.rec{border:1px solid #93a8d6}.rec th,.rec td{border:1px solid #c7d4ee;padding:3px 6px;vertical-align:middle}'
        . '.rec th{font-size:9px;font-weight:700;text-transform:uppercase;text-align:center;background:#fff}.rec th.banda{background:#fde4d4;color:#dc2626;font-size:10.5px;text-transform:none}'
        . '.rec th.sub{font-weight:600}.rec td.cod{width:52px}.rec td.mon{width:96px;text-align:right;font-variant-numeric:tabular-nums}.rec td.sig,.rec th.sig{width:20px;text-align:center;background:#eef3fc}'
        . '.rec tr.sec td{background:#f3f6fc;font-weight:700;font-size:9.5px}.grilla td{font-size:9.5px}.grilla td.glosa{width:170px}.grilla td.cod{width:34px}.grilla td.mon{width:62px}'
        . '.grilla td.nulo{background:#e6eefb}'
        . '.rep th,.rep td{border:1px solid #b9c9ea;padding:3px 6px}.rep th{background:#dbe6f8;font-weight:700}.rep td.num,.rep th.num,.num{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}'
        . '.rep .c{text-align:center}.rep tr.total td{font-weight:800;background:#eef1f6}.rep tr.seccion td{font-weight:800;background:#f1f4f9}'
        . '.sangria{padding-left:16px!important}.detalle{font-size:9px;color:#4b5563;margin-top:2px}.resumen{margin:4px 0 10px}.nota-pie{color:#6b7280;margin-top:12px;font-size:9.5px}'
        . '.tarjetas{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:10px 0}.tarjeta{border:1px solid #d1d5db;border-radius:6px;padding:7px 10px}'
        . '.tarjeta small{display:block;font-size:8.5px;color:#6b7280}.tarjeta strong{font-size:13px}'
        . '.informe .rli{margin:4px 0}.rli td{padding:4px 6px}.rli.caja{border:1px solid #d1d5db}.rli.caja td+td{border-left:1px solid #d1d5db}'
        . '.rli.linea{border-top:1px solid #9ca3af;margin-top:12px}.rli.final{border-top:2px solid #111827;margin-top:4px}.rli.final td{font-weight:800;font-size:12px}'
        . '.vacio{font-style:italic;color:#6b7280;margin:2px 0}'
        . '.obs{border:1px solid #f87171;background:#fef2f2;color:#b91c1c;padding:8px 10px;margin-top:14px;border-radius:4px}.obs.ok{border-color:#86efac;background:#f0fdf4;color:#166534}'
        . '.obs.aviso{border-color:#facc15;background:#fefce8;color:#a16207;margin:0 0 10px}.obs.nota{border-color:#d1d5db;background:#f9fafb;color:#111827}'
        . '.cpt{border:1px solid #d1d5db}.cpt td{padding:5px 8px;border-bottom:1px solid #e5e7eb}.cpt tr.grupo td{font-size:9px;font-weight:700;background:#f9fafb}'
        . '.cpt tr.sub td{font-weight:600}.cpt tr.resaltado td{background:#f1f5f9}.cpt tr.final td{font-weight:800;font-size:12px;background:#e8eefc}'
        . '.notas{margin-top:14px;font-size:9.5px;color:#4b5563;border-top:1px dashed #d1d5db;padding-top:6px;position:relative;z-index:1}.notas ul{margin:4px 0 0 18px;padding:0}'
        . '.pie{margin-top:18px;font-size:9px;color:#6b7280;border-top:1px solid #e5e7eb;padding-top:6px}'
        . '@media print{body{background:#fff}.barra{display:none}.hoja{box-shadow:none;margin:0;padding:0;max-width:none;overflow:visible}.agua{position:fixed}}'
        . '</style></head><body>'
        . '<div class="barra"><span>' . ctrib_h(($folio !== null ? 'Informe SII (hojas foliadas N° ' . $folio['folioDesde'] . ' a ' . $folio['folioHasta'] . ') — ' : '') . $titulo) . '</span><span><button type="button" onclick="window.print()">Imprimir o guardar como PDF</button> '
        . '<button type="button" onclick="window.close()">Cerrar</button></span></div>'
        . '<div class="hoja">' . ($borrador ? '<div class="agua" aria-hidden="true">BORRADOR</div>' : '')
        . $membrete . $encabezado . $cuerpo['html']
        . '<div class="pie">Generado por Cifrax Tributario. Documento de trabajo: no reemplaza el Formulario 22 ni las declaraciones presentadas al SII.</div>'
        . '</div></body></html>';
}
