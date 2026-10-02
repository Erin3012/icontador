<?php
// Conexión a la base de datos.
// Por defecto se usa SQLite en data/icontador.sqlite (no requiere instalar nada).
// Para MySQL/MariaDB copie este archivo como config.local.php y cambie los valores, o defina
// las variables de entorno ICONTADOR_DB_DSN, ICONTADOR_DB_USER e ICONTADOR_DB_PASS. Ejemplo:
//   'dsn' => 'mysql:host=127.0.0.1;dbname=icontador;charset=utf8mb4', 'user' => 'root', 'pass' => ''
if (is_file(__DIR__ . '/config.local.php')) {
    return require __DIR__ . '/config.local.php';
}
return [
    'dsn' => getenv('ICONTADOR_DB_DSN') ?: 'sqlite:' . dirname(__DIR__) . '/data/icontador.sqlite',
    'user' => getenv('ICONTADOR_DB_USER') ?: null,
    'pass' => getenv('ICONTADOR_DB_PASS') ?: null,
];
