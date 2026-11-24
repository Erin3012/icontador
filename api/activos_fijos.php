<?php
/**
 * API Activos Fijos
 *
 * GET  /api/activos_fijos.php?accion=listar[&estado=vigente|baja]   Activos con su valuación y las categorías disponibles
 * GET  /api/activos_fijos.php?accion=reporte                        Totales, detalle y resumen por categoría de los activos vigentes
 * GET  /api/activos_fijos.php?csrf=1                                Token CSRF para las operaciones de escritura
 * POST /api/activos_fijos.php?accion=crear   (JSON, cabecera X-CSRF-Token)
 *      {codigo, nombre, categoria, fecha_adquisicion, valor_adquisicion, vida_util_anos, metodo_depreciacion, tasa_depreciacion_acelerada, descripcion}
 * POST /api/activos_fijos.php?accion=baja    (JSON, cabecera X-CSRF-Token)
 *      {activo_id, fecha_baja, razon}
 *
 * Autenticación requerida: sesión. La empresa se toma de la cabecera X-Empresa-Id.
 */

require_once __DIR__ . '/lib/sesion.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/empresas.php';
require_once __DIR__ . '/lib/activos_fijos.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function activos_responder(int $status, array $cuerpo): never {
    http_response_code($status);
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = icontador_db();
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($metodo === 'GET' && ($_GET['csrf'] ?? '') === '1') {
        activos_responder(200, ['success' => true, 'csrf' => auth_csrf()]);
    }

    $empresa_id = empresa_actual($db);
    if ($empresa_id <= 0) activos_responder(400, ['success' => false, 'error' => 'Empresa no seleccionada']);

    ActivosFijos::crearEsquema($db);
    $activos = new ActivosFijos($db, $empresa_id);
    $accion = (string) ($_GET['accion'] ?? 'listar');

    if ($metodo === 'GET') {
        if ($accion === 'listar') {
            activos_responder(200, ['success' => true, 'data' => $activos->listarActivos((string) ($_GET['estado'] ?? 'vigente')), 'categorias' => $activos->obtenerCategorias()]);
        }
        if ($accion === 'reporte') {
            activos_responder(200, ['success' => true, 'data' => $activos->obtenerReporteActivos()]);
        }
        activos_responder(400, ['success' => false, 'error' => 'Acción no válida']);
    }

    if ($metodo !== 'POST') {
        header('Allow: GET, POST');
        activos_responder(405, ['success' => false, 'error' => 'Método no permitido']);
    }
    if (!auth_csrf_valido($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
        activos_responder(403, ['success' => false, 'error' => 'La sesión de seguridad venció. Recarga la página e inténtalo de nuevo.']);
    }
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
        activos_responder(415, ['success' => false, 'error' => 'Se espera JSON.']);
    }
    $datos = json_decode(file_get_contents('php://input'), true);
    if (!is_array($datos)) activos_responder(400, ['success' => false, 'error' => 'JSON inválido.']);

    if ($accion === 'crear') {
        $id = $activos->crearActivo(
            $datos['codigo'] ?? '', $datos['nombre'] ?? '', $datos['categoria'] ?? '', $datos['fecha_adquisicion'] ?? null,
            $datos['valor_adquisicion'] ?? null, $datos['vida_util_anos'] ?? 5, $datos['metodo_depreciacion'] ?? 'lineal',
            $datos['tasa_depreciacion_acelerada'] ?? null, $datos['descripcion'] ?? null
        );
        activos_responder(201, ['success' => true, 'message' => 'Activo fijo creado exitosamente', 'activo_id' => $id]);
    }
    if ($accion === 'baja') {
        $baja = $activos->darDeBaja($datos['activo_id'] ?? 0, $datos['fecha_baja'] ?? null, $datos['razon'] ?? null);
        activos_responder(200, ['success' => true, 'message' => 'Activo dado de baja exitosamente', 'data' => $baja]);
    }
    activos_responder(400, ['success' => false, 'error' => 'Acción no válida']);
} catch (ActivosError | EmpresaError $e) {
    activos_responder(422, ['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('activos_fijos.php: ' . $e);
    activos_responder(500, ['success' => false, 'error' => 'Error al procesar activos fijos']);
}
