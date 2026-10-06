<?php
// Usuarios, registro, inicio de sesión y aprobación por un administrador. Conexión en api/db.php.
// Estados: 'pendiente' (recién registrado), 'activo' (aprobado) y 'deshabilitado'. Roles: 'usuario' y 'admin'.
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';

const AUTH_ESTADOS = ['pendiente', 'activo', 'deshabilitado'];
const AUTH_ROLES = ['usuario', 'admin'];
const AUTH_CLAVE_MINIMA = 8;

class ErrorAuth extends Exception
{
}

function auth_schema(PDO $pdo): void
{
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $id = $mysql ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $motor = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios (
        id $id,
        email VARCHAR(190) NOT NULL UNIQUE,
        nombre VARCHAR(120) NOT NULL,
        clave_hash VARCHAR(255) NOT NULL,
        rol VARCHAR(10) NOT NULL DEFAULT 'usuario',
        estado VARCHAR(15) NOT NULL DEFAULT 'pendiente',
        creado_en VARCHAR(25) NOT NULL,
        aprobado_en VARCHAR(25) NULL,
        ultimo_acceso VARCHAR(25) NULL
    )$motor");
}

function auth_db(): PDO
{
    static $listo = false;
    $pdo = icontador_db();
    if (!$listo) {
        auth_schema($pdo);
        $listo = true;
    }
    return $pdo;
}

function auth_ahora(): string
{
    return date('Y-m-d H:i:s');
}

function auth_normalizar_email(string $email): string
{
    return mb_strtolower(trim($email));
}

/** Crea un usuario. Los registros públicos quedan 'pendiente'; el script de administración crea admins 'activo'. */
function auth_registrar(PDO $pdo, string $nombre, string $email, string $clave, string $rol = 'usuario', string $estado = 'pendiente'): int
{
    $nombre = trim($nombre);
    $email = auth_normalizar_email($email);
    $errores = [];
    if ($nombre === '' || mb_strlen($nombre) > 120) $errores[] = 'Ingresa tu nombre (máximo 120 caracteres).';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) $errores[] = 'Ingresa un correo válido.';
    if (strlen($clave) < AUTH_CLAVE_MINIMA) $errores[] = 'La clave debe tener al menos ' . AUTH_CLAVE_MINIMA . ' caracteres.';
    if (!in_array($rol, AUTH_ROLES, true) || !in_array($estado, AUTH_ESTADOS, true)) $errores[] = 'Rol o estado no válido.';
    if (!$errores) {
        $existe = $pdo->prepare('SELECT 1 FROM usuarios WHERE email = ?');
        $existe->execute([$email]);
        if ($existe->fetchColumn()) $errores[] = 'Ya existe una cuenta con ese correo.';
    }
    if ($errores) throw new ErrorAuth(implode(' ', $errores));
    $pdo->prepare('INSERT INTO usuarios (email, nombre, clave_hash, rol, estado, creado_en, aprobado_en) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$email, $nombre, password_hash($clave, PASSWORD_DEFAULT), $rol, $estado, auth_ahora(), $estado === 'activo' ? auth_ahora() : null]);
    return (int) $pdo->lastInsertId();
}

/** Comprueba correo y clave. Devuelve el usuario solo si está activo; si no, lanza ErrorAuth con el motivo. */
function auth_verificar(PDO $pdo, string $email, string $clave): array
{
    $consulta = $pdo->prepare('SELECT * FROM usuarios WHERE email = ?');
    $consulta->execute([auth_normalizar_email($email)]);
    $usuario = $consulta->fetch();
    if (!$usuario || !password_verify($clave, $usuario['clave_hash'])) {
        usleep(300000);
        throw new ErrorAuth('Correo o clave incorrectos.');
    }
    if ($usuario['estado'] === 'pendiente') throw new ErrorAuth('Tu cuenta está pendiente de aprobación. Podrás entrar cuando el administrador la apruebe.');
    if ($usuario['estado'] !== 'activo') throw new ErrorAuth('Tu cuenta está deshabilitada. Contacta al administrador.');
    if (password_needs_rehash($usuario['clave_hash'], PASSWORD_DEFAULT)) {
        $pdo->prepare('UPDATE usuarios SET clave_hash = ? WHERE id = ?')->execute([password_hash($clave, PASSWORD_DEFAULT), $usuario['id']]);
    }
    $pdo->prepare('UPDATE usuarios SET ultimo_acceso = ? WHERE id = ?')->execute([auth_ahora(), $usuario['id']]);
    return $usuario;
}

function auth_usuario(PDO $pdo, int $id): ?array
{
    $consulta = $pdo->prepare('SELECT id, email, nombre, rol, estado, creado_en, aprobado_en, ultimo_acceso FROM usuarios WHERE id = ?');
    $consulta->execute([$id]);
    return $consulta->fetch() ?: null;
}

function auth_listar(PDO $pdo): array
{
    return $pdo->query("SELECT id, email, nombre, rol, estado, creado_en, aprobado_en, ultimo_acceso FROM usuarios
        ORDER BY CASE estado WHEN 'pendiente' THEN 0 WHEN 'activo' THEN 1 ELSE 2 END, creado_en DESC")->fetchAll();
}

/** Cambios que hace un administrador sobre otra cuenta: aprobar, deshabilitar, hacer_admin, quitar_admin. */
function auth_cambiar(PDO $pdo, int $adminId, int $id, string $accion): void
{
    if ($id === $adminId) throw new ErrorAuth('No puedes cambiar tu propia cuenta.');
    $usuario = auth_usuario($pdo, $id);
    if (!$usuario) throw new ErrorAuth('El usuario no existe.');
    match ($accion) {
        'aprobar' => $pdo->prepare("UPDATE usuarios SET estado = 'activo', aprobado_en = ? WHERE id = ?")->execute([auth_ahora(), $id]),
        'deshabilitar' => $pdo->prepare("UPDATE usuarios SET estado = 'deshabilitado' WHERE id = ?")->execute([$id]),
        'hacer_admin' => $pdo->prepare("UPDATE usuarios SET rol = 'admin' WHERE id = ?")->execute([$id]),
        'quitar_admin' => $pdo->prepare("UPDATE usuarios SET rol = 'usuario' WHERE id = ?")->execute([$id]),
        default => throw new ErrorAuth('Acción no válida.'),
    };
}

/* ---------- Sesión ---------- */

function auth_iniciar_sesion(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off'
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_name('icontador_sesion');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

/** Usuario activo de la sesión actual. Se relee de la base en cada solicitud para que deshabilitar tenga efecto de inmediato. */
function auth_actual(): ?array
{
    auth_iniciar_sesion();
    $id = $_SESSION['usuario_id'] ?? null;
    if (!is_int($id)) return null;
    $usuario = auth_usuario(auth_db(), $id);
    if (!$usuario || $usuario['estado'] !== 'activo') {
        auth_cerrar();
        return null;
    }
    return $usuario;
}

function auth_entrar(array $usuario): void
{
    auth_iniciar_sesion();
    session_regenerate_id(true);
    $_SESSION = ['usuario_id' => (int) $usuario['id']];
}

function auth_cerrar(): void
{
    auth_iniciar_sesion();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => $p['samesite']]);
    }
    session_destroy();
}

function auth_csrf(): string
{
    auth_iniciar_sesion();
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function auth_csrf_valido(?string $token): bool
{
    auth_iniciar_sesion();
    return is_string($token) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

/** Primera línea de cada api/*.php: corta con 401 (sin sesión) o 403 (no admin) en JSON. */
function auth_exigir_api(bool $soloAdmin = false): array
{
    try {
        $usuario = auth_actual();
    } catch (Throwable $e) {
        error_log('iContador auth: ' . $e);
        auth_json(500, ['error' => 'No se pudo comprobar la sesión.', 'errores' => ['No se pudo comprobar la sesión.']]);
    }
    if (!$usuario) auth_json(401, ['error' => 'Debes iniciar sesión.', 'errores' => ['Debes iniciar sesión.']]);
    if ($soloAdmin && $usuario['rol'] !== 'admin') auth_json(403, ['error' => 'Solo un administrador puede hacer esto.', 'errores' => ['Solo un administrador puede hacer esto.']]);
    return $usuario;
}

/** Para pantallas: sin sesión redirige al inicio de sesión y vuelve a la página pedida después. */
function auth_exigir_pagina(bool $soloAdmin = false): array
{
    $usuario = auth_actual();
    if (!$usuario) {
        $volver = $_SERVER['REQUEST_URI'] ?? '/';
        header('Location: /auth/login.php?volver=' . rawurlencode($volver), true, 302);
        exit;
    }
    if ($soloAdmin && $usuario['rol'] !== 'admin') {
        http_response_code(403);
        auth_pagina('Acceso restringido', '<p>Solo un administrador puede ver esta página.</p><p><a href="/index.html">Volver al inicio</a></p>');
        exit;
    }
    return $usuario;
}

/** Destino seguro después de iniciar sesión: solo rutas locales. */
function auth_destino(?string $volver): string
{
    if (!is_string($volver) || $volver === '' || $volver[0] !== '/' || str_starts_with($volver, '//') || str_contains($volver, '\\')
        || str_starts_with($volver, '/auth/')) {
        return '/index.html';
    }
    return $volver;
}

function auth_json(int $estado, array $cuerpo): never
{
    http_response_code($estado);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- Pantallas de cuenta (login, registro, usuarios) ---------- */

function auth_h(?string $texto): string
{
    return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
}

function auth_pagina(string $titulo, string $cuerpo, bool $ancho = false): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    $max = $ancho ? '980px' : '420px';
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . auth_h($titulo) . ' · iContador</title><style>'
        . "body{font:16px system-ui,sans-serif;margin:0;padding:48px 16px;color:#24354a;background:#f5f7fa}"
        . "main{max-width:$max;margin:0 auto;background:#fff;border:1px solid #d9e0e8;border-radius:8px;padding:24px 28px}"
        . 'h1{color:#173759;font-size:24px;margin:0 0 16px}a{color:#1467b3}label{display:block;margin:12px 0 4px;font-weight:600}'
        . 'input{width:100%;box-sizing:border-box;padding:9px 10px;border:1px solid #b9c5d3;border-radius:6px;font:inherit}'
        . 'button{margin-top:18px;padding:9px 16px;border:0;border-radius:6px;background:#1467b3;color:#fff;font:inherit;cursor:pointer}'
        . 'button.sec{background:#e8edf3;color:#24354a;margin:0}button.peligro{background:#b42318;margin:0}button.ok{background:#157f3c;margin:0}'
        . '.aviso{padding:10px 12px;border-radius:6px;margin:0 0 12px}.error{background:#fde8e7;color:#8a1c12}.exito{background:#e5f5ea;color:#145c2c}'
        . 'table{width:100%;border-collapse:collapse;font-size:14px}th,td{text-align:left;padding:8px;border-bottom:1px solid #e3e8ef;vertical-align:middle}'
        . 'td form{display:inline}.chip{display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;background:#e8edf3}'
        . '.pendiente{background:#fff3d4}.activo{background:#e5f5ea}.deshabilitado{background:#fde8e7}nav{margin-bottom:16px;font-size:14px}'
        . 'small{color:#526579}</style></head><body><main><h1>' . auth_h($titulo) . '</h1>' . $cuerpo . '</main></body></html>';
}
