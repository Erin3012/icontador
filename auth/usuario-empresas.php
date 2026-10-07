<?php
// El administrador elige qué empresas ve un usuario. Los administradores ven todas, así que no se les asignan.
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/lib/auth.php';
require_once dirname(__DIR__) . '/api/lib/empresas.php';

auth_exigir_pagina(true);
$pdo = auth_db();
empresas_schema($pdo);
$usuario = auth_usuario($pdo, (int) ($_GET['id'] ?? 0));
if (!$usuario) {
    http_response_code(404);
    auth_pagina('Usuario no encontrado', '<p><a href="/auth/usuarios.php">Volver a Usuarios</a></p>');
    exit;
}
$id = (int) $usuario['id'];
$mensaje = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!auth_csrf_valido($_POST['csrf'] ?? null)) {
        $mensaje = '<p class="aviso error">La página expiró. Vuelve a intentarlo.</p>';
    } else {
        $elegidas = is_array($_POST['empresas'] ?? null) ? $_POST['empresas'] : [];
        empresas_asignar_usuario($pdo, $id, array_map('intval', array_filter($elegidas, 'is_scalar')));
        header('Location: /auth/usuario-empresas.php?id=' . $id . '&ok=1', true, 303);
        exit;
    }
}
if (isset($_GET['ok'])) $mensaje = '<p class="aviso exito">Empresas guardadas.</p>';

$asignadas = empresas_de_usuario($pdo, $id);
$filas = '';
foreach ($pdo->query('SELECT id, razon_social, datos_json, estado FROM empresas ORDER BY razon_social') as $e) {
    $eid = (int) $e['id'];
    $rut = (json_decode((string) $e['datos_json'], true) ?: [])['rut'] ?? '';
    $filas .= '<tr><td><input type="checkbox" name="empresas[]" value="' . $eid . '" id="e' . $eid . '" style="width:auto"'
        . (in_array($eid, $asignadas, true) ? ' checked' : '') . '></td>'
        . '<td><label for="e' . $eid . '" style="margin:0;font-weight:normal">' . auth_h($e['razon_social']) . '</label></td>'
        . '<td><small>' . auth_h(is_scalar($rut) ? (string) $rut : '') . '</small></td>'
        . '<td>' . ($e['estado'] === 'inactivo' ? '<span class="chip deshabilitado">inactiva</span>' : '') . '</td></tr>';
}
$aviso = $usuario['rol'] === 'admin' ? '<p class="aviso exito">' . auth_h($usuario['nombre']) . ' es administrador y ve todas las empresas.</p>' : '';
auth_pagina('Empresas de ' . $usuario['nombre'], '<nav><a href="/auth/usuarios.php">Volver a Usuarios</a></nav>' . $mensaje . $aviso
    . '<p>' . auth_h($usuario['email']) . ' verá solo las empresas marcadas.</p>'
    . ($filas === '' ? '<p>Todavía no hay empresas.</p>'
        : '<form method="post" action="/auth/usuario-empresas.php?id=' . $id . '"><input type="hidden" name="csrf" value="' . auth_h(auth_csrf()) . '">'
        . '<table><thead><tr><th></th><th>Empresa</th><th>RUT</th><th></th></tr></thead><tbody>' . $filas . '</tbody></table>'
        . '<button type="submit">Guardar</button></form>'), true);
