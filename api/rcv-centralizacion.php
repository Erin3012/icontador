<?php
// Configuración por empresa, vista previa y creación transaccional de vouchers desde RCV.
declare(strict_types=1);
require_once __DIR__ . '/lib/sesion.php';
require __DIR__ . '/db.php';
require __DIR__ . '/lib/vouchers.php';
require __DIR__ . '/lib/rcv.php';
require __DIR__ . '/lib/rcv-centralizacion.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function rcv_cent_responder(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = icontador_db();
    crearEsquema($db);
    rcv_schema($db);
    hon_schema($db);
    rcv_centralizacion_schema($db);
    $usuario = auth_actual();
    if (!$usuario) rcv_cent_responder(401, ['error' => 'Debes iniciar sesión.']);
    $empresa = empresa_actual($db);
    if ($empresa <= 0) rcv_cent_responder(422, ['error' => 'Selecciona una empresa antes de configurar o centralizar RCV.']);
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($metodo === 'GET') {
        if (($_GET['estados_periodos'] ?? '') === '1') rcv_cent_responder(200, rcv_centralizacion_estados_periodos($db, $empresa));
        rcv_cent_responder(200, rcv_centralizacion_configuracion($db, $empresa));
    }
    if (!in_array($metodo, ['PUT', 'POST'], true)) {
        header('Allow: GET, PUT, POST');
        rcv_cent_responder(405, ['error' => 'Método no permitido.']);
    }
    if (!auth_csrf_valido($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) rcv_cent_responder(403, ['error' => 'La sesión de seguridad venció. Recarga la página e inténtalo de nuevo.']);
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) rcv_cent_responder(415, ['error' => 'Se espera JSON.']);
    $entrada = json_decode(file_get_contents('php://input'), true);
    if (!is_array($entrada)) rcv_cent_responder(400, ['error' => 'JSON inválido.']);

    if ($metodo === 'PUT') rcv_cent_responder(200, rcv_centralizacion_guardar_configuracion($db, $empresa, $entrada));
    if (($entrada['accion'] ?? '') === 'previsualizar') {
        $previa = rcv_centralizacion_preparar($db, $empresa, $entrada);
        $items = array_map(static function (array $item): array {
            return ['source_key' => $item['source_key'], 'periodo' => $item['periodo'], 'libro' => $item['libro'], 'rut' => $item['rut'], 'folio' => $item['folio'],
                'tipo' => $item['tipo'] ?? 'rcv',
                'contraparte' => $item['contraparte'], 'total' => $item['total'], 'ajuste_redondeo' => $item['ajuste_redondeo'] ?? 0,
                'solo_pago' => !empty($item['solo_pago']), 'pago_seleccionado' => !empty($item['pago_seleccionado']),
                'voucher_existente_numero' => $item['voucher']['numero'] ?? null,
                'voucher' => $item['voucher'], 'pago_voucher' => $item['pago_voucher'] ?? null];
        }, $previa['items']);
        unset($previa['items']);
        $previa['items'] = $items;
        rcv_cent_responder(200, $previa);
    }
    if (($entrada['accion'] ?? '') === 'centralizar') rcv_cent_responder(200, rcv_centralizacion_confirmar($db, $empresa, $entrada));
    rcv_cent_responder(400, ['error' => 'Acción inválida. Usa previsualizar o centralizar.']);
} catch (RcvCentralizacionError $e) {
    rcv_cent_responder(422, ['error' => $e->getMessage()]);
} catch (ErrorValidacion $e) {
    rcv_cent_responder(422, ['error' => implode(' ', $e->errores), 'errores' => $e->errores]);
} catch (RcvError | EmpresaError $e) {
    rcv_cent_responder(422, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('rcv-centralizacion.php: ' . $e->getMessage());
    rcv_cent_responder(500, ['error' => 'No se pudo procesar la centralización del RCV.']);
}
