<?php
declare(strict_types=1);
/* Persistencia del RCV: documentos de compras y ventas por período. */

const RCV_LIBROS = ['compras', 'ventas'];
const RCV_MONTOS = ['exento', 'neto', 'iva', 'iva_no_rec', 'iva_uso_comun', 'iva_retenido', 'otros', 'total'];
// Nombre en la API (JavaScript) => columna
const RCV_CAMPOS = ['tipo' => 'tipo_doc', 'tipoOperacion' => 'tipo_operacion', 'rut' => 'rut', 'razon' => 'razon_social', 'folio' => 'folio', 'fecha' => 'fecha',
    'exento' => 'exento', 'neto' => 'neto', 'iva' => 'iva', 'ivaNoRec' => 'iva_no_rec', 'ivaUsoComun' => 'iva_uso_comun', 'ivaRetenido' => 'iva_retenido', 'otros' => 'otros', 'total' => 'total'];
const RCV_TEXTOS = ['tipo_operacion' => 60, 'rut' => 12, 'razon_social' => 255, 'folio' => 20, 'fecha' => 10];
const RCV_MAX_DOCUMENTOS = 20000;

final class RcvError extends RuntimeException {}

function rcv_schema(PDO $db): void {
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $db->exec(file_get_contents(dirname(__DIR__) . '/schema/rcv.mysql.sql'));
        return;
    }
    $montos = implode(', ', array_map(fn($c) => "$c INTEGER NOT NULL DEFAULT 0", RCV_MONTOS));
    $db->exec("CREATE TABLE IF NOT EXISTS rcv_documentos (
        id INTEGER PRIMARY KEY AUTOINCREMENT, periodo TEXT NOT NULL, libro TEXT NOT NULL, archivo TEXT NOT NULL DEFAULT '',
        tipo_doc INTEGER NOT NULL, tipo_operacion TEXT NOT NULL DEFAULT '', rut TEXT NOT NULL DEFAULT '', razon_social TEXT NOT NULL DEFAULT '',
        folio TEXT NOT NULL DEFAULT '', fecha TEXT NOT NULL DEFAULT '', $montos, creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_rcv_periodo_libro ON rcv_documentos (periodo, libro)');
}

function rcv_validar_periodo($periodo): string {
    if (!is_string($periodo) || !preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/', $periodo)) throw new RcvError('Período inválido; use AAAA-MM.');
    return $periodo;
}
function rcv_validar_libro($libro): string {
    if (!in_array($libro, RCV_LIBROS, true)) throw new RcvError('Libro inválido; use compras o ventas.');
    return $libro;
}

/** Reemplaza los documentos de un libro y período por los recibidos. Devuelve cuántos se guardaron. */
function rcv_guardar(PDO $db, array $datos): int {
    $periodo = rcv_validar_periodo($datos['period'] ?? null);
    $libro = rcv_validar_libro($datos['kind'] ?? null);
    $docs = $datos['docs'] ?? null;
    if (!is_array($docs) || !array_is_list($docs)) throw new RcvError('Faltan los documentos.');
    if (count($docs) > RCV_MAX_DOCUMENTOS) throw new RcvError('Demasiados documentos en un solo archivo.');
    $archivo = mb_substr(is_string($datos['fileName'] ?? null) ? $datos['fileName'] : '', 0, 255);
    $filas = [];
    foreach ($docs as $i => $doc) {
        if (!is_array($doc)) throw new RcvError('Documento ' . ($i + 1) . ' inválido.');
        $fila = [];
        foreach (RCV_CAMPOS as $campo => $columna) {
            $valor = $doc[$campo] ?? ($columna === 'tipo_doc' ? null : (isset(RCV_TEXTOS[$columna]) ? '' : 0));
            if ($columna === 'tipo_doc' || in_array($columna, RCV_MONTOS, true)) {
                if (!is_int($valor) && !(is_float($valor) && floor($valor) === $valor)) throw new RcvError('Documento ' . ($i + 1) . ': ' . $campo . ' debe ser un número entero.');
                $fila[$columna] = (int)$valor;
            } else {
                $fila[$columna] = mb_substr(is_scalar($valor) ? trim((string)$valor) : '', 0, RCV_TEXTOS[$columna]);
            }
        }
        if ($fila['tipo_doc'] <= 0) throw new RcvError('Documento ' . ($i + 1) . ' sin tipo de documento.');
        $filas[] = $fila;
    }
    $columnas = array_merge(['periodo', 'libro', 'archivo'], array_values(RCV_CAMPOS));
    $insert = $db->prepare('INSERT INTO rcv_documentos (' . implode(', ', $columnas) . ') VALUES (' . implode(', ', array_fill(0, count($columnas), '?')) . ')');
    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM rcv_documentos WHERE periodo = ? AND libro = ?')->execute([$periodo, $libro]);
        foreach ($filas as $fila) $insert->execute(array_merge([$periodo, $libro, $archivo], array_values($fila)));
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return count($filas);
}

/** Documentos de un período agrupados por libro, con la misma forma que produce RcvImport.parseRcv. */
function rcv_leer(PDO $db, string $periodo): array {
    $periodo = rcv_validar_periodo($periodo);
    $st = $db->prepare('SELECT * FROM rcv_documentos WHERE periodo = ? ORDER BY libro, id');
    $st->execute([$periodo]);
    $libros = [];
    foreach ($st as $fila) {
        $libro = $fila['libro'];
        $libros[$libro] ??= ['kind' => $libro, 'period' => $periodo, 'fileName' => $fila['archivo'], 'docs' => []];
        $doc = [];
        foreach (RCV_CAMPOS as $campo => $columna) $doc[$campo] = ($columna === 'tipo_doc' || in_array($columna, RCV_MONTOS, true)) ? (int)$fila[$columna] : $fila[$columna];
        $libros[$libro]['docs'][] = $doc;
    }
    return array_values($libros);
}

function rcv_periodos(PDO $db): array {
    $st = $db->query('SELECT periodo, libro, COUNT(*) AS documentos FROM rcv_documentos GROUP BY periodo, libro ORDER BY periodo DESC, libro');
    $out = [];
    foreach ($st as $fila) {
        $out[$fila['periodo']] ??= ['period' => $fila['periodo'], 'compras' => 0, 'ventas' => 0];
        $out[$fila['periodo']][$fila['libro']] = (int)$fila['documentos'];
    }
    return array_values($out);
}

function rcv_borrar(PDO $db, string $periodo): int {
    $st = $db->prepare('DELETE FROM rcv_documentos WHERE periodo = ?');
    $st->execute([rcv_validar_periodo($periodo)]);
    return $st->rowCount();
}
