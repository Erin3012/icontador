<?php
/**
 * Dashboard Ejecutivo API
 *
 * Endpoint: GET /api/dashboard.php
 * Retorna KPIs, saldos de caja y bancos, últimos comprobantes, flujo mensual, alertas e inventario
 * de la empresa indicada en la cabecera X-Empresa-Id.
 *
 * Autenticación requerida: sesión.
 */

require_once __DIR__ . '/lib/sesion.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/empresas.php';
require_once __DIR__ . '/lib/dashboard.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function dashboard_responder(int $status, array $cuerpo): never {
    http_response_code($status);
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        dashboard_responder(405, ['success' => false, 'error' => 'Método no permitido']);
    }
    $db = icontador_db();
    $empresa_id = empresa_actual($db);
    if ($empresa_id <= 0) dashboard_responder(400, ['success' => false, 'error' => 'Empresa no seleccionada']);

    dashboard_responder(200, ['success' => true, 'data' => (new Dashboard($db, $empresa_id))->obtenerResumen()]);
} catch (EmpresaError $e) {
    dashboard_responder(422, ['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('dashboard.php: ' . $e);
    dashboard_responder(500, ['success' => false, 'error' => 'Error al procesar dashboard']);
}
