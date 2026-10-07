<?php
/**
 * Módulo de Empleados: CRUD para gestión de empleados
 * Requiere: icontador_db() de api/db.php
 */
require_once __DIR__ . '/empresas.php';

function empleados_schema(PDO $db): void {
    try {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $sql = file_get_contents(__DIR__ . '/../schema/rrhh.mysql.sql');
            foreach (explode(';', $sql) as $stmt) {
                $s = trim($stmt);
                if (!empty($s) && stripos($s, 'CREATE TABLE IF NOT EXISTS empleados') !== false) {
                    $db->exec($s);
                    break;
                }
            }
            agregar_empresa_id($db, 'empleados');
        } else {
            // SQLite
            $db->exec('CREATE TABLE IF NOT EXISTS empleados (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                empresa_id INTEGER NOT NULL DEFAULT 0,
                rut TEXT NOT NULL,
                nombre TEXT NOT NULL,
                email TEXT,
                telefono TEXT,
                cargo TEXT,
                fecha_ingreso TEXT,
                direccion TEXT,
                banco_cuenta TEXT,
                tipo_contrato TEXT,
                estado TEXT NOT NULL DEFAULT "activo",
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )');
            $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS unique_rut_empresa ON empleados(empresa_id, rut)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_empleados_empresa ON empleados(empresa_id)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_empleados_estado ON empleados(estado)');
            agregar_empresa_id($db, 'empleados');
        }
    } catch (Exception $e) {
        error_log("empleados_schema error: {$e->getMessage()}");
    }
}

/**
 * Crear un nuevo empleado
 */
function empleado_crear(PDO $db, int $empresa, array $datos): int {
    // Validaciones
    if (empty($datos['rut'])) throw new Exception('RUT es requerido');
    if (empty($datos['nombre'])) throw new Exception('Nombre es requerido');

    // Validar que RUT sea único por empresa
    $stmt = $db->prepare('SELECT id FROM empleados WHERE empresa_id = ? AND rut = ?');
    $stmt->execute([$empresa, $datos['rut']]);
    if ($stmt->fetchColumn()) {
        throw new Exception('Ya existe un empleado con este RUT en la empresa');
    }

    // Validar email si se proporciona
    if (!empty($datos['email']) && !filter_var($datos['email'], FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Email inválido');
    }

    $query = <<<SQL
        INSERT INTO empleados (empresa_id, rut, nombre, email, telefono, cargo,
                               fecha_ingreso, direccion, banco_cuenta, tipo_contrato, estado)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    SQL;

    $stmt = $db->prepare($query);
    $stmt->execute([
        $empresa,
        $datos['rut'] ?? '',
        $datos['nombre'] ?? '',
        $datos['email'] ?? null,
        $datos['telefono'] ?? null,
        $datos['cargo'] ?? null,
        $datos['fecha_ingreso'] ?? null,
        $datos['direccion'] ?? null,
        $datos['banco_cuenta'] ?? null,
        $datos['tipo_contrato'] ?? null,
        $datos['estado'] ?? 'activo',
    ]);

    return (int)$db->lastInsertId();
}

/**
 * Obtener un empleado por ID
 */
function empleado_obtener(PDO $db, int $id, int $empresa): ?array {
    $stmt = $db->prepare('SELECT * FROM empleados WHERE id = ? AND empresa_id = ?');
    $stmt->execute([$id, $empresa]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}

/**
 * Obtener un empleado por RUT
 */
function empleado_obtener_por_rut(PDO $db, int $empresa, string $rut): ?array {
    $stmt = $db->prepare('SELECT * FROM empleados WHERE empresa_id = ? AND rut = ?');
    $stmt->execute([$empresa, $rut]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}

/**
 * Listar empleados de una empresa
 */
function empleados_listar(PDO $db, int $empresa, array $filtros = []): array {
    $query = 'SELECT * FROM empleados WHERE empresa_id = ?';
    $params = [$empresa];

    if (!empty($filtros['estado'])) {
        $query .= ' AND estado = ?';
        $params[] = $filtros['estado'];
    }

    if (!empty($filtros['buscar'])) {
        $query .= ' AND (nombre LIKE ? OR rut LIKE ?)';
        $search = '%' . $filtros['buscar'] . '%';
        $params[] = $search;
        $params[] = $search;
    }

    $query .= ' ORDER BY nombre ASC';

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Actualizar un empleado
 */
function empleado_actualizar(PDO $db, int $id, array $datos, int $empresa): bool {
    $campos = [];
    $valores = [];
    $permitidos = ['rut', 'nombre', 'email', 'telefono', 'cargo', 'fecha_ingreso',
                   'direccion', 'banco_cuenta', 'tipo_contrato', 'estado'];

    foreach ($permitidos as $campo) {
        if (isset($datos[$campo])) {
            $campos[] = "$campo = ?";
            $valores[] = $datos[$campo];
        }
    }

    if (empty($campos)) return false;

    // Validaciones
    if (isset($datos['email']) && !empty($datos['email']) &&
        !filter_var($datos['email'], FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Email inválido');
    }

    // Si se actualiza el RUT, validar que sea único en la empresa
    if (isset($datos['rut'])) {
        $stmt = $db->prepare('SELECT id FROM empleados WHERE empresa_id = ? AND rut = ? AND id != ?');
        $stmt->execute([$empresa, $datos['rut'], $id]);
        if ($stmt->fetchColumn()) {
            throw new Exception('Ya existe un empleado con este RUT en la empresa');
        }
    }

    $valores[] = $id;
    $valores[] = $empresa;
    $query = 'UPDATE empleados SET ' . implode(', ', $campos) .
             ' WHERE id = ? AND empresa_id = ?';

    $stmt = $db->prepare($query);
    return $stmt->execute($valores);
}

/**
 * Eliminar un empleado
 */
function empleado_eliminar(PDO $db, int $id, int $empresa): bool {
    $stmt = $db->prepare('DELETE FROM empleados WHERE id = ? AND empresa_id = ?');
    return $stmt->execute([$id, $empresa]);
}

/**
 * Contar empleados activos de una empresa
 */
function empleados_contar(PDO $db, int $empresa, string $estado = 'activo'): int {
    $stmt = $db->prepare('SELECT COUNT(*) FROM empleados WHERE empresa_id = ? AND estado = ?');
    $stmt->execute([$empresa, $estado]);
    return (int)$stmt->fetchColumn();
}
