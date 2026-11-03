-- Tareas de sincronización del RCV con el SII (MySQL 5.7+ / MariaDB). api/lib/sii.php la crea si no existe.
-- estado: pendiente (la pidió la web) | en_proceso (la reclamó el agente) | completada | error.
CREATE TABLE IF NOT EXISTS sii_tareas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL DEFAULT 0,
  periodo CHAR(7) NOT NULL,
  libro VARCHAR(7) NOT NULL,
  estado VARCHAR(12) NOT NULL DEFAULT 'pendiente',
  mensaje VARCHAR(500) NOT NULL DEFAULT '',
  creado_por INT UNSIGNED NOT NULL DEFAULT 0,
  creado_en DATETIME NOT NULL,
  actualizado_en DATETIME NOT NULL,
  reclamado_en DATETIME NULL,
  KEY idx_sii_empresa_estado (empresa_id, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
