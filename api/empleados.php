<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/sesion.php';
/* API de Empleados
   GET  /api/empleados.php?op=listar
   POST /api/empleados.php?op=crear  {rut, nombre, email, cargo, ...}
   GET  /api/empleados.php?op=obtener&id=N
   POST /api/empleados.php?op=actualizar&id=N
   POST /api/empleados.php?op=eliminar&id=N
*/
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/empleados.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function responder(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = icontador_db();
    empleados_schema($db);
    $empresa = empresa_actual($db);
    $op = $_GET['op'] ?? 'listar';
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($op === 'listar' && $metodo === 'GET') {
        $filtros = [];
        if (!empty($_GET['estado'])) $filtros['estado'] = $_GET['estado'];
        if (!empty($_GET['buscar'])) $filtros['buscar'] = $_GET['buscar'];

        $empleados = empleados_listar($db, $empresa, $filtros);
        responder(200, ['ok' => 1, 'empleados' => $empleados]);
    }
    elseif ($op === 'crear' && $metodo === 'POST') {
        $datos = $_POST;
        if (empty($datos)) {
            $datos = json_decode(file_get_contents('php://input'), true) ?? [];
        }

        try {
            $id = empleado_crear($db, $empresa, $datos);
            responder(201, ['ok' => 1, 'id' => $id]);
        } catch (Exception $e) {
            responder(400, ['ok' => 0, 'error' => $e->getMessage()]);
        }
    }
    elseif ($op === 'obtener' && $metodo === 'GET') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) responder(400, ['ok' => 0, 'error' => 'ID inválido']);

        $empleado = empleado_obtener($db, $id, $empresa);
        responder(200, $empleado ? ['ok' => 1, 'empleado' => $empleado] :
                              ['ok' => 0, 'error' => 'Empleado no encontrado']);
    }
    elseif ($op === 'obtener_por_rut' && $metodo === 'GET') {
        $rut = $_GET['rut'] ?? '';
        if (empty($rut)) responder(400, ['ok' => 0, 'error' => 'RUT requerido']);

        $empleado = empleado_obtener_por_rut($db, $empresa, $rut);
        responder(200, $empleado ? ['ok' => 1, 'empleado' => $empleado] :
                              ['ok' => 0, 'error' => 'Empleado no encontrado']);
    }
    elseif ($op === 'actualizar' && $metodo === 'POST') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) responder(400, ['ok' => 0, 'error' => 'ID inválido']);

        $datos = $_POST;
        if (empty($datos)) {
            $datos = json_decode(file_get_contents('php://input'), true) ?? [];
        }

        try {
            $ok = empleado_actualizar($db, $id, $datos, $empresa);
            responder(200, ['ok' => $ok ? 1 : 0]);
        } catch (Exception $e) {
            responder(400, ['ok' => 0, 'error' => $e->getMessage()]);
        }
    }
    elseif ($op === 'eliminar' && $metodo === 'POST') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) responder(400, ['ok' => 0, 'error' => 'ID inválido']);

        $ok = empleado_eliminar($db, $id, $empresa);
        responder(200, ['ok' => $ok ? 1 : 0]);
    }
    elseif ($op === 'contar' && $metodo === 'GET') {
        $estado = $_GET['estado'] ?? 'activo';
        $count = empleados_contar($db, $empresa, $estado);
        responder(200, ['ok' => 1, 'count' => $count]);
    }
    else {
        header('Allow: GET, POST');
        responder(405, ['ok' => 0, 'error' => 'Operación no permitida']);
    }
} catch (Throwable $e) {
    error_log('empleados.php: ' . $e->getMessage());
    responder(500, ['ok' => 0, 'error' => 'Error al acceder a la base de datos']);
}
