<?php
// Sincronización del RCV con el SII: la web encola tareas y muestra su estado. El agente del PC las ejecuta (api/sii-agente.php).
//   GET  api/sii.php            -> tareas recientes de la empresa elegida
//   POST api/sii.php  {periodo, libros:["compras","ventas"]} -> encola la descarga
declare(strict_types=1);
require_once __DIR__ . '/lib/sesion.php';
require __DIR__ . '/db.php';
require __DIR__ . '/lib/sii.php';

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
    sii_schema($db);
    rcv_schema($db);
    $empresa = empresa_actual($db);
    $usuario = auth_actual();
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($metodo === 'GET') {
        responder(200, ['tareas' => sii_listar($db, $empresa)]);
    }
    if ($metodo === 'POST') {
        if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) responder(415, ['error' => 'Se espera JSON.']);
        $datos = json_decode(file_get_contents('php://input'), true);
        if (!is_array($datos)) responder(400, ['error' => 'JSON inválido.']);
        responder(200, sii_crear($db, $empresa, (int) $usuario['id'], $datos['periodo'] ?? null, $datos['libros'] ?? null));
    }
    header('Allow: GET, POST');
    responder(405, ['error' => 'Método no permitido.']);
} catch (SiiError | RcvError | EmpresaError $e) {
    responder(422, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('sii.php: ' . $e->getMessage());
    responder(500, ['error' => 'No se pudo acceder a la base de datos.']);
}
