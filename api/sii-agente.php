<?php
// Endpoints del agente central. No usan sesión; exigen el token global emparejado por un administrador.
//   POST ?accion=canjear_servicio {codigo, nombre} -> token global de un solo uso
//   GET  ?accion=pendientes                       -> una tarea global con clave descifrada en memoria
//   POST ?accion=heartbeat {worker_id}            -> conserva el lease de instancia
//   POST ?accion=liberar {worker_id}              -> libera el lease
//   POST ?accion=importar {tarea_id, kind, period, fileName, rutEmpresa, docs}
//   POST ?accion=resultado_empresa {tarea_id, datos} -> devuelve el perfil del contribuyente al usuario solicitante
//   POST ?accion=error    {tarea_id, mensaje}
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
    hon_schema($db);
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $accion = (string) ($_GET['accion'] ?? '');
    if ($metodo === 'POST' && $accion === 'canjear_servicio') {
        if (!sii_transporte_seguro()) responder(400, ['error' => 'El enrolamiento del agente requiere HTTPS.']);
        $datos = json_decode(file_get_contents('php://input'), true);
        if (!is_array($datos) || !is_string($datos['codigo'] ?? null)) responder(400, ['error' => 'Falta el código de enrolamiento del servicio.']);
        responder(200, ['token' => sii_servicio_canjear($db, $datos['codigo'], (string) ($datos['nombre'] ?? 'Agente central'))]);
    }
    if ($metodo === 'POST' && $accion === 'canjear') responder(410, ['error' => 'El emparejamiento por usuario fue retirado. Usa el código de servicio emitido por un administrador.']);
    sii_servicio_autorizar($db);
    $workerId = (string)($_SERVER['HTTP_X_AGENTE_WORKER'] ?? '');
    if ($metodo === 'GET' && $accion === 'estado') responder(200, sii_servicio_cola($db));
    if ($metodo === 'POST' && $accion === 'heartbeat') {
        $datos = json_decode(file_get_contents('php://input'), true);
        if (!is_array($datos) || !is_string($datos['worker_id'] ?? null)) responder(400, ['error' => 'Falta worker_id.']);
        responder(200, sii_servicio_heartbeat($db, $datos['worker_id']));
    }
    if ($metodo === 'POST' && $accion === 'liberar') {
        $datos = json_decode(file_get_contents('php://input'), true);
        if (!is_array($datos) || !is_string($datos['worker_id'] ?? null)) responder(400, ['error' => 'Falta worker_id.']);
        sii_servicio_liberar($db, $datos['worker_id']);
        responder(200, ['ok' => true]);
    }
    if ($metodo === 'POST' && in_array($accion, ['importar', 'error', 'resultado_empresa', 'control', 'finalizar_control', 'evento', 'captura'], true)) {
        $estado = sii_servicio_heartbeat($db, $workerId);
        if ($estado['ocupado']) responder(409, ['error' => 'El agente central ya está ejecutándose en otra instancia.']);
    }
    if ($metodo === 'POST' && $accion === 'evento') {
        $datos = json_decode(file_get_contents('php://input'), true);
        if (!is_array($datos) || !is_int($datos['tarea_id'] ?? null) || !is_string($datos['etapa'] ?? null)) responder(400, ['error' => 'Faltan tarea_id o etapa.']);
        sii_evento_registrar($db, $datos['tarea_id'], $datos['etapa'], is_string($datos['detalle'] ?? null) ? $datos['detalle'] : '');
        responder(200, ['ok' => true]);
    }
    if ($metodo === 'POST' && $accion === 'captura') {
        $datos = json_decode(file_get_contents('php://input'), true);
        if (!is_array($datos) || !is_int($datos['tarea_id'] ?? null) || !is_string($datos['jpeg'] ?? null) || strlen($datos['jpeg']) > 1600000) responder(400, ['error' => 'Faltan los datos de captura.']);
        $imagen = base64_decode($datos['jpeg'], true);
        if ($imagen === false) responder(400, ['error' => 'La captura no tiene un formato válido.']);
        sii_captura_guardar($db, $datos['tarea_id'], $imagen);
        responder(200, ['ok' => true]);
    }
    if ($metodo === 'POST' && $accion === 'control') {
        $datos = json_decode(file_get_contents('php://input'), true);
        if (!is_array($datos) || !is_int($datos['tarea_id'] ?? null) || !is_string($datos['accion'] ?? null)) responder(400, ['error' => 'Faltan tarea_id o accion.']);
        responder(200, sii_control_tarea($db, $datos['tarea_id'], $datos['accion']));
    }
    if ($metodo === 'POST' && $accion === 'finalizar_control') {
        $datos = json_decode(file_get_contents('php://input'), true);
        if (!is_array($datos) || !is_int($datos['tarea_id'] ?? null) || !in_array($datos['accion'] ?? null, ['cancelar','reencolar'], true)) responder(400, ['error' => 'Faltan tarea_id o accion.']);
        sii_finalizar_control($db, $datos['tarea_id'], $datos['accion']);
        responder(200, ['ok' => true]);
    }
    if ($metodo === 'GET' && $accion === 'pendientes') {
        $estado = sii_servicio_heartbeat($db, $workerId);
        if ($estado['ocupado']) responder(409, ['error' => 'El agente central ya está ejecutándose en otra instancia.']);
        responder(200, ['tareas' => sii_reclamar($db, 1)]);
    }
    if ($metodo === 'POST' && in_array($accion, ['importar', 'error', 'resultado_empresa'], true)) {
        $datos = json_decode(file_get_contents('php://input'), true);
        if (!is_array($datos) || !is_int($datos['tarea_id'] ?? null)) responder(400, ['error' => 'Falta tarea_id.']);
        if ($accion === 'importar') {
            responder(200, ['guardados' => sii_importar($db, $datos['tarea_id'], $datos)]);
        }
        if ($accion === 'resultado_empresa') {
            if (!is_array($datos['datos'] ?? null)) responder(400, ['error' => 'Faltan los datos del contribuyente.']);
            responder(200, ['datos' => sii_resultado_empresa($db, $datos['tarea_id'], $datos['datos'])]);
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
