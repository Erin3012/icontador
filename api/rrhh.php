<?php
/**
 * API de Recursos Humanos
 * Rutas:
 *   POST /api/rrhh.php?op=contrato_crear
 *   GET  /api/rrhh.php?op=contrato_obtener&id=...
 *   POST /api/rrhh.php?op=contrato_actualizar&id=...
 *   POST /api/rrhh.php?op=permiso_crear
 *   POST /api/rrhh.php?op=feriado_crear
 *   POST /api/rrhh.php?op=finiquito_crear&id=...
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/rrhh.php';

try {
    rrhh_init_schema();

    $pdo = icontador_db();
    if (!$pdo) {
        throw new Exception('No database connection');
    }

    $op = $_GET['op'] ?? 'listar';
    $empresa_id = (int)($_GET['empresa'] ?? $_POST['empresa'] ?? 0);

    $response = [];

    if ($op === 'contrato_crear') {
        $id = contrato_crear($empresa_id, $_POST);
        $response = ['ok' => 1, 'id' => $id];
    }
    elseif ($op === 'contrato_obtener') {
        $id = (int)($_GET['id'] ?? 0);
        $response = contrato_obtener($id) ?: ['ok' => 0, 'error' => 'Not found'];
    }
    elseif ($op === 'contrato_actualizar') {
        $id = (int)($_GET['id'] ?? 0);
        $ok = contrato_actualizar($id, $_POST);
        $response = ['ok' => $ok ? 1 : 0];
    }
    elseif ($op === 'contrato_por_empleado') {
        $rut = $_GET['rut'] ?? '';
        $response = contrato_por_empleado($empresa_id, $rut) ?: ['ok' => 0];
    }
    elseif ($op === 'permiso_crear') {
        $id = permiso_crear($empresa_id, $_POST);
        $response = ['ok' => 1, 'id' => $id];
    }
    elseif ($op === 'permiso_obtener') {
        $id = (int)($_GET['id'] ?? 0);
        $response = permiso_obtener($id) ?: ['ok' => 0, 'error' => 'Not found'];
    }
    elseif ($op === 'feriado_crear') {
        $id = feriado_crear($empresa_id, $_POST);
        $response = ['ok' => 1, 'id' => $id];
    }
    elseif ($op === 'feriado_obtener') {
        $id = (int)($_GET['id'] ?? 0);
        $response = feriado_obtener($id) ?: ['ok' => 0, 'error' => 'Not found'];
    }
    elseif ($op === 'comprobante_feriado_crear') {
        $id = comprobante_feriado_crear($empresa_id, $_POST);
        $response = ['ok' => 1, 'id' => $id];
    }
    elseif ($op === 'finiquito_crear') {
        $id = finiquito_crear($empresa_id, $_POST);
        $response = ['ok' => 1, 'id' => $id];
    }
    elseif ($op === 'finiquito_obtener') {
        $id = (int)($_GET['id'] ?? 0);
        $response = finiquito_obtener($id) ?: ['ok' => 0, 'error' => 'Not found'];
    }
    elseif ($op === 'finiquito_actualizar') {
        $id = (int)($_GET['id'] ?? 0);
        $ok = finiquito_actualizar($id, $_POST);
        $response = ['ok' => $ok ? 1 : 0];
    }
    else {
        http_response_code(400);
        $response = ['ok' => 0, 'error' => 'Unknown operation'];
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => 0, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
