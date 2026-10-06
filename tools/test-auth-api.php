<?php
// Pruebas de registro, aprobación e inicio de sesión con SQLite en memoria: php tools/test-auth-api.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/auth.php';

$fallas = 0;
function comprobar(bool $ok, string $mensaje): void
{
    global $fallas;
    echo ($ok ? 'ok   ' : 'FALLA ') . $mensaje . PHP_EOL;
    $fallas += $ok ? 0 : 1;
}
function motivo(callable $f): string
{
    try {
        $f();
    } catch (ErrorAuth $e) {
        return $e->getMessage();
    }
    return '';
}

$pdo = base_prueba();
auth_schema($pdo);

$admin = auth_registrar($pdo, 'Mary', 'Mary@Ejemplo.cl', 'clave-segura', 'admin', 'activo');
$ana = auth_registrar($pdo, 'Ana', 'ana@ejemplo.cl', 'otra-clave-1');
comprobar(auth_usuario($pdo, $ana)['estado'] === 'pendiente', 'un registro nuevo queda pendiente');
comprobar(!str_contains((string) $pdo->query("SELECT clave_hash FROM usuarios WHERE id = $ana")->fetchColumn(), 'otra-clave-1'), 'la clave se guarda con hash');
comprobar(str_contains(motivo(fn() => auth_registrar($pdo, 'Ana 2', 'ANA@ejemplo.cl ', 'otra-clave-2')), 'Ya existe'), 'rechaza un correo repetido sin importar mayúsculas');
comprobar(str_contains(motivo(fn() => auth_registrar($pdo, 'X', 'no-es-correo', 'corta')), 'correo válido'), 'valida el correo');
comprobar(str_contains(motivo(fn() => auth_registrar($pdo, 'X', 'x@ejemplo.cl', 'corta')), 'al menos 8'), 'exige clave de 8 caracteres');

comprobar(str_contains(motivo(fn() => auth_verificar($pdo, 'ana@ejemplo.cl', 'otra-clave-1')), 'pendiente'), 'una cuenta pendiente no puede entrar');
comprobar(str_contains(motivo(fn() => auth_verificar($pdo, 'ana@ejemplo.cl', 'mala')), 'incorrectos'), 'rechaza una clave incorrecta');
comprobar(auth_verificar($pdo, 'mary@ejemplo.cl', 'clave-segura')['rol'] === 'admin', 'el admin entra con su correo en minúsculas');

auth_cambiar($pdo, $admin, $ana, 'aprobar');
comprobar(auth_verificar($pdo, 'ana@ejemplo.cl', 'otra-clave-1')['id'] == $ana, 'después de aprobarla, Ana entra');
comprobar(auth_usuario($pdo, $ana)['ultimo_acceso'] !== null, 'registra el último acceso');
auth_cambiar($pdo, $admin, $ana, 'deshabilitar');
comprobar(str_contains(motivo(fn() => auth_verificar($pdo, 'ana@ejemplo.cl', 'otra-clave-1')), 'deshabilitada'), 'una cuenta deshabilitada no puede entrar');
comprobar(str_contains(motivo(fn() => auth_cambiar($pdo, $admin, $admin, 'deshabilitar')), 'propia'), 'el admin no puede deshabilitarse a sí mismo');
comprobar(str_contains(motivo(fn() => auth_cambiar($pdo, $admin, $ana, 'borrar')), 'no válida'), 'rechaza acciones desconocidas');
auth_cambiar($pdo, $admin, $ana, 'hacer_admin');
comprobar(auth_usuario($pdo, $ana)['rol'] === 'admin', 'puede dar rol de administrador');
comprobar(auth_listar($pdo)[0]['estado'] === 'activo' && count(auth_listar($pdo)) === 2, 'lista las cuentas');

comprobar(auth_destino('/views/voucher.html?x=1') === '/views/voucher.html?x=1', 'vuelve a la página pedida tras entrar');
foreach (['https://otro.cl', '//otro.cl', '/\\otro.cl', 'javascript:alert(1)', '/auth/salir.php'] as $malo) {
    comprobar(auth_destino($malo) === '/index.html', "no redirige fuera del sitio: $malo");
}

// Cada endpoint de api/ debe exigir sesión, incluidos los que se agreguen después.
foreach (glob(dirname(__DIR__) . '/api/*.php') as $archivo) {
    $nombre = basename($archivo);
    if ($nombre === 'db.php' || str_starts_with($nombre, 'config')) continue;
    comprobar(str_contains(file_get_contents($archivo), "require_once __DIR__ . '/lib/sesion.php';"), "api/$nombre exige sesión");
}

echo $fallas ? "$fallas pruebas fallaron\n" : "Todas las pruebas pasaron\n";
exit($fallas ? 1 : 0);
