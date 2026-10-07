<?php
/**
 * Presupuestos - Comparativa Presupuestado vs Real
 *
 * Gestiona presupuestos por empresa, calcula varianzas contra
 * vouchers reales, y genera alertas si varianza > 10%
 */

class Presupuestos {
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

        $db->exec("CREATE TABLE IF NOT EXISTS presupuestos (
            id $id,
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
        )$motor");

        $db->exec("CREATE TABLE IF NOT EXISTS presupuesto_lineas (
            id $id,
            presupuesto_id INT UNSIGNED NOT NULL,
            orden INT NOT NULL DEFAULT 0,
            cuenta_codigo VARCHAR(20) NOT NULL,
            concepto VARCHAR(200) NOT NULL DEFAULT '',
            monto_presupuestado BIGINT NOT NULL DEFAULT 0,
            FOREIGN KEY (presupuesto_id) REFERENCES presupuestos(id) ON DELETE CASCADE,
            KEY idx_presupuesto_lineas_presupuesto (presupuesto_id),
            KEY idx_presupuesto_lineas_cuenta (cuenta_codigo)
        )$motor");
    }

    /**
     * Obtener comparativa Presupuesto vs Real para un período
     * @param int $anio
     * @param int|null $mes (null para anual)
     * @return array Comparativa con varianzas
     */
    public function obtenerComparativa($anio, $mes = null): array {
        try {
            // Obtener presupuesto del período
            $presupuesto = $this->obtenerPresupuesto($anio, $mes);
            if (!$presupuesto) {
                return [
                    'presupuesto_id' => null,
                    'periodo' => $mes ? "$anio-" . str_pad($mes, 2, '0', STR_PAD_LEFT) : $anio,
                    'lineas' => [],
                    'alertas' => [],
                    'totales' => ['presupuestado' => 0, 'real' => 0, 'varianza' => 0, 'varianza_pct' => 0],
                ];
            }

            // Obtener líneas con monto real desde vouchers
            $lineas = $this->obtenerLineasConReal($presupuesto['id'], $anio, $mes);

            // Calcular varianzas y detectar alertas
            $alertas = [];
            $totalPresupuestado = 0;
            $totalReal = 0;

            foreach ($lineas as &$linea) {
                $totalPresupuestado += $linea['monto_presupuestado'];
                $totalReal += $linea['monto_real'];

                $linea['varianza'] = $linea['monto_real'] - $linea['monto_presupuestado'];
                $linea['varianza_pct'] = $linea['monto_presupuestado'] > 0
                    ? round(($linea['varianza'] / $linea['monto_presupuestado']) * 100, 2)
                    : 0;

                // Alerta si varianza > 10% o < -10%
                if (abs($linea['varianza_pct']) > 10) {
                    $alertas[] = [
                        'tipo' => $linea['varianza_pct'] > 10 ? 'warning' : 'info',
                        'titulo' => $linea['concepto'] ?: $linea['cuenta_codigo'],
                        'mensaje' => $linea['varianza_pct'] > 0
                            ? "Gasto {$linea['varianza_pct']}% sobre presupuesto"
                            : "Ahorro del " . abs($linea['varianza_pct']) . "%",
                        'cuenta' => $linea['cuenta_codigo'],
                        'presupuestado' => $linea['monto_presupuestado'],
                        'real' => $linea['monto_real'],
                    ];
                }
            }

            $varianzaTotal = $totalReal - $totalPresupuestado;
            $varianzaTotalPct = $totalPresupuestado > 0
                ? round(($varianzaTotal / $totalPresupuestado) * 100, 2)
                : 0;

            return [
                'presupuesto_id' => $presupuesto['id'],
                'nombre' => $presupuesto['nombre'],
                'periodo' => $mes ? "$anio-" . str_pad($mes, 2, '0', STR_PAD_LEFT) : $anio,
                'lineas' => $lineas,
                'alertas' => $alertas,
                'totales' => [
                    'presupuestado' => $totalPresupuestado,
                    'real' => $totalReal,
                    'varianza' => $varianzaTotal,
                    'varianza_pct' => $varianzaTotalPct,
                ],
            ];
        } catch (Exception $e) {
            return [
                'presupuesto_id' => null,
                'periodo' => $mes ? "$anio-" . str_pad($mes, 2, '0', STR_PAD_LEFT) : $anio,
                'lineas' => [],
                'alertas' => [],
                'totales' => ['presupuestado' => 0, 'real' => 0, 'varianza' => 0, 'varianza_pct' => 0],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Obtener presupuesto del período
     */
    private function obtenerPresupuesto($anio, $mes = null): ?array {
        $sql = "SELECT id, nombre, periodo FROM presupuestos
                WHERE empresa_id = ? AND anio = ? AND estado = 'activo'";
        $params = [$this->empresa_id, $anio];

        if ($mes !== null) {
            $sql .= " AND mes = ?";
            $params[] = $mes;
        } else {
            $sql .= " AND mes IS NULL";
        }

        $result = $this->db->query($sql, $params)->fetch();
        return $result ?: null;
    }

    /**
     * Obtener líneas de presupuesto con monto real desde vouchers
     */
    private function obtenerLineasConReal($presupuesto_id, $anio, $mes = null): array {
        $sql = "SELECT
                    pl.id,
                    pl.cuenta_codigo,
                    pl.concepto,
                    pl.monto_presupuestado,
                    COALESCE(SUM(CASE WHEN v.tipo='Egreso' THEN vl.debe ELSE 0 END), 0) as monto_real
                FROM presupuesto_lineas pl
                LEFT JOIN vouchers v ON v.empresa_id = ?
                    AND YEAR(v.fecha) = ?
                    AND v.tipo = 'Egreso'
                    AND v.estado = 'registrado'
                LEFT JOIN voucher_lineas vl ON vl.voucher_id = v.id
                    AND vl.cuenta = pl.cuenta_codigo
                WHERE pl.presupuesto_id = ?
                GROUP BY pl.id, pl.cuenta_codigo, pl.concepto, pl.monto_presupuestado
                ORDER BY pl.orden";

        $params = [$this->empresa_id, $anio, $presupuesto_id];

        if ($mes !== null) {
            $sql = str_replace(
                "AND YEAR(v.fecha) = ?",
                "AND YEAR(v.fecha) = ? AND MONTH(v.fecha) = ?",
                $sql
            );
            array_splice($params, 2, 0, [$mes]);
        }

        return $this->db->query($sql, $params)->fetchAll() ?: [];
    }

    /**
     * Crear presupuesto
     */
    public function crearPresupuesto($nombre, $anio, $mes, $periodo, $lineas): int {
        $creado_en = date('Y-m-d H:i:s');

        // Insertar presupuesto
        $stmt = $this->db->prepare(
            "INSERT INTO presupuestos (empresa_id, nombre, anio, mes, periodo, estado, creado_en, actualizado_en)
             VALUES (?, ?, ?, ?, ?, 'activo', ?, ?)"
        );
        $stmt->execute([$this->empresa_id, $nombre, $anio, $mes, $periodo, $creado_en, $creado_en]);
        $presupuesto_id = $this->db->lastInsertId();

        // Insertar líneas
        $stmtLinea = $this->db->prepare(
            "INSERT INTO presupuesto_lineas (presupuesto_id, orden, cuenta_codigo, concepto, monto_presupuestado)
             VALUES (?, ?, ?, ?, ?)"
        );

        foreach ($lineas as $orden => $linea) {
            $stmtLinea->execute([
                $presupuesto_id,
                $orden,
                $linea['cuenta_codigo'],
                $linea['concepto'] ?? '',
                $linea['monto_presupuestado'],
            ]);
        }

        return $presupuesto_id;
    }

    /**
     * Listar presupuestos por año
     */
    public function listarPresupuestos($anio): array {
        try {
            $result = $this->db->query(
                "SELECT id, nombre, anio, mes, periodo, estado FROM presupuestos
                 WHERE empresa_id = ? AND anio = ?
                 ORDER BY mes ASC",
                [$this->empresa_id, $anio]
            )->fetchAll();

            return $result ?: [];
        } catch (Exception $e) {
            return [];
        }
    }
}
