<?php
/**
 * Dashboard Ejecutivo - Lógica de negocio
 *
 * KPIs y resúmenes calculados desde los comprobantes contables de la empresa (vouchers y voucher_lineas),
 * el RCV centralizado y el inventario.
 *
 * Convenciones del plan de cuentas (el plan de ejemplo de la aplicación las cumple):
 *   1.x activo · 2.x pasivo · 3.x pérdidas · 4.x ganancias · 1.1.x circulante · 1.1.01 Caja y 1.1.02 Banco.
 * Cada sección se calcula por separado: si falta una tabla (p. ej. aún no hay inventario) esa sección
 * queda vacía y el resto del dashboard sigue funcionando.
 */

class Dashboard {
    /** Cuentas (por prefijo de código) que se consideran caja y bancos. */
    public const CUENTAS_CAJA = ['1.1.01', '1.1.02'];
    public const SALDO_CAJA_BAJO = 1000000;

    private PDO $db;
    private int $empresa_id;

    public function __construct($db, $empresa_id) {
        $this->db = $db;
        $this->empresa_id = (int) $empresa_id;
    }

    /** Resumen ejecutivo completo. */
    public function obtenerResumen(): array {
        $sumas = $this->sumasPorCuenta();
        $kpis = $this->calcularKPIs($sumas);
        return [
            'timestamp' => date('Y-m-d H:i:s'),
            'empresa_id' => $this->empresa_id,
            'kpis' => $kpis,
            'balance_caja' => $this->obtenerBalanceCaja($sumas),
            'ultimos_vouchers' => $this->obtenerUltimosVouchers(5),
            'flujo_mensual' => $this->obtenerFlujoMensual(6),
            'alertas' => $this->obtenerAlertas($kpis),
            'resumen_inventario' => $this->obtenerResumenInventario(),
        ];
    }

    /** Debe y Haber acumulados por código de cuenta, de todos los comprobantes de la empresa. */
    private function sumasPorCuenta(): array {
        try {
            $st = $this->db->prepare('SELECT vl.cuenta, SUM(vl.debe) AS debe, SUM(vl.haber) AS haber
                FROM voucher_lineas vl JOIN vouchers v ON v.id = vl.voucher_id
                WHERE v.empresa_id = ? GROUP BY vl.cuenta');
            $st->execute([$this->empresa_id]);
            $sumas = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $fila) {
                $sumas[(string) $fila['cuenta']] = ['debe' => (int) $fila['debe'], 'haber' => (int) $fila['haber']];
            }
            return $sumas;
        } catch (PDOException $e) {
            return []; // todavía no hay comprobantes
        }
    }

    /** Suma neta (Debe - Haber, o al revés) de las cuentas cuyo código empieza con alguno de los prefijos. */
    private static function saldo(array $sumas, array $prefijos, bool $naturalezaDeudora): int {
        $total = 0;
        foreach ($sumas as $codigo => $s) {
            foreach ($prefijos as $prefijo) {
                if (str_starts_with((string) $codigo, $prefijo)) {
                    $total += $naturalezaDeudora ? $s['debe'] - $s['haber'] : $s['haber'] - $s['debe'];
                    break;
                }
            }
        }
        return $total;
    }

    /** Ratios financieros clave. razon_corriente es null si la empresa no tiene pasivo circulante. */
    private function calcularKPIs(array $sumas): array {
        $totalActivo = self::saldo($sumas, ['1'], true);
        $totalPasivo = self::saldo($sumas, ['2'], false);
        $activoCirculante = self::saldo($sumas, ['1.1'], true);
        $pasivoCirculante = self::saldo($sumas, ['2.1'], false);
        return [
            'saldo_caja' => self::saldo($sumas, self::CUENTAS_CAJA, true),
            'total_activo' => $totalActivo,
            'total_pasivo' => $totalPasivo,
            'razon_corriente' => $pasivoCirculante > 0 ? round($activoCirculante / $pasivoCirculante, 2) : null,
            'endeudamiento' => $totalActivo > 0 ? round($totalPasivo / $totalActivo, 2) : 0,
            'patrimonio' => $totalActivo - $totalPasivo,
        ];
    }

    /**
     * Saldo de cada cuenta de caja y banco. 'banco' lleva el nombre de la cuenta contable y 'numero_cuenta' su código.
     */
    private function obtenerBalanceCaja(array $sumas): array {
        $nombres = [];
        try {
            $st = $this->db->prepare('SELECT codigo, nombre FROM cuentas WHERE empresa_id = ?');
            $st->execute([$this->empresa_id]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) $nombres[(string) $c['codigo']] = (string) $c['nombre'];
        } catch (PDOException $e) {
            // sin plan de cuentas: se muestra el código
        }
        $filas = [];
        foreach ($sumas as $codigo => $s) {
            foreach (self::CUENTAS_CAJA as $prefijo) {
                if (str_starts_with((string) $codigo, $prefijo)) {
                    $filas[] = ['banco' => $nombres[$codigo] ?? (string) $codigo, 'numero_cuenta' => (string) $codigo, 'saldo' => $s['debe'] - $s['haber']];
                    break;
                }
            }
        }
        usort($filas, fn($a, $b) => strcmp($a['numero_cuenta'], $b['numero_cuenta']));
        return $filas;
    }

    /** Últimos comprobantes. 'tipo' es la letra (I, E, T) y 'tipo_nombre' su nombre. */
    private function obtenerUltimosVouchers(int $limite = 5): array {
        $limite = max(1, min($limite, 50));
        try {
            $st = $this->db->prepare("SELECT v.id, v.numero, v.tipo, v.fecha, v.glosa AS descripcion,
                    COALESCE(SUM(vl.debe), 0) AS total_debe, COALESCE(SUM(vl.haber), 0) AS total_haber
                FROM vouchers v LEFT JOIN voucher_lineas vl ON vl.voucher_id = v.id
                WHERE v.empresa_id = ?
                GROUP BY v.id, v.numero, v.tipo, v.fecha, v.glosa
                ORDER BY v.fecha DESC, v.id DESC LIMIT $limite");
            $st->execute([$this->empresa_id]);
            $nombres = ['I' => 'Ingreso', 'E' => 'Egreso', 'T' => 'Traspaso'];
            return array_map(fn($v) => [
                'id' => (int) $v['id'], 'numero' => (int) $v['numero'], 'tipo' => $v['tipo'], 'tipo_nombre' => $nombres[$v['tipo']] ?? $v['tipo'],
                'fecha' => $v['fecha'], 'descripcion' => $v['descripcion'],
                'total_debe' => (int) $v['total_debe'], 'total_haber' => (int) $v['total_haber'],
            ], $st->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Flujo de caja de los últimos N meses (incluye el actual): ingresos = Debe y egresos = Haber de las cuentas de caja y banco.
     * Los meses sin movimientos salen en cero para que el gráfico sea continuo.
     */
    private function obtenerFlujoMensual(int $meses = 6): array {
        $meses = max(1, min($meses, 24));
        $mes = new DateTime('first day of this month');
        $mes->modify('-' . ($meses - 1) . ' months');
        $desde = $mes->format('Y-m-d');
        $flujo = [];
        for ($i = 0; $i < $meses; $i++) {
            $flujo[$mes->format('Y-m')] = ['mes' => $mes->format('Y-m'), 'ingresos' => 0, 'egresos' => 0, 'neto' => 0];
            $mes->modify('+1 month');
        }
        try {
            $condicion = implode(' OR ', array_fill(0, count(self::CUENTAS_CAJA), 'vl.cuenta LIKE ?'));
            $st = $this->db->prepare("SELECT v.periodo AS mes, SUM(vl.debe) AS ingresos, SUM(vl.haber) AS egresos
                FROM vouchers v JOIN voucher_lineas vl ON vl.voucher_id = v.id
                WHERE v.empresa_id = ? AND v.fecha >= ? AND ($condicion)
                GROUP BY v.periodo");
            $st->execute(array_merge([$this->empresa_id, $desde], array_map(fn($p) => $p . '%', self::CUENTAS_CAJA)));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $fila) {
                if (!isset($flujo[$fila['mes']])) continue;
                $flujo[$fila['mes']] = ['mes' => $fila['mes'], 'ingresos' => (int) $fila['ingresos'], 'egresos' => (int) $fila['egresos'],
                    'neto' => (int) $fila['ingresos'] - (int) $fila['egresos']];
            }
        } catch (PDOException $e) {
            // sin comprobantes: se devuelven los meses en cero
        }
        return array_values($flujo);
    }

    /** Alertas informativas; cada una se evalúa por separado. */
    private function obtenerAlertas(array $kpis): array {
        $alertas = [];

        try {
            $st = $this->db->prepare('SELECT v.id FROM vouchers v JOIN voucher_lineas vl ON vl.voucher_id = v.id
                WHERE v.empresa_id = ? GROUP BY v.id HAVING SUM(vl.debe) <> SUM(vl.haber)');
            $st->execute([$this->empresa_id]);
            $descuadrados = count($st->fetchAll());
            if ($descuadrados > 0) {
                $alertas[] = ['tipo' => 'error', 'titulo' => 'Vouchers Descuadrados',
                    'mensaje' => $descuadrados . ' comprobante(s) con Debe ≠ Haber', 'accion' => 'Revisar comprobantes descuadrados'];
            }

            // Solo se avisa del saldo bajo si la empresa ya tiene comprobantes (una empresa nueva no es una alerta).
            $st = $this->db->prepare('SELECT COUNT(*) FROM vouchers WHERE empresa_id = ?');
            $st->execute([$this->empresa_id]);
            if ((int) $st->fetchColumn() > 0 && $kpis['saldo_caja'] < self::SALDO_CAJA_BAJO) {
                $alertas[] = ['tipo' => 'warning', 'titulo' => 'Saldo Bajo',
                    'mensaje' => 'Caja y bancos con saldo menor a $1.000.000', 'accion' => 'Revisar Tesorería'];
            }
        } catch (PDOException $e) {
            // sin comprobantes
        }

        try {
            require_once __DIR__ . '/rcv-centralizacion.php';
            // Mismo orden de esquemas que api/rcv-centralizacion.php: el estado por período consulta todas estas tablas.
            crearEsquema($this->db);
            rcv_schema($this->db);
            hon_schema($this->db);
            rcv_centralizacion_schema($this->db);
            $pendientes = 0;
            foreach (rcv_centralizacion_estados_periodos($this->db, $this->empresa_id)['periodos'] as $periodo) {
                foreach ($periodo['libros'] as $libro) {
                    if (in_array($libro['estado'], ['pendiente', 'parcial'], true)) $pendientes += $libro['esperados'] - $libro['contabilizados'];
                }
            }
            if ($pendientes > 0) {
                $alertas[] = ['tipo' => 'info', 'titulo' => 'RCV Pendiente',
                    'mensaje' => $pendientes . ' documento(s) importados sin contabilizar', 'accion' => 'Centralizar RCV'];
            }
        } catch (Throwable $e) {
            // sin RCV importado
        }

        return $alertas;
    }

    /** Conteo y valor del inventario. */
    private function obtenerResumenInventario(): array {
        $vacio = ['items_totales' => 0, 'cantidad_total' => 0, 'valor_total' => 0];
        try {
            $st = $this->db->prepare('SELECT COUNT(*) AS items_totales, COALESCE(SUM(cantidad), 0) AS cantidad_total,
                    COALESCE(SUM(cantidad * valor_unitario), 0) AS valor_total
                FROM inventario_items WHERE empresa_id = ?');
            $st->execute([$this->empresa_id]);
            $fila = $st->fetch(PDO::FETCH_ASSOC);
            return $fila ? array_map('intval', $fila) : $vacio;
        } catch (PDOException $e) {
            return $vacio;
        }
    }
}
