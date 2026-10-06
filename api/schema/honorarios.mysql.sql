-- Boletas de honorarios importadas desde el informe mensual del SII (MySQL 5.7+ / MariaDB). api/lib/honorarios.php la crea si no existe.
CREATE TABLE IF NOT EXISTS honorarios_boletas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL DEFAULT 0,
  periodo CHAR(7) NOT NULL,
  libro VARCHAR(9) NOT NULL,
  archivo VARCHAR(255) NOT NULL DEFAULT '',
  numero VARCHAR(20) NOT NULL DEFAULT '',
  fecha VARCHAR(10) NOT NULL DEFAULT '',
  estado VARCHAR(20) NOT NULL DEFAULT '',
  fecha_anulacion VARCHAR(10) NOT NULL DEFAULT '',
  rut VARCHAR(12) NOT NULL DEFAULT '',
  nombre VARCHAR(255) NOT NULL DEFAULT '',
  soc_prof TINYINT NOT NULL DEFAULT 0,
  bruto BIGINT NOT NULL DEFAULT 0,
  retenido BIGINT NOT NULL DEFAULT 0,
  pagado BIGINT NOT NULL DEFAULT 0,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_hon_empresa_periodo (empresa_id, periodo, libro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
