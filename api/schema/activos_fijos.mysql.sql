-- Activos Fijos: Gestión de activos, depreciaciones y valuaciones por empresa.
-- api/lib/activos_fijos.php la crea si no existe.

CREATE TABLE IF NOT EXISTS activos_fijos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL DEFAULT 0,
  codigo VARCHAR(50) NOT NULL DEFAULT '',
  nombre VARCHAR(255) NOT NULL DEFAULT '',
  descripcion TEXT,
  categoria VARCHAR(100) NOT NULL DEFAULT '',
  fecha_adquisicion DATE NOT NULL,
  valor_adquisicion BIGINT NOT NULL DEFAULT 0,
  moneda VARCHAR(10) NOT NULL DEFAULT 'CLP',
  vida_util_anos INT NOT NULL DEFAULT 5,
  metodo_depreciacion VARCHAR(20) NOT NULL DEFAULT 'lineal',
  tasa_depreciacion_acelerada DECIMAL(5,2) NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'vigente',
  fecha_baja DATE NULL,
  valor_residual BIGINT NOT NULL DEFAULT 0,
  ubicacion VARCHAR(255),
  responsable VARCHAR(255),
  numero_serie VARCHAR(100),
  creado_en VARCHAR(25) NOT NULL,
  actualizado_en VARCHAR(25) NOT NULL,
  KEY idx_activos_empresa_estado (empresa_id, estado),
  KEY idx_activos_empresa_categoria (empresa_id, categoria),
  KEY idx_activos_fecha_adquisicion (fecha_adquisicion),
  UNIQUE KEY uq_activos_empresa_codigo (empresa_id, codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registro de depreciaciones mensuales (caché actualizado cada mes)
CREATE TABLE IF NOT EXISTS depreciaciones_mensuales (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  activo_id INT UNSIGNED NOT NULL,
  empresa_id INT NOT NULL DEFAULT 0,
  ano INT NOT NULL,
  mes INT NOT NULL,
  valor_depreciation BIGINT NOT NULL DEFAULT 0,
  valor_acumulado BIGINT NOT NULL DEFAULT 0,
  valor_neto BIGINT NOT NULL DEFAULT 0,
  creado_en VARCHAR(25) NOT NULL,
  KEY idx_depreciaciones_activo (activo_id),
  KEY idx_depreciaciones_empresa_periodo (empresa_id, ano, mes),
  UNIQUE KEY uq_depreciaciones (activo_id, ano, mes),
  FOREIGN KEY (activo_id) REFERENCES activos_fijos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Movimientos de activos (adquisiciones, traspasos, bajas, revaluaciones)
CREATE TABLE IF NOT EXISTS movimientos_activos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  activo_id INT UNSIGNED NOT NULL,
  empresa_id INT NOT NULL DEFAULT 0,
  tipo_movimiento VARCHAR(50) NOT NULL DEFAULT 'adquisicion',
  fecha_movimiento DATE NOT NULL,
  valor_movimiento BIGINT NOT NULL DEFAULT 0,
  descripcion TEXT,
  comprobante_numero VARCHAR(50),
  comprobante_tipo VARCHAR(20),
  creado_en VARCHAR(25) NOT NULL,
  KEY idx_movimientos_activo (activo_id),
  KEY idx_movimientos_empresa_fecha (empresa_id, fecha_movimiento),
  FOREIGN KEY (activo_id) REFERENCES activos_fijos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
