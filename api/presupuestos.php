<?php
/**
 * API Presupuestos - comparativa presupuestado vs real y mantenimiento de presupuestos
 *
 * GET  /api/presupuestos.php?anio=2026[&mes=10]   Comparativa del período (sin mes = anual), presupuestos del año y cuentas disponibles
 * GET  /api/presupuestos.php?csrf=1               Token CSRF para las operaciones de escritura
 * POST /api/presupuestos.php  (JSON, cabecera X-CSRF-Token)
 *      {accion:'crear', nombre, anio, mes|null, lineas:[{cuenta_codigo, concepto, monto_presupuestado}]}
 *      {accion:'archivar', id}
 *
 * Autenticación requerida: sesión. La empresa se toma de la cabecera X-Empresa-Id.
 */

require_once __DIR__ . '/lib/sesion.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/empresas.php';
require_once __DIR__ . '/lib/vouchers.php';
require_once __DIR__ . '/lib/presupuestos.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function presupuestos_responder(int $status, array $cuerpo): never {
    http_response_code($status);
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = icontador_db();
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($metodo === 'GET' && ($_GET['csrf'] ?? '') === '1') {
        presupuestos_responder(200, ['success' => true, 'csrf' => auth_csrf()]);
    }

    $empresa_id = empresa_actual($db);
    if ($empresa_id <= 0) presupuestos_responder(400, ['success' => false, 'error' => 'Empresa no seleccionada']);

    crearEsquema($db);               // comprobantes y plan de cuentas (lib/vouchers.php)
    Presupuestos::crearEsquema($db);
    $presupuestos = new Presupuestos($db, $empresa_id);

    if ($metodo === 'GET') {
        $anio = $_GET['anio'] ?? date('Y');
        $mes = $_GET['mes'] ?? null;
        $datos = $presupuestos->obtenerComparativa($anio, $mes);
        $datos['presupuestos_disponibles'] = $presupuestos->listarPresupuestos($anio);
        $datos['cuentas_presupuestables'] = $presupuestos->listarCuentasPresupuestables();
        presupuestos_responder(200, ['success' => true, 'data' => $datos]);
    }

    if ($metodo !== 'POST') {
        header('Allow: GET, POST');
        presupuestos_responder(405, ['success' => false, 'error' => 'Método no permitido']);
    }
    if (!auth_csrf_valido($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
        presupuestos_responder(403, ['success' => false, 'error' => 'La sesión de seguridad venció. Recarga la página e inténtalo de nuevo.']);
    }
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
        presupuestos_responder(415, ['success' => false, 'error' => 'Se espera JSON.']);
    }
    $d = json_decode(file_get_contents('php://input'), true);
    if (!is_array($d)) presupuestos_responder(400, ['success' => false, 'error' => 'JSON inválido.']);

    switch ($d['accion'] ?? '') {
        case 'crear':
            $id = $presupuestos->crearPresupuesto($d['nombre'] ?? null, $d['anio'] ?? null, $d['mes'] ?? null, $d['lineas'] ?? null);
            presupuestos_responder(201, ['success' => true, 'message' => 'Presupuesto creado', 'presupuesto_id' => $id]);
        case 'archivar':
            $presupuestos->archivarPresupuesto($d['id'] ?? null);
            presupuestos_responder(200, ['success' => true, 'message' => 'Presupuesto archivado']);
    }
    presupuestos_responder(400, ['success' => false, 'error' => 'Acción no válida.']);
} catch (PresupuestoError | EmpresaError $e) {
    presupuestos_responder(422, ['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('presupuestos.php: ' . $e);
    presupuestos_responder(500, ['success' => false, 'error' => 'Error al procesar presupuestos']);
}
