<?php
// Documentos imprimibles de Cifrax Tributario (A4). Igual que api/pdf-generator.php, se entregan en HTML con estilos de
// impresión y el navegador los guarda como PDF. Documentos: BALANCE, R4, R6, R17, RLI, R18, R19, CPT, R20/RTRE, R21 y DJ1948.
declare(strict_types=1);

require_once __DIR__ . '/ctrib-cierre.php';

const CTRIB_DOCUMENTOS = [
    'BALANCE' => 'Balance General 8 Columnas',
    'R4' => 'Recuadro N° 4 — Enajenación de acciones y derechos sociales',
    'R6' => 'Recuadro N° 6 — Datos Informativos (Pro Pyme)',
    'R17' => 'Recuadro N° 17 — Base Imponible Pro Pyme',
    'RLI' => 'Informe de Renta Líquida Imponible (Recuadro 17)',
    'R18' => 'Determinación del RAI (R18)',
    'R19' => 'Recuadro N° 19 — CPTS Régimen Pro Pyme',
    'CPT' => 'Capital Propio Tributario Simplificado',
    'R20' => 'Registro Tributario de Rentas Empresariales (RTRE)',
    'RTRE' => 'Registro Tributario de Rentas Empresariales (RTRE)',
    'R21' => 'Recuadro N° 21 — Registro SAC (Pro Pyme)',
    'DJ1948' => 'DJ 1948 — Retiros y Dividendos (Certificado 70)',
];

function ctrib_h(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function ctrib_pesos(int|float $n): string
{
    return '$' . number_format((float) $n, 0, ',', '.');
}

function ctrib_empresa_ficha(PDO $pdo, int $empresa): array
{
    $st = $pdo->prepare('SELECT razon_social, datos_json FROM empresas WHERE id = ?');
    $st->execute([$empresa]);
    $f = $st->fetch() ?: [];
    $datos = json_decode((string) ($f['datos_json'] ?? ''), true) ?: [];
    return ['razon_social' => (string) ($f['razon_social'] ?? ''), 'rut' => (string) ($datos['rut'] ?? '')];
}

/** Tabla HTML simple: $columnas = [[título, alineación]], $filas = listas de celdas ya escapadas. */
function ctrib_pdf_tabla(array $columnas, array $filas, array $clasesFila = []): string
{
    $html = '<table><thead><tr>';
    foreach ($columnas as [$titulo, $alin]) {
        $html .= '<th class="' . $alin . '">' . ctrib_h($titulo) . '</th>';
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

function ctrib_pdf_lineas(array $lineas, bool $conCuentas = false, bool $conSeccion = false): string
{
    $filas = [];
    $clases = [];
    $seccion = null;
    foreach ($lineas as $l) {
        if ($conSeccion) {
            $s = $l['seccion'] ?? null;
            $s = $s ?: 'Cálculos';
            if ($s !== $seccion) {
                $filas[] = ['<strong>' . ctrib_h($s) . '</strong>', '', ''];
                $clases[count($filas) - 1] = 'seccion';
                $seccion = $s;
            }
        }
        $glosa = ctrib_h($l['glosa']);
        if ($conCuentas && !empty($l['cuentas'])) {
            $glosa .= '<div class="detalle">' . implode('<br>', array_map(fn($c) => ctrib_h($c['cuenta'] . ' ' . $c['nombre']) . ' — ' . ctrib_pesos($c['saldo']), $l['cuentas'])) . '</div>';
        }
        $filas[] = [$glosa, ctrib_h($l['codigo']), ctrib_pesos($l['monto'])];
        if (in_array($l['naturaleza'] ?? '', ['RESULTADO'], true) || in_array($l['codigo'], ['1410', '1430', '1729', '1440', '1545', '1546', '1816'], true)) {
            $clases[count($filas) - 1] = 'total';
        }
    }
    return ctrib_pdf_tabla([['Concepto', ''], ['Código', 'num'], ['Monto', 'num']], $filas, $clases);
}

function ctrib_pdf_notas(array $notas): string
{
    if (!$notas) {
        return '';
    }
    return '<div class="notas"><strong>Pendiente de cálculo en esta versión</strong><ul>' . implode('', array_map(fn($n) => '<li>' . ctrib_h($n) . '</li>', $notas)) . '</ul></div>';
}

/** Cuerpo del documento según su código. */
function ctrib_documento_cuerpo(PDO $pdo, int $empresa, int $anio, string $doc): array
{
    switch ($doc) {
        case 'BALANCE':
            $b = ctrib_balance_guardado($pdo, $empresa, $anio);
            if (!$b) {
                return ['estado' => '', 'html' => '<p>Aún no hay balance para este año.</p>'];
            }
            $cols = [['Cod. Cuenta', ''], ['Nom. Cuenta', ''], ['Débitos', 'num'], ['Créditos', 'num'], ['Deudor', 'num'], ['Acreedor', 'num'], ['Activo', 'num'], ['Pasivo', 'num'], ['Pérdidas', 'num'], ['Ganancias', 'num']];
            $claves = ['debitos', 'creditos', 'deudor', 'acreedor', 'activo', 'pasivo', 'perdida', 'ganancia'];
            $filas = [];
            foreach ($b['cuentas'] as $c) {
                $filas[] = array_merge([ctrib_h($c['codigo']), ctrib_h($c['nombre'])], array_map(fn($k) => number_format($c[$k], 0, ',', '.'), $claves));
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
                $filas[] = array_merge(['', '<strong>' . ctrib_h($etiqueta) . '</strong>'], array_map(fn($v) => number_format($v, 0, ',', '.'), $valores));
                $clases[count($filas) - 1] = 'total';
            }
            $origen = ['vouchers' => 'vouchers de contabilidad', 'itributario' => 'captura de iTributario', 'excel' => 'Excel', 'csv' => 'CSV'][$b['origen']] ?? $b['origen'];
            return ['estado' => 'Origen: ' . $origen, 'html' => '<div class="ancho">' . ctrib_pdf_tabla($cols, $filas, $clases) . '</div>', 'horizontal' => true];

        case 'R4':
        case 'R6':
            $r = $doc === 'R4' ? ctrib_r4($pdo, $empresa, $anio) : ctrib_r6($pdo, $empresa, $anio);
            return ['estado' => $r['estado'], 'html' => ctrib_pdf_lineas($r['lineas']) . ctrib_pdf_notas($r['pendientesDeCalculo'])];

        case 'R17':
        case 'RLI':
            $r = ctrib_r17($pdo, $empresa, $anio);
            $html = ctrib_pdf_lineas($r['lineas'], $doc === 'RLI', true);
            if ($doc === 'RLI') {
                $i = $r['incentivoAhorro'];
                $html .= '<h3>Incentivo al ahorro (art. 14 letra E)</h3>' . ctrib_pdf_tabla([['Concepto', ''], ['Monto', 'num']], [
                    ['Base reinvertida', ctrib_pesos($i['baseReinvertida'])],
                    ['Tope 50% de la base reinvertida', ctrib_pesos($i['topeMitad'])],
                    ['Tope 5.000 UF', $i['topeUf'] !== null ? ctrib_pesos($i['topeUf']) : 'Sin UF del año'],
                    ['Monto utilizado (1432)', ctrib_pesos($i['montoUtilizado'])],
                ]);
            }
            return ['estado' => $r['estado'], 'html' => $html . ctrib_pdf_notas($r['pendientesDeCalculo'])];

        case 'R19':
        case 'CPT':
            $r = ctrib_r19($pdo, $empresa, $anio);
            return ['estado' => $r['estado'], 'html' => ctrib_pdf_lineas($r['lineas']) . ctrib_pdf_notas($r['pendientesDeCalculo'])];

        case 'R18':
            $r = ctrib_r20($pdo, $empresa, $anio);
            $f = $r['formulaRai'];
            $filas = [
                ['CPTS positivo final (código 1545 del R19)', '1703', ctrib_pesos($f['cptPositivo'])],
                ['CPTS negativo final (código 1546 del R19)', '1719', ctrib_pesos($f['cptNegativo'])],
                ['Saldo negativo del registro REX', '1492', ctrib_pesos($f['rexNegativoAbs'])],
                ['Remesas, retiros o dividendos repartidos en el ejercicio, históricos', '1704', ctrib_pesos($f['retirosEjercicio'])],
                ['Subtotal', '1720', ctrib_pesos($f['subtotalRai'])],
                ['Saldo positivo del registro REX antes de imputaciones', '1493', ctrib_pesos(0)],
                ['Capital aportado, histórico', '1494', ctrib_pesos($f['capitalAportado'])],
                ['Saldo FUR', '1725', ctrib_pesos($f['saldoFur'])],
                ['Rentas afectas a impuestos (RAI)', '1721', ctrib_pesos($f['rai'])],
            ];
            return ['estado' => $r['estado'] === 'FINALIZADO' ? 'RTRE finalizado' : 'RTRE en borrador',
                'html' => ctrib_pdf_tabla([['Concepto', ''], ['Código', 'num'], ['Monto', 'num']], $filas, [4 => 'total', 8 => 'total'])
                    . ctrib_pdf_notas(['El R18 se arma con la fórmula del RAI del RTRE (4.2); capital aportado, FUR y REX se toman como cero en esta versión.'])];

        case 'R20':
        case 'RTRE':
            $r = ctrib_r20($pdo, $empresa, $anio);
            $f = $r['formulaRai'];
            $html = '<h3>Fórmula del RAI e IDPC del ejercicio</h3>' . ctrib_pdf_tabla([['Concepto', ''], ['Monto', 'num']], [
                ['CPTS positivo (1545)', ctrib_pesos($f['cptPositivo'])], ['CPTS negativo (1546)', ctrib_pesos($f['cptNegativo'])],
                ['Retiros del ejercicio', ctrib_pesos($f['retirosEjercicio'])], ['Subtotal determinado', ctrib_pesos($f['subtotalRai'])],
                ['RAI del ejercicio', ctrib_pesos($f['rai'])], ['IDPC del ejercicio (' . str_replace('.', ',', (string) $r['formulaSac']['tasaIdpc']) . '% sobre el 1440)', ctrib_pesos($r['idpc'])],
            ]);
            $filas = array_map(fn($c) => [ctrib_h($c['fila']), ctrib_h($c['glosa']), ctrib_h($c['columna']), ctrib_pesos($c['monto'])], $r['celdas']);
            $html .= '<h3>Celdas con valor</h3>' . ($filas ? ctrib_pdf_tabla([['Fila', ''], ['Concepto', ''], ['Columna', ''], ['Monto', 'num']], $filas) : '<p>Sin valores.</p>');
            return ['estado' => $r['estado'], 'html' => $html . ctrib_pdf_notas($r['pendientesDeCalculo'])];

        case 'R21':
            $r = ctrib_r21($pdo, $empresa, $anio);
            return ['estado' => $r['estado'], 'html' => ctrib_pdf_lineas(array_map(fn($l) => $l + ['naturaleza' => ''], $r['lineas'])) . ctrib_pdf_notas($r['pendientesDeCalculo'])];

        case 'DJ1948':
            $r = ctrib_dj1948($pdo, $empresa, $anio);
            $c = $r['composicion'];
            $html = '<p>' . ctrib_h($r['paso1']['mensaje']) . '</p>';
            $html .= '<h3>Composición y retiros del año</h3>' . ctrib_pdf_tabla(
                [['RUT', ''], ['Nombre', ''], ['% participación', 'num'], ['Utilidad que le corresponde', 'num'], ['Retirado en el año', 'num']],
                array_map(fn($s) => [ctrib_h($s['rut'] ?: '—'), ctrib_h($s['nombre']), ctrib_h(rtrim(rtrim(number_format($s['participacion'], 4, ',', '.'), '0'), ',')) . '%', ctrib_pesos($s['utilidad']), ctrib_pesos($s['retirado'])], $c['socios'])
            );
            $html .= '<h3>Retiros y certificados</h3>' . ($r['socios'] ? ctrib_pdf_tabla(
                [['RUT', ''], ['Nombre', ''], ['Retiros', 'num'], ['N° de retiros', 'num'], ['Certificado 70', 'num']],
                array_map(fn($s) => [ctrib_h($s['rut'] ?: '—'), ctrib_h($s['nombre']), ctrib_pesos($s['total']), (string) $s['cantidad'], $s['certificado'] ? 'N° ' . $s['certificado'] : '—'], $r['socios'])
            ) : '<p>Sin retiros en el año.</p>');
            return ['estado' => $r['paso1']['listo'] ? 'RTRE finalizado' : 'RTRE en borrador', 'html' => $html . ctrib_pdf_notas($r['pendientesDeCalculo'])];
    }
    throw new CtribError('Documento no válido.');
}

/** Documento HTML completo, listo para imprimir o guardar como PDF. */
function ctrib_documento_html(PDO $pdo, int $empresa, int $anio, string $doc): string
{
    if (!isset(CTRIB_DOCUMENTOS[$doc])) {
        throw new CtribError('Documento no válido.');
    }
    $ficha = ctrib_empresa_ficha($pdo, $empresa);
    $cuerpo = ctrib_documento_cuerpo($pdo, $empresa, $anio, $doc);
    $titulo = CTRIB_DOCUMENTOS[$doc];
    $estado = ['FINALIZADO' => 'Finalizado', 'BORRADOR' => 'Borrador'][$cuerpo['estado']] ?? $cuerpo['estado'];
    $pagina = !empty($cuerpo['horizontal']) ? 'A4 landscape' : 'A4';
    $generado = (new DateTimeImmutable('now', new DateTimeZone('America/Santiago')))->format('d-m-Y H:i');
    return '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . ctrib_h($titulo . ' — ' . $ficha['razon_social'] . ' — AT ' . $anio) . '</title><style>'
        . '@page{size:' . $pagina . ';margin:14mm}'
        . 'body{font-family:"Segoe UI",Arial,sans-serif;font-size:11.5px;color:#111827;margin:0;background:#eef1f6}'
        . '.hoja{background:#fff;max-width:' . (!empty($cuerpo['horizontal']) ? '1120px' : '820px') . ';margin:18px auto;padding:28px 32px;box-shadow:0 2px 12px rgba(0,0,0,.12)}'
        . '.barra{position:sticky;top:0;background:#1e3c72;color:#fff;padding:10px 16px;display:flex;gap:10px;align-items:center;justify-content:space-between}'
        . '.barra button{background:#fff;color:#1e3c72;border:0;border-radius:6px;padding:7px 14px;font:inherit;font-weight:700;cursor:pointer}'
        . '.membrete{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #1e3c72;padding-bottom:10px;margin-bottom:14px}'
        . '.membrete h1{font-size:16px;margin:0 0 4px}.membrete p{margin:2px 0;color:#374151}.marca{font-weight:800;color:#1e3c72;font-size:13px;text-align:right}'
        . 'h2{font-size:14.5px;margin:0 0 4px}h3{font-size:12.5px;margin:16px 0 6px}'
        . 'table{width:100%;border-collapse:collapse;margin-top:6px}th,td{border-bottom:1px solid #d1d5db;padding:4px 6px;text-align:left;vertical-align:top}'
        . 'th{background:#f3f4f6;font-weight:700}.num{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}'
        . 'tr.total td{font-weight:700;background:#f8fafc}tr.seccion td{background:#e8eef9}tr{page-break-inside:avoid}'
        . '.detalle{font-size:10px;color:#4b5563;margin-top:2px}.ancho table{font-size:10.5px}'
        . '.notas{margin-top:14px;font-size:10.5px;color:#4b5563;border-top:1px dashed #d1d5db;padding-top:6px}.notas ul{margin:4px 0 0 18px;padding:0}'
        . '.pie{margin-top:18px;font-size:10px;color:#6b7280;border-top:1px solid #e5e7eb;padding-top:6px}'
        . '@media print{body{background:#fff}.barra{display:none}.hoja{box-shadow:none;margin:0;padding:0;max-width:none}}'
        . '</style></head><body>'
        . '<div class="barra"><span>' . ctrib_h($titulo) . '</span><span><button type="button" onclick="window.print()">Imprimir o guardar como PDF</button> '
        . '<button type="button" onclick="window.close()">Cerrar</button></span></div>'
        . '<div class="hoja"><div class="membrete"><div><h1>' . ctrib_h($ficha['razon_social']) . '</h1>'
        . '<p>RUT ' . ctrib_h($ficha['rut'] ?: '—') . ' · Año tributario ' . $anio . ' · Año comercial ' . ctrib_anio_comercial($anio) . '</p></div>'
        . '<div class="marca">Cifrax Tributario</div></div>'
        . '<h2>' . ctrib_h($titulo) . '</h2>' . ($estado !== '' ? '<p>Estado: <strong>' . ctrib_h($estado) . '</strong></p>' : '')
        . $cuerpo['html']
        . '<div class="pie">Generado el ' . $generado . ' por Cifrax Tributario. Documento de trabajo: no reemplaza el Formulario 22 ni las declaraciones presentadas al SII.</div>'
        . '</div></body></html>';
}
