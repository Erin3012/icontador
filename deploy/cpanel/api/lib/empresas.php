<?php
declare(strict_types=1);
/* Tablas de las empresas importadas. Se crean al primer uso para que el selector funcione con una base nueva. */
require_once dirname(__DIR__) . '/db.php';

function empresas_schema(PDO $db): void {
    $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $id = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $texto = $mysql ? 'VARCHAR(191)' : 'TEXT';
    $largo = $mysql ? 'LONGTEXT' : 'TEXT';
    $estado = $mysql ? 'VARCHAR(12)' : 'TEXT';
    $motor = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    $db->exec("CREATE TABLE IF NOT EXISTS empresas (id $id, origen_id $texto NOT NULL UNIQUE, razon_social $texto NOT NULL, datos_json $largo NOT NULL, actualizado $texto NOT NULL, estado $estado NOT NULL DEFAULT 'activo')$motor");
    if (!columna_existe($db, 'empresas', 'estado')) {
        $db->exec("ALTER TABLE empresas ADD COLUMN estado $estado NOT NULL DEFAULT 'activo'");
    }
    $db->exec("CREATE TABLE IF NOT EXISTS importacion_vistas (id $id, empresa_id INTEGER NOT NULL, vista $texto NOT NULL, datos_json $largo NOT NULL, actualizado $texto NOT NULL, UNIQUE(empresa_id,vista), FOREIGN KEY(empresa_id) REFERENCES empresas(id))$motor");
    $db->exec("CREATE TABLE IF NOT EXISTS importacion_registros (id $id, empresa_id INTEGER NOT NULL, vista $texto NOT NULL, tabla $texto NOT NULL, huella CHAR(64) NOT NULL, datos_json $largo NOT NULL, UNIQUE(empresa_id,vista,tabla,huella), FOREIGN KEY(empresa_id) REFERENCES empresas(id))$motor");
}

final class EmpresaError extends RuntimeException {}

/** Empresa de la solicitud (cabecera X-Empresa-Id o ?empresa_id=). 0 = datos sin empresa, de antes de separar por empresa.
    Todas las APIs la obtienen aquí, así que cuando haya inicio de sesión basta con leerla de la sesión en esta función. */
function empresa_actual(PDO $db): int {
    $valor = $_SERVER['HTTP_X_EMPRESA_ID'] ?? ($_GET['empresa_id'] ?? '');
    if ($valor === '' || $valor === null) return 0;
    $id = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) throw new EmpresaError('Empresa inválida.');
    empresas_schema($db);
    $st = $db->prepare('SELECT 1 FROM empresas WHERE id = ?');
    $st->execute([$id]);
    if (!$st->fetchColumn()) throw new EmpresaError('La empresa seleccionada no existe en la base local. Vuelva a elegir la empresa.');
    return $id;
}

function columna_existe(PDO $db, string $tabla, string $columna): bool {
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $st = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $st->execute([$tabla, $columna]);
        return (bool)$st->fetchColumn();
    }
    foreach ($db->query("PRAGMA table_info($tabla)") as $c) if ($c['name'] === $columna) return true;
    return false;
}

/** Empresa a la que pasan los datos guardados antes de separar por empresa: la única importada, o 0 si hay varias o ninguna. */
function empresa_para_datos_previos(PDO $db): int {
    empresas_schema($db);
    $ids = $db->query('SELECT id FROM empresas LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
    return count($ids) === 1 ? (int)$ids[0] : 0;
}

/** Agrega empresa_id a una tabla creada antes de separar los datos por empresa. */
function agregar_empresa_id(PDO $db, string $tabla): void {
    if (columna_existe($db, $tabla, 'empresa_id')) return;
    $db->exec("ALTER TABLE $tabla ADD COLUMN empresa_id INTEGER NOT NULL DEFAULT 0");
    $db->prepare("UPDATE $tabla SET empresa_id = ?")->execute([empresa_para_datos_previos($db)]);
}
