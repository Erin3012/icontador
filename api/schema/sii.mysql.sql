-- Tareas de sincronización del RCV con el SII (MySQL 5.7+ / MariaDB). api/lib/sii.php la crea si no existe.
-- estado: pendiente (la pidió la web) | en_proceso (la reclamó el agente) | completada | error.
CREATE TABLE IF NOT EXISTS sii_tareas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL DEFAULT 0,
  tipo VARCHAR(16) NOT NULL DEFAULT 'rcv',
  periodo CHAR(7) NOT NULL DEFAULT '',
  libro VARCHAR(7) NOT NULL DEFAULT '',
  rut VARCHAR(12) NOT NULL DEFAULT '',
  estado VARCHAR(12) NOT NULL DEFAULT 'pendiente',
  mensaje VARCHAR(500) NOT NULL DEFAULT '',
  resultado_json TEXT NULL,
  creado_por INT UNSIGNED NOT NULL DEFAULT 0,
  creado_en DATETIME NOT NULL,
  actualizado_en DATETIME NOT NULL,
  reclamado_en DATETIME NULL,
  KEY idx_sii_empresa_estado (empresa_id, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Clave tributaria del SII por empresa, cifrada con la llave sii_clave_cifrado (api/config.php).
CREATE TABLE IF NOT EXISTS sii_claves (
  empresa_id INT NOT NULL PRIMARY KEY,
  clave_cifrada TEXT NOT NULL,
  actualizado_en DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
