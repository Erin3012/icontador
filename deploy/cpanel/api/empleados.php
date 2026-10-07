<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/lib/empleados.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $db = icontador_db();
    $metodo = $_SERVER['REQUEST_METHOD'];
    $empresa_id = isset($_GET['empresa_id']) ? (int)$_GET['empresa_id'] : 0;

    if ($metodo === 'GET') {
        if (isset($_GET['id'])) {
            $empleado = emp_obtener($db, (int)$_GET['id']);
            if ($empleado) {
                echo json_encode(['empleado' => $empleado], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Empleado no encontrado'], JSON_UNESCAPED_UNICODE);
            }
        } else {
            $empleados = emp_listar($db, $empresa_id);
            echo json_encode(['empleados' => $empleados], JSON_UNESCAPED_UNICODE);
        }
    } elseif ($metodo === 'POST') {
        $datos = json_decode(file_get_contents('php://input'), true) ?? [];
        if ($empresa_id === 0) {
            http_response_code(400);
            echo json_encode(['error' => 'empresa_id es requerido'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $id = emp_crear($db, $empresa_id, $datos);
        http_response_code(201);
        echo json_encode(['id' => $id], JSON_UNESCAPED_UNICODE);
    } elseif ($metodo === 'PUT') {
        $datos = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id === 0) {
            http_response_code(400);
            echo json_encode(['error' => 'id es requerido'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        emp_actualizar($db, $id, $datos);
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } elseif ($metodo === 'DELETE') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id === 0) {
            http_response_code(400);
            echo json_encode(['error' => 'id es requerido'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        emp_eliminar($db, $id);
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(405);
        header('Allow: GET, POST, PUT, DELETE');
        echo json_encode(['error' => 'Método no permitido'], JSON_UNESCAPED_UNICODE);
    }
} catch (EmpleadoError $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error en la base de datos'], JSON_UNESCAPED_UNICODE);
}
