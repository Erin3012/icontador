<?php
// Crea (o asciende y habilita) una cuenta de administrador desde la terminal:
//   php tools/crear-admin.php correo@ejemplo.cl "Nombre"
// Pide la clave sin mostrarla. También acepta la variable ICONTADOR_ADMIN_CLAVE para usarlo sin teclado.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/api/lib/auth.php';

[$_, $email, $nombre] = $argv + [null, null, null];
if (!$email) {
    fwrite(STDERR, "Uso: php tools/crear-admin.php correo@ejemplo.cl \"Nombre\"\n");
    exit(1);
}
$pdo = auth_db();
$consulta = $pdo->prepare('SELECT id FROM usuarios WHERE email = ?');
$consulta->execute([auth_normalizar_email($email)]);
$existente = $consulta->fetchColumn();
if ($existente) {
    $pdo->prepare("UPDATE usuarios SET rol = 'admin', estado = 'activo', aprobado_en = COALESCE(aprobado_en, ?) WHERE id = ?")->execute([auth_ahora(), $existente]);
    echo "La cuenta $email ya existía: ahora es administradora y está habilitada (su clave no cambió).\n";
    exit(0);
}

function leerClave(string $texto): string
{
    echo $texto;
    $oculto = DIRECTORY_SEPARATOR === '/' && stream_isatty(STDIN);
    if ($oculto) shell_exec('stty -echo');
    $clave = rtrim((string) fgets(STDIN), "\r\n");
    if ($oculto) {
        shell_exec('stty echo');
        echo "\n";
    }
    return $clave;
}

$clave = getenv('ICONTADOR_ADMIN_CLAVE') ?: '';
if ($clave === '') {
    $clave = leerClave('Clave: ');
    if ($clave !== leerClave('Repite la clave: ')) {
        fwrite(STDERR, "Las claves no coinciden.\n");
        exit(1);
    }
}
try {
    auth_registrar($pdo, $nombre ?: 'Administrador', $email, $clave, 'admin', 'activo');
} catch (ErrorAuth $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
echo "Administrador $email creado. Ya puedes entrar en /auth/login.php\n";
