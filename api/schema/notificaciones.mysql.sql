-- Notificaciones: Sistema de alertas y notificaciones por usuario/empresa.
-- api/lib/notificaciones.php la crea si no existe.

CREATE TABLE IF NOT EXISTS alertas_configuracion (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL DEFAULT 0,
  tipo_alerta VARCHAR(50) NOT NULL DEFAULT '',
  descripcion VARCHAR(255),
  umbral_minimo BIGINT,
  umbral_maximo BIGINT,
  habilitada BOOLEAN NOT NULL DEFAULT 1,
  frecuencia VARCHAR(20) NOT NULL DEFAULT 'inmediata',
  canal_notificacion VARCHAR(50) NOT NULL DEFAULT 'email',
  creado_en VARCHAR(25) NOT NULL,
  actualizado_en VARCHAR(25) NOT NULL,
  KEY idx_alertas_empresa (empresa_id),
  KEY idx_alertas_tipo (tipo_alerta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registro de notificaciones enviadas
CREATE TABLE IF NOT EXISTS notificaciones (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL DEFAULT 0,
  usuario_id INT,
  tipo_notificacion VARCHAR(50) NOT NULL DEFAULT '',
  titulo VARCHAR(255) NOT NULL DEFAULT '',
  mensaje TEXT,
  datos_json TEXT,
  estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
  fecha_enviada VARCHAR(25),
  fecha_leida VARCHAR(25),
  canal VARCHAR(50) NOT NULL DEFAULT 'sistema',
  creado_en VARCHAR(25) NOT NULL,
  KEY idx_notificaciones_usuario (usuario_id),
  KEY idx_notificaciones_empresa_estado (empresa_id, estado),
  KEY idx_notificaciones_fecha (creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preferencias de notificación por usuario
CREATE TABLE IF NOT EXISTS preferencias_notificacion (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  empresa_id INT NOT NULL DEFAULT 0,
  notificaciones_email BOOLEAN NOT NULL DEFAULT 1,
  notificaciones_sistema BOOLEAN NOT NULL DEFAULT 1,
  resumen_diario BOOLEAN NOT NULL DEFAULT 0,
  hora_resumen_diario TIME,
  alertas_criticas BOOLEAN NOT NULL DEFAULT 1,
  alertas_advertencia BOOLEAN NOT NULL DEFAULT 1,
  actualizado_en VARCHAR(25) NOT NULL,
  UNIQUE KEY uq_preferencias (usuario_id, empresa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
