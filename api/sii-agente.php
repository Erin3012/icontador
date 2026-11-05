<?php
// Endpoints del agente local de sii-agent/ (Playwright en el PC). No usan sesión: exigen el token del agente
// (propio, emparejado con un código) o el sii_agente_token compartido de instalaciones anteriores.
//   POST api/sii-agente.php?accion=canjear  {codigo, nombre}  -> sin token: devuelve el token propio del agente
//   GET  api/sii-agente.php?accion=pendientes                -> reclama hasta 3 tareas (RUT, período, libro)
//   POST api/sii-agente.php?accion=importar  {tarea_id, kind, period, fileName, rutEmpresa, docs}
//   POST api/sii-agente.php?accion=error     {tarea_id, mensaje}
//   POST api/sii-agente.php?accion=resultado_empresa  {tarea_id, razon_social, giro}  (consulta de datos de empresa)
declare(strict_types=1);
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
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $accion = (string) ($_GET['accion'] ?? '');
    if ($metodo === 'POST' && $accion === 'canjear') {
        // Emparejamiento: el código de un solo uso es la credencial; a cambio el agente recibe su token propio.
        $datos = json_decode(file_get_contents('php://input'), true);
        if (!is_array($datos) || !is_string($datos['codigo'] ?? null)) responder(400, ['error' => 'Falta el código de emparejamiento.']);
        responder(200, ['token' => sii_codigo_canjear($db, $datos['codigo'], (string) ($datos['nombre'] ?? ''))]);
    }
    sii_autorizar_agente($db);
    if ($metodo === 'GET' && $accion === 'pendientes') {
        responder(200, ['tareas' => sii_reclamar($db)]);
    }
    if ($metodo === 'POST' && $accion === 'resultado_empresa') {
        $datos = json_decode(file_get_contents('php://input'), true);
        if (!is_array($datos) || !is_int($datos['tarea_id'] ?? null)) responder(400, ['error' => 'Falta tarea_id.']);
        responder(200, ['empresa' => sii_resultado_empresa($db, $datos['tarea_id'], $datos)]);
    }
    if ($metodo === 'POST' && in_array($accion, ['importar', 'error'], true)) {
        $datos = json_decode(file_get_contents('php://input'), true);
        if (!is_array($datos) || !is_int($datos['tarea_id'] ?? null)) responder(400, ['error' => 'Falta tarea_id.']);
        if ($accion === 'importar') {
            responder(200, ['guardados' => sii_importar($db, $datos['tarea_id'], $datos)]);
        }
        sii_fallar($db, $datos['tarea_id'], (string) ($datos['mensaje'] ?? 'Error desconocido del agente.'));
        responder(200, ['ok' => true]);
    }
    responder(404, ['error' => 'Acción no válida.']);
} catch (SiiError | RcvError | EmpresaError $e) {
    responder(422, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('sii-agente.php: ' . $e->getMessage());
    responder(500, ['error' => 'No se pudo acceder a la base de datos.']);
}
