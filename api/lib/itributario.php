<?php
declare(strict_types=1);
/* Configuración de iTributario por empresa y solicitudes de sincronización del balance.
   Preferencia auto_sync: 'ask' = preguntar siempre (por defecto), 'S' = sincronizar sin preguntar, 'N' = no sincronizar.
   El envío real a iTributario todavía no está conectado: cada solicitud queda en estado 'pendiente'. */
require_once __DIR__ . '/empresas.php';

const ITRIB_PREFERENCIAS = ['ask', 'S', 'N'];
const ITRIB_ORIGENES = ['manual', 'automatico'];
const ITRIB_MENSAJE_PENDIENTE = 'Solicitud guardada. El envío del balance a iTributario todavía no está conectado.';

final class ItribError extends RuntimeException {}

function itrib_schema(PDO $db): void {
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $db->exec("CREATE TABLE IF NOT EXISTS itrib_config (
            empresa_id INT NOT NULL PRIMARY KEY,
            auto_sync VARCHAR(8) NOT NULL DEFAULT 'ask',
            actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS itrib_sincronizaciones (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL DEFAULT 0,
            periodo VARCHAR(7) NOT NULL,
            origen VARCHAR(12) NOT NULL,
            id_voucher_origen INT NULL,
            estado VARCHAR(12) NOT NULL,
            mensaje VARCHAR(255) NOT NULL DEFAULT '',
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_itrib_empresa (empresa_id, creado_en)
        ) DEFAULT CHARSET=utf8mb4");
        return;
    }
    $db->exec("CREATE TABLE IF NOT EXISTS itrib_config (
        empresa_id INTEGER NOT NULL PRIMARY KEY,
        auto_sync TEXT NOT NULL DEFAULT 'ask',
        actualizado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE IF NOT EXISTS itrib_sincronizaciones (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        empresa_id INTEGER NOT NULL DEFAULT 0,
        periodo TEXT NOT NULL,
        origen TEXT NOT NULL,
        id_voucher_origen INTEGER NULL,
        estado TEXT NOT NULL,
        mensaje TEXT NOT NULL DEFAULT '',
        creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_itrib_empresa ON itrib_sincronizaciones (empresa_id, creado_en)');
}

/** Preferencia de sincronización automática de la empresa. Sin registro, se pregunta siempre. */
function itrib_preferencia(PDO $db, int $empresa): string {
    $st = $db->prepare('SELECT auto_sync FROM itrib_config WHERE empresa_id = ?');
    $st->execute([$empresa]);
    $valor = $st->fetchColumn();
    return is_string($valor) && in_array($valor, ITRIB_PREFERENCIAS, true) ? $valor : 'ask';
}

function itrib_guardar_preferencia(PDO $db, int $empresa, string $preferencia): void {
    if (!in_array($preferencia, ITRIB_PREFERENCIAS, true)) {
        throw new ItribError('Preferencia no válida.');
    }
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $st = $db->prepare('INSERT INTO itrib_config (empresa_id, auto_sync) VALUES (?, ?) ON DUPLICATE KEY UPDATE auto_sync = VALUES(auto_sync)');
    } else {
        $st = $db->prepare('INSERT INTO itrib_config (empresa_id, auto_sync) VALUES (?, ?) ON CONFLICT(empresa_id) DO UPDATE SET auto_sync = excluded.auto_sync, actualizado_en = CURRENT_TIMESTAMP');
    }
    $st->execute([$empresa, $preferencia]);
}

/** Registra una solicitud de sincronización del balance del período. Devuelve su id. */
function itrib_solicitar_sincronizacion(PDO $db, int $empresa, string $periodo, string $origen, ?int $voucherOrigen): int {
    if (!preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/', $periodo)) {
        throw new ItribError('El período debe tener el formato AAAA-MM.');
    }
    if (!in_array($origen, ITRIB_ORIGENES, true)) {
        throw new ItribError('Origen de sincronización no válido.');
    }
    $st = $db->prepare('INSERT INTO itrib_sincronizaciones (empresa_id, periodo, origen, id_voucher_origen, estado, mensaje) VALUES (?, ?, ?, ?, ?, ?)');
    $st->execute([$empresa, $periodo, $origen, $voucherOrigen, 'pendiente', ITRIB_MENSAJE_PENDIENTE]);
    return (int) $db->lastInsertId();
}

/** Últimas solicitudes de la empresa, de la más reciente a la más antigua. */
function itrib_historial(PDO $db, int $empresa, int $limite = 20): array {
    $st = $db->prepare('SELECT id, periodo, origen, estado, mensaje, creado_en FROM itrib_sincronizaciones WHERE empresa_id = ? ORDER BY id DESC LIMIT ' . (int) $limite);
    $st->execute([$empresa]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
