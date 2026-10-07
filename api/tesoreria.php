<?php
/**
 * API Tesorería - Flujo de caja proyectado y pagos programados
 *
 * Endpoint: GET /api/tesoreria.php?dias=30
 * Retorna flujo de caja proyectado a 30/60/90 días con alertas
 *
 * Autenticación requerida: Sí (sesión)
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/lib/sesion.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/empresas.php';
require_once __DIR__ . '/lib/tesoreria.php';

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

    // Crear esquema si no existe
    Tesoreria::crearEsquema(icontador_db());

    // Obtener parámetro de días
    $dias = isset($_GET['dias']) ? (int)$_GET['dias'] : 30;
    $dias = max(30, min($dias, 90)); // Limitar a 30-90 días

    // Inicializar clase Tesoreria
    $tesoreria = new Tesoreria(icontador_db(), $empresa_id);

    // Obtener flujo de caja
    $flujo = $tesoreria->obtenerFlujoCaja($dias);

    // Obtener pagos programados
    $pagosProgramados = $tesoreria->listarPagosProgramados($dias);

    // Retornar respuesta
    echo json_encode([
        'success' => true,
        'data' => [
            'flujo' => $flujo,
            'pagos_programados' => $pagosProgramados,
        ],
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
        'error' => 'Error al procesar tesorería',
        'debug' => defined('DEBUG') ? $e->getMessage() : null,
    ]);
}
