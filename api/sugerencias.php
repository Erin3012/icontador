<?php
declare(strict_types=1);
/* API de sugerencias y reportes de problemas.
   GET    api/sugerencias.php                 -> sugerencias propias (todas si eres administrador) y si eres administrador
   GET    api/sugerencias.php?estado=nueva    -> filtro por estado (nueva, en_revision, resuelta)
   GET    api/sugerencias.php?adjunto=N       -> descarga un adjunto (dueño o administrador)
   POST   api/sugerencias.php  multipart: tipo, comentario, pagina, adjuntos[]  -> crea una sugerencia
   PATCH  api/sugerencias.php?id=N  {estado, nota_admin}  -> el administrador cambia estado o nota
   POST y PATCH exigen la cabecera X-Icontador: 1 para que otro sitio no pueda enviarlos con la sesión del usuario. */
require_once __DIR__ . '/lib/sesion.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/sugerencias.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function responder(int $status, array $body): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

function enviar_adjunto(array $a): never {
    $ext = $a['extension'];
    $enLinea = in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'pdf'], true);
    $nombre = preg_replace('/[^\w.\- ]+/u', '_', $a['nombre']) ?: 'adjunto.' . $ext;
    header('Content-Type: ' . SUG_ENTREGA[$ext]);
    header('Content-Length: ' . filesize($a['ruta']));
    header("Content-Disposition: " . ($enLinea ? 'inline' : 'attachment') . "; filename=\"adjunto.$ext\"; filename*=UTF-8''" . rawurlencode($nombre));
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
    readfile($a['ruta']);
    exit;
}

$usuario = auth_exigir_api();
$esAdmin = $usuario['rol'] === 'admin';
try {
    $db = icontador_db();
    sug_schema($db);
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($metodo === 'GET') {
        if (isset($_GET['adjunto'])) {
            $a = sug_adjunto($db, $_GET['adjunto'], $usuario, $esAdmin);
            if (!$a) responder(404, ['error' => 'Adjunto no encontrado.']);
            enviar_adjunto($a);
        }
        $estado = isset($_GET['estado']) ? (string)$_GET['estado'] : null;
        responder(200, ['usuario' => ['nombre' => $usuario['nombre'], 'es_admin' => $esAdmin], 'sugerencias' => sug_listar($db, $usuario, $esAdmin, $estado)]);
    }
    if (($_SERVER['HTTP_X_ICONTADOR'] ?? '') !== '1') responder(403, ['error' => 'Solicitud no permitida.']);
    if ($metodo === 'POST') {
        // Si el envío supera post_max_size, PHP descarta todo el formulario sin avisar.
        if (!$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) responder(413, ['error' => 'Los adjuntos superan el tamaño que acepta el servidor.']);
        $id = sug_crear($db, $usuario, ['navegador' => $_SERVER['HTTP_USER_AGENT'] ?? ''] + $_POST, sug_archivos_subidos($_FILES['adjuntos'] ?? null));
        responder(201, ['id' => $id]);
    }
    if ($metodo === 'PATCH') {
        if (!$esAdmin) responder(403, ['error' => 'Solo un administrador puede cambiar el estado.']);
        $datos = json_decode(file_get_contents('php://input'), true);
        if (!is_array($datos)) responder(400, ['error' => 'JSON inválido.']);
        sug_actualizar($db, $_GET['id'] ?? null, $datos);
        responder(200, ['ok' => true]);
    }
    header('Allow: GET, POST, PATCH');
    responder(405, ['error' => 'Método no permitido.']);
} catch (SugerenciaError $e) {
    responder(422, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('sugerencias.php: ' . $e->getMessage());
    responder(500, ['error' => 'No se pudo guardar o leer las sugerencias.']);
}
