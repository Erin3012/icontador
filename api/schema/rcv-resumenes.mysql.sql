-- Resúmenes mensuales agregados del RCV. Separados de rcv_documentos para excluirlos de vouchers.
CREATE TABLE IF NOT EXISTS rcv_resumenes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL DEFAULT 0,
  periodo CHAR(7) NOT NULL,
  libro VARCHAR(7) NOT NULL DEFAULT 'ventas',
  archivo VARCHAR(255) NOT NULL DEFAULT '',
  tipo SMALLINT UNSIGNED NOT NULL,
  tipo_nombre VARCHAR(120) NOT NULL DEFAULT '',
  documentos INT UNSIGNED NOT NULL DEFAULT 0,
  exento BIGINT NOT NULL DEFAULT 0,
  neto BIGINT NOT NULL DEFAULT 0,
  iva BIGINT NOT NULL DEFAULT 0,
  total BIGINT NOT NULL DEFAULT 0,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rcv_res_empresa_periodo (empresa_id, periodo, libro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
