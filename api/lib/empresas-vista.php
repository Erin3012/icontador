<?php
declare(strict_types=1);

/** Preferencia visual de administradores, independiente de las asignaciones de acceso. */
function empresas_vista_schema(PDO $db): void
{
    $motor = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ENGINE=InnoDB' : '';
    $db->exec("CREATE TABLE IF NOT EXISTS empresa_vistas_admin (
        usuario_id INTEGER NOT NULL PRIMARY KEY,
        empresas_json TEXT NOT NULL
    )$motor");
}

/** null significa que el administrador usa el listado completo. */
function empresas_vista_admin(PDO $db, int $usuarioId): ?array
{
    empresas_vista_schema($db);
    $consulta = $db->prepare('SELECT empresas_json FROM empresa_vistas_admin WHERE usuario_id = ?');
    $consulta->execute([$usuarioId]);
    $lista = $consulta->fetchColumn();
    return $lista === false ? null : json_decode($lista, true, 512, JSON_THROW_ON_ERROR);
}

function empresas_guardar_vista_admin(PDO $db, int $usuarioId, ?array $empresas): void
{
    empresas_vista_schema($db);
    if ($empresas === null) {
        $db->prepare('DELETE FROM empresa_vistas_admin WHERE usuario_id = ?')->execute([$usuarioId]);
        return;
    }

    $actualizar = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        ? 'ON DUPLICATE KEY UPDATE empresas_json = ?'
        : 'ON CONFLICT(usuario_id) DO UPDATE SET empresas_json = ?';
    $lista = json_encode(array_values($empresas), JSON_THROW_ON_ERROR);
    $db->prepare("INSERT INTO empresa_vistas_admin (usuario_id, empresas_json) VALUES (?, ?) $actualizar")
        ->execute([$usuarioId, $lista, $lista]);
}
