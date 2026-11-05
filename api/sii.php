<?php
// Sincronización del RCV con el SII: la web encola tareas y muestra su estado. El agente del PC las ejecuta (api/sii-agente.php).
//   GET  api/sii.php                 -> tareas de RCV recientes de la empresa elegida y si hay clave guardada
//   POST api/sii.php  {tipo:"clave", clave} -> guarda (cifrada) la clave tributaria de la empresa; vacía la borra
//   POST api/sii.php  {periodo, libros:["compras","ventas"]}  -> encola la descarga del RCV
//   POST api/sii.php  {tipo:"datos_empresa", rut}             -> pide razón social y giro del RUT (no requiere empresa)
//   GET  api/sii.php?tarea=ID        -> estado y datos de esa consulta (solo quien la pidió)
//   GET  api/sii.php?agentes=1       -> PCs emparejados del usuario (sin tokens)
//   POST api/sii.php  {tipo:"codigo_emparejamiento"} -> código de un solo uso para el instalador (vence en 10 min)
//   POST api/sii.php  {tipo:"revocar_agente", id}    -> revoca un PC emparejado
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
    $usuario = auth_actual();
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $entrada = $metodo === 'POST' ? json_decode(file_get_contents('php://input'), true) : null;
    if ($metodo === 'GET' && isset($_GET['tarea'])) {
        $consulta = sii_consulta_empresa($db, (int) $_GET['tarea'], (int) $usuario['id']);
        if (!$consulta) responder(404, ['error' => 'Consulta no encontrada.']);
        responder(200, $consulta);
    }
    if ($metodo === 'POST' && is_array($entrada) && ($entrada['tipo'] ?? '') === 'datos_empresa') {
        responder(200, sii_crear_consulta_empresa($db, (int) $usuario['id'], $entrada['rut'] ?? ''));
    }
    // Emparejamiento de PCs: no dependen de la empresa elegida.
    if ($metodo === 'GET' && isset($_GET['agentes'])) {
        responder(200, ['agentes' => sii_agentes_listar($db, (int) $usuario['id'])]);
    }
    if ($metodo === 'POST' && is_array($entrada) && ($entrada['tipo'] ?? '') === 'codigo_emparejamiento') {
        responder(200, sii_codigo_crear($db, (int) $usuario['id']));
    }
    if ($metodo === 'POST' && is_array($entrada) && ($entrada['tipo'] ?? '') === 'revocar_agente') {
        sii_agente_revocar($db, (int) $usuario['id'], (int) ($entrada['id'] ?? 0));
        responder(200, ['agentes' => sii_agentes_listar($db, (int) $usuario['id'])]);
    }
    $empresa = empresa_actual($db);
    if ($metodo === 'GET') {
        responder(200, ['tareas' => sii_listar($db, $empresa)]);
    }
    if ($metodo === 'POST') {
        if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) responder(415, ['error' => 'Se espera JSON.']);
        if (!is_array($entrada)) responder(400, ['error' => 'JSON inválido.']);
        if (($entrada['tipo'] ?? '') === 'clave') {
            responder(410, ['error' => 'El proyecto no almacena Claves Tributarias. Usa solo el mecanismo indicado por el canal autorizado del SII.']);
        }
        responder(200, sii_crear($db, $empresa, (int) $usuario['id'], $entrada['periodo'] ?? null, $entrada['libros'] ?? null));
    }
    header('Allow: GET, POST');
    responder(405, ['error' => 'Método no permitido.']);
} catch (SiiError | RcvError | EmpresaError $e) {
    responder(422, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('sii.php: ' . $e->getMessage());
    responder(500, ['error' => 'No se pudo acceder a la base de datos.']);
}
