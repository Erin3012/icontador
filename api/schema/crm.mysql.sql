-- CRM Básico: Gestión de contactos, interacciones y oportunidades de venta.
-- api/lib/crm.php la crea si no existe.

CREATE TABLE IF NOT EXISTS crm_contactos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL DEFAULT 0,
  tipo VARCHAR(20) NOT NULL DEFAULT 'cliente',
  nombre VARCHAR(255) NOT NULL DEFAULT '',
  email VARCHAR(255),
  telefono VARCHAR(20),
  empresa VARCHAR(255),
  cargo VARCHAR(255),
  direccion TEXT,
  ciudad VARCHAR(100),
  pais VARCHAR(100),
  rut VARCHAR(20),
  estado VARCHAR(20) NOT NULL DEFAULT 'activo',
  categoria VARCHAR(50),
  probabilidad_compra INT NOT NULL DEFAULT 0,
  monto_estimado BIGINT NOT NULL DEFAULT 0,
  ultima_interaccion DATE,
  proximo_seguimiento DATE,
  vendedor_id INT,
  notas TEXT,
  creado_en VARCHAR(25) NOT NULL,
  actualizado_en VARCHAR(25) NOT NULL,
  KEY idx_crm_empresa_tipo (empresa_id, tipo),
  KEY idx_crm_empresa_estado (empresa_id, estado),
  KEY idx_crm_ultima_interaccion (ultima_interaccion),
  KEY idx_crm_proximo_seguimiento (proximo_seguimiento),
  UNIQUE KEY uq_crm_empresa_email (empresa_id, email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registro de interacciones (llamadas, emails, reuniones, etc.)
CREATE TABLE IF NOT EXISTS crm_interacciones (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  contacto_id INT UNSIGNED NOT NULL,
  empresa_id INT NOT NULL DEFAULT 0,
  tipo_interaccion VARCHAR(50) NOT NULL DEFAULT 'llamada',
  descripcion TEXT,
  resultado VARCHAR(255),
  usuario_id INT,
  fecha_interaccion DATETIME NOT NULL,
  duracion_minutos INT,
  proxima_accion VARCHAR(255),
  proximafecha_seguimiento DATE,
  creado_en VARCHAR(25) NOT NULL,
  KEY idx_interacciones_contacto (contacto_id),
  KEY idx_interacciones_empresa_fecha (empresa_id, fecha_interaccion),
  FOREIGN KEY (contacto_id) REFERENCES crm_contactos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Oportunidades de venta
CREATE TABLE IF NOT EXISTS crm_oportunidades (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  contacto_id INT UNSIGNED NOT NULL,
  empresa_id INT NOT NULL DEFAULT 0,
  nombre VARCHAR(255) NOT NULL DEFAULT '',
  descripcion TEXT,
  monto_estimado BIGINT NOT NULL DEFAULT 0,
  etapa VARCHAR(50) NOT NULL DEFAULT 'prospecto',
  probabilidad INT NOT NULL DEFAULT 10,
  fecha_cierre_estimada DATE,
  fecha_cierre_real DATE,
  vendedor_id INT,
  estado VARCHAR(20) NOT NULL DEFAULT 'abierta',
  motivo_cierre VARCHAR(255),
  creado_en VARCHAR(25) NOT NULL,
  actualizado_en VARCHAR(25) NOT NULL,
  KEY idx_oportunidades_contacto (contacto_id),
  KEY idx_oportunidades_empresa_etapa (empresa_id, etapa),
  KEY idx_oportunidades_estado (estado),
  FOREIGN KEY (contacto_id) REFERENCES crm_contactos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tareas de seguimiento
CREATE TABLE IF NOT EXISTS crm_tareas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  contacto_id INT UNSIGNED NOT NULL,
  empresa_id INT NOT NULL DEFAULT 0,
  descripcion TEXT NOT NULL,
  tipo_tarea VARCHAR(50) NOT NULL DEFAULT 'seguimiento',
  estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
  prioridad VARCHAR(20) NOT NULL DEFAULT 'media',
  fecha_vencimiento DATE NOT NULL,
  usuario_asignado INT,
  completada_en VARCHAR(25),
  creado_en VARCHAR(25) NOT NULL,
  KEY idx_tareas_contacto (contacto_id),
  KEY idx_tareas_empresa_estado (empresa_id, estado),
  KEY idx_tareas_fecha_vencimiento (fecha_vencimiento),
  FOREIGN KEY (contacto_id) REFERENCES crm_contactos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
