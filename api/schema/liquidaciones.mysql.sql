-- Liquidaciones de sueldo guardadas desde la calculadora (MySQL 5.7+ / MariaDB). api/lib/liquidaciones.php la crea si no existe.
CREATE TABLE IF NOT EXISTS liquidaciones (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL DEFAULT 0,
  periodo CHAR(7) NOT NULL,
  trabajador VARCHAR(120) NOT NULL,
  sueldo_base BIGINT NOT NULL DEFAULT 0,
  gratificacion BIGINT NOT NULL DEFAULT 0,
  imponible BIGINT NOT NULL DEFAULT 0,
  total_haberes BIGINT NOT NULL DEFAULT 0,
  afp BIGINT NOT NULL DEFAULT 0,
  salud BIGINT NOT NULL DEFAULT 0,
  afc BIGINT NOT NULL DEFAULT 0,
  impuesto BIGINT NOT NULL DEFAULT 0,
  total_descuentos BIGINT NOT NULL DEFAULT 0,
  liquido BIGINT NOT NULL DEFAULT 0,
  costo_empresa BIGINT NOT NULL DEFAULT 0,
  detalle TEXT NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_liquidaciones_empresa_periodo (empresa_id, periodo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
