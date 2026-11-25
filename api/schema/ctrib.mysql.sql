-- Cifrax Tributario (MySQL / MariaDB de cPanel). api/lib/ctrib.php crea estas mismas tablas con CREATE TABLE IF NOT EXISTS;
-- este archivo sirve para revisarlas o aplicarlas a mano. No altera datos existentes.

CREATE TABLE IF NOT EXISTS ctrib_mapeo_digitos (
    empresa_id INT NOT NULL DEFAULT 0,
    digito CHAR(1) NOT NULL,
    tipo VARCHAR(12) NOT NULL,
    asignado VARCHAR(25) NOT NULL,
    usuario VARCHAR(120) NOT NULL DEFAULT '',
    PRIMARY KEY (empresa_id, digito)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ctrib_balance (
    empresa_id INT NOT NULL DEFAULT 0,
    anio INT NOT NULL,
    origen VARCHAR(12) NOT NULL,
    archivo VARCHAR(255) NOT NULL DEFAULT '',
    importado VARCHAR(25) NOT NULL,
    usuario VARCHAR(120) NOT NULL DEFAULT '',
    PRIMARY KEY (empresa_id, anio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ctrib_balance_cuentas (
    empresa_id INT NOT NULL DEFAULT 0,
    anio INT NOT NULL,
    codigo VARCHAR(20) NOT NULL,
    nombre VARCHAR(120) NOT NULL,
    debitos BIGINT NOT NULL DEFAULT 0,
    creditos BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (empresa_id, anio, codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ctrib_homologacion (
    empresa_id INT NOT NULL DEFAULT 0,
    anio INT NOT NULL,
    cuenta VARCHAR(20) NOT NULL,
    codigo CHAR(4) NOT NULL,
    usuario VARCHAR(120) NOT NULL DEFAULT '',
    actualizado VARCHAR(25) NOT NULL,
    PRIMARY KEY (empresa_id, anio, cuenta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ctrib_historial (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT NOT NULL DEFAULT 0,
    anio INT NOT NULL,
    entidad VARCHAR(20) NOT NULL,
    referencia VARCHAR(40) NOT NULL,
    antes VARCHAR(255) NOT NULL DEFAULT '',
    despues VARCHAR(255) NOT NULL DEFAULT '',
    motivo VARCHAR(255) NOT NULL DEFAULT '',
    usuario VARCHAR(120) NOT NULL DEFAULT '',
    creado VARCHAR(25) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
