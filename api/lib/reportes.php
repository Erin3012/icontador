<?php
/**
 * Reportes Avanzados - Exportación y generación de reportes
 *
 * Genera reportes en Excel/PDF con filtros, gráficos y datos detallados
 */

class Reportes {
    private $db;
    private $empresa_id;

    public function __construct($db, $empresa_id) {
        $this->db = $db;
        $this->empresa_id = $empresa_id;
    }

    /**
     * Obtener datos de reportes contables con filtros
     */
    public function obtenerDatosReporte($tipo, $filtros = []): array {
        $desdeF = $filtros['desde'] ?? date('Y-01-01');
        $hastaF = $filtros['hasta'] ?? date('Y-m-d');
        $registro = $filtros['registro'] ?? 'Ambos';
        $tipoVoucher = $filtros['tipo'] ?? null;

        try {
            switch ($tipo) {
                case 'diario':
                    return $this->reporteDiario($desdeF, $hastaF, $registro);
                case 'mayor':
                    return $this->reporteMayor($desdeF, $hastaF, $registro);
                case 'balance':
                    return $this->reporteBalance($desdeF, $hastaF, $registro);
                case 'resultado':
                    return $this->reporteResultado($desdeF, $hastaF, $registro);
                case 'flujo':
                    return $this->reporteFlujo($desdeF, $hastaF);
                default:
                    return [];
            }
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Reporte Libro Diario
     */
    private function reporteDiario($desde, $hasta, $registro): array {
        $sql = "SELECT
                    v.id, v.numero, v.tipo, v.fecha, v.descripcion,
                    SUM(vl.debe) as total_debe,
                    SUM(vl.haber) as total_haber
                FROM vouchers v
                LEFT JOIN voucher_lineas vl ON v.id = vl.voucher_id
                WHERE v.empresa_id = ?
                AND v.estado = 'registrado'
                AND v.fecha BETWEEN ? AND ?";

        $params = [$this->empresa_id, $desde, $hasta];

        if ($registro !== 'Ambos') {
            $sql .= " AND v.registro = ?";
            $params[] = $registro;
        }

        $sql .= " GROUP BY v.id ORDER BY v.fecha, v.numero";

        $vouchers = $this->db->query($sql, $params)->fetchAll();

        $totalDebe = array_sum(array_column($vouchers, 'total_debe'));
        $totalHaber = array_sum(array_column($vouchers, 'total_haber'));

        return [
            'tipo' => 'Libro Diario',
            'periodo' => "$desde a $hasta",
            'vouchers' => $vouchers,
            'totales' => [
                'debe' => $totalDebe,
                'haber' => $totalHaber,
                'cantidad' => count($vouchers),
            ],
        ];
    }

    /**
     * Reporte Mayor (cuentas)
     */
    private function reporteMayor($desde, $hasta, $registro): array {
        $sql = "SELECT DISTINCT
                    c.codigo, c.nombre,
                    SUM(CASE WHEN vl.debe > 0 THEN vl.debe ELSE 0 END) as debe_total,
                    SUM(CASE WHEN vl.haber > 0 THEN vl.haber ELSE 0 END) as haber_total
                FROM cuentas c
                LEFT JOIN voucher_lineas vl ON vl.cuenta = c.codigo
                LEFT JOIN vouchers v ON vl.voucher_id = v.id
                WHERE c.empresa_id = ?
                AND (v.id IS NULL OR (v.estado = 'registrado' AND v.fecha BETWEEN ? AND ?";

        $params = [$this->empresa_id, $desde, $hasta];

        if ($registro !== 'Ambos') {
            $sql .= " AND v.registro = ?";
            $params[] = $registro;
        }

        $sql .= "))
                GROUP BY c.codigo, c.nombre
                ORDER BY c.codigo";

        $cuentas = $this->db->query($sql, $params)->fetchAll();

        $totalDebe = array_sum(array_column($cuentas, 'debe_total'));
        $totalHaber = array_sum(array_column($cuentas, 'haber_total'));

        return [
            'tipo' => 'Libro Mayor',
            'periodo' => "$desde a $hasta",
            'cuentas' => $cuentas,
            'totales' => [
                'debe' => $totalDebe,
                'haber' => $totalHaber,
                'cantidad_cuentas' => count($cuentas),
            ],
        ];
    }

    /**
     * Reporte Balance General
     */
    private function reporteBalance($desde, $hasta, $registro): array {
        $sql = "SELECT
                    CASE WHEN c.codigo LIKE '1%' THEN 'Activo'
                         WHEN c.codigo LIKE '2%' THEN 'Pasivo'
                         WHEN c.codigo LIKE '3%' THEN 'Resultado'
                         WHEN c.codigo LIKE '4%' THEN 'Ingresos'
                         ELSE 'Otro' END as clase,
                    c.codigo, c.nombre,
                    SUM(CASE WHEN vl.debe > 0 THEN vl.debe ELSE 0 END) as debe,
                    SUM(CASE WHEN vl.haber > 0 THEN vl.haber ELSE 0 END) as haber
                FROM cuentas c
                LEFT JOIN voucher_lineas vl ON vl.cuenta = c.codigo
                LEFT JOIN vouchers v ON vl.voucher_id = v.id
                WHERE c.empresa_id = ?
                AND (v.id IS NULL OR (v.estado = 'registrado' AND v.fecha BETWEEN ? AND ?";

        $params = [$this->empresa_id, $desde, $hasta];

        if ($registro !== 'Ambos') {
            $sql .= " AND v.registro = ?";
            $params[] = $registro;
        }

        $sql .= "))
                GROUP BY clase, c.codigo, c.nombre
                ORDER BY clase, c.codigo";

        $datos = $this->db->query($sql, $params)->fetchAll();

        // Agrupar por clase
        $balance = [];
        foreach ($datos as $fila) {
            $clase = $fila['clase'];
            if (!isset($balance[$clase])) {
                $balance[$clase] = [];
            }
            $balance[$clase][] = $fila;
        }

        return [
            'tipo' => 'Balance General',
            'periodo' => "$desde a $hasta",
            'balance' => $balance,
        ];
    }

    /**
     * Reporte Estado de Resultado
     */
    private function reporteResultado($desde, $hasta, $registro): array {
        $sql = "SELECT
                    CASE WHEN c.codigo LIKE '4%' THEN 'Ingresos'
                         WHEN c.codigo LIKE '3%' THEN 'Gastos'
                         ELSE 'Otro' END as tipo,
                    c.codigo, c.nombre,
                    SUM(CASE WHEN v.tipo='Ingreso' THEN vl.debe ELSE 0 END) as ingresos,
                    SUM(CASE WHEN v.tipo='Egreso' THEN vl.debe ELSE 0 END) as gastos
                FROM cuentas c
                LEFT JOIN voucher_lineas vl ON vl.cuenta = c.codigo
                LEFT JOIN vouchers v ON vl.voucher_id = v.id
                WHERE c.empresa_id = ?
                AND (v.id IS NULL OR (v.estado = 'registrado' AND v.fecha BETWEEN ? AND ?";

        $params = [$this->empresa_id, $desde, $hasta];

        if ($registro !== 'Ambos') {
            $sql .= " AND v.registro = ?";
            $params[] = $registro;
        }

        $sql .= "))
                GROUP BY tipo, c.codigo, c.nombre
                ORDER BY tipo, c.codigo";

        $datos = $this->db->query($sql, $params)->fetchAll();

        $totalIngresos = 0;
        $totalGastos = 0;
        foreach ($datos as $fila) {
            $totalIngresos += $fila['ingresos'];
            $totalGastos += $fila['gastos'];
        }

        return [
            'tipo' => 'Estado de Resultado',
            'periodo' => "$desde a $hasta",
            'items' => $datos,
            'totales' => [
                'ingresos' => $totalIngresos,
                'gastos' => $totalGastos,
                'resultado' => $totalIngresos - $totalGastos,
            ],
        ];
    }

    /**
     * Reporte Flujo de Caja
     */
    private function reporteFlujo($desde, $hasta): array {
        $sql = "SELECT
                    DATE_FORMAT(v.fecha, '%Y-%m') as mes,
                    SUM(CASE WHEN v.tipo='Ingreso' THEN vl.debe ELSE 0 END) as ingresos,
                    SUM(CASE WHEN v.tipo='Egreso' THEN vl.debe ELSE 0 END) as egresos
                FROM vouchers v
                LEFT JOIN voucher_lineas vl ON v.id = vl.voucher_id
                WHERE v.empresa_id = ?
                AND v.estado = 'registrado'
                AND v.fecha BETWEEN ? AND ?
                GROUP BY DATE_FORMAT(v.fecha, '%Y-%m')
                ORDER BY mes";

        $flujos = $this->db->query($sql, [$this->empresa_id, $desde, $hasta])->fetchAll();

        $totalIngresos = array_sum(array_column($flujos, 'ingresos'));
        $totalEgresos = array_sum(array_column($flujos, 'egresos'));

        return [
            'tipo' => 'Flujo de Caja',
            'periodo' => "$desde a $hasta",
            'flujos' => $flujos,
            'totales' => [
                'ingresos' => $totalIngresos,
                'egresos' => $totalEgresos,
                'neto' => $totalIngresos - $totalEgresos,
            ],
        ];
    }

    /**
     * Obtener opciones de filtros disponibles
     */
    public function obtenerFiltros(): array {
        return [
            'registros' => ['Ambos', 'Tributario', 'IFRS'],
            'tipos' => ['Ingreso', 'Egreso', 'Traspaso'],
            'reportes' => [
                ['id' => 'diario', 'nombre' => 'Libro Diario'],
                ['id' => 'mayor', 'nombre' => 'Libro Mayor'],
                ['id' => 'balance', 'nombre' => 'Balance General'],
                ['id' => 'resultado', 'nombre' => 'Estado de Resultado'],
                ['id' => 'flujo', 'nombre' => 'Flujo de Caja'],
            ],
        ];
    }
}
