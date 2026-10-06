<?php
// Administración de cuentas: aprobar registros pendientes, deshabilitar y dar o quitar rol de administrador.
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/lib/auth.php';

$admin = auth_exigir_pagina(true);
$pdo = auth_db();
$mensaje = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!auth_csrf_valido($_POST['csrf'] ?? null)) {
        $mensaje = '<p class="aviso error">La página expiró. Vuelve a intentarlo.</p>';
    } else {
        try {
            auth_cambiar($pdo, (int) $admin['id'], (int) ($_POST['id'] ?? 0), (string) ($_POST['accion'] ?? ''));
            header('Location: /auth/usuarios.php?ok=1', true, 303);
            exit;
        } catch (ErrorAuth $e) {
            $mensaje = '<p class="aviso error">' . auth_h($e->getMessage()) . '</p>';
        }
    }
}
if (isset($_GET['ok'])) $mensaje = '<p class="aviso exito">Cambio guardado.</p>';

$csrf = auth_h(auth_csrf());
$boton = fn(int $id, string $accion, string $texto, string $clase = 'sec') =>
    '<form method="post" action="/auth/usuarios.php"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="id" value="' . $id . '">'
    . '<input type="hidden" name="accion" value="' . $accion . '"><button class="' . $clase . '" type="submit">' . $texto . '</button></form> ';
$filas = '';
$pendientes = 0;
foreach (auth_listar($pdo) as $u) {
    $id = (int) $u['id'];
    $pendientes += $u['estado'] === 'pendiente' ? 1 : 0;
    $acciones = '';
    if ($id === (int) $admin['id']) {
        $acciones = '<small>Tu cuenta</small>';
    } else {
        if ($u['estado'] !== 'activo') $acciones .= $boton($id, 'aprobar', $u['estado'] === 'pendiente' ? 'Aprobar' : 'Habilitar', 'ok');
        if ($u['estado'] !== 'deshabilitado') $acciones .= $boton($id, 'deshabilitar', $u['estado'] === 'pendiente' ? 'Rechazar' : 'Deshabilitar', 'peligro');
        $acciones .= $u['rol'] === 'admin' ? $boton($id, 'quitar_admin', 'Quitar admin') : $boton($id, 'hacer_admin', 'Hacer admin');
    }
    $filas .= '<tr><td>' . auth_h($u['nombre']) . '</td><td>' . auth_h($u['email']) . '</td>'
        . '<td><span class="chip ' . auth_h($u['estado']) . '">' . auth_h($u['estado']) . '</span>' . ($u['rol'] === 'admin' ? ' <span class="chip">admin</span>' : '') . '</td>'
        . '<td><small>' . auth_h($u['creado_en']) . '</small></td><td><small>' . auth_h($u['ultimo_acceso'] ?? '—') . '</small></td><td>' . $acciones . '</td></tr>';
}
auth_pagina('Usuarios', '<nav><a href="/index.html">Volver a iContador</a> · <a href="/auth/salir.php">Cerrar sesión</a></nav>' . $mensaje
    . '<p>' . ($pendientes ? "<strong>$pendientes</strong> " . ($pendientes === 1 ? 'cuenta espera' : 'cuentas esperan') . ' tu aprobación.' : 'No hay cuentas pendientes.') . '</p>'
    . '<table><thead><tr><th>Nombre</th><th>Correo</th><th>Estado</th><th>Registro</th><th>Último acceso</th><th></th></tr></thead><tbody>' . $filas . '</tbody></table>', true);
