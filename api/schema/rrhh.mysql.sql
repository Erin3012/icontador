-- Módulo de Recursos Humanos: Contratos, permisos, feriados, finiquitos (MySQL 5.7+ / MariaDB)
-- api/lib/rrhh.php la crea si no existe.

-- Empleados
CREATE TABLE IF NOT EXISTS empleados (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL DEFAULT 0,
  rut VARCHAR(20) NOT NULL,
  nombre VARCHAR(255) NOT NULL,
  email VARCHAR(255),
  telefono VARCHAR(20),
  cargo VARCHAR(255),
  fecha_ingreso DATE,
  direccion VARCHAR(255),
  banco_cuenta VARCHAR(50),
  tipo_contrato VARCHAR(50),
  estado VARCHAR(50) NOT NULL DEFAULT 'activo',
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_rut_empresa (empresa_id, rut),
  KEY idx_empleados_empresa (empresa_id),
  KEY idx_empleados_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contratos de trabajo
CREATE TABLE IF NOT EXISTS contratos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL DEFAULT 0,
  empleado_rut VARCHAR(20) NOT NULL,
  empleado_nombre VARCHAR(255) NOT NULL,
  fecha_inicio DATE NOT NULL,
  fecha_termino DATE,
  tipo_contrato VARCHAR(50) NOT NULL DEFAULT 'indefinido',
  cargo VARCHAR(255) NOT NULL,
  lugar_prestacion VARCHAR(255) NOT NULL,
  jornada_tipo VARCHAR(50) NOT NULL DEFAULT 'completa',
  jornada_horas INT NOT NULL DEFAULT 44,
  sueldo_base BIGINT NOT NULL DEFAULT 0,
  sueldo_uf TINYINT NOT NULL DEFAULT 0,
  beneficios TEXT,
  activo TINYINT NOT NULL DEFAULT 1,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_contratos_empresa (empresa_id),
  KEY idx_contratos_rut (empleado_rut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Anexos a contratos
CREATE TABLE IF NOT EXISTS anexos_contrato (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL DEFAULT 0,
  contrato_id INT UNSIGNED NOT NULL,
  empleado_rut VARCHAR(20) NOT NULL,
  fecha DATE NOT NULL,
  detalle TEXT NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_anexos_empresa (empresa_id),
  KEY idx_anexos_contrato (contrato_id),
  FOREIGN KEY (contrato_id) REFERENCES contratos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Solicitudes de permiso sin goce de sueldo
CREATE TABLE IF NOT EXISTS permisos_sin_goce (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL DEFAULT 0,
  empleado_rut VARCHAR(20) NOT NULL,
  empleado_nombre VARCHAR(255) NOT NULL,
  servicio VARCHAR(255) NOT NULL,
  fecha_desde DATE NOT NULL,
  fecha_hasta DATE NOT NULL,
  dias INT NOT NULL,
  estado VARCHAR(50) NOT NULL DEFAULT 'solicitado',
  observaciones TEXT,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_permisos_empresa (empresa_id),
  KEY idx_permisos_rut (empleado_rut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Solicitudes de feriado legal
CREATE TABLE IF NOT EXISTS feriados_legal (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL DEFAULT 0,
  empleado_rut VARCHAR(20) NOT NULL,
  empleado_nombre VARCHAR(255) NOT NULL,
  servicio VARCHAR(255) NOT NULL,
  fecha_desde DATE NOT NULL,
  fecha_hasta DATE NOT NULL,
  dias INT NOT NULL,
  anio_feriado YEAR NOT NULL,
  estado VARCHAR(50) NOT NULL DEFAULT 'solicitado',
  observaciones TEXT,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_feriados_empresa (empresa_id),
  KEY idx_feriados_rut (empleado_rut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Comprobantes de feriado usado
CREATE TABLE IF NOT EXISTS comprobantes_feriado (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL DEFAULT 0,
  feriado_legal_id INT UNSIGNED,
  empleado_rut VARCHAR(20) NOT NULL,
  empleado_nombre VARCHAR(255) NOT NULL,
  fecha_desde DATE NOT NULL,
  fecha_hasta DATE NOT NULL,
  lugar VARCHAR(255),
  dias_usados INT NOT NULL,
  valor_diario BIGINT NOT NULL DEFAULT 0,
  total BIGINT NOT NULL DEFAULT 0,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_comprobantes_empresa (empresa_id),
  KEY idx_comprobantes_rut (empleado_rut),
  FOREIGN KEY (feriado_legal_id) REFERENCES feriados_legal(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Finiquitos
CREATE TABLE IF NOT EXISTS finiquitos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL DEFAULT 0,
  empleado_rut VARCHAR(20) NOT NULL,
  empleado_nombre VARCHAR(255) NOT NULL,
  cargo VARCHAR(255) NOT NULL,
  fecha_inicio DATE NOT NULL,
  fecha_termino DATE NOT NULL,
  lugar_prestacion VARCHAR(255) NOT NULL,
  causal_termino VARCHAR(255) NOT NULL,
  dias_trabajados INT NOT NULL DEFAULT 0,
  sueldo_liquido BIGINT NOT NULL DEFAULT 0,
  vacaciones_proporcional BIGINT NOT NULL DEFAULT 0,
  feriado_proporcional BIGINT NOT NULL DEFAULT 0,
  indemnizacion_aviso BIGINT NOT NULL DEFAULT 0,
  indemnizacion_años BIGINT NOT NULL DEFAULT 0,
  otros_conceptos TEXT,
  total_haberes BIGINT NOT NULL DEFAULT 0,
  descuentos_prev BIGINT NOT NULL DEFAULT 0,
  total_descuentos BIGINT NOT NULL DEFAULT 0,
  liquido_pagado BIGINT NOT NULL DEFAULT 0,
  retencion_pension_alimenticia BIGINT NOT NULL DEFAULT 0,
  observaciones TEXT,
  estado VARCHAR(50) NOT NULL DEFAULT 'borrador',
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_finiquitos_empresa (empresa_id),
  KEY idx_finiquitos_rut (empleado_rut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
