-- Cuentas de acceso (MySQL 5.7+ / MariaDB). api/lib/auth.php la crea si no existe.
-- estado: pendiente (recién registrada) | activo (aprobada) | deshabilitado. rol: usuario | admin.
CREATE TABLE IF NOT EXISTS usuarios (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  nombre VARCHAR(120) NOT NULL,
  clave_hash VARCHAR(255) NOT NULL,
  rol VARCHAR(10) NOT NULL DEFAULT 'usuario',
  estado VARCHAR(15) NOT NULL DEFAULT 'pendiente',
  creado_en VARCHAR(25) NOT NULL,
  aprobado_en VARCHAR(25) NULL,
  ultimo_acceso VARCHAR(25) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
