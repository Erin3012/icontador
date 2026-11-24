<?php
// Asignaciones de usuarios y preferencia visual de administradores.
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/lib/auth.php';
require_once dirname(__DIR__) . '/api/lib/empresas.php';
require_once dirname(__DIR__) . '/api/lib/empresas-vista.php';

$admin = auth_exigir_pagina(true);
$pdo = auth_db();
empresas_schema($pdo);
$usuario = auth_usuario($pdo, (int) ($_GET['id'] ?? $admin['id']));
if (!$usuario) {
    http_response_code(404);
    auth_pagina('Usuario no encontrado', '<p><a href="/auth/usuarios.php">Volver a Usuarios</a></p>');
    exit;
}
$id = (int) $usuario['id'];
$esAdmin = empresa_es_admin($usuario);
$empresas = $pdo->query('SELECT id, razon_social, datos_json, estado FROM empresas ORDER BY razon_social')->fetchAll();
$seleccion = $esAdmin ? empresas_vista_admin($pdo, $id) : empresas_de_usuario($pdo, $id);
$mensaje = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!auth_csrf_valido($_POST['csrf'] ?? null)) {
        $mensaje = '<p class="aviso error">La página expiró. Vuelve a intentarlo.</p>';
    } else {
        $modo = $_POST['modo'] ?? '';
        $recibidas = is_array($_POST['empresas'] ?? null) ? $_POST['empresas'] : [];
        $ids = array_map('intval', array_filter($recibidas, 'is_string'));
        $elegidas = array_values(array_intersect(array_map('intval', array_column($empresas, 'id')), $ids));
        if ($esAdmin && $modo !== 'todas' && $modo !== 'seleccion') {
            $mensaje = '<p class="aviso error">Elige cómo mostrar las empresas.</p>';
        } elseif ($esAdmin && $modo === 'seleccion' && !$elegidas) {
            $seleccion = [];
            $mensaje = '<p class="aviso error">Marca al menos una empresa o elige Mostrar todas.</p>';
        } else {
            if ($esAdmin) {
                empresas_guardar_vista_admin($pdo, $id, $modo === 'todas' ? null : $elegidas);
            } else {
                empresas_asignar_usuario($pdo, $id, $elegidas);
            }
            header('Location: /auth/usuario-empresas.php?id=' . $id . '&ok=1', true, 303);
            exit;
        }
    }
}
if ($mensaje === '' && isset($_GET['ok'])) {
    $mensaje = '<p class="aviso exito">' . ($esAdmin ? 'Vista guardada.' : 'Empresas guardadas.') . '</p>';
}

$asignadas = array_fill_keys($seleccion ?? [], true);
$filas = '';
foreach ($empresas as $e) {
    $eid = (int) $e['id'];
    $rut = (json_decode((string) $e['datos_json'], true) ?: [])['rut'] ?? '';
    $filas .= '<tr data-empresa><td><input type="checkbox" name="empresas[]" value="' . $eid . '" id="e' . $eid . '" style="width:auto"'
        . (isset($asignadas[$eid]) ? ' checked' : '') . '></td>'
        . '<td><label for="e' . $eid . '" style="margin:0;font-weight:normal">' . auth_h($e['razon_social']) . '</label></td>'
        . '<td><small>' . auth_h(is_scalar($rut) ? (string) $rut : '') . '</small></td>'
        . '<td>' . ($e['estado'] === 'inactivo' ? '<span class="chip deshabilitado">inactiva</span>' : '') . '</td></tr>';
}
$modos = $esAdmin ? '<fieldset><legend>Empresas que se muestran al iniciar</legend>'
    . '<label><input type="radio" name="modo" value="todas" style="width:auto"' . ($seleccion === null ? ' checked' : '') . '> Mostrar todas</label>'
    . '<label><input type="radio" name="modo" value="seleccion" style="width:auto"' . ($seleccion !== null ? ' checked' : '') . '> Mostrar solo las marcadas</label></fieldset>' : '';
$instrucciones = $esAdmin
    ? 'Elige las empresas que aparecen en el listado y en el selector. Esta preferencia conserva el acceso de administrador.'
    : 'Esta cuenta verá solo las empresas marcadas.';
auth_pagina('Empresas de ' . $usuario['nombre'], '<nav><a href="/auth/usuarios.php">Volver a Usuarios</a> · <a href="/views/empresas.html">Ir a Empresas</a></nav>' . $mensaje
    . '<p>' . auth_h($usuario['email']) . '</p><p>' . $instrucciones . '</p>'
    . ($filas === '' ? '<p>Todavía no hay empresas.</p>'
        : '<form method="post" action="/auth/usuario-empresas.php?id=' . $id . '"><input type="hidden" name="csrf" value="' . auth_h(auth_csrf()) . '">'
        . $modos . '<div id="busqueda-empresas" hidden><label for="buscar-empresa">Buscar empresa</label>'
        . '<input id="buscar-empresa" type="search" placeholder="Razón social o RUT"><p id="sin-coincidencias" hidden role="status">No hay empresas que coincidan.</p></div>'
        . '<button type="submit">Guardar</button><div style="overflow-x:auto;margin-top:12px"><table><thead><tr><th>Selección</th><th>Empresa</th><th>RUT</th><th>Estado</th></tr></thead><tbody>' . $filas . '</tbody></table></div>'
        . '<button type="submit">Guardar</button></form>'), true, '/assets/usuario-empresas.js');
