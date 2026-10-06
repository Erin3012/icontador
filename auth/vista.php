<?php
// Puerta de las pantallas HTML en Apache/cPanel (ver .htaccess): solo las entrega con una sesión aprobada.
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/lib/auth.php';

$ruta = (string) ($_GET['r'] ?? 'index.html');
if (!preg_match('#^(index\.html|views/[A-Za-z0-9_-]+\.html)$#', $ruta) || !is_file(dirname(__DIR__) . '/' . $ruta)) {
    http_response_code(404);
    exit('Archivo no encontrado');
}
auth_exigir_pagina();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
readfile(dirname(__DIR__) . '/' . $ruta);
