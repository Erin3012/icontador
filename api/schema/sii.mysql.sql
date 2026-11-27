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

-- Agentes del PC emparejados con un código de un solo uso. Solo se guarda el hash del token.
CREATE TABLE IF NOT EXISTS sii_agentes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  nombre VARCHAR(80) NOT NULL DEFAULT '',
  token_hash CHAR(64) NOT NULL,
  creado_en DATETIME NOT NULL,
  ultimo_uso DATETIME NULL,
  revocado_en DATETIME NULL,
  UNIQUE KEY uq_sii_agentes_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Resultados de las consultas de solo lectura al SII (Formulario 22 y declaraciones juradas) por año tributario.
CREATE TABLE IF NOT EXISTS sii_consultas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL,
  tipo VARCHAR(8) NOT NULL,
  anio SMALLINT NOT NULL,
  estado VARCHAR(40) NOT NULL DEFAULT '',
  datos_json MEDIUMTEXT NOT NULL,
  consultado_en DATETIME NOT NULL,
  KEY idx_sii_consultas (empresa_id, tipo, anio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Códigos de emparejamiento: se guarda solo el hash; caducan y se usan una vez.
CREATE TABLE IF NOT EXISTS sii_codigos_emparejamiento (
  codigo_hash CHAR(64) NOT NULL PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  creado_en DATETIME NOT NULL,
  expira_en DATETIME NOT NULL,
  usado_en DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
