<?php
/**
 * API Tesorería - Flujo de caja proyectado y pagos programados
 *
 * GET  /api/tesoreria.php?dias=30            Flujo de caja a 30-90 días y pagos programados próximos
 * GET  /api/tesoreria.php?csrf=1             Token CSRF para las operaciones de escritura
 * POST /api/tesoreria.php  (JSON, cabecera X-CSRF-Token)
 *      {accion:'crear', concepto, monto, fecha_pago, tipo:'pago|ingreso', recurrencia:'una_vez|diaria|semanal|mensual', fecha_fin?}
 *      {accion:'pagar', id}       Marca como pagado un pago de una sola vez
 *      {accion:'cancelar', id}    Cancela un pago pendiente
 *
 * Autenticación requerida: sesión. La empresa se toma de la cabecera X-Empresa-Id.
 */

require_once __DIR__ . '/lib/sesion.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/empresas.php';
require_once __DIR__ . '/lib/tesoreria.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function tesoreria_responder(int $status, array $cuerpo): never {
    http_response_code($status);
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = icontador_db();
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($metodo === 'GET' && ($_GET['csrf'] ?? '') === '1') {
        tesoreria_responder(200, ['success' => true, 'csrf' => auth_csrf()]);
    }

    $empresa_id = empresa_actual($db);
    if ($empresa_id <= 0) tesoreria_responder(400, ['success' => false, 'error' => 'Empresa no seleccionada']);

    Tesoreria::crearEsquema($db);
    $tesoreria = new Tesoreria($db, $empresa_id);

    if ($metodo === 'GET') {
        $dias = max(30, min((int) ($_GET['dias'] ?? 30), 90));
        tesoreria_responder(200, ['success' => true, 'data' => [
            'flujo' => $tesoreria->obtenerFlujoCaja($dias),
            'pagos_programados' => $tesoreria->listarPagosProgramados($dias),
        ]]);
    }

    if ($metodo !== 'POST') {
        header('Allow: GET, POST');
        tesoreria_responder(405, ['success' => false, 'error' => 'Método no permitido']);
    }
    if (!auth_csrf_valido($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
        tesoreria_responder(403, ['success' => false, 'error' => 'La sesión de seguridad venció. Recarga la página e inténtalo de nuevo.']);
    }
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
        tesoreria_responder(415, ['success' => false, 'error' => 'Se espera JSON.']);
    }
    $entrada = json_decode(file_get_contents('php://input'), true);
    if (!is_array($entrada)) tesoreria_responder(400, ['success' => false, 'error' => 'JSON inválido.']);

    switch ($entrada['accion'] ?? '') {
        case 'crear':
            $id = $tesoreria->crearPagoProgramado(
                $entrada['concepto'] ?? '', $entrada['monto'] ?? null, $entrada['fecha_pago'] ?? '',
                $entrada['tipo'] ?? 'pago', $entrada['recurrencia'] ?? 'una_vez', $entrada['fecha_fin'] ?? null
            );
            tesoreria_responder(201, ['success' => true, 'id' => $id]);
        case 'pagar':
        case 'cancelar':
            $tesoreria->cambiarEstado((int) ($entrada['id'] ?? 0), $entrada['accion'] === 'pagar' ? 'pagado' : 'cancelado');
            tesoreria_responder(200, ['success' => true]);
        default:
            tesoreria_responder(400, ['success' => false, 'error' => 'Acción no válida.']);
    }
} catch (TesoreriaError | EmpresaError $e) {
    tesoreria_responder(422, ['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('tesoreria.php: ' . $e);
    tesoreria_responder(500, ['success' => false, 'error' => 'Error al procesar tesorería']);
}
