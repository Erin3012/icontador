<?php
declare(strict_types=1);
/* API del RCV.
   GET    api/rcv.php                  -> períodos guardados
   GET    api/rcv.php?periodo=AAAA-MM  -> libros del período
   GET    api/rcv.php?libro=compras|ventas[&desde=AAAA-MM-DD&hasta=AAAA-MM-DD&tipo=N] -> Libro de Compras o Ventas
   POST   api/rcv.php  {kind, period, fileName, docs}  -> reemplaza ese libro del período
   DELETE api/rcv.php?periodo=AAAA-MM  -> borra el período */
require __DIR__ . '/db.php';
require __DIR__ . '/lib/rcv.php';

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
    rcv_schema($db);
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $periodo = isset($_GET['periodo']) ? (string)$_GET['periodo'] : null;
    if ($metodo === 'GET' && isset($_GET['libro'])) {
        $tipo = filter_var($_GET['tipo'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $fecha = fn(string $k) => is_string($_GET[$k] ?? null) && $_GET[$k] !== '' ? $_GET[$k] : null;
        responder(200, rcv_libro($db, (string)$_GET['libro'], $fecha('desde'), $fecha('hasta'), $tipo));
    }
    if ($metodo === 'GET') {
        responder(200, $periodo === null ? ['periodos' => rcv_periodos($db)] : ['periodo' => $periodo, 'libros' => rcv_leer($db, $periodo)]);
    }
    if ($metodo === 'POST') {
        if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) responder(415, ['error' => 'Se espera JSON.']);
        $datos = json_decode(file_get_contents('php://input'), true);
        if (!is_array($datos)) responder(400, ['error' => 'JSON inválido.']);
        responder(200, ['guardados' => rcv_guardar($db, $datos)]);
    }
    if ($metodo === 'DELETE') {
        responder(200, ['borrados' => rcv_borrar($db, (string)$periodo)]);
    }
    header('Allow: GET, POST, DELETE');
    responder(405, ['error' => 'Método no permitido.']);
} catch (RcvError $e) {
    responder(422, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('rcv.php: ' . $e->getMessage());
    responder(500, ['error' => 'No se pudo acceder a la base de datos.']);
}
