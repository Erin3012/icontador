-- Tesorería: Flujo de caja, pagos programados/recurrentes por empresa.
-- api/lib/tesoreria.php la crea si no existe.

CREATE TABLE IF NOT EXISTS pagos_programados (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL DEFAULT 0,
  concepto VARCHAR(255) NOT NULL DEFAULT '',
  monto BIGINT NOT NULL DEFAULT 0,
  fecha_pago DATE NOT NULL,
  tipo VARCHAR(20) NOT NULL DEFAULT 'pago',
  recurrencia VARCHAR(20) NOT NULL DEFAULT 'una_vez',
  frecuencia_dias INT NULL,
  fecha_inicio DATE NULL,
  fecha_fin DATE NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
  creado_en VARCHAR(25) NOT NULL,
  actualizado_en VARCHAR(25) NOT NULL,
  KEY idx_pagos_empresa_fecha (empresa_id, fecha_pago),
  KEY idx_pagos_empresa_estado (empresa_id, estado),
  KEY idx_pagos_recurrencia (recurrencia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Proyecciones de flujo (caché actualizado diariamente)
CREATE TABLE IF NOT EXISTS flujo_proyecciones (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL DEFAULT 0,
  fecha DATE NOT NULL,
  saldo_estimado BIGINT NOT NULL DEFAULT 0,
  ingresos_acumulados BIGINT NOT NULL DEFAULT 0,
  egresos_acumulados BIGINT NOT NULL DEFAULT 0,
  creado_en VARCHAR(25) NOT NULL,
  UNIQUE KEY uq_flujo_proyecciones (empresa_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
