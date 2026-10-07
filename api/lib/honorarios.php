<?php
declare(strict_types=1);
/* Boletas de honorarios por empresa y período, importadas desde el informe mensual del SII
   (boletas recibidas, o emitidas si el informe trae la columna Receptor). */
require_once __DIR__ . '/empresas.php';

const HON_LIBROS = ['recibidas', 'emitidas'];
const HON_MONTOS = ['bruto', 'retenido', 'pagado'];
// Nombre en la API (JavaScript) => columna
const HON_CAMPOS = ['numero' => 'numero', 'fecha' => 'fecha', 'estado' => 'estado', 'fechaAnulacion' => 'fecha_anulacion', 'rut' => 'rut', 'nombre' => 'nombre',
    'socProf' => 'soc_prof', 'bruto' => 'bruto', 'retenido' => 'retenido', 'pagado' => 'pagado'];
const HON_TEXTOS = ['numero' => 20, 'fecha' => 10, 'estado' => 20, 'fecha_anulacion' => 10, 'rut' => 12, 'nombre' => 255];
const HON_MAX_BOLETAS = 20000;

final class HonorariosError extends RuntimeException {}

function hon_schema(PDO $db): void {
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $db->exec(file_get_contents(dirname(__DIR__) . '/schema/honorarios.mysql.sql'));
        return;
    }
    $db->exec("CREATE TABLE IF NOT EXISTS honorarios_boletas (
        id INTEGER PRIMARY KEY AUTOINCREMENT, empresa_id INTEGER NOT NULL DEFAULT 0, periodo TEXT NOT NULL, libro TEXT NOT NULL, archivo TEXT NOT NULL DEFAULT '',
        numero TEXT NOT NULL DEFAULT '', fecha TEXT NOT NULL DEFAULT '', estado TEXT NOT NULL DEFAULT '', fecha_anulacion TEXT NOT NULL DEFAULT '',
        rut TEXT NOT NULL DEFAULT '', nombre TEXT NOT NULL DEFAULT '', soc_prof INTEGER NOT NULL DEFAULT 0,
        bruto INTEGER NOT NULL DEFAULT 0, retenido INTEGER NOT NULL DEFAULT 0, pagado INTEGER NOT NULL DEFAULT 0, creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_hon_empresa_periodo ON honorarios_boletas (empresa_id, periodo, libro)');
}

function hon_validar_periodo($periodo): string {
    if (!is_string($periodo) || !preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/', $periodo)) throw new HonorariosError('Período inválido; use AAAA-MM.');
    return $periodo;
}
function hon_validar_libro($libro): string {
    if (!in_array($libro, HON_LIBROS, true)) throw new HonorariosError('Libro inválido; use recibidas o emitidas.');
    return $libro;
}
/** Las boletas anuladas se guardan pero no suman (igual que en el informe del SII). */
function hon_anulada(array $b): bool {
    return stripos($b['estado'], 'NUL') !== false;
}

/** Reemplaza las boletas de un libro y período por las recibidas. Devuelve cuántas se guardaron. */
function hon_guardar(PDO $db, array $datos, int $empresa = 0): int {
    $periodo = hon_validar_periodo($datos['period'] ?? null);
    $libro = hon_validar_libro($datos['kind'] ?? null);
    $boletas = $datos['boletas'] ?? null;
    if (!is_array($boletas) || !array_is_list($boletas)) throw new HonorariosError('Faltan las boletas.');
    if (count($boletas) > HON_MAX_BOLETAS) throw new HonorariosError('Demasiadas boletas en un solo archivo.');
    empresa_validar_rut($db, $empresa, $datos['rutEmpresa'] ?? null);
    $archivo = mb_substr(is_string($datos['fileName'] ?? null) ? $datos['fileName'] : '', 0, 255);
    $filas = [];
    foreach ($boletas as $i => $b) {
        if (!is_array($b)) throw new HonorariosError('Boleta ' . ($i + 1) . ' inválida.');
        $fila = [];
        foreach (HON_CAMPOS as $campo => $columna) {
            $valor = $b[$campo] ?? (isset(HON_TEXTOS[$columna]) ? '' : 0);
            if ($columna === 'soc_prof') {
                $fila[$columna] = $valor === true || $valor === 1 ? 1 : 0;
            } elseif (in_array($columna, HON_MONTOS, true)) {
                if (!is_int($valor) && !(is_float($valor) && floor($valor) === $valor)) throw new HonorariosError('Boleta ' . ($i + 1) . ': ' . $campo . ' debe ser un número entero.');
                $fila[$columna] = (int)$valor;
            } else {
                $fila[$columna] = mb_substr(is_scalar($valor) ? trim((string)$valor) : '', 0, HON_TEXTOS[$columna]);
            }
        }
        if ($fila['numero'] === '') throw new HonorariosError('Boleta ' . ($i + 1) . ' sin número.');
        $filas[] = $fila;
    }
    $columnas = array_merge(['empresa_id', 'periodo', 'libro', 'archivo'], array_values(HON_CAMPOS));
    $insert = $db->prepare('INSERT INTO honorarios_boletas (' . implode(', ', $columnas) . ') VALUES (' . implode(', ', array_fill(0, count($columnas), '?')) . ')');
    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM honorarios_boletas WHERE empresa_id = ? AND periodo = ? AND libro = ?')->execute([$empresa, $periodo, $libro]);
        foreach ($filas as $fila) $insert->execute(array_merge([$empresa, $periodo, $libro, $archivo], array_values($fila)));
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return count($filas);
}

function hon_doc(array $fila): array {
    $b = [];
    foreach (HON_CAMPOS as $campo => $columna) {
        $b[$campo] = $columna === 'soc_prof' ? (bool)(int)$fila[$columna] : (in_array($columna, HON_MONTOS, true) ? (int)$fila[$columna] : $fila[$columna]);
    }
    return $b;
}
function hon_totales(array $boletas): array {
    $t = ['boletas' => count($boletas), 'anuladas' => 0, 'bruto' => 0, 'retenido' => 0, 'pagado' => 0];
    foreach ($boletas as $b) {
        if (hon_anulada($b)) { $t['anuladas']++; continue; }
        foreach (HON_MONTOS as $m) $t[$m] += $b[$m];
    }
    return $t;
}

/** Boletas de un período agrupadas por libro, con la misma forma que produce HonorariosImport.parseInforme. */
function hon_leer(PDO $db, string $periodo, int $empresa = 0): array {
    $periodo = hon_validar_periodo($periodo);
    $st = $db->prepare('SELECT * FROM honorarios_boletas WHERE empresa_id = ? AND periodo = ? ORDER BY libro, id');
    $st->execute([$empresa, $periodo]);
    $libros = [];
    foreach ($st as $fila) {
        $libros[$fila['libro']] ??= ['kind' => $fila['libro'], 'period' => $periodo, 'fileName' => $fila['archivo'], 'boletas' => []];
        $libros[$fila['libro']]['boletas'][] = hon_doc($fila);
    }
    foreach ($libros as &$l) $l['totales'] = hon_totales($l['boletas']);
    return array_values($libros);
}

function hon_periodos(PDO $db, int $empresa = 0): array {
    $st = $db->prepare('SELECT periodo, libro, COUNT(*) AS boletas FROM honorarios_boletas WHERE empresa_id = ? GROUP BY periodo, libro ORDER BY periodo DESC, libro');
    $st->execute([$empresa]);
    $out = [];
    foreach ($st as $fila) {
        $out[$fila['periodo']] ??= ['period' => $fila['periodo'], 'recibidas' => 0, 'emitidas' => 0];
        $out[$fila['periodo']][$fila['libro']] = (int)$fila['boletas'];
    }
    return array_values($out);
}

function hon_borrar(PDO $db, string $periodo, int $empresa = 0): int {
    $st = $db->prepare('DELETE FROM honorarios_boletas WHERE empresa_id = ? AND periodo = ?');
    $st->execute([$empresa, hon_validar_periodo($periodo)]);
    return $st->rowCount();
}

/** Libro de Honorarios entre dos fechas (AAAA-MM-DD, opcionales). Las anuladas se listan pero no suman. */
function hon_libro(PDO $db, string $libro = 'recibidas', ?string $desde = null, ?string $hasta = null, int $empresa = 0): array {
    $libro = hon_validar_libro($libro);
    foreach ([$desde, $hasta] as $f) {
        if ($f !== null && $f !== '' && !(preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $f, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]))) throw new HonorariosError('Fecha inválida; use AAAA-MM-DD.');
    }
    if ($desde && $hasta && $desde > $hasta) throw new HonorariosError('La fecha Desde es posterior a Hasta.');
    $sql = 'SELECT * FROM honorarios_boletas WHERE empresa_id = ? AND libro = ?';
    $params = [$empresa, $libro];
    if ($desde) { $sql .= ' AND periodo >= ?'; $params[] = substr($desde, 0, 7); }
    if ($hasta) { $sql .= ' AND periodo <= ?'; $params[] = substr($hasta, 0, 7); }
    $st = $db->prepare($sql . ' ORDER BY periodo, id');
    $st->execute($params);
    $boletas = [];
    foreach ($st as $fila) {
        $iso = hon_fecha_iso($fila['fecha']);
        if ($iso !== null && (($desde && $iso < $desde) || ($hasta && $iso > $hasta))) continue;
        $boletas[] = ['periodo' => $fila['periodo']] + hon_doc($fila);
    }
    return ['libro' => $libro, 'boletas' => $boletas, 'totales' => hon_totales($boletas)];
}

function hon_fecha_iso(string $fecha): ?string {
    if (preg_match('/^(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})$/', $fecha, $m)) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) ? $fecha : null;
}
