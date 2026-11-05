<?php
declare(strict_types=1);
/* Gestión de empleados - funciones de persistencia */

const EMP_CAMPOS = ['rut' => 'rut', 'nombre' => 'nombre', 'email' => 'email', 'telefono' => 'telefono',
    'cargo' => 'cargo', 'fecha_ingreso' => 'fecha_ingreso', 'direccion' => 'direccion',
    'banco_cuenta' => 'banco_cuenta', 'tipo_contrato' => 'tipo_contrato', 'estado' => 'estado'];

final class EmpleadoError extends RuntimeException {}

function emp_schema(PDO $db): void {
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $db->exec(file_get_contents(dirname(__DIR__) . '/schema/empleados-create.sql'));
        return;
    }
    $db->exec("CREATE TABLE IF NOT EXISTS empleados (
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
        estado TEXT NOT NULL DEFAULT 'activo',
        creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        actualizado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_emp_rut_empresa ON empleados (empresa_id, rut)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_emp_empresa ON empleados (empresa_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_emp_estado ON empleados (estado)');
}

function emp_validar_rut($rut): string {
    if (!is_string($rut)) throw new EmpleadoError('RUT debe ser texto.');
    $rut = trim($rut);
    if (strlen($rut) === 0) throw new EmpleadoError('RUT es requerido.');
    if (strlen($rut) > 20) throw new EmpleadoError('RUT demasiado largo.');
    return $rut;
}

function emp_validar_nombre($nombre): string {
    if (!is_string($nombre)) throw new EmpleadoError('Nombre debe ser texto.');
    $nombre = trim($nombre);
    if (strlen($nombre) === 0) throw new EmpleadoError('Nombre es requerido.');
    if (strlen($nombre) > 255) throw new EmpleadoError('Nombre demasiado largo.');
    return $nombre;
}

function emp_listar(PDO $db, int $empresa_id): array {
    $st = $db->prepare('SELECT id, rut, nombre, email, telefono, cargo, fecha_ingreso,
                               estado, creado_en FROM empleados
                        WHERE empresa_id = ? ORDER BY nombre');
    $st->execute([$empresa_id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function emp_obtener(PDO $db, int $id): ?array {
    $st = $db->prepare('SELECT * FROM empleados WHERE id = ?');
    $st->execute([$id]);
    $fila = $st->fetch(PDO::FETCH_ASSOC);
    return $fila ?: null;
}

function emp_crear(PDO $db, int $empresa_id, array $datos): int {
    $rut = emp_validar_rut($datos['rut'] ?? null);
    $nombre = emp_validar_nombre($datos['nombre'] ?? null);

    $fila = [
        'empresa_id' => $empresa_id,
        'rut' => $rut,
        'nombre' => $nombre,
        'email' => is_string($datos['email'] ?? null) ? trim($datos['email']) : null,
        'telefono' => is_string($datos['telefono'] ?? null) ? trim($datos['telefono']) : null,
        'cargo' => is_string($datos['cargo'] ?? null) ? trim($datos['cargo']) : null,
        'fecha_ingreso' => is_string($datos['fecha_ingreso'] ?? null) ? trim($datos['fecha_ingreso']) : null,
        'direccion' => is_string($datos['direccion'] ?? null) ? trim($datos['direccion']) : null,
        'banco_cuenta' => is_string($datos['banco_cuenta'] ?? null) ? trim($datos['banco_cuenta']) : null,
        'tipo_contrato' => is_string($datos['tipo_contrato'] ?? null) ? trim($datos['tipo_contrato']) : null,
        'estado' => is_string($datos['estado'] ?? null) ? trim($datos['estado']) : 'activo'
    ];

    $columnas = array_keys($fila);
    $st = $db->prepare('INSERT INTO empleados (' . implode(', ', $columnas) . ')
                        VALUES (' . implode(', ', array_map(fn($c) => ":$c", $columnas)) . ')');
    $st->execute($fila);
    return (int)$db->lastInsertId();
}

function emp_actualizar(PDO $db, int $id, array $datos): void {
    $empleado = emp_obtener($db, $id);
    if (!$empleado) throw new EmpleadoError('Empleado no encontrado.');

    $actualizables = [];
    if (isset($datos['nombre'])) $actualizables['nombre'] = emp_validar_nombre($datos['nombre']);
    if (isset($datos['email'])) $actualizables['email'] = is_string($datos['email']) ? trim($datos['email']) : null;
    if (isset($datos['telefono'])) $actualizables['telefono'] = is_string($datos['telefono']) ? trim($datos['telefono']) : null;
    if (isset($datos['cargo'])) $actualizables['cargo'] = is_string($datos['cargo']) ? trim($datos['cargo']) : null;
    if (isset($datos['estado'])) $actualizables['estado'] = is_string($datos['estado']) ? trim($datos['estado']) : 'activo';

    if (empty($actualizables)) return;

    $actualizables['actualizado_en'] = date('Y-m-d H:i:s');
    $set = implode(', ', array_map(fn($c) => "$c = :$c", array_keys($actualizables)));
    $st = $db->prepare("UPDATE empleados SET $set WHERE id = :id");
    $actualizables['id'] = $id;
    $st->execute($actualizables);
}

function emp_eliminar(PDO $db, int $id): void {
    $st = $db->prepare('DELETE FROM empleados WHERE id = ?');
    $st->execute([$id]);
}
