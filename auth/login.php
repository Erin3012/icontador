<?php
// Inicio de sesión. Solo entran cuentas aprobadas por un administrador.
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/lib/auth.php';

$volver = auth_destino($_POST['volver'] ?? $_GET['volver'] ?? null);
if (auth_actual()) {
    header('Location: ' . $volver, true, 302);
    exit;
}
$error = '';
$email = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $email = (string) ($_POST['email'] ?? '');
    if (!auth_csrf_valido($_POST['csrf'] ?? null)) {
        $error = 'La página expiró. Vuelve a intentarlo.';
    } else {
        try {
            auth_entrar(auth_verificar(auth_db(), $email, (string) ($_POST['clave'] ?? '')));
            header('Location: ' . $volver, true, 303);
            exit;
        } catch (ErrorAuth $e) {
            $error = $e->getMessage();
        }
    }
}
$aviso = isset($_GET['registrado']) ? '<p class="aviso exito">Tu cuenta fue creada. Podrás entrar cuando el administrador la apruebe.</p>' : '';
if (isset($_GET['salio'])) $aviso = '<p class="aviso exito">Cerraste sesión.</p>';
auth_pagina('Iniciar sesión', $aviso
    . ($error ? '<p class="aviso error">' . auth_h($error) . '</p>' : '')
    . '<form method="post" action="/auth/login.php">'
    . '<input type="hidden" name="csrf" value="' . auth_h(auth_csrf()) . '"><input type="hidden" name="volver" value="' . auth_h($volver) . '">'
    . '<label for="email">Correo</label><input id="email" name="email" type="email" autocomplete="username" required autofocus value="' . auth_h($email) . '">'
    . '<label for="clave">Clave</label><input id="clave" name="clave" type="password" autocomplete="current-password" required>'
    . '<button type="submit">Entrar</button></form>'
    . '<p><small>¿No tienes cuenta? <a href="/auth/registro.php">Regístrate</a></small></p>');
