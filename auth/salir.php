<?php
// Cierra la sesión. El botón "Salir" de las pantallas llega por GET desde el mismo sitio y cierra de inmediato;
// si la solicitud viene de otro sitio se pide confirmar con un formulario POST con token, para que nadie pueda forzarlo.
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/lib/auth.php';

function salir_desde_este_sitio(): bool
{
    $sitio = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
    if ($sitio !== null) return $sitio === 'same-origin';
    $origen = parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST);
    return is_string($origen) && strcasecmp($origen, (string) parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) === 0;
}

$post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if (($post && auth_csrf_valido($_POST['csrf'] ?? null)) || (!$post && salir_desde_este_sitio())) {
    auth_cerrar();
    // Borra también lo que la app guarda en el navegador (la empresa seleccionada) para que el siguiente usuario parta de cero.
    header('Clear-Site-Data: "storage"');
    header('Cache-Control: no-store');
    header('Location: /auth/login.php?salio=1', true, 303);
    exit;
}
if (!auth_actual()) {
    header('Location: /auth/login.php', true, 302);
    exit;
}
auth_pagina('Cerrar sesión', '<form method="post" action="/auth/salir.php"><input type="hidden" name="csrf" value="' . auth_h(auth_csrf()) . '">'
    . '<p>¿Quieres cerrar tu sesión?</p><button type="submit">Cerrar sesión</button></form><p><small><a href="/index.html">Volver</a></small></p>');
