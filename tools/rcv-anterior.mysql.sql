-- Tabla del Registro de Compras y Ventas (MySQL 5.7+ / MariaDB). api/lib/rcv.php la crea si no existe.
CREATE TABLE IF NOT EXISTS rcv_documentos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  periodo CHAR(7) NOT NULL,
  libro VARCHAR(7) NOT NULL,
  archivo VARCHAR(255) NOT NULL DEFAULT '',
  tipo_doc SMALLINT UNSIGNED NOT NULL,
  tipo_operacion VARCHAR(60) NOT NULL DEFAULT '',
  rut VARCHAR(12) NOT NULL DEFAULT '',
  razon_social VARCHAR(255) NOT NULL DEFAULT '',
  folio VARCHAR(20) NOT NULL DEFAULT '',
  fecha VARCHAR(10) NOT NULL DEFAULT '',
  exento BIGINT NOT NULL DEFAULT 0,
  neto BIGINT NOT NULL DEFAULT 0,
  iva BIGINT NOT NULL DEFAULT 0,
  iva_no_rec BIGINT NOT NULL DEFAULT 0,
  iva_uso_comun BIGINT NOT NULL DEFAULT 0,
  iva_retenido BIGINT NOT NULL DEFAULT 0,
  otros BIGINT NOT NULL DEFAULT 0,
  total BIGINT NOT NULL DEFAULT 0,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rcv_periodo_libro (periodo, libro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
