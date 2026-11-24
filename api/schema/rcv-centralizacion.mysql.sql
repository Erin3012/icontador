-- Configuración y vínculo de vouchers generados desde documentos RCV.
CREATE TABLE IF NOT EXISTS rcv_centralizacion_config (
  empresa_id INT NOT NULL PRIMARY KEY,
  config_json LONGTEXT NOT NULL,
  actualizado_en VARCHAR(25) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rcv_proveedor_cuentas (
  empresa_id INT NOT NULL,
  rut VARCHAR(12) NOT NULL,
  nombre VARCHAR(255) NOT NULL DEFAULT '',
  cuenta VARCHAR(20) NOT NULL,
  actualizado_en VARCHAR(25) NOT NULL,
  PRIMARY KEY (empresa_id, rut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rcv_centralizaciones (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL,
  source_key CHAR(64) NOT NULL,
  source_hash CHAR(64) NOT NULL,
  voucher_id INT NOT NULL,
  periodo CHAR(7) NOT NULL,
  libro VARCHAR(24) NOT NULL,
  rut VARCHAR(12) NOT NULL DEFAULT '',
  folio VARCHAR(20) NOT NULL DEFAULT '',
  creado_en VARCHAR(25) NOT NULL,
  UNIQUE KEY uq_rcv_centralizado_documento (empresa_id, source_key),
  KEY idx_rcv_centralizado_voucher (voucher_id),
  CONSTRAINT fk_rcv_centralizado_voucher FOREIGN KEY (voucher_id) REFERENCES vouchers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rcv_centralizacion_pagos (
  empresa_id INT NOT NULL,
  source_key CHAR(64) NOT NULL,
  pago_voucher_id INT NOT NULL,
  creado_en VARCHAR(25) NOT NULL,
  PRIMARY KEY (empresa_id, source_key),
  KEY idx_rcv_pago_voucher (pago_voucher_id),
  CONSTRAINT fk_rcv_pago_documento FOREIGN KEY (empresa_id, source_key) REFERENCES rcv_centralizaciones (empresa_id, source_key) ON DELETE CASCADE,
  CONSTRAINT fk_rcv_pago_voucher FOREIGN KEY (pago_voucher_id) REFERENCES vouchers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
