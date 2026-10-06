<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/sesion.php';
/* API de boletas de honorarios importadas desde el SII.
   GET    api/honorarios.php                  -> períodos guardados
   GET    api/honorarios.php?periodo=AAAA-MM  -> boletas del período
   GET    api/honorarios.php?libro=recibidas|emitidas[&desde=AAAA-MM-DD&hasta=AAAA-MM-DD] -> Libro de Honorarios
   POST   api/honorarios.php  {kind, period, fileName, rutEmpresa, boletas}  -> reemplaza ese libro del período
   DELETE api/honorarios.php?periodo=AAAA-MM  -> borra el período */
require __DIR__ . '/db.php';
require __DIR__ . '/lib/honorarios.php';

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
    hon_schema($db);
    $empresa = empresa_actual($db);
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $periodo = isset($_GET['periodo']) ? (string)$_GET['periodo'] : null;
    if ($metodo === 'GET' && isset($_GET['libro'])) {
        $fecha = fn(string $k) => is_string($_GET[$k] ?? null) && $_GET[$k] !== '' ? $_GET[$k] : null;
        responder(200, hon_libro($db, (string)$_GET['libro'], $fecha('desde'), $fecha('hasta'), $empresa));
    }
    if ($metodo === 'GET') {
        responder(200, $periodo === null ? ['periodos' => hon_periodos($db, $empresa)] : ['periodo' => $periodo, 'libros' => hon_leer($db, $periodo, $empresa)]);
    }
    if ($metodo === 'POST') {
        if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) responder(415, ['error' => 'Se espera JSON.']);
        $datos = json_decode(file_get_contents('php://input'), true);
        if (!is_array($datos)) responder(400, ['error' => 'JSON inválido.']);
        responder(200, ['guardadas' => hon_guardar($db, $datos, $empresa)]);
    }
    if ($metodo === 'DELETE') {
        responder(200, ['borradas' => hon_borrar($db, (string)$periodo, $empresa)]);
    }
    header('Allow: GET, POST, DELETE');
    responder(405, ['error' => 'Método no permitido.']);
} catch (HonorariosError | EmpresaError $e) {
    responder(422, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('honorarios.php: ' . $e->getMessage());
    responder(500, ['error' => 'No se pudo acceder a la base de datos.']);
}
