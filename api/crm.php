<?php
/**
 * API CRM - Gestión de contactos, interacciones y oportunidades
 *
 * Endpoints:
 * GET  /api/crm.php?accion=dashboard
 * GET  /api/crm.php?accion=contactos&tipo=cliente
 * GET  /api/crm.php?accion=oportunidades
 * GET  /api/crm.php?accion=tareas
 * POST /api/crm.php?accion=crear_contacto
 * POST /api/crm.php?accion=crear_oportunidad
 * POST /api/crm.php?accion=registrar_interaccion
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/lib/sesion.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/empresas.php';
require_once __DIR__ . '/lib/crm.php';

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

    CRM::crearEsquema(icontador_db());
    $crm = new CRM(icontador_db(), $empresa_id);
    $accion = $_GET['accion'] ?? 'dashboard';
    $metodo = $_SERVER['REQUEST_METHOD'];

    if ($accion === 'dashboard' && $metodo === 'GET') {
        $datos = $crm->obtenerDashboard();
        echo json_encode(['success' => true, 'data' => $datos]);
    }
    elseif ($accion === 'contactos' && $metodo === 'GET') {
        $tipo = $_GET['tipo'] ?? 'cliente';
        $contactos = $crm->listarContactos($tipo, 'activo');
        echo json_encode(['success' => true, 'data' => $contactos]);
    }
    elseif ($accion === 'oportunidades' && $metodo === 'GET') {
        $oportunidades = $crm->obtenerOportunidadesAbiertas();
        echo json_encode(['success' => true, 'data' => $oportunidades]);
    }
    elseif ($accion === 'tareas' && $metodo === 'GET') {
        $tareas = $crm->obtenerTareasPendientes();
        echo json_encode(['success' => true, 'data' => $tareas]);
    }
    elseif ($accion === 'crear_contacto' && $metodo === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $nombre = $data['nombre'] ?? null;
        $tipo = $data['tipo'] ?? 'cliente';
        $email = $data['email'] ?? null;
        $telefono = $data['telefono'] ?? null;
        $empresa = $data['empresa'] ?? null;

        if (!$nombre) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Nombre es requerido']);
            exit;
        }

        $contactoId = $crm->crearContacto($nombre, $tipo, $email, $telefono, $empresa);
        echo json_encode(['success' => true, 'message' => 'Contacto creado', 'contacto_id' => $contactoId]);
    }
    elseif ($accion === 'crear_oportunidad' && $metodo === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $contactoId = $data['contacto_id'] ?? null;
        $nombre = $data['nombre'] ?? null;
        $montoEstimado = (int)($data['monto_estimado'] ?? 0);
        $fechaCierre = $data['fecha_cierre'] ?? date('Y-m-d', strtotime('+30 days'));

        if (!$contactoId || !$nombre) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Parámetros requeridos']);
            exit;
        }

        $oportunidadId = $crm->crearOportunidad($contactoId, $nombre, $montoEstimado, $fechaCierre);
        echo json_encode(['success' => true, 'message' => 'Oportunidad creada', 'oportunidad_id' => $oportunidadId]);
    }
    elseif ($accion === 'registrar_interaccion' && $metodo === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $contactoId = $data['contacto_id'] ?? null;
        $tipoInteraccion = $data['tipo'] ?? 'llamada';
        $descripcion = $data['descripcion'] ?? null;
        $resultado = $data['resultado'] ?? null;

        if (!$contactoId || !$descripcion) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Parámetros requeridos']);
            exit;
        }

        $interaccionId = $crm->registrarInteraccion($contactoId, $tipoInteraccion, $descripcion, $resultado);
        echo json_encode(['success' => true, 'message' => 'Interacción registrada', 'interaccion_id' => $interaccionId]);
    }
    else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Acción no válida']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
