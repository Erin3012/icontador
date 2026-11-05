-- Tabla de Empleados
-- Ejecutar esto en cPanel phpMyAdmin en la base de datos qlccl_icontador
-- Solamente si la tabla no existe (CREATE TABLE IF NOT EXISTS es idempotente)

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
