<?php
/**
 * API Activos Fijos - Gestión de activos fijos, depreciaciones y reportes
 *
 * Endpoints:
 * GET  /api/activos_fijos.php?accion=listar&estado=vigente
 * GET  /api/activos_fijos.php?accion=reporte
 * POST /api/activos_fijos.php (crear nuevo activo)
 * POST /api/activos_fijos.php?accion=baja (dar de baja)
 *
 * Autenticación requerida: Sí (sesión)
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/lib/sesion.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/empresas.php';
require_once __DIR__ . '/lib/activos_fijos.php';

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

    // Obtener empresa actual
    $empresa_id = empresa_actual();
    if (!$empresa_id) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Empresa no seleccionada',
        ]);
        exit;
    }

    // Crear esquema
    ActivosFijos::crearEsquema(icontador_db());

    $activos = new ActivosFijos(icontador_db(), $empresa_id);
    $accion = $_GET['accion'] ?? 'listar';
    $metodo = $_SERVER['REQUEST_METHOD'];

    // Listar activos
    if ($accion === 'listar' && $metodo === 'GET') {
        $estado = $_GET['estado'] ?? 'vigente';
        $resultado = $activos->listarActivos($estado);

        echo json_encode([
            'success' => true,
            'data' => $resultado,
            'categorias' => $activos->obtenerCategorias(),
        ]);
    }

    // Obtener reporte
    elseif ($accion === 'reporte' && $metodo === 'GET') {
        $reporte = $activos->obtenerReporteActivos();

        echo json_encode([
            'success' => true,
            'data' => $reporte,
        ]);
    }

    // Crear nuevo activo
    elseif ($accion === 'crear' && $metodo === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);

        $codigo = $data['codigo'] ?? null;
        $nombre = $data['nombre'] ?? null;
        $categoria = $data['categoria'] ?? null;
        $fechaAdquisicion = $data['fecha_adquisicion'] ?? date('Y-m-d');
        $valorAdquisicion = (int)($data['valor_adquisicion'] ?? 0);
        $vidaUtil = (int)($data['vida_util_anos'] ?? 5);
        $metodoDepreciacion = $data['metodo_depreciacion'] ?? 'lineal';
        $tasaAcelerada = isset($data['tasa_depreciacion_acelerada']) ? (float)$data['tasa_depreciacion_acelerada'] : null;

        if (!$codigo || !$nombre || !$categoria) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Parámetros requeridos: codigo, nombre, categoria',
            ]);
            exit;
        }

        $activoId = $activos->crearActivo($codigo, $nombre, $categoria, $fechaAdquisicion, $valorAdquisicion, $vidaUtil, $metodoDepreciacion, $tasaAcelerada);

        echo json_encode([
            'success' => true,
            'message' => 'Activo fijo creado exitosamente',
            'activo_id' => $activoId,
        ]);
    }

    // Dar de baja
    elseif ($accion === 'baja' && $metodo === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);

        $activoId = $data['activo_id'] ?? null;
        $fechaBaja = $data['fecha_baja'] ?? date('Y-m-d');
        $razon = $data['razon'] ?? 'Baja de activo fijo';

        if (!$activoId) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Se requiere activo_id',
            ]);
            exit;
        }

        $resultado = $activos->darDeBaja($activoId, $fechaBaja, $razon);

        echo json_encode([
            'success' => $resultado,
            'message' => $resultado ? 'Activo dado de baja exitosamente' : 'Error al dar de baja',
        ]);
    }

    // Acción no reconocida
    else {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Acción no válida: ' . $accion,
        ]);
    }

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
        'error' => 'Error al procesar activos fijos',
        'debug' => defined('DEBUG') ? $e->getMessage() : null,
    ]);
}
