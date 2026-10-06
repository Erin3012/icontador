<?php
declare(strict_types=1);
/* Tablas de las empresas importadas. Se crean al primer uso para que el selector funcione con una base nueva. */
require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/auth.php';

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
    // Qué empresas ve cada usuario que no es administrador. Las asigna un administrador en /auth/usuarios.php;
    // quien crea o importa una empresa queda asignado a ella.
    $db->exec("CREATE TABLE IF NOT EXISTS empresa_usuarios (empresa_id INTEGER NOT NULL, usuario_id INTEGER NOT NULL, asignado_en VARCHAR(25) NOT NULL, PRIMARY KEY(empresa_id, usuario_id))$motor");
    $db->exec("CREATE TABLE IF NOT EXISTS importacion_vistas (id $id, empresa_id INTEGER NOT NULL, vista $texto NOT NULL, datos_json $largo NOT NULL, actualizado $texto NOT NULL, UNIQUE(empresa_id,vista), FOREIGN KEY(empresa_id) REFERENCES empresas(id))$motor");
    $db->exec("CREATE TABLE IF NOT EXISTS importacion_registros (id $id, empresa_id INTEGER NOT NULL, vista $texto NOT NULL, tabla $texto NOT NULL, huella CHAR(64) NOT NULL, datos_json $largo NOT NULL, UNIQUE(empresa_id,vista,tabla,huella), FOREIGN KEY(empresa_id) REFERENCES empresas(id))$motor");
}

final class EmpresaError extends RuntimeException {}

/** Empresa de la solicitud (cabecera X-Empresa-Id o ?empresa_id=). 0 = datos sin empresa, de antes de separar por empresa.
    Todas las APIs la obtienen aquí: un usuario que no es administrador solo puede usar las empresas que tiene asignadas. */
function empresa_actual(PDO $db): int {
    $usuario = auth_actual();
    $valor = $_SERVER['HTTP_X_EMPRESA_ID'] ?? ($_GET['empresa_id'] ?? '');
    if ($valor === '' || $valor === null) {
        if (!empresa_es_admin($usuario)) throw new EmpresaError('Elija una empresa en la pantalla Empresas.');
        return 0;
    }
    $id = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) throw new EmpresaError('Empresa inválida.');
    empresas_schema($db);
    $st = $db->prepare('SELECT 1 FROM empresas WHERE id = ?');
    $st->execute([$id]);
    if (!$st->fetchColumn()) throw new EmpresaError('La empresa seleccionada no existe en la base local. Vuelva a elegir la empresa.');
    if (!empresa_usuario_puede($db, $usuario, $id)) throw new EmpresaError('No tiene acceso a esta empresa. Pida al administrador que se la asigne.');
    return $id;
}

function empresa_es_admin(?array $usuario): bool {
    return ($usuario['rol'] ?? '') === 'admin';
}

function empresa_usuario_puede(PDO $db, ?array $usuario, int $empresa): bool {
    if (!$usuario) return false;
    if (empresa_es_admin($usuario)) return true;
    $st = $db->prepare('SELECT 1 FROM empresa_usuarios WHERE empresa_id = ? AND usuario_id = ?');
    $st->execute([$empresa, (int) $usuario['id']]);
    return (bool) $st->fetchColumn();
}

/** Ids de las empresas asignadas a un usuario. */
function empresas_de_usuario(PDO $db, int $usuarioId): array {
    empresas_schema($db);
    $st = $db->prepare('SELECT empresa_id FROM empresa_usuarios WHERE usuario_id = ?');
    $st->execute([$usuarioId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

function empresa_asignar(PDO $db, int $empresa, int $usuarioId): void {
    $st = $db->prepare('SELECT 1 FROM empresa_usuarios WHERE empresa_id = ? AND usuario_id = ?');
    $st->execute([$empresa, $usuarioId]);
    if ($st->fetchColumn()) return;
    $db->prepare('INSERT INTO empresa_usuarios (empresa_id, usuario_id, asignado_en) VALUES (?, ?, ?)')->execute([$empresa, $usuarioId, date('Y-m-d H:i:s')]);
}

/** Reemplaza las empresas asignadas a un usuario por la lista recibida (la guarda un administrador). */
function empresas_asignar_usuario(PDO $db, int $usuarioId, array $empresas): void {
    empresas_schema($db);
    $existentes = array_map('intval', $db->query('SELECT id FROM empresas')->fetchAll(PDO::FETCH_COLUMN));
    $elegidas = array_values(array_intersect($existentes, array_map('intval', $empresas)));
    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM empresa_usuarios WHERE usuario_id = ?')->execute([$usuarioId]);
        foreach ($elegidas as $empresa) empresa_asignar($db, $empresa, $usuarioId);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
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
