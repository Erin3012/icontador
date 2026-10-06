<?php
/**
 * Módulo de Recursos Humanos: Contratos, permisos, feriados, finiquitos
 * Requiere: icontador_db() de api/db.php
 */

function rrhh_init_schema() {
    global $pdo;
    try {
        $sql = file_get_contents(__DIR__ . '/../schema/rrhh.mysql.sql');
        foreach (explode(';', $sql) as $stmt) {
            $s = trim($stmt);
            if (!empty($s)) {
                $pdo->exec($s);
            }
        }
    } catch (Exception $e) {
        error_log("rrhh_init_schema error: {$e->getMessage()}");
    }
}

/**
 * Contratos
 */
function contrato_crear($empresa_id, $datos) {
    global $pdo;
    $query = <<<SQL
        INSERT INTO contratos (empresa_id, empleado_rut, empleado_nombre, fecha_inicio, tipo_contrato,
                               cargo, lugar_prestacion, jornada_tipo, jornada_horas, sueldo_base,
                               sueldo_uf, beneficios, activo)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
    SQL;
    $stmt = $pdo->prepare($query);
    $stmt->execute([
        $empresa_id,
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
    return $pdo->lastInsertId();
}

function contrato_obtener($id) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM contratos WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function contrato_por_empleado($empresa_id, $empleado_rut) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM contratos WHERE empresa_id = ? AND empleado_rut = ? ORDER BY fecha_inicio DESC LIMIT 1');
    $stmt->execute([$empresa_id, $empleado_rut]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function contrato_actualizar($id, $datos) {
    global $pdo;
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
    $query = 'UPDATE contratos SET ' . implode(', ', $campos) . ' WHERE id = ?';
    $stmt = $pdo->prepare($query);
    return $stmt->execute($valores);
}

/**
 * Permisos sin Goce
 */
function permiso_crear($empresa_id, $datos) {
    global $pdo;
    $fecha_desde = $datos['fecha_desde'] ?? date('Y-m-d');
    $fecha_hasta = $datos['fecha_hasta'] ?? date('Y-m-d');
    $dias = (int)(strtotime($fecha_hasta) - strtotime($fecha_desde)) / 86400 + 1;

    $query = <<<SQL
        INSERT INTO permisos_sin_goce (empresa_id, empleado_rut, empleado_nombre, servicio,
                                       fecha_desde, fecha_hasta, dias, estado, observaciones)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'solicitado', ?)
    SQL;
    $stmt = $pdo->prepare($query);
    $stmt->execute([
        $empresa_id,
        $datos['empleado_rut'] ?? '',
        $datos['empleado_nombre'] ?? '',
        $datos['servicio'] ?? '',
        $fecha_desde,
        $fecha_hasta,
        $dias,
        $datos['observaciones'] ?? '',
    ]);
    return $pdo->lastInsertId();
}

function permiso_obtener($id) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM permisos_sin_goce WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Feriados Legales
 */
function feriado_crear($empresa_id, $datos) {
    global $pdo;
    $fecha_desde = $datos['fecha_desde'] ?? date('Y-m-d');
    $fecha_hasta = $datos['fecha_hasta'] ?? date('Y-m-d');
    $dias = (int)(strtotime($fecha_hasta) - strtotime($fecha_desde)) / 86400 + 1;
    $anio = $datos['anio_feriado'] ?? date('Y');

    $query = <<<SQL
        INSERT INTO feriados_legal (empresa_id, empleado_rut, empleado_nombre, servicio,
                                    fecha_desde, fecha_hasta, dias, anio_feriado, estado, observaciones)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'solicitado', ?)
    SQL;
    $stmt = $pdo->prepare($query);
    $stmt->execute([
        $empresa_id,
        $datos['empleado_rut'] ?? '',
        $datos['empleado_nombre'] ?? '',
        $datos['servicio'] ?? '',
        $fecha_desde,
        $fecha_hasta,
        $dias,
        $anio,
        $datos['observaciones'] ?? '',
    ]);
    return $pdo->lastInsertId();
}

function feriado_obtener($id) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM feriados_legal WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Comprobantes de Feriado
 */
function comprobante_feriado_crear($empresa_id, $datos) {
    global $pdo;
    $query = <<<SQL
        INSERT INTO comprobantes_feriado (empresa_id, feriado_legal_id, empleado_rut, empleado_nombre,
                                          fecha_desde, fecha_hasta, lugar, dias_usados, valor_diario, total)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    SQL;
    $stmt = $pdo->prepare($query);
    $valor_diario = $datos['valor_diario'] ?? 0;
    $dias_usados = $datos['dias_usados'] ?? 0;
    $total = $valor_diario * $dias_usados;

    $stmt->execute([
        $empresa_id,
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
    return $pdo->lastInsertId();
}

/**
 * Finiquitos
 */
function finiquito_crear($empresa_id, $datos) {
    global $pdo;
    $query = <<<SQL
        INSERT INTO finiquitos (empresa_id, empleado_rut, empleado_nombre, cargo, fecha_inicio, fecha_termino,
                               lugar_prestacion, causal_termino, dias_trabajados, sueldo_liquido,
                               vacaciones_proporcional, feriado_proporcional, indemnizacion_aviso,
                               indemnizacion_años, otros_conceptos, total_haberes, descuentos_prev,
                               total_descuentos, liquido_pagado, retencion_pension_alimenticia, observaciones, estado)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'borrador')
    SQL;
    $stmt = $pdo->prepare($query);

    // Calcular total_haberes
    $total_haberes = ($datos['sueldo_liquido'] ?? 0) + ($datos['vacaciones_proporcional'] ?? 0) +
                     ($datos['feriado_proporcional'] ?? 0) + ($datos['indemnizacion_aviso'] ?? 0) +
                     ($datos['indemnizacion_años'] ?? 0);

    // Calcular totales descuentos
    $total_descuentos = ($datos['descuentos_prev'] ?? 0) + ($datos['retencion_pension_alimenticia'] ?? 0);
    $liquido_pagado = $total_haberes - $total_descuentos;

    $stmt->execute([
        $empresa_id,
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
    return $pdo->lastInsertId();
}

function finiquito_obtener($id) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM finiquitos WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function finiquito_actualizar($id, $datos) {
    global $pdo;
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

    // Recalcular totales si hay cambios
    $actual = finiquito_obtener($id);
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
    $query = 'UPDATE finiquitos SET ' . implode(', ', $campos) . ' WHERE id = ?';
    $stmt = $pdo->prepare($query);
    return $stmt->execute($valores);
}
