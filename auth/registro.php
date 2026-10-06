<?php
// Registro público. La cuenta queda pendiente hasta que un administrador la apruebe en /auth/usuarios.php.
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/lib/auth.php';

if (auth_actual()) {
    header('Location: /index.html', true, 302);
    exit;
}
$error = '';
$nombre = '';
$email = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $nombre = (string) ($_POST['nombre'] ?? '');
    $email = (string) ($_POST['email'] ?? '');
    $clave = (string) ($_POST['clave'] ?? '');
    if (!auth_csrf_valido($_POST['csrf'] ?? null)) {
        $error = 'La página expiró. Vuelve a intentarlo.';
    } elseif ($clave !== (string) ($_POST['clave2'] ?? '')) {
        $error = 'Las claves no coinciden.';
    } else {
        try {
            auth_registrar(auth_db(), $nombre, $email, $clave);
            header('Location: /auth/login.php?registrado=1', true, 303);
            exit;
        } catch (ErrorAuth $e) {
            $error = $e->getMessage();
        }
    }
}
auth_pagina('Crear cuenta', ($error ? '<p class="aviso error">' . auth_h($error) . '</p>' : '')
    . '<form method="post" action="/auth/registro.php"><input type="hidden" name="csrf" value="' . auth_h(auth_csrf()) . '">'
    . '<label for="nombre">Nombre</label><input id="nombre" name="nombre" autocomplete="name" required maxlength="120" value="' . auth_h($nombre) . '">'
    . '<label for="email">Correo</label><input id="email" name="email" type="email" autocomplete="email" required maxlength="190" value="' . auth_h($email) . '">'
    . '<label for="clave">Clave</label><input id="clave" name="clave" type="password" autocomplete="new-password" required minlength="' . AUTH_CLAVE_MINIMA . '">'
    . '<label for="clave2">Repite la clave</label><input id="clave2" name="clave2" type="password" autocomplete="new-password" required minlength="' . AUTH_CLAVE_MINIMA . '">'
    . '<button type="submit">Crear cuenta</button></form>'
    . '<p><small>Tu acceso quedará pendiente hasta que el administrador lo apruebe. ¿Ya tienes cuenta? <a href="/auth/login.php">Inicia sesión</a></small></p>');
