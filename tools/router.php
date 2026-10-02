<?php
// Router para el servidor integrado de PHP: php -S 127.0.0.1:4173 tools/router.php
$ruta = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if (preg_match('#(^|/)(node_modules|\.git|reference|tools|data)(/|$)#', $ruta) || preg_match('#^/api/(lib|config|config\.local)\.php$#', $ruta) || str_contains($ruta, '..')) {
    http_response_code(403);
    return true;
}
if ($ruta === '/') {
    $ruta = '/index.html';
}
$archivo = dirname(__DIR__) . $ruta;
if (str_starts_with($ruta, '/api/') && str_ends_with($ruta, '.php') && is_file($archivo)) {
    require $archivo;
    return true;
}
return false;
