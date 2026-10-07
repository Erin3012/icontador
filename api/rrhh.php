<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/sesion.php';
/* API de Recursos Humanos
   POST /api/rrhh.php?op=contrato_crear  {empleado_rut, empleado_nombre, ...}
   GET  /api/rrhh.php?op=contrato_obtener&id=N
   POST /api/rrhh.php?op=contrato_actualizar&id=N
   POST /api/rrhh.php?op=permiso_crear  {empleado_rut, ...}
   POST /api/rrhh.php?op=feriado_crear  {empleado_rut, ...}
   POST /api/rrhh.php?op=finiquito_crear  {empleado_rut, ...}
*/
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/rrhh.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function responder(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = icontador_db();
    rrhh_schema($db);
    $empresa = empresa_actual($db);
    $op = $_GET['op'] ?? 'listar';
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($op === 'contrato_crear' && $metodo === 'POST') {
        $id = contrato_crear($db, $empresa, $_POST);
        responder(201, ['ok' => 1, 'id' => $id]);
    }
    elseif ($op === 'contrato_obtener' && $metodo === 'GET') {
        $id = (int)($_GET['id'] ?? 0);
        $contrato = contrato_obtener($db, $id, $empresa);
        responder(200, $contrato ? $contrato : ['ok' => 0, 'error' => 'No encontrado']);
    }
    elseif ($op === 'contrato_actualizar' && $metodo === 'POST') {
        $id = (int)($_GET['id'] ?? 0);
        $ok = contrato_actualizar($db, $id, $_POST, $empresa);
        responder(200, ['ok' => $ok ? 1 : 0]);
    }
    elseif ($op === 'permiso_crear' && $metodo === 'POST') {
        $id = permiso_crear($db, $empresa, $_POST);
        responder(201, ['ok' => 1, 'id' => $id]);
    }
    elseif ($op === 'permiso_obtener' && $metodo === 'GET') {
        $id = (int)($_GET['id'] ?? 0);
        $permiso = permiso_obtener($db, $id, $empresa);
        responder(200, $permiso ? $permiso : ['ok' => 0, 'error' => 'No encontrado']);
    }
    elseif ($op === 'feriado_crear' && $metodo === 'POST') {
        $id = feriado_crear($db, $empresa, $_POST);
        responder(201, ['ok' => 1, 'id' => $id]);
    }
    elseif ($op === 'feriado_obtener' && $metodo === 'GET') {
        $id = (int)($_GET['id'] ?? 0);
        $feriado = feriado_obtener($db, $id, $empresa);
        responder(200, $feriado ? $feriado : ['ok' => 0, 'error' => 'No encontrado']);
    }
    elseif ($op === 'comprobante_feriado_crear' && $metodo === 'POST') {
        $id = comprobante_feriado_crear($db, $empresa, $_POST);
        responder(201, ['ok' => 1, 'id' => $id]);
    }
    elseif ($op === 'finiquito_crear' && $metodo === 'POST') {
        $id = finiquito_crear($db, $empresa, $_POST);
        responder(201, ['ok' => 1, 'id' => $id]);
    }
    elseif ($op === 'finiquito_obtener' && $metodo === 'GET') {
        $id = (int)($_GET['id'] ?? 0);
        $finiquito = finiquito_obtener($db, $id, $empresa);
        responder(200, $finiquito ? $finiquito : ['ok' => 0, 'error' => 'No encontrado']);
    }
    elseif ($op === 'finiquito_actualizar' && $metodo === 'POST') {
        $id = (int)($_GET['id'] ?? 0);
        $ok = finiquito_actualizar($db, $id, $_POST, $empresa);
        responder(200, ['ok' => $ok ? 1 : 0]);
    }
    else {
        header('Allow: GET, POST');
        responder(405, ['ok' => 0, 'error' => 'Operación no permitida']);
    }
} catch (Throwable $e) {
    error_log('rrhh.php: ' . $e->getMessage());
    responder(500, ['ok' => 0, 'error' => 'Error al acceder a la base de datos']);
}
