<?php
/**
 * Tesorería Mejorada - Flujo de caja y pagos programados
 *
 * Calcula flujo de caja proyectado a 30/60/90 días,
 * gestiona pagos programados/recurrentes y genera alertas de insolvencia
 */

class Tesoreria {
    private $db;
    private $empresa_id;

    public function __construct($db, $empresa_id) {
        $this->db = $db;
        $this->empresa_id = $empresa_id;
    }

    /**
     * Crear tabla de esquema si no existe
     * @param PDO $db
     */
    public static function crearEsquema($db): void {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $motor = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        $db->exec("CREATE TABLE IF NOT EXISTS pagos_programados (
            id $id,
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
        )$motor");

        $db->exec("CREATE TABLE IF NOT EXISTS flujo_proyecciones (
            id $id,
            empresa_id INT NOT NULL DEFAULT 0,
            fecha DATE NOT NULL,
            saldo_estimado BIGINT NOT NULL DEFAULT 0,
            ingresos_acumulados BIGINT NOT NULL DEFAULT 0,
            egresos_acumulados BIGINT NOT NULL DEFAULT 0,
            creado_en VARCHAR(25) NOT NULL,
            UNIQUE KEY uq_flujo_proyecciones (empresa_id, fecha)
        )$motor");
    }

    /**
     * Obtener flujo de caja proyectado para N días
     * @param int $dias (30, 60, 90)
     * @return array Flujo proyectado con alertas
     */
    public function obtenerFlujoCaja($dias = 30): array {
        try {
            $saldoActual = $this->obtenerSaldoActual();
            $hoy = date('Y-m-d');
            $fechaFin = date('Y-m-d', strtotime("+$dias days"));

            // Obtener flujos diarios proyectados
            $flujos = $this->calcularFlujoDiario($hoy, $fechaFin, $saldoActual);

            // Detectar alertas de insolvencia
            $alertas = $this->detectarAlertas($flujos);

            return [
                'saldo_actual' => $saldoActual,
                'dias_proyectados' => $dias,
                'fecha_inicio' => $hoy,
                'fecha_fin' => $fechaFin,
                'flujos' => $flujos,
                'alertas' => $alertas,
                'saldo_minimo' => min(array_column($flujos, 'saldo_estimado')),
                'saldo_promedio' => array_sum(array_column($flujos, 'saldo_estimado')) / count($flujos),
            ];
        } catch (Exception $e) {
            return [
                'error' => $e->getMessage(),
                'flujos' => [],
                'alertas' => [],
            ];
        }
    }

    /**
     * Calcular saldo actual desde banco_movimientos
     */
    private function obtenerSaldoActual(): int {
        try {
            $result = $this->db->query(
                "SELECT SUM(CASE WHEN tipo='deposito' THEN monto ELSE -monto END) as saldo
                 FROM banco_movimientos
                 WHERE empresa_id = ?",
                [$this->empresa_id]
            )->fetch();

            return $result['saldo'] ?? 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Calcular flujo diario proyectado (combina vouchers reales + pagos programados)
     */
    private function calcularFlujoDiario($fechaInicio, $fechaFin, $saldoInicial): array {
        $flujos = [];
        $saldoAcumulado = $saldoInicial;
        $ingresoAcumulado = 0;
        $egresoAcumulado = 0;

        $fecha = new DateTime($fechaInicio);
        $fechaTermino = new DateTime($fechaFin);

        while ($fecha <= $fechaTermino) {
            $fechaFormato = $fecha->format('Y-m-d');

            // Obtener ingresos/egresos reales de ese día (si pasado)
            $movDia = $this->obtenerMovimientoDia($fechaFormato);

            // Obtener pagos programados de ese día
            $pagosDia = $this->obtenerPagosProgramados($fechaFormato);

            $ingresosDia = $movDia['ingresos'] + $pagosDia['ingresos'];
            $egresosDia = $movDia['egresos'] + $pagosDia['egresos'];
            $neto = $ingresosDia - $egresosDia;

            $saldoAcumulado += $neto;
            $ingresoAcumulado += $ingresosDia;
            $egresoAcumulado += $egresosDia;

            $flujos[] = [
                'fecha' => $fechaFormato,
                'dia_semana' => $this->nombreDiaSemana($fecha),
                'ingresos' => $ingresosDia,
                'egresos' => $egresosDia,
                'neto' => $neto,
                'saldo_estimado' => $saldoAcumulado,
                'ingresos_acumulados' => $ingresoAcumulado,
                'egresos_acumulados' => $egresoAcumulado,
                'es_futuro' => $fechaFormato > date('Y-m-d'),
            ];

            $fecha->modify('+1 day');
        }

        return $flujos;
    }

    /**
     * Obtener movimientos reales de un día
     */
    private function obtenerMovimientoDia($fecha): array {
        $sql = "SELECT
                    SUM(CASE WHEN v.tipo='Ingreso' THEN vl.debe ELSE 0 END) as ingresos,
                    SUM(CASE WHEN v.tipo='Egreso' THEN vl.debe ELSE 0 END) as egresos
                FROM vouchers v
                LEFT JOIN voucher_lineas vl ON v.id = vl.voucher_id
                WHERE v.empresa_id = ?
                AND DATE(v.fecha) = ?
                AND v.estado = 'registrado'";

        $result = $this->db->query($sql, [$this->empresa_id, $fecha])->fetch();
        return [
            'ingresos' => $result['ingresos'] ?? 0,
            'egresos' => $result['egresos'] ?? 0,
        ];
    }

    /**
     * Obtener pagos programados para una fecha
     */
    private function obtenerPagosProgramados($fecha): array {
        $hoy = date('Y-m-d');
        $soloFuturos = $fecha > $hoy;

        $sql = "SELECT tipo, SUM(monto) as total
                FROM pagos_programados
                WHERE empresa_id = ? AND estado = 'pendiente'
                AND (
                    (recurrencia = 'una_vez' AND DATE(fecha_pago) = ?)
                    OR (recurrencia = 'diaria' AND DATE(fecha_pago) <= ? AND (fecha_fin IS NULL OR DATE(fecha_fin) >= ?))
                    OR (recurrencia = 'semanal' AND DAYOFWEEK(fecha_pago) = DAYOFWEEK(?) AND DATE(fecha_pago) <= ? AND (fecha_fin IS NULL OR DATE(fecha_fin) >= ?))
                    OR (recurrencia = 'mensual' AND DAY(fecha_pago) = DAY(?) AND DATE(fecha_pago) <= ? AND (fecha_fin IS NULL OR DATE(fecha_fin) >= ?))
                )
                GROUP BY tipo";

        $params = [$this->empresa_id, $fecha, $fecha, $fecha, $fecha, $fecha, $fecha, $fecha, $fecha, $fecha];
        $resultado = $this->db->query($sql, $params)->fetchAll();

        $ingresos = 0;
        $egresos = 0;

        foreach ($resultado as $row) {
            if ($row['tipo'] === 'ingreso') {
                $ingresos += $row['total'];
            } else {
                $egresos += $row['total'];
            }
        }

        return ['ingresos' => $ingresos, 'egresos' => $egresos];
    }

    /**
     * Detectar alertas de insolvencia
     */
    private function detectarAlertas($flujos): array {
        $alertas = [];

        // Buscar saldos negativos
        $diasNegativos = array_filter($flujos, fn($f) => $f['saldo_estimado'] < 0);
        if (count($diasNegativos) > 0) {
            $primeraNegativa = reset($diasNegativos);
            $alertas[] = [
                'tipo' => 'error',
                'titulo' => '⚠️ Insolvencia Proyectada',
                'mensaje' => 'Saldo estimado negativo a partir del ' . $this->formatearFecha($primeraNegativa['fecha']),
                'fecha' => $primeraNegativa['fecha'],
                'saldo' => $primeraNegativa['saldo_estimado'],
            ];
        }

        // Alertas de saldo bajo (< 500k CLP)
        $diasBajos = array_filter($flujos, fn($f) => $f['saldo_estimado'] > 0 && $f['saldo_estimado'] < 500000);
        if (count($diasBajos) > 2) {
            $alertas[] = [
                'tipo' => 'warning',
                'titulo' => '⚠️ Saldo Bajo Sostenido',
                'mensaje' => 'Más de 2 días con saldo < $500.000',
                'dias_afectados' => count($diasBajos),
            ];
        }

        return $alertas;
    }

    /**
     * Crear pago programado
     */
    public function crearPagoProgramado($concepto, $monto, $fechaPago, $tipo = 'pago', $recurrencia = 'una_vez'): int {
        $creado_en = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            "INSERT INTO pagos_programados
             (empresa_id, concepto, monto, fecha_pago, tipo, recurrencia, creado_en, actualizado_en)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->execute([
            $this->empresa_id,
            $concepto,
            $monto,
            $fechaPago,
            $tipo,
            $recurrencia,
            $creado_en,
            $creado_en,
        ]);

        return $this->db->lastInsertId();
    }

    /**
     * Listar pagos programados próximos
     */
    public function listarPagosProgramados($dias = 30): array {
        try {
            $hoy = date('Y-m-d');
            $fechaFin = date('Y-m-d', strtotime("+$dias days"));

            $result = $this->db->query(
                "SELECT id, concepto, monto, fecha_pago, tipo, recurrencia, estado
                 FROM pagos_programados
                 WHERE empresa_id = ? AND estado = 'pendiente'
                 AND fecha_pago BETWEEN ? AND ?
                 ORDER BY fecha_pago ASC",
                [$this->empresa_id, $hoy, $fechaFin]
            )->fetchAll();

            return $result ?: [];
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Helpers
     */
    private function nombreDiaSemana(DateTime $fecha): string {
        $dias = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
        return $dias[$fecha->format('w')];
    }

    private function formatearFecha($fecha): string {
        $dt = new DateTime($fecha);
        return $dt->format('d/m/Y');
    }
}
