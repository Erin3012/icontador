-- Movimientos de la cartola bancaria cargados desde la plantilla CSV (MySQL 5.7+ / MariaDB). api/lib/banco.php la crea si no existe.
-- comprobante_id apunta a vouchers.id; se valida en PHP contra los vouchers de la misma empresa.
CREATE TABLE IF NOT EXISTS extractos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL DEFAULT 0,
  fecha DATE NOT NULL,
  descripcion VARCHAR(255) NOT NULL DEFAULT '',
  monto BIGINT NOT NULL DEFAULT 0,
  referencia VARCHAR(100) NOT NULL DEFAULT '',
  estado VARCHAR(10) NOT NULL DEFAULT 'pendiente',
  comprobante_id INT NULL,
  creado_en VARCHAR(25) NOT NULL,
  actualizado_en VARCHAR(25) NOT NULL,
  KEY idx_extractos_empresa_fecha (empresa_id, fecha),
  KEY idx_extractos_empresa_estado (empresa_id, estado),
  KEY idx_extractos_comprobante (comprobante_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
