<?php
/**
 * API Reportes - Generación de reportes contables
 *
 * Endpoint: GET /api/reportes.php?tipo=diario&desde=2024-01-01&hasta=2024-12-31
 * Retorna reportes en JSON con datos completos, totales y alertas
 *
 * Autenticación requerida: Sí (sesión)
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/lib/sesion.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/empresas.php';
require_once __DIR__ . '/lib/reportes.php';

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

    // Inicializar clase Reportes
    $reportes = new Reportes(icontador_db(), $empresa_id);

    // Obtener parámetros de reporte
    $tipo = $_GET['tipo'] ?? 'diario';
    $desde = $_GET['desde'] ?? date('Y-01-01');
    $hasta = $_GET['hasta'] ?? date('Y-m-d');
    $registro = $_GET['registro'] ?? 'Ambos';
    $tipoVoucher = $_GET['tipo_voucher'] ?? null;

    // Validar tipo de reporte
    $reportesValidos = ['diario', 'mayor', 'balance', 'resultado', 'flujo'];
    if (!in_array($tipo, $reportesValidos)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Tipo de reporte no válido',
        ]);
        exit;
    }

    // Obtener datos del reporte
    $filtros = [
        'desde' => $desde,
        'hasta' => $hasta,
        'registro' => $registro,
        'tipo' => $tipoVoucher,
    ];

    $datos = $reportes->obtenerDatosReporte($tipo, $filtros);

    // Retornar respuesta
    echo json_encode([
        'success' => true,
        'data' => $datos,
        'filtros_disponibles' => $reportes->obtenerFiltros(),
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
        'error' => 'Error al generar reporte',
        'debug' => defined('DEBUG') ? $e->getMessage() : null,
    ]);
}
