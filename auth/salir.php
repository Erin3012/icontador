<?php
// Cierra la sesión (POST con token para que otro sitio no pueda forzarlo).
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/lib/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && auth_csrf_valido($_POST['csrf'] ?? null)) {
    auth_cerrar();
    header('Location: /auth/login.php?salio=1', true, 303);
    exit;
}
if (!auth_actual()) {
    header('Location: /auth/login.php', true, 302);
    exit;
}
auth_pagina('Cerrar sesión', '<form method="post" action="/auth/salir.php"><input type="hidden" name="csrf" value="' . auth_h(auth_csrf()) . '">'
    . '<p>¿Quieres cerrar tu sesión?</p><button type="submit">Cerrar sesión</button></form><p><small><a href="/index.html">Volver</a></small></p>');
