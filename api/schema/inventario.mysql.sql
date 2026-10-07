-- Tabla de Inventario (MySQL 5.7+ / MariaDB). api/lib/inventario.php la crea si no existe.
CREATE TABLE IF NOT EXISTS inventario_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL DEFAULT 0,
  codigo VARCHAR(20) NOT NULL,
  descripcion VARCHAR(255) NOT NULL,
  categoria VARCHAR(100) NOT NULL DEFAULT '',
  cantidad INT NOT NULL DEFAULT 0,
  valor_unitario BIGINT NOT NULL DEFAULT 0,
  fecha_ingreso DATE,
  ubicacion VARCHAR(100) NOT NULL DEFAULT '',
  observaciones TEXT,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE (empresa_id, codigo),
  KEY idx_inventario_empresa (empresa_id),
  KEY idx_inventario_categoria (empresa_id, categoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
