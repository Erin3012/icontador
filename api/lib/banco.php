<?php
// Módulo Banco: movimientos de la cartola cargados desde la plantilla CSV, conciliación con vouchers y saldo del período.
declare(strict_types=1);

require_once __DIR__ . '/vouchers.php';

const BANCO_ESTADOS = ['pendiente', 'conciliado'];
const BANCO_PLANTILLA = ['Fecha', 'Descripción', 'Monto', 'Referencia'];
// Encabezado normalizado (sin tildes, minúsculas) => campo
const BANCO_COLUMNAS = ['fecha' => 'fecha', 'descripcion' => 'descripcion', 'glosa' => 'descripcion', 'monto' => 'monto', 'referencia' => 'referencia', 'n documento' => 'referencia'];
const BANCO_MAX_FILAS = 5000;
const BANCO_MAX_ERRORES = 50;

function banco_schema(PDO $pdo): void
{
    crearEsquema($pdo);
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $pdo->exec(file_get_contents(dirname(__DIR__) . '/schema/banco.mysql.sql'));
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS extractos (
        id INTEGER PRIMARY KEY AUTOINCREMENT, empresa_id INTEGER NOT NULL DEFAULT 0, fecha TEXT NOT NULL, descripcion TEXT NOT NULL DEFAULT '',
        monto INTEGER NOT NULL DEFAULT 0, referencia TEXT NOT NULL DEFAULT '', estado TEXT NOT NULL DEFAULT 'pendiente', comprobante_id INTEGER NULL,
        creado_en TEXT NOT NULL, actualizado_en TEXT NOT NULL)");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_extractos_empresa_fecha ON extractos (empresa_id, fecha)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_extractos_empresa_estado ON extractos (empresa_id, estado)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_extractos_comprobante ON extractos (comprobante_id)');
}

/** Plantilla vacía con BOM para que Excel muestre las tildes; separador punto y coma, como lo guarda Excel en español. */
function banco_plantilla(): string
{
    return "\u{FEFF}" . implode(';', BANCO_PLANTILLA) . "\r\n";
}

// ---------- Lectura del CSV ----------

function banco_normalizar(string $texto): string
{
    $texto = strtr(mb_strtolower(trim($texto)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', '°' => ' ', 'º' => ' ']);
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $texto));
}

/** Acepta DD-MM-AAAA, DD/MM/AAAA y AAAA-MM-DD. Devuelve AAAA-MM-DD o null. */
function banco_fecha(string $valor): ?string
{
    $valor = trim($valor);
    if (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})$/', $valor, $m)) {
        [$d, $mes, $a] = [(int) $m[1], (int) $m[2], (int) $m[3]];
    } elseif (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $valor, $m)) {
        [$a, $mes, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
    } else {
        return null;
    }
    return $a >= 1900 && checkdate($mes, $d, $a) ? sprintf('%04d-%02d-%02d', $a, $mes, $d) : null;
}

/** Monto en pesos con signo: "-15.000", "15000", "$ 1.234.567", "2500,00". Abonos positivos, cargos negativos. */
function banco_monto(string $valor): ?int
{
    $v = str_replace(['$', ' ', "\u{00A0}"], '', trim($valor));
    $negativo = false;
    if ($v !== '' && ($v[0] === '-' || $v[0] === '+')) {
        $negativo = $v[0] === '-';
        $v = substr($v, 1);
    }
    // Coma decimal con uno o dos decimales; "1,000" se rechaza porque podría ser mil escrito al estilo inglés.
    if (preg_match('/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $v) || preg_match('/^\d+(,\d{1,2})?$/', $v)) {
        [$entero, $decimales] = array_pad(explode(',', str_replace('.', '', $v)), 2, '');
    } elseif (preg_match('/^\d+\.\d{1,2}$/', $v)) {
        [$entero, $decimales] = explode('.', $v);
    } else {
        return null;
    }
    if (trim($decimales, '0') !== '' || strlen($entero) > 15) {
        return null;
    }
    return ($negativo ? -1 : 1) * (int) $entero;
}

/**
 * Lee el CSV de la plantilla. Devuelve ['movimientos' => [...], 'errores' => [...]]; los errores indican la línea del archivo.
 * El separador (; , o tabulador) se detecta en el encabezado y las columnas se buscan por nombre.
 */
function banco_leer_csv(string $texto): array
{
    if (!mb_check_encoding($texto, 'UTF-8')) {
        $texto = mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
    }
    $texto = preg_replace('/^\x{FEFF}/u', '', $texto);
    $primera = strtok($texto, "\r\n") ?: '';
    $separador = ';';
    foreach ([',', "\t"] as $otro) {
        if (substr_count($primera, $otro) > substr_count($primera, $separador)) {
            $separador = $otro;
        }
    }

    $flujo = fopen('php://temp', 'r+');
    fwrite($flujo, $texto);
    rewind($flujo);
    $indices = null;
    $movimientos = [];
    $errores = [];
    $linea = 0;
    while (($celdas = fgetcsv($flujo, 0, $separador, '"', '')) !== false) {
        $linea++;
        if ($celdas === [null] || implode('', array_map('trim', $celdas)) === '') {
            continue;
        }
        if ($indices === null) {
            $indices = [];
            foreach ($celdas as $i => $nombre) {
                $campo = BANCO_COLUMNAS[banco_normalizar((string) $nombre)] ?? null;
                if ($campo !== null && !isset($indices[$campo])) {
                    $indices[$campo] = $i;
                }
            }
            $faltan = array_diff(['fecha', 'descripcion', 'monto'], array_keys($indices));
            if ($faltan) {
                return ['movimientos' => [], 'errores' => ['El archivo no tiene el encabezado de la plantilla (Fecha; Descripción; Monto; Referencia). Falta: ' . implode(', ', $faltan) . '.']];
            }
            continue;
        }
        if (count($movimientos) >= BANCO_MAX_FILAS) {
            $errores[] = 'El archivo tiene más de ' . BANCO_MAX_FILAS . ' movimientos; divídalo en varios archivos.';
            break;
        }
        $celda = fn(string $campo) => isset($indices[$campo]) ? trim((string) ($celdas[$indices[$campo]] ?? '')) : '';
        $problemas = [];
        $fecha = banco_fecha($celda('fecha'));
        if ($fecha === null) {
            $problemas[] = 'fecha inválida "' . mb_substr($celda('fecha'), 0, 20) . '" (use DD-MM-AAAA)';
        }
        $descripcion = $celda('descripcion');
        if ($descripcion === '') {
            $problemas[] = 'falta la descripción';
        }
        $monto = banco_monto($celda('monto'));
        if ($monto === null) {
            $problemas[] = 'monto inválido "' . mb_substr($celda('monto'), 0, 20) . '" (use un número entero, negativo para cargos)';
        } elseif ($monto === 0) {
            $problemas[] = 'el monto no puede ser cero';
        }
        if ($problemas) {
            $errores[] = "Línea $linea: " . implode('; ', $problemas) . '.';
            if (count($errores) >= BANCO_MAX_ERRORES) {
                $errores[] = 'Hay más errores; corrija los anteriores y vuelva a subir el archivo.';
                break;
            }
            continue;
        }
        $movimientos[] = ['fecha' => $fecha, 'descripcion' => mb_substr($descripcion, 0, 255), 'monto' => $monto, 'referencia' => mb_substr($celda('referencia'), 0, 100)];
    }
    fclose($flujo);
    if ($indices === null) {
        $errores[] = 'El archivo está vacío.';
    } elseif (!$movimientos && !$errores) {
        $errores[] = 'El archivo no tiene movimientos; complete la plantilla desde la segunda línea.';
    }
    return ['movimientos' => $movimientos, 'errores' => $errores];
}

// ---------- Movimientos ----------

function banco_ahora(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function banco_clave(array $m): string
{
    return implode("\x1F", [substr((string) $m['fecha'], 0, 10), $m['descripcion'], (int) $m['monto'], $m['referencia']]);
}

/**
 * Guarda los movimientos del CSV. Si el archivo trae un error no se guarda nada.
 * Un movimiento que ya está cargado (misma fecha, descripción, monto y referencia) no se repite al volver a subir la cartola.
 */
function banco_importar(PDO $pdo, string $csv, int $empresa): array
{
    $leido = banco_leer_csv($csv);
    if ($leido['errores']) {
        throw new ErrorValidacion($leido['errores']);
    }
    $movimientos = $leido['movimientos'];
    $fechas = array_column($movimientos, 'fecha');
    $desde = min($fechas);
    $hasta = max($fechas);
    $existentes = [];
    $consulta = $pdo->prepare('SELECT fecha, descripcion, monto, referencia FROM extractos WHERE empresa_id = ? AND fecha BETWEEN ? AND ?');
    $consulta->execute([$empresa, $desde, $hasta]);
    foreach ($consulta as $fila) {
        $clave = banco_clave($fila);
        $existentes[$clave] = ($existentes[$clave] ?? 0) + 1;
    }
    $ahora = banco_ahora();
    $insertar = $pdo->prepare('INSERT INTO extractos (empresa_id, fecha, descripcion, monto, referencia, estado, comprobante_id, creado_en, actualizado_en) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?)');
    $importados = $omitidos = 0;
    $pdo->beginTransaction();
    try {
        foreach ($movimientos as $m) {
            $clave = banco_clave($m);
            if (($existentes[$clave] ?? 0) > 0) {
                $existentes[$clave]--;
                $omitidos++;
                continue;
            }
            $insertar->execute([$empresa, $m['fecha'], $m['descripcion'], $m['monto'], $m['referencia'], 'pendiente', $ahora, $ahora]);
            $importados++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['importados' => $importados, 'omitidos' => $omitidos, 'desde' => $desde, 'hasta' => $hasta];
}

function banco_fecha_filtro(array $filtros, string $campo): ?string
{
    $valor = $filtros[$campo] ?? '';
    if ($valor === '' || $valor === null) {
        return null;
    }
    if (!fechaValida($valor)) {
        throw new ErrorValidacion(['Fecha "' . $campo . '" inválida; use AAAA-MM-DD.']);
    }
    return $valor;
}

/** Condiciones comunes de búsqueda: desde, hasta, texto (q) y monto (sin importar el signo). */
function banco_filtros(array $filtros, string $fecha, array $textos): array
{
    $where = [];
    $params = [];
    $desde = banco_fecha_filtro($filtros, 'desde');
    $hasta = banco_fecha_filtro($filtros, 'hasta');
    if ($desde && $hasta && $desde > $hasta) {
        throw new ErrorValidacion(['La fecha Desde es posterior a Hasta.']);
    }
    if ($desde) {
        $where[] = "$fecha >= ?";
        $params[] = $desde;
    }
    if ($hasta) {
        $where[] = "$fecha <= ?";
        $params[] = $hasta;
    }
    $q = trim((string) ($filtros['q'] ?? ''));
    if ($q !== '') {
        // '!' como escape: la barra invertida se interpreta distinto en MySQL y en SQLite.
        $patron = '%' . strtr(mb_strtolower($q), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $where[] = '(' . implode(' OR ', array_map(fn($c) => "LOWER($c) LIKE ? ESCAPE '!'", $textos)) . ')';
        array_push($params, ...array_fill(0, count($textos), $patron));
    }
    $monto = null;
    if (trim((string) ($filtros['monto'] ?? '')) !== '') {
        $monto = banco_monto((string) $filtros['monto']);
        if ($monto === null) {
            throw new ErrorValidacion(['El monto a buscar debe ser un número entero.']);
        }
        $monto = abs($monto);
    }
    return [$where, $params, $monto];
}

function banco_extracto(array $f): array
{
    $comprobante = null;
    if ($f['comprobante_id'] !== null && $f['v_numero'] !== null) {
        $comprobante = ['id' => (int) $f['comprobante_id'], 'tipo' => $f['v_tipo'], 'tipoNombre' => TIPOS[$f['v_tipo']] ?? $f['v_tipo'], 'numero' => (int) $f['v_numero'],
            'fecha' => substr((string) $f['v_fecha'], 0, 10), 'glosa' => $f['v_glosa']];
    }
    return ['id' => (int) $f['id'], 'fecha' => substr((string) $f['fecha'], 0, 10), 'descripcion' => $f['descripcion'], 'monto' => (int) $f['monto'],
        'referencia' => $f['referencia'], 'estado' => $f['estado'], 'comprobanteId' => $f['comprobante_id'] === null ? null : (int) $f['comprobante_id'], 'comprobante' => $comprobante];
}

function banco_listar(PDO $pdo, array $filtros, int $empresa): array
{
    [$where, $params, $monto] = banco_filtros($filtros, 'e.fecha', ['e.descripcion', 'e.referencia']);
    array_unshift($where, 'e.empresa_id = ?');
    array_unshift($params, $empresa);
    $estado = $filtros['estado'] ?? '';
    if ($estado !== '' && $estado !== null) {
        if (!in_array($estado, BANCO_ESTADOS, true)) {
            throw new ErrorValidacion(['Estado inválido; use pendiente o conciliado.']);
        }
        $where[] = 'e.estado = ?';
        $params[] = $estado;
    }
    if ($monto !== null) {
        $where[] = '(e.monto = ? OR e.monto = ?)';
        array_push($params, $monto, -$monto);
    }
    $consulta = $pdo->prepare('SELECT e.*, v.tipo AS v_tipo, v.numero AS v_numero, v.fecha AS v_fecha, v.glosa AS v_glosa
        FROM extractos e LEFT JOIN vouchers v ON v.id = e.comprobante_id AND v.empresa_id = e.empresa_id
        WHERE ' . implode(' AND ', $where) . ' ORDER BY e.fecha, e.id');
    $consulta->execute($params);
    return array_map('banco_extracto', $consulta->fetchAll());
}

function banco_obtener(PDO $pdo, int $id, int $empresa): ?array
{
    $consulta = $pdo->prepare('SELECT e.*, v.tipo AS v_tipo, v.numero AS v_numero, v.fecha AS v_fecha, v.glosa AS v_glosa
        FROM extractos e LEFT JOIN vouchers v ON v.id = e.comprobante_id AND v.empresa_id = e.empresa_id WHERE e.id = ? AND e.empresa_id = ?');
    $consulta->execute([$id, $empresa]);
    $fila = $consulta->fetch();
    return $fila ? banco_extracto($fila) : null;
}

/**
 * Vouchers de la empresa que ningún movimiento del banco tiene asociado. El monto es el total del voucher,
 * positivo en los de Ingreso y negativo en los de Egreso, para compararlo con la cartola.
 */
function banco_comprobantes_pendientes(PDO $pdo, array $filtros, int $empresa): array
{
    [$where, $params, $monto] = banco_filtros($filtros, 'v.fecha', ['v.glosa']);
    array_unshift($where, 'v.empresa_id = ?', 'NOT EXISTS (SELECT 1 FROM extractos e WHERE e.comprobante_id = v.id AND e.empresa_id = v.empresa_id)');
    array_unshift($params, $empresa);
    $sql = 'SELECT v.id, v.tipo, v.numero, v.fecha, v.glosa, COALESCE(SUM(l.debe), 0) AS total
        FROM vouchers v LEFT JOIN voucher_lineas l ON l.voucher_id = v.id
        WHERE ' . implode(' AND ', $where) . ' GROUP BY v.id, v.tipo, v.numero, v.fecha, v.glosa';
    if ($monto !== null) {
        // Entero ya validado; como parámetro, SQLite lo compararía como texto contra la suma.
        $sql .= ' HAVING COALESCE(SUM(l.debe), 0) = ' . (int) $monto;
    }
    $consulta = $pdo->prepare($sql . ' ORDER BY v.fecha, v.tipo, v.numero');
    $consulta->execute($params);
    return array_map(fn($v) => ['id' => (int) $v['id'], 'tipo' => $v['tipo'], 'tipoNombre' => TIPOS[$v['tipo']] ?? $v['tipo'], 'numero' => (int) $v['numero'],
        'fecha' => substr((string) $v['fecha'], 0, 10), 'glosa' => $v['glosa'], 'total' => (int) $v['total'],
        'monto' => $v['tipo'] === 'E' ? -(int) $v['total'] : (int) $v['total']], $consulta->fetchAll());
}

/** Marca un movimiento como conciliado, con el voucher que lo respalda o sin voucher (por ejemplo, comisiones ya contabilizadas). */
function banco_conciliar(PDO $pdo, int $id, ?int $comprobante, int $empresa): array
{
    $extracto = banco_obtener($pdo, $id, $empresa);
    if (!$extracto) {
        throw new ErrorValidacion(['El movimiento no existe.']);
    }
    if ($extracto['estado'] === 'conciliado') {
        throw new ErrorValidacion(['El movimiento ya está conciliado.']);
    }
    if ($comprobante !== null && !obtenerVoucher($pdo, $comprobante, $empresa)) {
        throw new ErrorValidacion(['El voucher no existe en esta empresa.']);
    }
    $pdo->prepare("UPDATE extractos SET estado = 'conciliado', comprobante_id = ?, actualizado_en = ? WHERE id = ? AND empresa_id = ?")
        ->execute([$comprobante, banco_ahora(), $id, $empresa]);
    return banco_obtener($pdo, $id, $empresa);
}

function banco_desconciliar(PDO $pdo, int $id, int $empresa): array
{
    if (!banco_obtener($pdo, $id, $empresa)) {
        throw new ErrorValidacion(['El movimiento no existe.']);
    }
    $pdo->prepare("UPDATE extractos SET estado = 'pendiente', comprobante_id = NULL, actualizado_en = ? WHERE id = ? AND empresa_id = ?")
        ->execute([banco_ahora(), $id, $empresa]);
    return banco_obtener($pdo, $id, $empresa);
}

/** Solo se eliminan movimientos pendientes (por ejemplo, una línea cargada por error). */
function banco_eliminar(PDO $pdo, int $id, int $empresa): bool
{
    $consulta = $pdo->prepare("DELETE FROM extractos WHERE id = ? AND empresa_id = ? AND estado = 'pendiente'");
    $consulta->execute([$id, $empresa]);
    if ($consulta->rowCount() === 0 && banco_obtener($pdo, $id, $empresa)) {
        throw new ErrorValidacion(['Desconcilie el movimiento antes de eliminarlo.']);
    }
    return $consulta->rowCount() > 0;
}

/** Saldo inicial (movimientos anteriores a Desde), abonos y cargos del período y saldo final. */
function banco_reporte(PDO $pdo, array $filtros, int $empresa): array
{
    $desde = banco_fecha_filtro($filtros, 'desde');
    $hasta = banco_fecha_filtro($filtros, 'hasta');
    if ($desde && $hasta && $desde > $hasta) {
        throw new ErrorValidacion(['La fecha Desde es posterior a Hasta.']);
    }
    $inicial = 0;
    if ($desde) {
        $consulta = $pdo->prepare('SELECT COALESCE(SUM(monto), 0) FROM extractos WHERE empresa_id = ? AND fecha < ?');
        $consulta->execute([$empresa, $desde]);
        $inicial = (int) $consulta->fetchColumn();
    }
    $movimientos = banco_listar($pdo, ['desde' => $desde, 'hasta' => $hasta], $empresa);
    $abonos = $cargos = $conciliados = 0;
    $saldo = $inicial;
    foreach ($movimientos as &$m) {
        $m['monto'] > 0 ? $abonos += $m['monto'] : $cargos += $m['monto'];
        $conciliados += $m['estado'] === 'conciliado' ? 1 : 0;
        $saldo += $m['monto'];
        $m['saldo'] = $saldo;
    }
    unset($m);
    return ['desde' => $desde, 'hasta' => $hasta, 'saldoInicial' => $inicial, 'abonos' => $abonos, 'cargos' => $cargos, 'saldoFinal' => $saldo,
        'movimientos' => $movimientos, 'conciliados' => $conciliados, 'pendientes' => count($movimientos) - $conciliados];
}
