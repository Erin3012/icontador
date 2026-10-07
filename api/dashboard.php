<?php
/**
 * Dashboard Ejecutivo API
 *
 * Endpoint: GET /api/dashboard.php
 * Retorna KPIs, balance caja, flujos, alertas
 *
 * Autenticación requerida: Sí (sesión)
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/lib/sesion.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/empresas.php';
require_once __DIR__ . '/lib/dashboard.php';

try {
    // Validar sesión
    if (!isset($_SESSION['usuario_id'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Autenticación requerida',
        ]);
        exit;
    }

    // Obtener empresa actual del usuario
    $empresa_id = empresa_actual();
    if (!$empresa_id) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Empresa no seleccionada',
        ]);
        exit;
    }

    // Inicializar clase Dashboard
    $dashboard = new Dashboard($pdo, $empresa_id);

    // Obtener datos
    $datos = $dashboard->obtenerResumen();

    // Retornar respuesta
    echo json_encode([
        'success' => true,
        'data' => $datos,
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error en base de datos',
        'debug' => defined('DEBUG') ? $e->getMessage() : null,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error al procesar dashboard',
        'debug' => defined('DEBUG') ? $e->getMessage() : null,
    ]);
}
