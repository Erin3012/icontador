<?php
/**
 * API Presupuestos - Comparativa Presupuestado vs Real
 *
 * Endpoint: GET /api/presupuestos.php?anio=2026&mes=10 (optional)
 * Retorna comparativa de presupuesto vs gastos reales con alertas
 *
 * Autenticación requerida: Sí (sesión)
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/lib/sesion.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/empresas.php';
require_once __DIR__ . '/lib/presupuestos.php';

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
    Presupuestos::crearEsquema(icontador_db());

    // Obtener parámetros
    $anio = isset($_GET['anio']) ? (int)$_GET['anio'] : date('Y');
    $mes = isset($_GET['mes']) ? (int)$_GET['mes'] : null;

    // Validar año
    if ($anio < 2000 || $anio > 2099) {
        $anio = date('Y');
    }

    // Validar mes si se proporciona
    if ($mes !== null && ($mes < 1 || $mes > 12)) {
        $mes = null;
    }

    // Inicializar clase Presupuestos
    $presupuestos = new Presupuestos(icontador_db(), $empresa_id);

    // Obtener datos según parámetros
    if ($mes !== null) {
        // Comparativa mensual
        $datos = $presupuestos->obtenerComparativa($anio, $mes);
    } else {
        // Comparativa anual
        $datos = $presupuestos->obtenerComparativa($anio);
    }

    // Agregar listado de presupuestos disponibles
    $datos['presupuestos_disponibles'] = $presupuestos->listarPresupuestos($anio);

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
        'error' => 'Error al procesar presupuestos',
        'debug' => defined('DEBUG') ? $e->getMessage() : null,
    ]);
}
