<?php
declare(strict_types=1);
/* Persistencia de liquidaciones de sueldo calculadas en la calculadora de Remuneraciones. */

const LIQ_MONTOS = ['sueldoBase' => 'sueldo_base', 'gratificacion' => 'gratificacion', 'imponible' => 'imponible', 'totalHaberes' => 'total_haberes',
    'afp' => 'afp', 'salud' => 'salud', 'afc' => 'afc', 'impuesto' => 'impuesto', 'totalDescuentos' => 'total_descuentos', 'liquido' => 'liquido', 'costoEmpresa' => 'costo_empresa'];
const LIQ_MAX_DETALLE = 20000;

final class LiquidacionError extends RuntimeException {}

function liq_schema(PDO $db): void {
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $db->exec(file_get_contents(dirname(__DIR__) . '/schema/liquidaciones.mysql.sql'));
        return;
    }
    $montos = implode(', ', array_map(fn($c) => "$c INTEGER NOT NULL DEFAULT 0", LIQ_MONTOS));
    $db->exec("CREATE TABLE IF NOT EXISTS liquidaciones (
        id INTEGER PRIMARY KEY AUTOINCREMENT, periodo TEXT NOT NULL, trabajador TEXT NOT NULL, $montos,
        detalle TEXT NOT NULL, creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_liquidaciones_periodo ON liquidaciones (periodo)');
}

function liq_validar_periodo($periodo): string {
    if (!is_string($periodo) || !preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/', $periodo)) throw new LiquidacionError('Período inválido; use AAAA-MM.');
    return $periodo;
}

/** Guarda una liquidación y devuelve su id. */
function liq_guardar(PDO $db, array $datos): int {
    $periodo = liq_validar_periodo($datos['periodo'] ?? null);
    $trabajador = is_string($datos['trabajador'] ?? null) ? trim($datos['trabajador']) : '';
    if ($trabajador === '') throw new LiquidacionError('Indica el nombre del trabajador.');
    $fila = ['periodo' => $periodo, 'trabajador' => mb_substr($trabajador, 0, 120)];
    foreach (LIQ_MONTOS as $campo => $columna) {
        $valor = $datos[$campo] ?? null;
        if (!is_int($valor) && !(is_float($valor) && floor($valor) === $valor)) throw new LiquidacionError($campo . ' debe ser un monto entero.');
        if ($valor < 0) throw new LiquidacionError($campo . ' no puede ser negativo.');
        $fila[$columna] = (int)$valor;
    }
    if ($fila['imponible'] > $fila['total_haberes']) throw new LiquidacionError('El imponible no puede superar el total de haberes.');
    if ($fila['afp'] + $fila['salud'] + $fila['afc'] + $fila['impuesto'] > $fila['total_descuentos']) throw new LiquidacionError('Los descuentos legales superan el total de descuentos.');
    if ($fila['total_haberes'] - $fila['total_descuentos'] !== $fila['liquido']) throw new LiquidacionError('El líquido no cuadra con haberes menos descuentos.');
    $detalle = json_encode(is_array($datos['detalle'] ?? null) ? $datos['detalle'] : new stdClass(), JSON_UNESCAPED_UNICODE);
    if (strlen($detalle) > LIQ_MAX_DETALLE) throw new LiquidacionError('El detalle es demasiado grande.');
    $fila['detalle'] = $detalle;
    $columnas = array_keys($fila);
    $st = $db->prepare('INSERT INTO liquidaciones (' . implode(', ', $columnas) . ') VALUES (' . implode(', ', array_map(fn($c) => ":$c", $columnas)) . ')');
    $st->execute($fila);
    return (int)$db->lastInsertId();
}

/** Liquidaciones guardadas, las más recientes primero; opcionalmente de un período. */
function liq_listar(PDO $db, ?string $periodo = null): array {
    $sql = 'SELECT * FROM liquidaciones';
    $params = [];
    if ($periodo !== null) { $sql .= ' WHERE periodo = ?'; $params[] = liq_validar_periodo($periodo); }
    $st = $db->prepare($sql . ' ORDER BY periodo DESC, id DESC LIMIT 500');
    $st->execute($params);
    return array_map('liq_desde_fila', $st->fetchAll());
}

function liq_desde_fila(array $f): array {
    $out = ['id' => (int)$f['id'], 'periodo' => $f['periodo'], 'trabajador' => $f['trabajador']];
    foreach (LIQ_MONTOS as $campo => $columna) $out[$campo] = (int)$f[$columna];
    $out['detalle'] = json_decode((string)$f['detalle'], true) ?: [];
    $out['creadoEn'] = (string)$f['creado_en'];
    return $out;
}

function liq_borrar(PDO $db, $id): int {
    if (!is_numeric($id) || (int)$id <= 0) throw new LiquidacionError('Id inválido.');
    $st = $db->prepare('DELETE FROM liquidaciones WHERE id = ?');
    $st->execute([(int)$id]);
    return $st->rowCount();
}
