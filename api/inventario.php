<?php
// API de Inventario.
// GET    api/inventario.php                 -> lista de items
// POST   api/inventario.php {item}          -> crea o actualiza item
// DELETE api/inventario.php?codigo=...     -> elimina item
declare(strict_types=1);
require_once __DIR__ . '/lib/sesion.php';
require __DIR__ . '/db.php';
require __DIR__ . '/lib/inventario.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function responder(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = icontador_db();
    inventario_schema($pdo);
    $empresa = empresa_actual($pdo);
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($metodo === 'GET') {
        $items = inventario_items($pdo, $empresa);
        responder(200, ['items' => $items]);
    }

    if ($metodo === 'POST') {
        if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
            responder(415, ['error' => 'Se espera JSON.']);
        }
        $datos = json_decode(file_get_contents('php://input'), true);
        if (!is_array($datos)) {
            responder(400, ['error' => 'JSON inválido.']);
        }
        if (empty($datos['codigo'])) {
            responder(400, ['error' => 'El código es requerido.']);
        }
        $resultado = inventario_agregar($pdo, $empresa, $datos);
        responder(200, $resultado);
    }

    if ($metodo === 'DELETE') {
        if (empty($_GET['codigo'])) {
            responder(400, ['error' => 'Se requiere parámetro codigo.']);
        }
        $resultado = inventario_eliminar($pdo, $empresa, (string)$_GET['codigo']);
        responder(200, $resultado);
    }

    header('Allow: GET, POST, DELETE');
    responder(405, ['error' => 'Método no permitido.']);
} catch (EmpresaError $e) {
    responder(403, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log($e);
    responder(500, ['error' => 'Error del servidor.']);
}
