<?php
/**
 * API CRM - contactos, interacciones, oportunidades y tareas
 *
 * GET  /api/crm.php?accion=dashboard                      KPIs, oportunidades abiertas y tareas pendientes
 * GET  /api/crm.php?accion=contactos[&tipo=cliente|prospecto|proveedor|todos]
 * GET  /api/crm.php?accion=oportunidades                  Oportunidades abiertas
 * GET  /api/crm.php?accion=tareas                         Tareas pendientes
 * GET  /api/crm.php?accion=interacciones&contacto_id=N    Historial de un contacto
 * GET  /api/crm.php?csrf=1                                Token CSRF para las operaciones de escritura
 * POST /api/crm.php?accion=...   (JSON, cabecera X-CSRF-Token)
 *      crear_contacto {nombre, tipo, email, telefono, empresa}
 *      registrar_interaccion {contacto_id, tipo, descripcion, resultado, duracion}
 *      crear_oportunidad {contacto_id, nombre, monto_estimado, fecha_cierre, etapa}
 *      cambiar_etapa {oportunidad_id, etapa}
 *      cerrar_oportunidad {oportunidad_id, resultado: ganada|perdida, motivo}
 *      crear_tarea {contacto_id, descripcion, fecha_vencimiento, prioridad, tipo}
 *      completar_tarea {tarea_id}
 *
 * Autenticación requerida: sesión. La empresa se toma de la cabecera X-Empresa-Id.
 */

require_once __DIR__ . '/lib/sesion.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/empresas.php';
require_once __DIR__ . '/lib/crm.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function crm_responder(int $status, array $cuerpo): never {
    http_response_code($status);
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = icontador_db();
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($metodo === 'GET' && ($_GET['csrf'] ?? '') === '1') {
        crm_responder(200, ['success' => true, 'csrf' => auth_csrf()]);
    }

    $empresa_id = empresa_actual($db);
    if ($empresa_id <= 0) crm_responder(400, ['success' => false, 'error' => 'Empresa no seleccionada']);

    CRM::crearEsquema($db);
    $crm = new CRM($db, $empresa_id);
    $accion = (string) ($_GET['accion'] ?? 'dashboard');

    if ($metodo === 'GET') {
        switch ($accion) {
            case 'dashboard':
                crm_responder(200, ['success' => true, 'data' => $crm->obtenerDashboard()]);
            case 'contactos':
                crm_responder(200, ['success' => true, 'data' => $crm->listarContactos($_GET['tipo'] ?? null, 'activo'),
                    'tipos' => CRM::TIPOS_CONTACTO]);
            case 'oportunidades':
                crm_responder(200, ['success' => true, 'data' => $crm->obtenerOportunidadesAbiertas(), 'etapas' => $crm->obtenerEtapasOportunidad()]);
            case 'tareas':
                crm_responder(200, ['success' => true, 'data' => $crm->obtenerTareasPendientes()]);
            case 'interacciones':
                crm_responder(200, ['success' => true, 'data' => $crm->listarInteracciones($_GET['contacto_id'] ?? null), 'tipos' => $crm->obtenerTiposInteraccion()]);
        }
        crm_responder(400, ['success' => false, 'error' => 'Acción no válida']);
    }

    if ($metodo !== 'POST') {
        header('Allow: GET, POST');
        crm_responder(405, ['success' => false, 'error' => 'Método no permitido']);
    }
    if (!auth_csrf_valido($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
        crm_responder(403, ['success' => false, 'error' => 'La sesión de seguridad venció. Recarga la página e inténtalo de nuevo.']);
    }
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
        crm_responder(415, ['success' => false, 'error' => 'Se espera JSON.']);
    }
    $d = json_decode(file_get_contents('php://input'), true);
    if (!is_array($d)) crm_responder(400, ['success' => false, 'error' => 'JSON inválido.']);

    switch ($accion) {
        case 'crear_contacto':
            $id = $crm->crearContacto($d['nombre'] ?? null, $d['tipo'] ?? 'cliente', $d['email'] ?? null, $d['telefono'] ?? null, $d['empresa'] ?? null);
            crm_responder(201, ['success' => true, 'message' => 'Contacto creado', 'contacto_id' => $id]);
        case 'registrar_interaccion':
            $id = $crm->registrarInteraccion($d['contacto_id'] ?? null, $d['tipo'] ?? 'llamada', $d['descripcion'] ?? null, $d['resultado'] ?? null, $d['duracion'] ?? null);
            crm_responder(201, ['success' => true, 'message' => 'Interacción registrada', 'interaccion_id' => $id]);
        case 'crear_oportunidad':
            $id = $crm->crearOportunidad($d['contacto_id'] ?? null, $d['nombre'] ?? null, $d['monto_estimado'] ?? 0, $d['fecha_cierre'] ?? null, $d['etapa'] ?? 'prospecto');
            crm_responder(201, ['success' => true, 'message' => 'Oportunidad creada', 'oportunidad_id' => $id]);
        case 'cambiar_etapa':
            $crm->cambiarEtapa($d['oportunidad_id'] ?? null, $d['etapa'] ?? null);
            crm_responder(200, ['success' => true, 'message' => 'Etapa actualizada']);
        case 'cerrar_oportunidad':
            $crm->cerrarOportunidad($d['oportunidad_id'] ?? null, $d['resultado'] ?? null, $d['motivo'] ?? null);
            crm_responder(200, ['success' => true, 'message' => 'Oportunidad cerrada']);
        case 'crear_tarea':
            $id = $crm->crearTarea($d['contacto_id'] ?? null, $d['descripcion'] ?? null, $d['fecha_vencimiento'] ?? null, $d['prioridad'] ?? 'media', $d['tipo'] ?? 'seguimiento');
            crm_responder(201, ['success' => true, 'message' => 'Tarea creada', 'tarea_id' => $id]);
        case 'completar_tarea':
            $crm->completarTarea($d['tarea_id'] ?? null);
            crm_responder(200, ['success' => true, 'message' => 'Tarea completada']);
    }
    crm_responder(400, ['success' => false, 'error' => 'Acción no válida']);
} catch (CrmError | EmpresaError $e) {
    crm_responder(422, ['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('crm.php: ' . $e);
    crm_responder(500, ['success' => false, 'error' => 'Error al procesar el CRM']);
}
