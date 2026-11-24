<?php
// Administración de cuentas: aprobar registros pendientes, deshabilitar, dar o quitar rol de administrador
// y elegir qué empresas ve cada usuario (en /auth/usuario-empresas.php).
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/lib/auth.php';
require_once dirname(__DIR__) . '/api/lib/empresas.php';

$admin = auth_exigir_pagina(true);
$pdo = auth_db();
$mensaje = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!auth_csrf_valido($_POST['csrf'] ?? null)) {
        $mensaje = '<p class="aviso error">La página expiró. Vuelve a intentarlo.</p>';
    } else {
        try {
            $accion = (string) ($_POST['accion'] ?? '');
            if ($accion === 'crear') {
                auth_registrar($pdo, (string) ($_POST['nombre'] ?? ''), (string) ($_POST['email'] ?? ''), (string) ($_POST['clave'] ?? ''), (string) ($_POST['rol'] ?? 'usuario'), 'activo');
                header('Location: /auth/usuarios.php?ok=creado', true, 303);
            } else {
                auth_cambiar($pdo, (int) $admin['id'], (int) ($_POST['id'] ?? 0), $accion);
                header('Location: /auth/usuarios.php?ok=1', true, 303);
            }
            exit;
        } catch (ErrorAuth $e) {
            $mensaje = '<p class="aviso error">' . auth_h($e->getMessage()) . '</p>';
        }
    }
}
if (($_GET['ok'] ?? '') === 'creado') $mensaje = '<p class="aviso exito">Usuario creado y habilitado.</p>';
elseif (isset($_GET['ok'])) $mensaje = '<p class="aviso exito">Cambio guardado.</p>';

$csrf = auth_h(auth_csrf());
$boton = fn(int $id, string $accion, string $texto, string $clase = 'sec') =>
    '<form method="post" action="/auth/usuarios.php"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="id" value="' . $id . '">'
    . '<input type="hidden" name="accion" value="' . $accion . '"><button class="' . $clase . '" type="submit">' . $texto . '</button></form> ';
$filas = '';
$pendientes = 0;
empresas_schema($pdo);
$asignadas = [];
foreach ($pdo->query('SELECT usuario_id, COUNT(*) AS n FROM empresa_usuarios GROUP BY usuario_id') as $f) $asignadas[(int) $f['usuario_id']] = (int) $f['n'];
$todos = auth_listar($pdo);
$estadoFiltro = (string) ($_GET['estado'] ?? 'todos');
$busqueda = trim((string) ($_GET['q'] ?? ''));
foreach ($todos as $u) if ($u['estado'] === 'pendiente') $pendientes++;
$usuarios = array_values(array_filter($todos, static function (array $u) use ($estadoFiltro, $busqueda): bool {
    $estadoOk = $estadoFiltro === 'todos' || $u['estado'] === $estadoFiltro;
    $texto = mb_strtolower($u['nombre'] . ' ' . $u['email'] . ' ' . $u['rol'] . ' ' . $u['estado']);
    return $estadoOk && ($busqueda === '' || str_contains($texto, mb_strtolower($busqueda)));
}));
foreach ($usuarios as $u) {
    $id = (int) $u['id'];
    $n = $asignadas[$id] ?? 0;
    $textoEmpresas = $u['rol'] === 'admin' ? 'Configurar vista' : ($n === 0 ? 'Ninguna' : ($n === 1 ? '1 empresa' : "$n empresas"));
    $empresas = '<a href="/auth/usuario-empresas.php?id=' . $id . '">' . $textoEmpresas . '</a>';
    $acciones = '';
    if ($id === (int) $admin['id']) {
        $acciones = '<small>Tu cuenta</small>';
    } else {
        if ($u['estado'] !== 'activo') $acciones .= $boton($id, 'aprobar', $u['estado'] === 'pendiente' ? 'Aprobar' : 'Habilitar', 'ok');
        if ($u['estado'] !== 'deshabilitado') $acciones .= $boton($id, 'deshabilitar', $u['estado'] === 'pendiente' ? 'Rechazar' : 'Deshabilitar', 'peligro');
        $acciones .= $u['rol'] === 'admin' ? $boton($id, 'quitar_admin', 'Quitar admin') : $boton($id, 'hacer_admin', 'Hacer admin');
    }
    $filas .= '<tr><td>' . auth_h($u['nombre']) . '</td><td>' . auth_h($u['email']) . '</td>'
        . '<td><span class="chip">' . ($u['rol'] === 'admin' ? 'Administrador' : 'Usuario') . '</span></td>'
        . '<td><span class="chip ' . auth_h($u['estado']) . '">' . auth_h($u['estado']) . '</span></td>'
        . '<td>' . $empresas . '</td><td><small>' . auth_h($u['creado_en']) . '</small></td><td><small>' . auth_h($u['ultimo_acceso'] ?? '—') . '</small></td><td>' . $acciones . '</td></tr>';
}
$crear = '<details style="margin:16px 0;padding:12px 16px;border:1px solid #d9e0e8;border-radius:8px;background:#f8fafc"><summary style="cursor:pointer;font-weight:700;color:#173759">＋ Crear usuario</summary>'
    . '<form method="post" action="/auth/usuarios.php" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;align-items:end;margin-top:14px">'
    . '<input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="accion" value="crear">'
    . '<label>Nombre<input name="nombre" maxlength="120" required autocomplete="name"></label>'
    . '<label>Correo<input name="email" type="email" maxlength="190" required autocomplete="email"></label>'
    . '<label>Clave inicial<input name="clave" type="password" minlength="8" required autocomplete="new-password"></label>'
    . '<label>Rol<select name="rol"><option value="usuario">Usuario</option><option value="admin">Administrador</option></select></label>'
    . '<button type="submit">Crear y habilitar</button></form><small>La cuenta queda activa de inmediato. Asigna sus empresas desde la columna Empresas.</small></details>';
$filtro = '<form method="get" action="/auth/usuarios.php" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin:16px 0">'
    . '<label>Estado<select name="estado">';
foreach (['todos' => 'Todos', 'pendiente' => 'Pendientes', 'activo' => 'Activos', 'deshabilitado' => 'Deshabilitados'] as $valor => $texto) {
    $filtro .= '<option value="' . $valor . '"' . ($estadoFiltro === $valor ? ' selected' : '') . '>' . $texto . '</option>';
}
$filtro .= '</select></label><label>Buscar<input type="search" name="q" value="' . auth_h($busqueda) . '" placeholder="Nombre, correo o rol"></label><button type="submit">Filtrar</button>'
    . '<a href="/auth/usuarios.php" style="padding:9px 12px">Limpiar</a></form>';
$contenido = '<nav><a href="/index.html">Volver a Cifrax</a> · <a href="/auth/salir.php">Cerrar sesión</a></nav>' . $mensaje
    . '<p>' . ($pendientes ? "<strong>$pendientes</strong> " . ($pendientes === 1 ? 'cuenta espera' : 'cuentas esperan') . ' tu aprobación.' : 'No hay cuentas pendientes.') . '</p>'
    . '<p><small>Asigna empresas a los usuarios o configura el listado de cada administrador desde la columna Empresas.</small></p>'
    . $crear . $filtro
    . '<p><small>Mostrando ' . count($usuarios) . ' de ' . count($todos) . ' cuentas.</small></p>'
    . '<div style="overflow-x:auto"><table><thead><tr><th>Nombre</th><th>Correo</th><th>Rol</th><th>Estado</th><th>Empresas</th><th>Registro</th><th>Último acceso</th><th>Acciones</th></tr></thead><tbody>'
    . ($filas !== '' ? $filas : '<tr><td colspan="8">No hay usuarios que coincidan con el filtro.</td></tr>') . '</tbody></table></div>';
auth_pagina('Usuarios', $contenido, true);
