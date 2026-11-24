<?php
// Puerta de las pantallas HTML en Apache/cPanel (ver .htaccess): solo las entrega con una sesión aprobada.
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/lib/auth.php';

$ruta = (string) ($_GET['r'] ?? 'index.html');
if (!preg_match('#^(index\.html|vistas\.html|views/[A-Za-z0-9_-]+\.html)$#', $ruta) || !is_file(dirname(__DIR__) . '/' . $ruta)) {
    http_response_code(404);
    exit('Archivo no encontrado');
}
auth_exigir_pagina();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
$archivo = dirname(__DIR__) . '/' . $ruta;
$reportes = [
    'views/reportes.html',
    'views/reportes-libro-balance.html',
    'views/reportes-libro-diario.html',
    'views/reportes-libro-mayor.html',
    'views/reportes-libro-compras.html',
    'views/reportes-libro-ventas.html',
    'views/reportes-libro-honorarios.html',
    'views/reportes-libro-inventario.html',
    'views/reportes-resultados.html',
];
if (in_array($ruta, $reportes, true)) {
    readfile(dirname(__DIR__) . '/views/reportes.html');
    return;
}
if (!str_starts_with($ruta, 'views/') || $ruta === 'views/rcv.html') {
    readfile($archivo);
    return;
}

$html = file_get_contents($archivo);
$cierre = $html === false ? false : stripos($html, '</head>');
if ($cierre === false) {
    echo $html;
    return;
}
$recursosVisuales = str_contains($html, 'libro-cifrax.css')
    ? ''
    : '<link rel="stylesheet" href="/assets/libro-cifrax.css?v=marco-libro-20261118">';
$recursosMoviles = '<link rel="stylesheet" href="/assets/cifrax-movil.css?v=20261118">'
    . '<script defer src="/assets/cifrax-movil.js?v=20261118"></script>';
echo substr_replace($html, $recursosVisuales . $recursosMoviles, $cierre, 0);
