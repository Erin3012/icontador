-- Sugerencias y reportes de problemas con sus adjuntos (MySQL 5.7+ / MariaDB). api/lib/sugerencias.php las crea si no existen.
-- estado: nueva | en_revision | resuelta. tipo: problema | sugerencia. Los archivos viven en data/adjuntos (fuera de la base).
CREATE TABLE IF NOT EXISTS sugerencias (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  usuario_id VARCHAR(40) NOT NULL,
  usuario_nombre VARCHAR(120) NOT NULL DEFAULT '',
  usuario_email VARCHAR(190) NOT NULL DEFAULT '',
  tipo VARCHAR(12) NOT NULL,
  comentario TEXT NOT NULL,
  pagina VARCHAR(255) NOT NULL DEFAULT '',
  navegador VARCHAR(255) NOT NULL DEFAULT '',
  estado VARCHAR(12) NOT NULL DEFAULT 'nueva',
  nota_admin TEXT NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sugerencias_estado (estado),
  KEY idx_sugerencias_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sugerencia_adjuntos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sugerencia_id INT UNSIGNED NOT NULL,
  nombre VARCHAR(200) NOT NULL,
  archivo CHAR(32) NOT NULL,
  extension VARCHAR(5) NOT NULL,
  bytes INT UNSIGNED NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sugerencia_adjuntos_sugerencia (sugerencia_id),
  CONSTRAINT fk_sugerencia_adjuntos FOREIGN KEY (sugerencia_id) REFERENCES sugerencias (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
