<?php
declare(strict_types=1);
/* Tablas de las empresas importadas. Se crean al primer uso para que el selector funcione con una base nueva. */
require_once dirname(__DIR__) . '/db.php';

function empresas_schema(PDO $db): void {
    $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $id = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $texto = $mysql ? 'VARCHAR(191)' : 'TEXT';
    $largo = $mysql ? 'LONGTEXT' : 'TEXT';
    $motor = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    $db->exec("CREATE TABLE IF NOT EXISTS empresas (id $id, origen_id $texto NOT NULL UNIQUE, razon_social $texto NOT NULL, datos_json $largo NOT NULL, actualizado $texto NOT NULL)$motor");
    $db->exec("CREATE TABLE IF NOT EXISTS importacion_vistas (id $id, empresa_id INTEGER NOT NULL, vista $texto NOT NULL, datos_json $largo NOT NULL, actualizado $texto NOT NULL, UNIQUE(empresa_id,vista), FOREIGN KEY(empresa_id) REFERENCES empresas(id))$motor");
    $db->exec("CREATE TABLE IF NOT EXISTS importacion_registros (id $id, empresa_id INTEGER NOT NULL, vista $texto NOT NULL, tabla $texto NOT NULL, huella CHAR(64) NOT NULL, datos_json $largo NOT NULL, UNIQUE(empresa_id,vista,tabla,huella), FOREIGN KEY(empresa_id) REFERENCES empresas(id))$motor");
}
