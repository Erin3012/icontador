<?php
// Base vacía para las pruebas PHP: SQLite en memoria, o una base MySQL/MariaDB nueva si se define
// ICONTADOR_TEST_MYSQL (por ejemplo "host=127.0.0.1;port=3306" con ICONTADOR_TEST_USER y ICONTADOR_TEST_PASS).
declare(strict_types=1);

function base_prueba(): PDO
{
    $opciones = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
    $mysql = getenv('ICONTADOR_TEST_MYSQL');
    if (!$mysql) {
        return new PDO('sqlite::memory:', null, null, $opciones);
    }
    $usuario = getenv('ICONTADOR_TEST_USER') ?: 'root';
    $clave = getenv('ICONTADOR_TEST_PASS') ?: '';
    $nombre = 'icontador_prueba_' . bin2hex(random_bytes(4));
    (new PDO("mysql:$mysql", $usuario, $clave, $opciones))->exec("CREATE DATABASE `$nombre` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    register_shutdown_function(function () use ($mysql, $usuario, $clave, $nombre, $opciones) {
        (new PDO("mysql:$mysql", $usuario, $clave, $opciones))->exec("DROP DATABASE IF EXISTS `$nombre`");
    });
    $pdo = new PDO("mysql:$mysql;dbname=$nombre;charset=utf8mb4", $usuario, $clave, $opciones);
    return $pdo;
}

function es_mysql(PDO $pdo): bool
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
}
