<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/sesion.php';
/* API de la pantalla iTributario (configuración de la integración por empresa).
   GET  api/itributario.php                       -> preferencia y historial de la empresa
   POST api/itributario.php?accion=preferencia    {auto_sync: 'ask'|'S'|'N'}
   POST api/itributario.php?accion=sincronizar    {periodo: 'AAAA-MM', origen: 'manual'|'automatico', id_voucher_origen?} */
require __DIR__ . '/db.php';
require __DIR__ . '/lib/itributario.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function responder(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

function cuerpo_json(): array {
    $datos = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($datos)) responder(400, ['error' => 'Se espera JSON válido.']);
    return $datos;
}

try {
    $db = icontador_db();
    itrib_schema($db);
    $empresa = empresa_actual($db);
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $accion = (string) ($_GET['accion'] ?? '');

    if ($metodo === 'GET') {
        responder(200, [
            'auto_sync' => itrib_preferencia($db, $empresa),
            'historial' => itrib_historial($db, $empresa),
        ]);
    }
    if ($metodo === 'POST' && $accion === 'preferencia') {
        $datos = cuerpo_json();
        itrib_guardar_preferencia($db, $empresa, (string) ($datos['auto_sync'] ?? ''));
        responder(200, ['exito' => 1, 'auto_sync' => itrib_preferencia($db, $empresa)]);
    }
    if ($metodo === 'POST' && $accion === 'sincronizar') {
        $datos = cuerpo_json();
        $voucher = isset($datos['id_voucher_origen']) && $datos['id_voucher_origen'] !== null
            ? (int) $datos['id_voucher_origen'] : null;
        $id = itrib_solicitar_sincronizacion($db, $empresa, (string) ($datos['periodo'] ?? ''), (string) ($datos['origen'] ?? 'manual'), $voucher);
        // exito 0 mientras el envío real no esté conectado: la pantalla no debe mostrar una sincronización que no ocurrió.
        responder(200, ['exito' => 0, 'id' => $id, 'mensaje' => ITRIB_MENSAJE_PENDIENTE]);
    }
    responder(404, ['error' => 'Acción no válida.']);
} catch (ItribError | EmpresaError $e) {
    responder(422, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('itributario.php: ' . $e->getMessage());
    responder(500, ['error' => 'No se pudo acceder a la base de datos.']);
}
