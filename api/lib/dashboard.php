<?php
/**
 * Dashboard Ejecutivo - Lógica de negocio
 *
 * Calcula KPIs y resúmenes financieros en tiempo real
 * desde vouchers, banco, RCV e inventario.
 */

class Dashboard {
    private $db;
    private $empresa_id;

    public function __construct($db, $empresa_id) {
        $this->db = $db;
        $this->empresa_id = $empresa_id;
    }

    /**
     * Obtener resumen ejecutivo completo
     * @return array Datos del dashboard
     */
    public function obtenerResumen() {
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

    /**
     * Calcular ratios financieros clave
     * @return array KPIs
     */
    private function calcularKPIs() {
        try {
            // Balance actual (últimos vouchers)
            $balance = $this->db->query(
                "SELECT
                    SUM(CASE WHEN v.tipo='Ingreso' THEN vl.monto ELSE -vl.monto END) as total
                FROM vouchers v
                JOIN voucher_lineas vl ON v.id = vl.voucher_id
                WHERE v.empresa_id = ?
                AND v.estado = 'registrado'",
                [$this->empresa_id]
            )->fetch();

            $totalActivo = 0;
            $totalPasivo = 0;

            // Activos (cuentas que comienzan con 1)
            $activos = $this->db->query(
                "SELECT SUM(vl.monto) as total
                FROM vouchers v
                JOIN voucher_lineas vl ON v.id = vl.voucher_id
                JOIN cuentas c ON vl.cuenta_id = c.id
                WHERE v.empresa_id = ?
                AND c.codigo LIKE '1%'
                AND vl.debe > 0",
                [$this->empresa_id]
            )->fetch();

            $totalActivo = $activos['total'] ?? 0;

            // Pasivos (cuentas que comienzan con 2)
            $pasivos = $this->db->query(
                "SELECT SUM(vl.monto) as total
                FROM vouchers v
                JOIN voucher_lineas vl ON v.id = vl.voucher_id
                JOIN cuentas c ON vl.cuenta_id = c.id
                WHERE v.empresa_id = ?
                AND c.codigo LIKE '2%'
                AND vl.haber > 0",
                [$this->empresa_id]
            )->fetch();

            $totalPasivo = $pasivos['total'] ?? 0;

            // Cálculos
            $razonCorriente = $totalPasivo > 0 ? $totalActivo / $totalPasivo : 1;
            $endeudamiento = $totalActivo > 0 ? $totalPasivo / $totalActivo : 0;

            return [
                'saldo_caja' => $balance['total'] ?? 0,
                'total_activo' => $totalActivo,
                'total_pasivo' => $totalPasivo,
                'razon_corriente' => round($razonCorriente, 2),
                'endeudamiento' => round($endeudamiento, 2),
                'patrimonio' => $totalActivo - $totalPasivo,
            ];
        } catch (Exception $e) {
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
     * Obtener balance de caja por cuenta bancaria
     * @return array Saldos por cuenta
     */
    private function obtenerBalanceCaja() {
        try {
            $result = $this->db->query(
                "SELECT
                    b.numero_cuenta,
                    b.banco,
                    SUM(bm.monto * (CASE WHEN bm.tipo='deposito' THEN 1 ELSE -1 END)) as saldo
                FROM banco_movimientos bm
                JOIN banco b ON bm.banco_id = b.id
                WHERE bm.empresa_id = ?
                GROUP BY b.id, b.banco, b.numero_cuenta
                ORDER BY b.banco",
                [$this->empresa_id]
            )->fetchAll();

            return $result ?: [];
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Últimos vouchers registrados
     * @param int $limit Cantidad a retornar
     * @return array Vouchers
     */
    private function obtenerUltimosVouchers($limit = 5) {
        try {
            $result = $this->db->query(
                "SELECT
                    v.id,
                    v.numero,
                    v.tipo,
                    v.fecha,
                    v.descripcion,
                    SUM(vl.debe) as total_debe,
                    SUM(vl.haber) as total_haber,
                    v.estado
                FROM vouchers v
                LEFT JOIN voucher_lineas vl ON v.id = vl.voucher_id
                WHERE v.empresa_id = ?
                GROUP BY v.id
                ORDER BY v.fecha DESC, v.id DESC
                LIMIT ?",
                [$this->empresa_id, $limit]
            )->fetchAll();

            return $result ?: [];
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Flujo de caja histórico (últimos N meses)
     * @param int $meses Meses a incluir
     * @return array Flujo por mes
     */
    private function obtenerFlujoMensual($meses = 6) {
        try {
            $meses = max(1, min($meses, 24)); // 1-24 meses

            $result = $this->db->query(
                "SELECT
                    DATE_FORMAT(v.fecha, '%Y-%m') as mes,
                    SUM(CASE WHEN v.tipo='Ingreso' THEN vl.monto ELSE 0 END) as ingresos,
                    SUM(CASE WHEN v.tipo='Egreso' THEN vl.monto ELSE 0 END) as egresos,
                    SUM(CASE WHEN v.tipo='Ingreso' THEN vl.monto ELSE -vl.monto END) as neto
                FROM vouchers v
                JOIN voucher_lineas vl ON v.id = vl.voucher_id
                WHERE v.empresa_id = ?
                AND v.fecha >= DATE_SUB(NOW(), INTERVAL ? MONTH)
                AND v.estado = 'registrado'
                GROUP BY DATE_FORMAT(v.fecha, '%Y-%m')
                ORDER BY v.fecha",
                [$this->empresa_id, $meses]
            )->fetchAll();

            return $result ?: [];
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Alertas y validaciones críticas
     * @return array Alertas activas
     */
    private function obtenerAlertas() {
        $alertas = [];

        try {
            // Alerta 1: Vouchers descuadrados
            $descuadrados = $this->db->query(
                "SELECT COUNT(*) as cantidad
                FROM vouchers v
                LEFT JOIN voucher_lineas vl ON v.id = vl.voucher_id
                WHERE v.empresa_id = ?
                AND v.estado = 'registrado'
                GROUP BY v.id
                HAVING SUM(vl.debe) != SUM(vl.haber)",
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

            // Alerta 2: Saldo de caja bajo (< 1,000,000 CLP)
            $saldoBajo = $this->db->query(
                "SELECT SUM(bm.monto * (CASE WHEN bm.tipo='deposito' THEN 1 ELSE -1 END)) as saldo
                FROM banco_movimientos bm
                WHERE bm.empresa_id = ?",
                [$this->empresa_id]
            )->fetch();

            if (($saldoBajo['saldo'] ?? 0) < 1000000) {
                $alertas[] = [
                    'tipo' => 'warning',
                    'titulo' => 'Saldo Bajo',
                    'mensaje' => 'Caja con saldo < $1.000.000',
                    'accion' => 'Revisar Tesorería',
                ];
            }

            // Alerta 3: RCV pendiente (importar últimos 30 días)
            $rcvPendiente = $this->db->query(
                "SELECT COUNT(*) as cantidad
                FROM rcv_compras rc
                WHERE rc.empresa_id = ?
                AND rc.fecha_import IS NULL
                AND rc.fecha >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
                [$this->empresa_id]
            )->fetch();

            if (($rcvPendiente['cantidad'] ?? 0) > 0) {
                $alertas[] = [
                    'tipo' => 'info',
                    'titulo' => 'RCV Pendiente',
                    'mensaje' => $rcvPendiente['cantidad'] . ' documento(s) sin procesar',
                    'accion' => 'Importar RCV',
                ];
            }

        } catch (Exception $e) {
            // Silent fail - las alertas son informativas
        }

        return $alertas;
    }

    /**
     * Resumen de inventario
     * @return array Conteos e valores
     */
    private function obtenerResumenInventario() {
        try {
            $result = $this->db->query(
                "SELECT
                    COUNT(*) as items_totales,
                    SUM(cantidad) as cantidad_total,
                    SUM(cantidad * valor_unitario) as valor_total
                FROM inventario_items
                WHERE empresa_id = ?",
                [$this->empresa_id]
            )->fetch();

            return $result ?: [
                'items_totales' => 0,
                'cantidad_total' => 0,
                'valor_total' => 0,
            ];
        } catch (Exception $e) {
            return [
                'items_totales' => 0,
                'cantidad_total' => 0,
                'valor_total' => 0,
            ];
        }
    }
}
