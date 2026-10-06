<?php
/**
 * Módulo de Recursos Humanos: Contratos, permisos, feriados, finiquitos
 * Requiere: icontador_db() de api/db.php
 */

function rrhh_schema(PDO $db): void {
    try {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $sql = file_get_contents(__DIR__ . '/../schema/rrhh.mysql.sql');
            foreach (explode(';', $sql) as $stmt) {
                $s = trim($stmt);
                if (!empty($s)) {
                    $db->exec($s);
                }
            }
            agregar_empresa_id($db);
        } else {
            // SQLite
            $statements = [
                'CREATE TABLE IF NOT EXISTS contratos (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    empresa_id INTEGER NOT NULL DEFAULT 0,
                    empleado_rut TEXT NOT NULL,
                    empleado_nombre TEXT NOT NULL,
                    fecha_inicio TEXT NOT NULL,
                    fecha_termino TEXT,
                    tipo_contrato TEXT NOT NULL DEFAULT "indefinido",
                    cargo TEXT NOT NULL,
                    lugar_prestacion TEXT NOT NULL,
                    jornada_tipo TEXT NOT NULL DEFAULT "completa",
                    jornada_horas INTEGER NOT NULL DEFAULT 44,
                    sueldo_base INTEGER NOT NULL DEFAULT 0,
                    sueldo_uf INTEGER NOT NULL DEFAULT 0,
                    beneficios TEXT,
                    activo INTEGER NOT NULL DEFAULT 1,
                    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                )',
                'CREATE TABLE IF NOT EXISTS anexos_contrato (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    empresa_id INTEGER NOT NULL DEFAULT 0,
                    contrato_id INTEGER NOT NULL,
                    empleado_rut TEXT NOT NULL,
                    fecha TEXT NOT NULL,
                    detalle TEXT NOT NULL,
                    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (contrato_id) REFERENCES contratos(id) ON DELETE CASCADE
                )',
                'CREATE TABLE IF NOT EXISTS permisos_sin_goce (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    empresa_id INTEGER NOT NULL DEFAULT 0,
                    empleado_rut TEXT NOT NULL,
                    empleado_nombre TEXT NOT NULL,
                    servicio TEXT NOT NULL,
                    fecha_desde TEXT NOT NULL,
                    fecha_hasta TEXT NOT NULL,
                    dias INTEGER NOT NULL,
                    estado TEXT NOT NULL DEFAULT "solicitado",
                    observaciones TEXT,
                    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                )',
                'CREATE TABLE IF NOT EXISTS feriados_legal (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    empresa_id INTEGER NOT NULL DEFAULT 0,
                    empleado_rut TEXT NOT NULL,
                    empleado_nombre TEXT NOT NULL,
                    servicio TEXT NOT NULL,
                    fecha_desde TEXT NOT NULL,
                    fecha_hasta TEXT NOT NULL,
                    dias INTEGER NOT NULL,
                    anio_feriado TEXT NOT NULL,
                    estado TEXT NOT NULL DEFAULT "solicitado",
                    observaciones TEXT,
                    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                )',
                'CREATE TABLE IF NOT EXISTS comprobantes_feriado (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    empresa_id INTEGER NOT NULL DEFAULT 0,
                    feriado_legal_id INTEGER,
                    empleado_rut TEXT NOT NULL,
                    empleado_nombre TEXT NOT NULL,
                    fecha_desde TEXT NOT NULL,
                    fecha_hasta TEXT NOT NULL,
                    lugar TEXT,
                    dias_usados INTEGER NOT NULL,
                    valor_diario INTEGER NOT NULL DEFAULT 0,
                    total INTEGER NOT NULL DEFAULT 0,
                    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (feriado_legal_id) REFERENCES feriados_legal(id) ON DELETE SET NULL
                )',
                'CREATE TABLE IF NOT EXISTS finiquitos (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    empresa_id INTEGER NOT NULL DEFAULT 0,
                    empleado_rut TEXT NOT NULL,
                    empleado_nombre TEXT NOT NULL,
                    cargo TEXT NOT NULL,
                    fecha_inicio TEXT NOT NULL,
                    fecha_termino TEXT NOT NULL,
                    lugar_prestacion TEXT NOT NULL,
                    causal_termino TEXT NOT NULL,
                    dias_trabajados INTEGER NOT NULL DEFAULT 0,
                    sueldo_liquido INTEGER NOT NULL DEFAULT 0,
                    vacaciones_proporcional INTEGER NOT NULL DEFAULT 0,
                    feriado_proporcional INTEGER NOT NULL DEFAULT 0,
                    indemnizacion_aviso INTEGER NOT NULL DEFAULT 0,
                    indemnizacion_años INTEGER NOT NULL DEFAULT 0,
                    otros_conceptos TEXT,
                    total_haberes INTEGER NOT NULL DEFAULT 0,
                    descuentos_prev INTEGER NOT NULL DEFAULT 0,
                    total_descuentos INTEGER NOT NULL DEFAULT 0,
                    liquido_pagado INTEGER NOT NULL DEFAULT 0,
                    retencion_pension_alimenticia INTEGER NOT NULL DEFAULT 0,
                    observaciones TEXT,
                    estado TEXT NOT NULL DEFAULT "borrador",
                    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                )',
                'CREATE INDEX IF NOT EXISTS idx_contratos_empresa ON contratos(empresa_id)',
                'CREATE INDEX IF NOT EXISTS idx_contratos_rut ON contratos(empleado_rut)',
                'CREATE INDEX IF NOT EXISTS idx_anexos_empresa ON anexos_contrato(empresa_id)',
                'CREATE INDEX IF NOT EXISTS idx_anexos_contrato ON anexos_contrato(contrato_id)',
                'CREATE INDEX IF NOT EXISTS idx_permisos_empresa ON permisos_sin_goce(empresa_id)',
                'CREATE INDEX IF NOT EXISTS idx_permisos_rut ON permisos_sin_goce(empleado_rut)',
                'CREATE INDEX IF NOT EXISTS idx_feriados_empresa ON feriados_legal(empresa_id)',
                'CREATE INDEX IF NOT EXISTS idx_feriados_rut ON feriados_legal(empleado_rut)',
                'CREATE INDEX IF NOT EXISTS idx_comprobantes_empresa ON comprobantes_feriado(empresa_id)',
                'CREATE INDEX IF NOT EXISTS idx_comprobantes_rut ON comprobantes_feriado(empleado_rut)',
                'CREATE INDEX IF NOT EXISTS idx_finiquitos_empresa ON finiquitos(empresa_id)',
                'CREATE INDEX IF NOT EXISTS idx_finiquitos_rut ON finiquitos(empleado_rut)',
            ];

            foreach ($statements as $stmt) {
                $db->exec($stmt);
            }
            agregar_empresa_id($db);
        }
    } catch (Exception $e) {
        error_log("rrhh_schema error: {$e->getMessage()}");
    }
}

/**
 * Contratos
 */
function contrato_crear(PDO $db, int $empresa, array $datos): int {
    $query = <<<SQL
        INSERT INTO contratos (empresa_id, empleado_rut, empleado_nombre, fecha_inicio, tipo_contrato,
                               cargo, lugar_prestacion, jornada_tipo, jornada_horas, sueldo_base,
                               sueldo_uf, beneficios, activo)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
    SQL;
    $stmt = $db->prepare($query);
    $stmt->execute([
        $empresa,
        $datos['empleado_rut'] ?? '',
        $datos['empleado_nombre'] ?? '',
        $datos['fecha_inicio'] ?? date('Y-m-d'),
        $datos['tipo_contrato'] ?? 'indefinido',
        $datos['cargo'] ?? '',
        $datos['lugar_prestacion'] ?? '',
        $datos['jornada_tipo'] ?? 'completa',
        $datos['jornada_horas'] ?? 44,
        $datos['sueldo_base'] ?? 0,
        $datos['sueldo_uf'] ?? 0,
        $datos['beneficios'] ?? '',
    ]);
    return (int)$db->lastInsertId();
}

function contrato_obtener(PDO $db, int $id, int $empresa): ?array {
    $stmt = $db->prepare('SELECT * FROM contratos WHERE id = ? AND empresa_id = ?');
    $stmt->execute([$id, $empresa]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}

function contrato_por_empleado(PDO $db, int $empresa, string $empleado_rut): ?array {
    $stmt = $db->prepare('SELECT * FROM contratos WHERE empresa_id = ? AND empleado_rut = ? ORDER BY fecha_inicio DESC LIMIT 1');
    $stmt->execute([$empresa, $empleado_rut]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}

function contrato_actualizar(PDO $db, int $id, array $datos, int $empresa): bool {
    $campos = [];
    $valores = [];
    $permitidos = ['tipo_contrato', 'cargo', 'lugar_prestacion', 'jornada_tipo', 'jornada_horas', 'sueldo_base', 'sueldo_uf', 'beneficios', 'fecha_termino', 'activo'];
    foreach ($permitidos as $campo) {
        if (isset($datos[$campo])) {
            $campos[] = "$campo = ?";
            $valores[] = $datos[$campo];
        }
    }
    if (empty($campos)) return false;
    $valores[] = $id;
    $valores[] = $empresa;
    $query = 'UPDATE contratos SET ' . implode(', ', $campos) . ' WHERE id = ? AND empresa_id = ?';
    $stmt = $db->prepare($query);
    return $stmt->execute($valores);
}

/**
 * Permisos sin Goce
 */
function permiso_crear(PDO $db, int $empresa, array $datos): int {
    $fecha_desde = $datos['fecha_desde'] ?? date('Y-m-d');
    $fecha_hasta = $datos['fecha_hasta'] ?? date('Y-m-d');
    $dias = (int)(strtotime($fecha_hasta) - strtotime($fecha_desde)) / 86400 + 1;

    $query = <<<SQL
        INSERT INTO permisos_sin_goce (empresa_id, empleado_rut, empleado_nombre, servicio,
                                       fecha_desde, fecha_hasta, dias, estado, observaciones)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'solicitado', ?)
    SQL;
    $stmt = $db->prepare($query);
    $stmt->execute([
        $empresa,
        $datos['empleado_rut'] ?? '',
        $datos['empleado_nombre'] ?? '',
        $datos['servicio'] ?? '',
        $fecha_desde,
        $fecha_hasta,
        $dias,
        $datos['observaciones'] ?? '',
    ]);
    return (int)$db->lastInsertId();
}

function permiso_obtener(PDO $db, int $id, int $empresa): ?array {
    $stmt = $db->prepare('SELECT * FROM permisos_sin_goce WHERE id = ? AND empresa_id = ?');
    $stmt->execute([$id, $empresa]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}

/**
 * Feriados Legales
 */
function feriado_crear(PDO $db, int $empresa, array $datos): int {
    $fecha_desde = $datos['fecha_desde'] ?? date('Y-m-d');
    $fecha_hasta = $datos['fecha_hasta'] ?? date('Y-m-d');
    $dias = (int)(strtotime($fecha_hasta) - strtotime($fecha_desde)) / 86400 + 1;
    $anio = $datos['anio_feriado'] ?? date('Y');

    $query = <<<SQL
        INSERT INTO feriados_legal (empresa_id, empleado_rut, empleado_nombre, servicio,
                                    fecha_desde, fecha_hasta, dias, anio_feriado, estado, observaciones)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'solicitado', ?)
    SQL;
    $stmt = $db->prepare($query);
    $stmt->execute([
        $empresa,
        $datos['empleado_rut'] ?? '',
        $datos['empleado_nombre'] ?? '',
        $datos['servicio'] ?? '',
        $fecha_desde,
        $fecha_hasta,
        $dias,
        $anio,
        $datos['observaciones'] ?? '',
    ]);
    return (int)$db->lastInsertId();
}

function feriado_obtener(PDO $db, int $id, int $empresa): ?array {
    $stmt = $db->prepare('SELECT * FROM feriados_legal WHERE id = ? AND empresa_id = ?');
    $stmt->execute([$id, $empresa]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}

/**
 * Comprobantes de Feriado
 */
function comprobante_feriado_crear(PDO $db, int $empresa, array $datos): int {
    $query = <<<SQL
        INSERT INTO comprobantes_feriado (empresa_id, feriado_legal_id, empleado_rut, empleado_nombre,
                                          fecha_desde, fecha_hasta, lugar, dias_usados, valor_diario, total)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    SQL;
    $stmt = $db->prepare($query);
    $valor_diario = $datos['valor_diario'] ?? 0;
    $dias_usados = $datos['dias_usados'] ?? 0;
    $total = $valor_diario * $dias_usados;

    $stmt->execute([
        $empresa,
        $datos['feriado_legal_id'] ?? null,
        $datos['empleado_rut'] ?? '',
        $datos['empleado_nombre'] ?? '',
        $datos['fecha_desde'] ?? date('Y-m-d'),
        $datos['fecha_hasta'] ?? date('Y-m-d'),
        $datos['lugar'] ?? '',
        $dias_usados,
        $valor_diario,
        $total,
    ]);
    return (int)$db->lastInsertId();
}

/**
 * Finiquitos
 */
function finiquito_crear(PDO $db, int $empresa, array $datos): int {
    $query = <<<SQL
        INSERT INTO finiquitos (empresa_id, empleado_rut, empleado_nombre, cargo, fecha_inicio, fecha_termino,
                               lugar_prestacion, causal_termino, dias_trabajados, sueldo_liquido,
                               vacaciones_proporcional, feriado_proporcional, indemnizacion_aviso,
                               indemnizacion_años, otros_conceptos, total_haberes, descuentos_prev,
                               total_descuentos, liquido_pagado, retencion_pension_alimenticia, observaciones, estado)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'borrador')
    SQL;
    $stmt = $db->prepare($query);

    $total_haberes = ($datos['sueldo_liquido'] ?? 0) + ($datos['vacaciones_proporcional'] ?? 0) +
                     ($datos['feriado_proporcional'] ?? 0) + ($datos['indemnizacion_aviso'] ?? 0) +
                     ($datos['indemnizacion_años'] ?? 0);

    $total_descuentos = ($datos['descuentos_prev'] ?? 0) + ($datos['retencion_pension_alimenticia'] ?? 0);
    $liquido_pagado = $total_haberes - $total_descuentos;

    $stmt->execute([
        $empresa,
        $datos['empleado_rut'] ?? '',
        $datos['empleado_nombre'] ?? '',
        $datos['cargo'] ?? '',
        $datos['fecha_inicio'] ?? date('Y-m-d'),
        $datos['fecha_termino'] ?? date('Y-m-d'),
        $datos['lugar_prestacion'] ?? '',
        $datos['causal_termino'] ?? '',
        $datos['dias_trabajados'] ?? 0,
        $datos['sueldo_liquido'] ?? 0,
        $datos['vacaciones_proporcional'] ?? 0,
        $datos['feriado_proporcional'] ?? 0,
        $datos['indemnizacion_aviso'] ?? 0,
        $datos['indemnizacion_años'] ?? 0,
        $datos['otros_conceptos'] ?? '',
        $total_haberes,
        $datos['descuentos_prev'] ?? 0,
        $total_descuentos,
        $liquido_pagado,
        $datos['retencion_pension_alimenticia'] ?? 0,
        $datos['observaciones'] ?? '',
    ]);
    return (int)$db->lastInsertId();
}

function finiquito_obtener(PDO $db, int $id, int $empresa): ?array {
    $stmt = $db->prepare('SELECT * FROM finiquitos WHERE id = ? AND empresa_id = ?');
    $stmt->execute([$id, $empresa]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}

function finiquito_actualizar(PDO $db, int $id, array $datos, int $empresa): bool {
    $campos = [];
    $valores = [];
    $permitidos = ['cargo', 'fecha_inicio', 'fecha_termino', 'lugar_prestacion', 'causal_termino',
                  'dias_trabajados', 'sueldo_liquido', 'vacaciones_proporcional', 'feriado_proporcional',
                  'indemnizacion_aviso', 'indemnizacion_años', 'otros_conceptos', 'descuentos_prev',
                  'retencion_pension_alimenticia', 'observaciones', 'estado'];

    foreach ($permitidos as $campo) {
        if (isset($datos[$campo])) {
            $campos[] = "$campo = ?";
            $valores[] = $datos[$campo];
        }
    }

    $actual = finiquito_obtener($db, $id, $empresa);
    if ($actual) {
        $total_haberes = ($datos['sueldo_liquido'] ?? $actual['sueldo_liquido']) +
                        ($datos['vacaciones_proporcional'] ?? $actual['vacaciones_proporcional']) +
                        ($datos['feriado_proporcional'] ?? $actual['feriado_proporcional']) +
                        ($datos['indemnizacion_aviso'] ?? $actual['indemnizacion_aviso']) +
                        ($datos['indemnizacion_años'] ?? $actual['indemnizacion_años']);

        $total_descuentos = ($datos['descuentos_prev'] ?? $actual['descuentos_prev']) +
                           ($datos['retencion_pension_alimenticia'] ?? $actual['retencion_pension_alimenticia']);

        $campos[] = 'total_haberes = ?';
        $valores[] = $total_haberes;
        $campos[] = 'total_descuentos = ?';
        $valores[] = $total_descuentos;
        $campos[] = 'liquido_pagado = ?';
        $valores[] = $total_haberes - $total_descuentos;
    }

    if (empty($campos)) return false;
    $valores[] = $id;
    $valores[] = $empresa;
    $query = 'UPDATE finiquitos SET ' . implode(', ', $campos) . ' WHERE id = ? AND empresa_id = ?';
    $stmt = $db->prepare($query);
    return $stmt->execute($valores);
}
