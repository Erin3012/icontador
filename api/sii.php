<?php
// Sincronización central del RCV con el SII.
//   GET  api/sii.php                 -> tareas de RCV recientes de la empresa elegida
//   POST api/sii.php  {desde, hasta, libros:["compras","ventas"]} -> encola uno o varios meses del RCV
//   POST api/sii.php  {tipo:"datos_empresa", rut, clave}      -> consulta perfil MiSII; la clave se cifra temporalmente
//   GET  api/sii.php?tarea=ID        -> estado y datos de esa consulta (solo quien la pidió)
//   GET  api/sii.php?panel=1         -> tareas y disponibilidad genérica del servicio
//   POST api/sii.php {tipo:"guardar_clave", clave} / {tipo:"eliminar_clave"}
// El enrolamiento y la revocación se operan por CLI fuera del directorio público.
declare(strict_types=1);
require_once __DIR__ . '/lib/sesion.php';
require __DIR__ . '/db.php';
require __DIR__ . '/lib/sii.php';

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
    sii_schema($db);
    rcv_schema($db);
    $usuario = auth_actual();
    if (!$usuario) auth_json(401, ['error' => 'Debes iniciar sesión.']);
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $entrada = $metodo === 'POST' ? json_decode(file_get_contents('php://input'), true) : null;
    if ($metodo === 'GET' && isset($_GET['csrf'])) responder(200, ['csrf' => auth_csrf()]);
    if ($metodo === 'GET' && isset($_GET['tarea'])) {
        $consulta = sii_consulta_empresa($db, (int) $_GET['tarea'], (int) $usuario['id']);
        if (!$consulta) responder(404, ['error' => 'Consulta no encontrada.']);
        responder(200, $consulta);
    }
    if ($metodo === 'POST' && is_array($entrada) && ($entrada['tipo'] ?? '') === 'datos_empresa') {
        if (!sii_transporte_seguro()) responder(400, ['error' => 'La Clave Tributaria solo se puede enviar por HTTPS.']);
        if (!auth_csrf_valido($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) responder(403, ['error' => 'La sesión de seguridad venció. Recarga la página e inténtalo de nuevo.']);
        if (!is_string($entrada['rut'] ?? null) || !is_string($entrada['clave'] ?? null)) responder(400, ['error' => 'Ingresa el RUT y la Clave Tributaria.']);
        responder(202, sii_crear_consulta_empresa($db, (int) $usuario['id'], $entrada['rut'], $entrada['clave']));
    }
    if ($metodo === 'GET' && isset($_GET['servicio'])) {
        responder(200, ['disponible' => sii_servicio_estado($db, false)['conectado']]);
    }
    if ($metodo === 'GET' && isset($_GET['logs'])) {
        responder(200, ['agente' => sii_servicio_estado($db, true), 'eventos' => sii_logs_agente_listar($db)]);
    }
    if ($metodo === 'POST' && is_array($entrada) && in_array(($entrada['tipo'] ?? ''), ['codigo_servicio', 'revocar_servicio'], true)) {
        responder(410, ['error' => 'La administración del servicio se realiza desde la consola del servidor.']);
    }
    $empresa = empresa_actual($db);
    if ($metodo === 'GET') {
        if (isset($_GET['captura'])) {
            $captura = sii_captura_leer($db, (int) $_GET['captura'], (int) $usuario['id'], $empresa);
            if (!$captura) responder(404, ['error' => 'La captura no existe o venció.']);
            header('Content-Type: image/jpeg', true);
            header('Cache-Control: private, no-store, max-age=0', true);
            header('Content-Disposition: inline; filename="rcv-error.jpg"', true);
            header('X-Content-Type-Options: nosniff', true);
            echo $captura['imagen'];
            exit;
        }
        if (isset($_GET['eventos'])) {
            $eventos = sii_eventos_listar($db, (int) $_GET['eventos'], $empresa);
            if ($eventos === null) responder(404, ['error' => 'Tarea no encontrada para la empresa seleccionada.']);
            responder(200, ['eventos' => $eventos]);
        }
        if (isset($_GET['panel'])) responder(200, [
            'tareas' => sii_listar($db, $empresa),
            'administrador' => empresa_es_admin($usuario),
            'clave_configurada' => sii_clave_guardada($db, $empresa),
            'cifrado_disponible' => sii_clave_cifrado_disponible(),
            'sincronizacion_disponible' => sii_servicio_estado($db, false)['conectado'],
        ]);
        if (isset($_GET['clave'])) responder(200, ['configurada' => sii_clave_guardada($db, $empresa)]);
        responder(200, ['tareas' => sii_listar($db, $empresa)]);
    }
    if ($metodo === 'POST') {
        if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) responder(415, ['error' => 'Se espera JSON.']);
        if (!is_array($entrada)) responder(400, ['error' => 'JSON inválido.']);
        if (!auth_csrf_valido($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) responder(403, ['error' => 'La sesión de seguridad venció. Recarga la página e inténtalo de nuevo.']);
        if (($entrada['tipo'] ?? '') === 'guardar_clave') {
            if (!sii_transporte_seguro()) responder(400, ['error' => 'La Clave Tributaria solo se puede enviar por HTTPS.']);
            if (!is_string($entrada['clave'] ?? null)) responder(400, ['error' => 'Ingresa la Clave Tributaria.']);
            sii_clave_guardar($db, $empresa, $entrada['clave']);
            responder(200, ['configurada' => true]);
        }
        if (($entrada['tipo'] ?? '') === 'eliminar_clave') {
            sii_clave_borrar($db, $empresa);
            responder(200, ['configurada' => false]);
        }
        if (($entrada['tipo'] ?? '') === 'clave') {
            responder(410, ['error' => 'Usa la opción de administrar la clave en RCV; no se acepta en este formato.']);
        }
        if (array_key_exists('desde', $entrada) || array_key_exists('hasta', $entrada)) {
            responder(200, sii_crear_rango($db, $empresa, (int) $usuario['id'], $entrada['desde'] ?? null, $entrada['hasta'] ?? null, $entrada['libros'] ?? [], $entrada['honorarios'] ?? []));
        }
        responder(200, sii_crear($db, $empresa, (int) $usuario['id'], $entrada['periodo'] ?? null, $entrada['libros'] ?? null));
    }
    header('Allow: GET, POST');
    responder(405, ['error' => 'Método no permitido.']);
} catch (SiiError | RcvError | EmpresaError $e) {
    responder(422, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('sii.php: ' . $e->getMessage());
    responder(500, ['error' => 'No se pudo acceder a la base de datos.']);
}
