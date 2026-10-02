<?php
declare(strict_types=1);
/* API de liquidaciones de sueldo.
   GET    api/liquidaciones.php                  -> liquidaciones guardadas
   GET    api/liquidaciones.php?periodo=AAAA-MM  -> liquidaciones del período
   POST   api/liquidaciones.php  {periodo, trabajador, montos..., detalle}  -> guarda una liquidación
   DELETE api/liquidaciones.php?id=N             -> borra una liquidación */
require __DIR__ . '/db.php';
require __DIR__ . '/lib/liquidaciones.php';

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
    liq_schema($db);
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($metodo === 'GET') {
        responder(200, ['liquidaciones' => liq_listar($db, isset($_GET['periodo']) ? (string)$_GET['periodo'] : null)]);
    }
    if ($metodo === 'POST') {
        if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) responder(415, ['error' => 'Se espera JSON.']);
        $datos = json_decode(file_get_contents('php://input'), true);
        if (!is_array($datos)) responder(400, ['error' => 'JSON inválido.']);
        responder(201, ['id' => liq_guardar($db, $datos)]);
    }
    if ($metodo === 'DELETE') {
        responder(200, ['borrados' => liq_borrar($db, $_GET['id'] ?? null)]);
    }
    header('Allow: GET, POST, DELETE');
    responder(405, ['error' => 'Método no permitido.']);
} catch (LiquidacionError $e) {
    responder(422, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('liquidaciones.php: ' . $e->getMessage());
    responder(500, ['error' => 'No se pudo acceder a la base de datos.']);
}
