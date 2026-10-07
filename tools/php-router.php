<?php
// Servidor local con PHP: php -S 127.0.0.1:4173 tools/php-router.php
// Sirve la copia igual que tools/serve.cjs, ejecuta los endpoints de api/ y las pantallas de cuenta de auth/,
// y exige iniciar sesión para ver las pantallas HTML.
$root = dirname(__DIR__);
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if ($path === '/') $path = '/index.html';
if (str_contains($path, "\0") || str_contains($path, '..') || preg_match('#/(node_modules|\.git|reference|tools|data|samples)(/|$)#', $path)) {
    http_response_code(403);
    return true;
}
if (preg_match('#^/(api|auth)/[a-z0-9_-]+\.php$#', $path) && is_file($root . $path) && $path !== '/api/db.php' && !str_starts_with($path, '/api/config')) {
    require $root . $path;
    return true;
}
if (str_ends_with($path, '.php') || str_starts_with($path, '/api/')) {
    http_response_code(404);
    return true;
}
$file = $root . $path;
if (!is_file($file)) {
    http_response_code(404);
    echo 'Archivo no encontrado';
    return true;
}
// Las pantallas solo se entregan con una sesión aprobada (en Apache lo hace auth/vista.php vía .htaccess).
if (str_ends_with($path, '.html')) {
    require_once $root . '/api/lib/auth.php';
    auth_exigir_pagina();
}
header('Cache-Control: no-store');
return false;
