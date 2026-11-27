<?php
/**
 * Reportes Avanzados - Generación de reportes contables
 *
 * Genera reportes de Libro Diario, Mayor, Balance, Estado de Resultado y Flujo de Caja
 * usando el esquema real de vouchers, voucher_lineas y cuentas.
 */

require_once __DIR__ . '/vouchers.php';

class Reportes {
    private PDO $db;
    private int $empresa_id;

    public function __construct(PDO $db, int $empresa_id) {
        $this->db = $db;
        $this->empresa_id = $empresa_id;
    }

    private function consultar(string $sql, array $params = []): PDOStatement {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    private function asegurarEsquema(): void {
        $existe = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? (bool) $this->consultar(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vouchers'"
            )->fetchColumn()
            : (bool) $this->consultar(
                "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'vouchers'"
            )->fetchColumn();

        if (!$existe) {
            crearEsquema($this->db);
        }
    }

    private function normalizarTipoVoucher(?string $tipo): ?string {
        if ($tipo === null || $tipo === '') {
            return null;
        }
        $mapa = ['I' => 'I', 'Ingreso' => 'I', 'E' => 'E', 'Egreso' => 'E', 'T' => 'T', 'Traspaso' => 'T'];
        return $mapa[$tipo] ?? null;
    }

    private function filtroVouchersSql(string $desde, string $hasta, string $registro, ?string $tipoVoucher): array {
        $where = ['v.empresa_id = ?', 'v.fecha BETWEEN ? AND ?'];
        $params = [$this->empresa_id, $desde, $hasta];

        if (in_array($registro, ['IFRS', 'Tributario'], true)) {
            $where[] = "(v.registro = 'Ambos' OR v.registro = ?)";
            $params[] = $registro;
        }

        $codigoTipo = $this->normalizarTipoVoucher($tipoVoucher);
        if ($codigoTipo !== null) {
            $where[] = 'v.tipo = ?';
            $params[] = $codigoTipo;
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * Obtener datos de reportes contables con filtros
     */
    public function obtenerDatosReporte(string $tipo, array $filtros = []): array {
        $desdeF = $filtros['desde'] ?? date('Y-01-01');
        $hastaF = $filtros['hasta'] ?? date('Y-m-d');
        $registro = $filtros['registro'] ?? 'Ambos';
        $tipoVoucher = $filtros['tipo'] ?? null;

        try {
            $this->asegurarEsquema();

            return match ($tipo) {
                'diario' => $this->reporteDiario($desdeF, $hastaF, $registro, $tipoVoucher),
                'mayor' => $this->reporteMayor($desdeF, $hastaF, $registro, $tipoVoucher),
                'balance' => $this->reporteBalance($desdeF, $hastaF, $registro, $tipoVoucher),
                'resultado' => $this->reporteResultado($desdeF, $hastaF, $registro, $tipoVoucher),
                'flujo' => $this->reporteFlujo($desdeF, $hastaF, $registro, $tipoVoucher),
                default => [],
            };
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Reporte Libro Diario
     */
    private function reporteDiario(string $desde, string $hasta, string $registro, ?string $tipoVoucher = null): array {
        [$where, $params] = $this->filtroVouchersSql($desde, $hasta, $registro, $tipoVoucher);

        $sql = "SELECT
                    v.id,
                    v.numero,
                    v.tipo,
                    v.fecha,
                    v.registro,
                    v.glosa AS descripcion,
                    COALESCE(SUM(vl.debe), 0) AS total_debe,
                    COALESCE(SUM(vl.haber), 0) AS total_haber
                FROM vouchers v
                LEFT JOIN voucher_lineas vl ON v.id = vl.voucher_id
                WHERE $where
                GROUP BY v.id, v.numero, v.tipo, v.fecha, v.registro, v.glosa
                ORDER BY v.fecha, v.numero, v.id";

        $filas = $this->consultar($sql, $params)->fetchAll();
        $vouchers = array_map(fn($v) => [
            'id' => (int) $v['id'],
            'numero' => (int) $v['numero'],
            'tipo' => TIPOS[$v['tipo']] ?? $v['tipo'],
            'tipo_codigo' => $v['tipo'],
            'fecha' => substr((string) $v['fecha'], 0, 10),
            'registro' => $v['registro'],
            'descripcion' => $v['descripcion'],
            'glosa' => $v['descripcion'],
            'total_debe' => (int) $v['total_debe'],
            'total_haber' => (int) $v['total_haber'],
        ], $filas);

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
     * Movimientos acumulados por cuenta en el período/filtros para la empresa actual.
     */
    private function consultarCuentasConMovimientos(string $desde, string $hasta, string $registro, ?string $tipoVoucher = null, string $extraWhereCuentas = ''): array {
        [$whereV, $paramsV] = $this->filtroVouchersSql($desde, $hasta, $registro, $tipoVoucher);
        $params = array_merge($paramsV, [$this->empresa_id]);

        $sql = "SELECT
                    c.codigo,
                    c.nombre,
                    COALESCE(SUM(mv.debe), 0) AS debe_total,
                    COALESCE(SUM(mv.haber), 0) AS haber_total
                FROM cuentas c
                LEFT JOIN (
                    SELECT vl.cuenta, vl.debe, vl.haber
                    FROM voucher_lineas vl
                    JOIN vouchers v ON vl.voucher_id = v.id
                    WHERE $whereV
                ) mv ON mv.cuenta = c.codigo
                WHERE c.empresa_id = ? $extraWhereCuentas
                GROUP BY c.codigo, c.nombre
                ORDER BY c.codigo";

        return $this->consultar($sql, $params)->fetchAll();
    }

    /**
     * Reporte Mayor (cuentas)
     */
    private function reporteMayor(string $desde, string $hasta, string $registro, ?string $tipoVoucher = null): array {
        $filas = $this->consultarCuentasConMovimientos($desde, $hasta, $registro, $tipoVoucher);
        $cuentas = array_map(fn($c) => [
            'codigo' => $c['codigo'],
            'nombre' => $c['nombre'],
            'debe_total' => (int) $c['debe_total'],
            'haber_total' => (int) $c['haber_total'],
        ], $filas);

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
    private function reporteBalance(string $desde, string $hasta, string $registro, ?string $tipoVoucher = null): array {
        $datos = $this->consultarCuentasConMovimientos($desde, $hasta, $registro, $tipoVoucher);

        $balance = [];
        foreach ($datos as $fila) {
            $clase = match ($fila['codigo'][0] ?? '') {
                '1' => 'Activo',
                '2' => 'Pasivo',
                '3' => 'Resultado',
                '4' => 'Ingresos',
                default => 'Otro',
            };
            $balance[$clase][] = [
                'clase' => $clase,
                'codigo' => $fila['codigo'],
                'nombre' => $fila['nombre'],
                'debe' => (int) $fila['debe_total'],
                'haber' => (int) $fila['haber_total'],
            ];
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
    private function reporteResultado(string $desde, string $hasta, string $registro, ?string $tipoVoucher = null): array {
        $datos = $this->consultarCuentasConMovimientos(
            $desde,
            $hasta,
            $registro,
            $tipoVoucher,
            "AND (c.codigo LIKE '3%' OR c.codigo LIKE '4%')"
        );

        $items = [];
        $totalIngresos = 0;
        $totalGastos = 0;

        foreach ($datos as $fila) {
            $esIngreso = str_starts_with((string) $fila['codigo'], '4');
            $debe = (int) $fila['debe_total'];
            $haber = (int) $fila['haber_total'];
            $ingresos = $esIngreso ? ($haber - $debe) : 0;
            $gastos = $esIngreso ? 0 : ($debe - $haber);

            $totalIngresos += $ingresos;
            $totalGastos += $gastos;

            $items[] = [
                'tipo' => $esIngreso ? 'Ingresos' : 'Gastos',
                'codigo' => $fila['codigo'],
                'nombre' => $fila['nombre'],
                'ingresos' => $ingresos,
                'gastos' => $gastos,
            ];
        }

        return [
            'tipo' => 'Estado de Resultado',
            'periodo' => "$desde a $hasta",
            'items' => $items,
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
    private function reporteFlujo(string $desde, string $hasta, string $registro = 'Ambos', ?string $tipoVoucher = null): array {
        [$where, $params] = $this->filtroVouchersSql($desde, $hasta, $registro, $tipoVoucher);
        $mysql = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $fmtMes = $mysql ? "DATE_FORMAT(v.fecha, '%Y-%m')" : "strftime('%Y-%m', v.fecha)";

        $sql = "SELECT
                    $fmtMes AS mes,
                    COALESCE(SUM(CASE WHEN v.tipo = 'I' THEN vl.debe ELSE 0 END), 0) AS ingresos,
                    COALESCE(SUM(CASE WHEN v.tipo = 'E' THEN vl.debe ELSE 0 END), 0) AS egresos
                FROM vouchers v
                JOIN voucher_lineas vl ON v.id = vl.voucher_id
                WHERE $where
                  AND v.tipo IN ('I', 'E')
                GROUP BY $fmtMes
                ORDER BY mes";

        $filas = $this->consultar($sql, $params)->fetchAll();
        $flujos = array_map(fn($f) => [
            'mes' => $f['mes'],
            'ingresos' => (int) $f['ingresos'],
            'egresos' => (int) $f['egresos'],
        ], $filas);

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
