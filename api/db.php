<?php
declare(strict_types=1);
/* Conexión PDO compartida. Usa MySQL cuando hay configuración (api/config.php o variables de entorno)
   y, si no, un archivo SQLite local en data/icontador.sqlite para desarrollo. */
if (!function_exists('icontador_db')) {
function icontador_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $config = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
    $dsn = getenv('ICONTADOR_DB_DSN') ?: ($config['dsn'] ?? '');
    $user = getenv('ICONTADOR_DB_USER') ?: ($config['user'] ?? null);
    $pass = getenv('ICONTADOR_DB_PASS') ?: ($config['password'] ?? null);
    if ($dsn === '') {
        $dir = dirname(__DIR__) . '/data';
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        $dsn = 'sqlite:' . $dir . '/icontador.sqlite';
    }
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $pdo->exec("SET NAMES utf8mb4");
    return $pdo;
}
}
