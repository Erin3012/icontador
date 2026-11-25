-- Configuración de iTributario por empresa y solicitudes de sincronización del balance (MySQL 5.7+ / MariaDB).
-- api/lib/itributario.php la crea sola si no existe; este archivo sirve para crearla a mano en cPanel.
-- Preferencia auto_sync: 'ask' (preguntar siempre, por defecto), 'S' (sincronizar sin preguntar), 'N' (no sincronizar).
-- Las solicitudes quedan en estado 'pendiente' hasta que exista el envío real a iTributario.

CREATE TABLE IF NOT EXISTS itrib_config (
  empresa_id INT NOT NULL PRIMARY KEY,
  auto_sync VARCHAR(8) NOT NULL DEFAULT 'ask',
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS itrib_sincronizaciones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL DEFAULT 0,
  periodo VARCHAR(7) NOT NULL,
  origen VARCHAR(12) NOT NULL,
  id_voucher_origen INT NULL,
  estado VARCHAR(12) NOT NULL,
  mensaje VARCHAR(255) NOT NULL DEFAULT '',
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_itrib_empresa (empresa_id, creado_en)
) DEFAULT CHARSET=utf8mb4;
