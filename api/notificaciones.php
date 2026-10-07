<?php
/**
 * API Notificaciones - Gestión de alertas y notificaciones
 *
 * Endpoints:
 * GET  /api/notificaciones.php?accion=obtener
 * GET  /api/notificaciones.php?accion=preferencias
 * POST /api/notificaciones.php?accion=marcar_leida
 * POST /api/notificaciones.php?accion=actualizar_preferencias
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/lib/sesion.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/empresas.php';
require_once __DIR__ . '/lib/notificaciones.php';

try {
    if (!isset($_SESSION['usuario_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Autenticación requerida']);
        exit;
    }

    $empresa_id = empresa_actual();
    if (!$empresa_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Empresa no seleccionada']);
        exit;
    }

    Notificaciones::crearEsquema(icontador_db());
    $notificaciones = new Notificaciones(icontador_db(), $empresa_id);
    $usuario_id = $_SESSION['usuario_id'];
    $accion = $_GET['accion'] ?? 'obtener';
    $metodo = $_SERVER['REQUEST_METHOD'];

    if ($accion === 'obtener' && $metodo === 'GET') {
        $nots = $notificaciones->obtenerNotificacionesPendientes($usuario_id);
        echo json_encode(['success' => true, 'data' => $nots, 'count' => count($nots)]);
    }
    elseif ($accion === 'preferencias' && $metodo === 'GET') {
        $prefs = $notificaciones->obtenerPreferencias($usuario_id);
        echo json_encode(['success' => true, 'data' => $prefs]);
    }
    elseif ($accion === 'marcar_leida' && $metodo === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $notificacionId = $data['notificacion_id'] ?? null;

        if (!$notificacionId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID de notificación requerido']);
            exit;
        }

        $resultado = $notificaciones->marcarComoLeida($notificacionId, $usuario_id);
        echo json_encode(['success' => $resultado, 'message' => 'Notificación marcada como leída']);
    }
    elseif ($accion === 'actualizar_preferencias' && $metodo === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $resultado = $notificaciones->actualizarPreferencias($usuario_id, $data);
        echo json_encode(['success' => $resultado, 'message' => 'Preferencias actualizadas']);
    }
    else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Acción no válida']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
