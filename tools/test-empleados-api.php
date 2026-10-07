<?php
// Prueba de empleados con SQLite en memoria: php tools/test-empleados-api.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/empleados.php';

function check(bool $ok, string $msg): void {
    if (!$ok) {
        fwrite(STDERR, "FALLA: $msg\n");
        exit(1);
    }
    echo "✓ $msg\n";
}

function rechaza(callable $f): bool {
    try {
        $f();
        return false;
    } catch (Exception $e) {
        return true;
    }
}

$db = base_prueba();
empleados_schema($db);
empleados_schema($db); // idempotente

// Crear una empresa para pruebas
$db->exec("CREATE TABLE IF NOT EXISTS empresas (id INTEGER PRIMARY KEY AUTOINCREMENT, razon_social TEXT)");
$db->exec("INSERT INTO empresas (razon_social) VALUES ('Empresa Test')");
$empresa_id = (int)$db->lastInsertId();

// Test 1: Crear empleado válido
$id1 = empleado_crear($db, $empresa_id, [
    'rut' => '12345678-9',
    'nombre' => 'Juan García',
    'email' => 'juan@ejemplo.cl',
    'telefono' => '912345678',
    'cargo' => 'Ingeniero',
    'fecha_ingreso' => '2026-01-15',
    'estado' => 'activo'
]);
check($id1 > 0, 'crear empleado válido');

// Test 2: Crear otro empleado
$id2 = empleado_crear($db, $empresa_id, [
    'rut' => '98765432-1',
    'nombre' => 'María López',
    'email' => 'maria@ejemplo.cl',
    'cargo' => 'Contador'
]);
check($id2 > 0, 'crear segundo empleado');

// Test 3: Obtener empleado por ID
$emp = empleado_obtener($db, $id1, $empresa_id);
check($emp !== null && $emp['nombre'] === 'Juan García' && $emp['rut'] === '12345678-9', 'obtener empleado por ID');

// Test 4: Obtener empleado por RUT
$emp = empleado_obtener_por_rut($db, $empresa_id, '98765432-1');
check($emp !== null && $emp['id'] == $id2, 'obtener empleado por RUT');

// Test 5: Actualizar empleado
$ok = empleado_actualizar($db, $id1, ['cargo' => 'Senior Ingeniero', 'telefono' => '987654321'], $empresa_id);
check($ok, 'actualizar empleado');
$emp = empleado_obtener($db, $id1, $empresa_id);
check($emp['cargo'] === 'Senior Ingeniero' && $emp['telefono'] === '987654321', 'cambios guardados correctamente');

// Test 6: Listar empleados
$empleados = empleados_listar($db, $empresa_id);
check(count($empleados) === 2, 'listar empleados');

// Test 7: Listar con filtro por estado
$activos = empleados_listar($db, $empresa_id, ['estado' => 'activo']);
check(count($activos) === 2, 'filtrar por estado activo');

// Test 8: Listar con búsqueda
$resultados = empleados_listar($db, $empresa_id, ['buscar' => 'García']);
check(count($resultados) === 1 && $resultados[0]['nombre'] === 'Juan García', 'búsqueda por nombre');

$resultados = empleados_listar($db, $empresa_id, ['buscar' => '98765']);
check(count($resultados) === 1 && $resultados[0]['rut'] === '98765432-1', 'búsqueda por RUT');

// Test 9: Contar empleados
$count = empleados_contar($db, $empresa_id, 'activo');
check($count === 2, 'contar empleados activos');

// Test 10: Eliminar empleado
$ok = empleado_eliminar($db, $id2, $empresa_id);
check($ok, 'eliminar empleado');
$emp = empleado_obtener($db, $id2, $empresa_id);
check($emp === null, 'empleado no existe después de eliminar');

// Test 11: Validaciones
check(rechaza(fn() => empleado_crear($db, $empresa_id, ['nombre' => 'Sin RUT'])), 'rechaza empleado sin RUT');
check(rechaza(fn() => empleado_crear($db, $empresa_id, ['rut' => '12345678-9', 'nombre' => 'Otro'])), 'rechaza RUT duplicado en empresa');
check(rechaza(fn() => empleado_crear($db, $empresa_id, ['rut' => '11111111-1', 'nombre' => 'Test', 'email' => 'email-invalida'])), 'rechaza email inválido');

// Test 12: Email válido
$id3 = empleado_crear($db, $empresa_id, [
    'rut' => '22222222-2',
    'nombre' => 'Pedro Pérez',
    'email' => 'pedro@ejemplo.cl'
]);
check($id3 > 0, 'crear empleado con email válido');

// Test 13: Segregación por empresa
$empresa_id2 = 999;
$id4 = empleado_crear($db, $empresa_id2, [
    'rut' => '12345678-9',
    'nombre' => 'Juan García Otra Empresa'
]);
check($id4 > 0, 'mismo RUT permitido en empresa diferente');
$emp = empleado_obtener($db, $id4, $empresa_id2);
check($emp !== null, 'obtener del empleado de otra empresa');
$emp = empleado_obtener($db, $id4, $empresa_id);
check($emp === null, 'no ve empleado de otra empresa');

// Test 14: Campos opcionales
$id5 = empleado_crear($db, $empresa_id, [
    'rut' => '33333333-3',
    'nombre' => 'Empleado Mínimo'
]);
check($id5 > 0, 'crear empleado con solo RUT y nombre');
$emp = empleado_obtener($db, $id5, $empresa_id);
check($emp['email'] === null && $emp['cargo'] === null && $emp['estado'] === 'activo', 'campos opcionales son null y estado es activo por defecto');

echo "\nEmpleados API: CRUD, validaciones, segregación por empresa y filtros verificados ✓\n";
