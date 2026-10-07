-- Presupuestos y líneas de presupuesto por empresa. api/lib/presupuestos.php la crea si no existe.
-- Período puede ser 'mensual', 'trimestral' o 'anual'.
CREATE TABLE IF NOT EXISTS presupuestos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL DEFAULT 0,
  nombre VARCHAR(120) NOT NULL DEFAULT '',
  periodo VARCHAR(20) NOT NULL DEFAULT 'anual',
  anio INT NOT NULL,
  mes INT NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'activo',
  descripcion TEXT NOT NULL DEFAULT '',
  creado_en VARCHAR(25) NOT NULL,
  actualizado_en VARCHAR(25) NOT NULL,
  KEY idx_presupuestos_empresa (empresa_id),
  KEY idx_presupuestos_empresa_anio_mes (empresa_id, anio, mes),
  KEY idx_presupuestos_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS presupuesto_lineas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  presupuesto_id INT UNSIGNED NOT NULL,
  orden INT NOT NULL DEFAULT 0,
  cuenta_codigo VARCHAR(20) NOT NULL,
  concepto VARCHAR(200) NOT NULL DEFAULT '',
  monto_presupuestado BIGINT NOT NULL DEFAULT 0,
  FOREIGN KEY (presupuesto_id) REFERENCES presupuestos(id) ON DELETE CASCADE,
  KEY idx_presupuesto_lineas_presupuesto (presupuesto_id),
  KEY idx_presupuesto_lineas_cuenta (cuenta_codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
