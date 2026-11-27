<?php
/**
 * Dashboard Ejecutivo - Lógica de negocio
 *
 * Calcula KPIs y resúmenes financieros en tiempo real
 * desde vouchers, extractos bancarios e inventario.
 */

require_once __DIR__ . '/vouchers.php';

class Dashboard {
    private PDO $db;
    private int $empresa_id;

    public function __construct(PDO $db, int $empresa_id) {
        $this->db = $db;
        $this->empresa_id = $empresa_id;
    }

    /**
     * Obtener resumen ejecutivo completo
     * @return array Datos del dashboard
     */
    public function obtenerResumen(): array {
        if (!$this->tablaExiste('vouchers')) {
            crearEsquema($this->db);
        }

        return [
            'timestamp' => date('Y-m-d H:i:s'),
            'empresa_id' => $this->empresa_id,
            'kpis' => $this->calcularKPIs(),
            'balance_caja' => $this->obtenerBalanceCaja(),
            'ultimos_vouchers' => $this->obtenerUltimosVouchers(5),
            'flujo_mensual' => $this->obtenerFlujoMensual(6),
            'alertas' => $this->obtenerAlertas(),
            'resumen_inventario' => $this->obtenerResumenInventario(),
        ];
    }

    private function consultar(string $sql, array $params = []): PDOStatement {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    private function tablaExiste(string $tabla): bool {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            return (bool) $this->consultar(
                'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$tabla]
            )->fetchColumn();
        }
        return (bool) $this->consultar(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$tabla]
        )->fetchColumn();
    }

    private function saldosContables(): array {
        $fila = $this->consultar(
            "SELECT
                COALESCE(SUM(CASE WHEN vl.cuenta LIKE '1.1.01%' OR vl.cuenta LIKE '1.1.02%' THEN vl.debe - vl.haber ELSE 0 END), 0) AS saldo_caja,
                COALESCE(SUM(CASE WHEN vl.cuenta LIKE '1%' THEN vl.debe - vl.haber ELSE 0 END), 0) AS total_activo,
                COALESCE(SUM(CASE WHEN vl.cuenta LIKE '2%' AND vl.cuenta NOT LIKE '2.3%' THEN vl.haber - vl.debe ELSE 0 END), 0) AS total_pasivo
            FROM vouchers v
            JOIN voucher_lineas vl ON v.id = vl.voucher_id
            WHERE v.empresa_id = ?",
            [$this->empresa_id]
        )->fetch();

        return [
            'saldo_caja' => (int) ($fila['saldo_caja'] ?? 0),
            'total_activo' => (int) ($fila['total_activo'] ?? 0),
            'total_pasivo' => (int) ($fila['total_pasivo'] ?? 0),
        ];
    }

    /**
     * Calcular ratios financieros clave
     * @return array KPIs
     */
    private function calcularKPIs(): array {
        try {
            $saldos = $this->saldosContables();
            $saldoCaja = $saldos['saldo_caja'];
            $totalActivo = $saldos['total_activo'];
            $totalPasivo = $saldos['total_pasivo'];

            // Si no hay movimientos contables de caja/banco, usar saldo neto de cartola si existe
            if ($saldoCaja === 0 && $this->tablaExiste('extractos')) {
                $ext = $this->consultar(
                    'SELECT COUNT(*) AS cnt, COALESCE(SUM(monto), 0) AS saldo FROM extractos WHERE empresa_id = ?',
                    [$this->empresa_id]
                )->fetch();
                if ((int) ($ext['cnt'] ?? 0) > 0) {
                    $saldoCaja = (int) $ext['saldo'];
                }
            }

            $razonCorriente = $totalPasivo > 0
                ? $totalActivo / $totalPasivo
                : ($totalActivo > 0 ? 1.0 : 0.0);
            $endeudamiento = $totalActivo > 0 ? $totalPasivo / $totalActivo : 0.0;

            return [
                'saldo_caja' => $saldoCaja,
                'total_activo' => $totalActivo,
                'total_pasivo' => $totalPasivo,
                'razon_corriente' => round($razonCorriente, 2),
                'endeudamiento' => round($endeudamiento, 2),
                'patrimonio' => $totalActivo - $totalPasivo,
            ];
        } catch (Throwable $e) {
            return [
                'saldo_caja' => 0,
                'total_activo' => 0,
                'total_pasivo' => 0,
                'razon_corriente' => 0,
                'endeudamiento' => 0,
                'patrimonio' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Obtener balance de caja y bancos por cuenta contable y cartola bancaria
     * @return array Saldos por cuenta
     */
    private function obtenerBalanceCaja(): array {
        try {
            $cuentas = $this->consultar(
                "SELECT
                    c.nombre AS banco,
                    c.codigo AS numero_cuenta,
                    COALESCE(SUM(vl.debe - vl.haber), 0) AS saldo
                FROM cuentas c
                JOIN voucher_lineas vl ON vl.cuenta = c.codigo
                JOIN vouchers v ON v.id = vl.voucher_id AND v.empresa_id = c.empresa_id
                WHERE c.empresa_id = ?
                  AND (c.codigo LIKE '1.1.01%' OR c.codigo LIKE '1.1.02%')
                GROUP BY c.codigo, c.nombre
                ORDER BY c.codigo",
                [$this->empresa_id]
            )->fetchAll();

            $resultado = array_map(fn($f) => [
                'banco' => $f['banco'],
                'numero_cuenta' => $f['numero_cuenta'],
                'saldo' => (int) $f['saldo'],
            ], $cuentas);

            if ($this->tablaExiste('extractos')) {
                $ext = $this->consultar(
                    'SELECT COUNT(*) AS cnt, COALESCE(SUM(monto), 0) AS saldo FROM extractos WHERE empresa_id = ?',
                    [$this->empresa_id]
                )->fetch();
                if ((int) ($ext['cnt'] ?? 0) > 0) {
                    $resultado[] = [
                        'banco' => 'Cartola bancaria',
                        'numero_cuenta' => 'Extractos',
                        'saldo' => (int) $ext['saldo'],
                    ];
                }
            }

            return $resultado;
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Últimos vouchers registrados
     * @param int $limit Cantidad a retornar
     * @return array Vouchers
     */
    private function obtenerUltimosVouchers(int $limit = 5): array {
        try {
            $limit = max(1, min($limit, 100));
            $filas = $this->consultar(
                "SELECT
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
                WHERE v.empresa_id = ?
                GROUP BY v.id, v.numero, v.tipo, v.fecha, v.registro, v.glosa
                ORDER BY v.fecha DESC, v.id DESC
                LIMIT $limit",
                [$this->empresa_id]
            )->fetchAll();

            return array_map(fn($v) => [
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
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Flujo de caja histórico (últimos N meses)
     * @param int $meses Meses a incluir
     * @return array Flujo por mes
     */
    private function obtenerFlujoMensual(int $meses = 6): array {
        try {
            $meses = max(1, min($meses, 24));
            $mysql = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
            $fmtMes = $mysql ? "DATE_FORMAT(v.fecha, '%Y-%m')" : "strftime('%Y-%m', v.fecha)";
            $desde = date('Y-m-d', strtotime("-{$meses} months"));

            $filas = $this->consultar(
                "SELECT
                    $fmtMes AS mes,
                    COALESCE(SUM(CASE WHEN v.tipo = 'I' THEN vl.debe ELSE 0 END), 0) AS ingresos,
                    COALESCE(SUM(CASE WHEN v.tipo = 'E' THEN vl.debe ELSE 0 END), 0) AS egresos,
                    COALESCE(SUM(CASE WHEN v.tipo = 'I' THEN vl.debe WHEN v.tipo = 'E' THEN -vl.debe ELSE 0 END), 0) AS neto
                FROM vouchers v
                JOIN voucher_lineas vl ON v.id = vl.voucher_id
                WHERE v.empresa_id = ?
                  AND v.fecha >= ?
                  AND v.tipo IN ('I', 'E')
                GROUP BY $fmtMes
                ORDER BY mes",
                [$this->empresa_id, $desde]
            )->fetchAll();

            return array_map(fn($f) => [
                'mes' => $f['mes'],
                'ingresos' => (int) $f['ingresos'],
                'egresos' => (int) $f['egresos'],
                'neto' => (int) $f['neto'],
            ], $filas);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Alertas y validaciones críticas
     * @return array Alertas activas
     */
    private function obtenerAlertas(): array {
        $alertas = [];

        try {
            // Alerta 1: Vouchers descuadrados
            $descuadrados = $this->consultar(
                "SELECT v.id
                FROM vouchers v
                LEFT JOIN voucher_lineas vl ON v.id = vl.voucher_id
                WHERE v.empresa_id = ?
                GROUP BY v.id
                HAVING COALESCE(SUM(vl.debe), 0) != COALESCE(SUM(vl.haber), 0)",
                [$this->empresa_id]
            )->fetchAll();

            if (count($descuadrados) > 0) {
                $alertas[] = [
                    'tipo' => 'error',
                    'titulo' => 'Vouchers Descuadrados',
                    'mensaje' => count($descuadrados) . ' comprobante(s) con Debe ≠ Haber',
                    'accion' => 'Revisar Control de Vouchers',
                ];
            }

            // Alerta 2: Saldo de caja/banco bajo (< 1,000,000 CLP) cuando existen registros
            $cantVouchers = (int) $this->consultar(
                'SELECT COUNT(*) FROM vouchers WHERE empresa_id = ?',
                [$this->empresa_id]
            )->fetchColumn();
            $saldos = $this->saldosContables();
            $tieneExtractos = false;
            $saldoExtractos = 0;
            if ($this->tablaExiste('extractos')) {
                $ext = $this->consultar(
                    'SELECT COUNT(*) AS cnt, COALESCE(SUM(monto), 0) AS saldo FROM extractos WHERE empresa_id = ?',
                    [$this->empresa_id]
                )->fetch();
                $tieneExtractos = (int) ($ext['cnt'] ?? 0) > 0;
                $saldoExtractos = (int) ($ext['saldo'] ?? 0);
            }
            $saldoCaja = ($saldos['saldo_caja'] === 0 && $tieneExtractos) ? $saldoExtractos : $saldos['saldo_caja'];
            if (($cantVouchers > 0 || $tieneExtractos) && $saldoCaja < 1000000) {
                $alertas[] = [
                    'tipo' => 'warning',
                    'titulo' => 'Saldo Bajo',
                    'mensaje' => 'Caja con saldo < $1.000.000',
                    'accion' => 'Revisar Tesorería',
                ];
            }

            // Alerta 3: Movimientos bancarios pendientes de conciliar
            if ($this->tablaExiste('extractos')) {
                $pendientes = (int) $this->consultar(
                    "SELECT COUNT(*) FROM extractos WHERE empresa_id = ? AND estado = 'pendiente'",
                    [$this->empresa_id]
                )->fetchColumn();

                if ($pendientes > 0) {
                    $alertas[] = [
                        'tipo' => 'info',
                        'titulo' => 'Banco Pendiente',
                        'mensaje' => $pendientes . ' movimiento(s) bancario(s) sin conciliar',
                        'accion' => 'Revisar Conciliación Bancaria',
                    ];
                }
            }
        } catch (Throwable $e) {
            // Silent fail - las alertas son informativas
        }

        return $alertas;
    }

    /**
     * Resumen de inventario
     * @return array Conteos e valores
     */
    private function obtenerResumenInventario(): array {
        try {
            if (!$this->tablaExiste('inventario_items')) {
                return [
                    'items_totales' => 0,
                    'cantidad_total' => 0,
                    'valor_total' => 0,
                ];
            }

            $result = $this->consultar(
                "SELECT
                    COUNT(*) AS items_totales,
                    COALESCE(SUM(cantidad), 0) AS cantidad_total,
                    COALESCE(SUM(cantidad * valor_unitario), 0) AS valor_total
                FROM inventario_items
                WHERE empresa_id = ?",
                [$this->empresa_id]
            )->fetch();

            return [
                'items_totales' => (int) ($result['items_totales'] ?? 0),
                'cantidad_total' => (int) ($result['cantidad_total'] ?? 0),
                'valor_total' => (int) ($result['valor_total'] ?? 0),
            ];
        } catch (Throwable $e) {
            return [
                'items_totales' => 0,
                'cantidad_total' => 0,
                'valor_total' => 0,
            ];
        }
    }
}
