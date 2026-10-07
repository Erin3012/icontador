<?php
declare(strict_types=1);
/* Persistencia del RCV: documentos de compras y ventas por empresa y período. */
require_once __DIR__ . '/empresas.php';

const RCV_LIBROS = ['compras', 'ventas'];
const RCV_MONTOS = ['exento', 'neto', 'iva', 'iva_no_rec', 'iva_uso_comun', 'iva_retenido', 'otros', 'total', 'neto_activo_fijo', 'iva_activo_fijo', 'imp_sin_credito'];
// Nombre en la API (JavaScript) => columna
const RCV_CAMPOS = ['tipo' => 'tipo_doc', 'tipoOperacion' => 'tipo_operacion', 'rut' => 'rut', 'razon' => 'razon_social', 'folio' => 'folio', 'fecha' => 'fecha',
    'exento' => 'exento', 'neto' => 'neto', 'iva' => 'iva', 'ivaNoRec' => 'iva_no_rec', 'ivaUsoComun' => 'iva_uso_comun', 'ivaRetenido' => 'iva_retenido', 'otros' => 'otros', 'total' => 'total',
    'netoActivoFijo' => 'neto_activo_fijo', 'ivaActivoFijo' => 'iva_activo_fijo', 'impSinCredito' => 'imp_sin_credito', 'ivaNoRecCodigo' => 'cod_iva_no_rec',
    'otroImpCodigo' => 'cod_otro_imp', 'otroImpTasa' => 'tasa_otro_imp', 'fechaRecepcion' => 'fecha_recepcion', 'refTipo' => 'ref_tipo', 'refFolio' => 'ref_folio'];
const RCV_TEXTOS = ['tipo_operacion' => 60, 'rut' => 12, 'razon_social' => 255, 'folio' => 20, 'fecha' => 10,
    'cod_iva_no_rec' => 5, 'cod_otro_imp' => 10, 'tasa_otro_imp' => 10, 'fecha_recepcion' => 19, 'ref_tipo' => 5, 'ref_folio' => 20];
// Columnas agregadas después de la primera versión de la tabla: se crean al vuelo en bases existentes.
const RCV_COLUMNAS_NUEVAS = ['neto_activo_fijo', 'iva_activo_fijo', 'imp_sin_credito', 'cod_iva_no_rec', 'cod_otro_imp', 'tasa_otro_imp', 'fecha_recepcion', 'ref_tipo', 'ref_folio'];
// Archivos del RCV de compras que no forman parte del registro (y no dan crédito fiscal).
const RCV_COMPRAS_FUERA = ['PENDIENTE' => 'pendientes', 'NO_INCLUIR' => 'no incluidos', 'RECLAMADO' => 'reclamados'];
const RCV_MAX_DOCUMENTOS = 20000;
const RCV_NOTAS_CREDITO = [60, 61, 106, 112];

final class RcvError extends RuntimeException {}

function rcv_schema(PDO $db): void {
    $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    if ($mysql) {
        $db->exec(file_get_contents(dirname(__DIR__) . '/schema/rcv.mysql.sql'));
    } else {
        $textos = implode(', ', array_map(fn($c) => "$c TEXT NOT NULL DEFAULT ''", array_intersect(RCV_COLUMNAS_NUEVAS, array_keys(RCV_TEXTOS))));
        $montos = implode(', ', array_map(fn($c) => "$c INTEGER NOT NULL DEFAULT 0", RCV_MONTOS));
        $db->exec("CREATE TABLE IF NOT EXISTS rcv_documentos (
            id INTEGER PRIMARY KEY AUTOINCREMENT, empresa_id INTEGER NOT NULL DEFAULT 0, periodo TEXT NOT NULL, libro TEXT NOT NULL, archivo TEXT NOT NULL DEFAULT '',
            tipo_doc INTEGER NOT NULL, tipo_operacion TEXT NOT NULL DEFAULT '', rut TEXT NOT NULL DEFAULT '', razon_social TEXT NOT NULL DEFAULT '',
            folio TEXT NOT NULL DEFAULT '', fecha TEXT NOT NULL DEFAULT '', $montos, $textos, creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    }
    agregar_empresa_id($db, 'rcv_documentos');
    if (!$mysql) $db->exec('CREATE INDEX IF NOT EXISTS idx_rcv_empresa_periodo ON rcv_documentos (empresa_id, periodo, libro)');
    foreach (RCV_COLUMNAS_NUEVAS as $columna) {
        if (columna_existe($db, 'rcv_documentos', $columna)) continue;
        $tipo = isset(RCV_TEXTOS[$columna]) ? ($mysql ? 'VARCHAR(' . RCV_TEXTOS[$columna] . ") NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''") : ($mysql ? 'BIGINT' : 'INTEGER') . ' NOT NULL DEFAULT 0';
        $db->exec("ALTER TABLE rcv_documentos ADD COLUMN $columna $tipo");
    }
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
function rcv_guardar(PDO $db, array $datos, int $empresa = 0): int {
    $periodo = rcv_validar_periodo($datos['period'] ?? null);
    $libro = rcv_validar_libro($datos['kind'] ?? null);
    $docs = $datos['docs'] ?? null;
    if (!is_array($docs) || !array_is_list($docs)) throw new RcvError('Faltan los documentos.');
    if (count($docs) > RCV_MAX_DOCUMENTOS) throw new RcvError('Demasiados documentos en un solo archivo.');
    $archivo = mb_substr(is_string($datos['fileName'] ?? null) ? $datos['fileName'] : '', 0, 255);
    if ($libro === 'compras' && preg_match('/RCV_COMPRA_(' . implode('|', array_keys(RCV_COMPRAS_FUERA)) . ')/i', $archivo, $m)) {
        throw new RcvError('Este archivo trae los documentos ' . RCV_COMPRAS_FUERA[strtoupper($m[1])] . ' del RCV, que no forman parte del Libro de Compras. Importe el archivo RCV_COMPRA_REGISTRO.');
    }
    empresa_validar_rut($db, $empresa, $datos['rutEmpresa'] ?? null);
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
    $columnas = array_merge(['empresa_id', 'periodo', 'libro', 'archivo'], array_values(RCV_CAMPOS));
    $insert = $db->prepare('INSERT INTO rcv_documentos (' . implode(', ', $columnas) . ') VALUES (' . implode(', ', array_fill(0, count($columnas), '?')) . ')');
    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM rcv_documentos WHERE empresa_id = ? AND periodo = ? AND libro = ?')->execute([$empresa, $periodo, $libro]);
        foreach ($filas as $fila) $insert->execute(array_merge([$empresa, $periodo, $libro, $archivo], array_values($fila)));
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return count($filas);
}

/** Documentos de un período agrupados por libro, con la misma forma que produce RcvImport.parseRcv. */
function rcv_leer(PDO $db, string $periodo, int $empresa = 0): array {
    $periodo = rcv_validar_periodo($periodo);
    $st = $db->prepare('SELECT * FROM rcv_documentos WHERE empresa_id = ? AND periodo = ? ORDER BY libro, id');
    $st->execute([$empresa, $periodo]);
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

function rcv_periodos(PDO $db, int $empresa = 0): array {
    $st = $db->prepare('SELECT periodo, libro, COUNT(*) AS documentos FROM rcv_documentos WHERE empresa_id = ? GROUP BY periodo, libro ORDER BY periodo DESC, libro');
    $st->execute([$empresa]);
    $out = [];
    foreach ($st as $fila) {
        $out[$fila['periodo']] ??= ['period' => $fila['periodo'], 'compras' => 0, 'ventas' => 0];
        $out[$fila['periodo']][$fila['libro']] = (int)$fila['documentos'];
    }
    return array_values($out);
}

function rcv_borrar(PDO $db, string $periodo, int $empresa = 0): int {
    $st = $db->prepare('DELETE FROM rcv_documentos WHERE empresa_id = ? AND periodo = ?');
    $st->execute([$empresa, rcv_validar_periodo($periodo)]);
    return $st->rowCount();
}

/** Fecha del documento en AAAA-MM-DD (el SII la entrega como DD/MM/AAAA). */
function rcv_fecha_iso(string $fecha): ?string {
    if (preg_match('/^(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})$/', $fecha, $m)) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) ? $fecha : null;
}

/** Libro de compras o ventas entre dos fechas (AAAA-MM-DD, opcionales), con notas de crédito restando en los totales. */
function rcv_libro(PDO $db, string $libro, ?string $desde = null, ?string $hasta = null, ?int $tipo = null, int $empresa = 0): array {
    $libro = rcv_validar_libro($libro);
    foreach ([$desde, $hasta] as $f) {
        if ($f !== null && $f !== '' && !(preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $f, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]))) throw new RcvError('Fecha inválida; use AAAA-MM-DD.');
    }
    if ($desde && $hasta && $desde > $hasta) throw new RcvError('La fecha Desde es posterior a Hasta.');
    $sql = 'SELECT * FROM rcv_documentos WHERE empresa_id = ? AND libro = ?';
    $params = [$empresa, $libro];
    if ($desde) { $sql .= ' AND periodo >= ?'; $params[] = substr($desde, 0, 7); }
    if ($hasta) { $sql .= ' AND periodo <= ?'; $params[] = substr($hasta, 0, 7); }
    if ($tipo) { $sql .= ' AND tipo_doc = ?'; $params[] = $tipo; }
    $st = $db->prepare($sql . ' ORDER BY periodo, id');
    $st->execute($params);
    $docs = [];
    $totales = array_fill_keys(array_merge(['documentos'], array_keys(array_intersect(RCV_CAMPOS, RCV_MONTOS))), 0);
    foreach ($st as $fila) {
        $iso = rcv_fecha_iso($fila['fecha']);
        // El período filtra por mes; la fecha del documento afina el rango cuando se puede leer.
        if ($iso !== null && (($desde && $iso < $desde) || ($hasta && $iso > $hasta))) continue;
        $doc = ['periodo' => $fila['periodo']];
        foreach (RCV_CAMPOS as $campo => $columna) $doc[$campo] = ($columna === 'tipo_doc' || in_array($columna, RCV_MONTOS, true)) ? (int)$fila[$columna] : $fila[$columna];
        $doc['signo'] = in_array($doc['tipo'], RCV_NOTAS_CREDITO, true) ? -1 : 1;
        $totales['documentos']++;
        foreach ($totales as $campo => $_) if ($campo !== 'documentos') $totales[$campo] += $doc['signo'] * $doc[$campo];
        $docs[] = $doc;
    }
    return ['libro' => $libro, 'docs' => $docs, 'totales' => $totales];
}
