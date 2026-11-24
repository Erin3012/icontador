<?php
/**
 * Tesorería - Flujo de caja proyectado y pagos programados
 *
 * - Saldo actual: suma de la cartola bancaria cargada (tabla extractos; los cargos son negativos).
 * - Proyección: aplica día a día los pagos/ingresos programados pendientes (una vez, diaria, semanal o mensual).
 * - Los pagos se crean, se marcan como pagados o se cancelan; nunca se borran.
 */

class TesoreriaError extends RuntimeException {}

class Tesoreria {
    public const TIPOS = ['pago', 'ingreso'];
    public const RECURRENCIAS = ['una_vez', 'diaria', 'semanal', 'mensual'];
    public const MONTO_MAXIMO = 1000000000000;
    private const SALDO_BAJO = 500000;

    private PDO $db;
    private int $empresa_id;

    public function __construct($db, $empresa_id) {
        $this->db = $db;
        $this->empresa_id = (int) $empresa_id;
    }

    /** Crea las tablas si no existen (MySQL y SQLite). */
    public static function crearEsquema($db): void {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $motor = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $llaves = $mysql ? ",
            KEY idx_pagos_empresa_fecha (empresa_id, fecha_pago),
            KEY idx_pagos_empresa_estado (empresa_id, estado),
            KEY idx_pagos_recurrencia (recurrencia)" : '';

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
            actualizado_en VARCHAR(25) NOT NULL$llaves
        )$motor");

        $llavesFlujo = $mysql ? ",
            UNIQUE KEY uq_flujo_proyecciones (empresa_id, fecha)" : '';
        $db->exec("CREATE TABLE IF NOT EXISTS flujo_proyecciones (
            id $id,
            empresa_id INT NOT NULL DEFAULT 0,
            fecha DATE NOT NULL,
            saldo_estimado BIGINT NOT NULL DEFAULT 0,
            ingresos_acumulados BIGINT NOT NULL DEFAULT 0,
            egresos_acumulados BIGINT NOT NULL DEFAULT 0,
            creado_en VARCHAR(25) NOT NULL$llavesFlujo
        )$motor");

        if (!$mysql) {
            $db->exec('CREATE INDEX IF NOT EXISTS idx_pagos_empresa_fecha ON pagos_programados (empresa_id, fecha_pago)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_pagos_empresa_estado ON pagos_programados (empresa_id, estado)');
            $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_flujo_proyecciones ON flujo_proyecciones (empresa_id, fecha)');
        }
    }

    /** Flujo de caja proyectado a N días (30 a 90). */
    public function obtenerFlujoCaja($dias = 30): array {
        $dias = max(1, min((int) $dias, 365));
        $saldoActual = $this->obtenerSaldoActual();
        $hoy = date('Y-m-d');
        $fechaFin = date('Y-m-d', strtotime("+$dias days"));
        $pagos = $this->pagosPendientes();

        $flujos = $this->calcularFlujoDiario($hoy, $fechaFin, $saldoActual, $pagos);
        $saldos = array_column($flujos, 'saldo_estimado');

        return [
            'saldo_actual' => $saldoActual,
            'dias_proyectados' => $dias,
            'fecha_inicio' => $hoy,
            'fecha_fin' => $fechaFin,
            'flujos' => $flujos,
            'alertas' => $this->detectarAlertas($flujos),
            'saldo_minimo' => min($saldos),
            'saldo_promedio' => array_sum($saldos) / count($saldos),
        ];
    }

    /** Saldo de la cartola bancaria de la empresa. */
    private function obtenerSaldoActual(): int {
        try {
            $st = $this->db->prepare('SELECT COALESCE(SUM(monto), 0) FROM extractos WHERE empresa_id = ?');
            $st->execute([$this->empresa_id]);
            return (int) $st->fetchColumn();
        } catch (PDOException $e) {
            return 0; // todavía no se ha cargado ninguna cartola (la tabla no existe)
        }
    }

    private function calcularFlujoDiario(string $fechaInicio, string $fechaFin, int $saldoInicial, array $pagos): array {
        $flujos = [];
        $saldo = $saldoInicial;
        $ingresoAcumulado = 0;
        $egresoAcumulado = 0;
        $hoy = date('Y-m-d');

        $fecha = new DateTime($fechaInicio);
        $termino = new DateTime($fechaFin);
        while ($fecha <= $termino) {
            $dia = $fecha->format('Y-m-d');
            $ingresos = 0;
            $egresos = 0;
            foreach ($pagos as $pago) {
                if (!self::ocurre($pago, $dia)) continue;
                if ($pago['tipo'] === 'ingreso') $ingresos += (int) $pago['monto'];
                else $egresos += (int) $pago['monto'];
            }
            $neto = $ingresos - $egresos;
            $saldo += $neto;
            $ingresoAcumulado += $ingresos;
            $egresoAcumulado += $egresos;
            $flujos[] = [
                'fecha' => $dia,
                'dia_semana' => $this->nombreDiaSemana($fecha),
                'ingresos' => $ingresos,
                'egresos' => $egresos,
                'neto' => $neto,
                'saldo_estimado' => $saldo,
                'ingresos_acumulados' => $ingresoAcumulado,
                'egresos_acumulados' => $egresoAcumulado,
                'es_futuro' => $dia > $hoy,
            ];
            $fecha->modify('+1 day');
        }
        return $flujos;
    }

    /**
     * ¿El pago programado cae en esa fecha? Los mensuales de día 29 a 31 caen el último día de los meses más cortos.
     */
    public static function ocurre(array $pago, string $fecha): bool {
        $inicio = substr((string) $pago['fecha_pago'], 0, 10);
        if ($fecha < $inicio) return false;
        $fin = $pago['fecha_fin'] ?? null;
        if ($fin !== null && $fin !== '' && $fecha > substr((string) $fin, 0, 10)) return false;

        switch ($pago['recurrencia']) {
            case 'una_vez':
                return $fecha === $inicio;
            case 'diaria':
                return true;
            case 'semanal':
                return (new DateTime($fecha))->format('w') === (new DateTime($inicio))->format('w');
            case 'mensual':
                $diaPago = (int) substr($inicio, 8, 2);
                $f = new DateTime($fecha);
                return (int) $f->format('j') === min($diaPago, (int) $f->format('t'));
        }
        return false;
    }

    /** Pagos pendientes de la empresa. */
    private function pagosPendientes(): array {
        $st = $this->db->prepare("SELECT id, concepto, monto, fecha_pago, fecha_fin, tipo, recurrencia, estado
            FROM pagos_programados WHERE empresa_id = ? AND estado = 'pendiente' ORDER BY fecha_pago, id");
        $st->execute([$this->empresa_id]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private function detectarAlertas(array $flujos): array {
        $alertas = [];
        $negativos = array_filter($flujos, fn($f) => $f['saldo_estimado'] < 0);
        if ($negativos) {
            $primero = reset($negativos);
            $alertas[] = [
                'tipo' => 'error',
                'titulo' => 'Riesgo de insolvencia',
                'mensaje' => 'El saldo proyectado queda negativo desde el ' . $primero['fecha'] . '.',
                'fecha' => $primero['fecha'],
                'dias_afectados' => count($negativos),
            ];
        }
        $bajos = array_filter($flujos, fn($f) => $f['saldo_estimado'] >= 0 && $f['saldo_estimado'] < self::SALDO_BAJO);
        if (count($bajos) > 2) {
            $alertas[] = [
                'tipo' => 'warning',
                'titulo' => 'Saldo bajo sostenido',
                'mensaje' => 'Más de 2 días con saldo menor a $500.000.',
                'dias_afectados' => count($bajos),
            ];
        }
        return $alertas;
    }

    /**
     * Crea un pago o ingreso programado y devuelve su id.
     * @throws TesoreriaError si algún dato no es válido
     */
    public function crearPagoProgramado($concepto, $monto, $fechaPago, $tipo = 'pago', $recurrencia = 'una_vez', $fechaFin = null): int {
        $concepto = trim((string) $concepto);
        if ($concepto === '' || mb_strlen($concepto) > 255) throw new TesoreriaError('El concepto es obligatorio (máximo 255 caracteres).');
        if (is_string($monto)) $monto = trim($monto);
        if ((!is_int($monto) && !(is_string($monto) && preg_match('/^\d{1,13}$/', $monto))) || (int) $monto <= 0 || (int) $monto > self::MONTO_MAXIMO) {
            throw new TesoreriaError('El monto debe ser un número entero positivo.');
        }
        $fechaPago = self::fechaValida($fechaPago, 'La fecha de pago');
        if (!in_array($tipo, self::TIPOS, true)) throw new TesoreriaError('El tipo debe ser pago o ingreso.');
        if (!in_array($recurrencia, self::RECURRENCIAS, true)) throw new TesoreriaError('La recurrencia debe ser una vez, diaria, semanal o mensual.');
        if ($fechaFin === '' || $fechaFin === null) {
            $fechaFin = null;
        } else {
            $fechaFin = self::fechaValida($fechaFin, 'La fecha de término');
            if ($recurrencia === 'una_vez') throw new TesoreriaError('La fecha de término solo aplica a pagos recurrentes.');
            if ($fechaFin < $fechaPago) throw new TesoreriaError('La fecha de término no puede ser anterior a la fecha de pago.');
        }

        $ahora = date('Y-m-d H:i:s');
        $st = $this->db->prepare(
            "INSERT INTO pagos_programados
             (empresa_id, concepto, monto, fecha_pago, tipo, recurrencia, fecha_inicio, fecha_fin, estado, creado_en, actualizado_en)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pendiente', ?, ?)"
        );
        $st->execute([$this->empresa_id, $concepto, (int) $monto, $fechaPago, $tipo, $recurrencia, $fechaPago, $fechaFin, $ahora, $ahora]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Cambia el estado de un pago pendiente de esta empresa: 'cancelado', o 'pagado' (solo pagos de una vez).
     * @throws TesoreriaError si no existe, ya no está pendiente o el cambio no aplica
     */
    public function cambiarEstado(int $id, string $estado): void {
        if (!in_array($estado, ['cancelado', 'pagado'], true)) throw new TesoreriaError('Estado no válido.');
        $st = $this->db->prepare('SELECT recurrencia, estado FROM pagos_programados WHERE id = ? AND empresa_id = ?');
        $st->execute([$id, $this->empresa_id]);
        $pago = $st->fetch(PDO::FETCH_ASSOC);
        if (!$pago) throw new TesoreriaError('El pago programado no existe en esta empresa.');
        if ($pago['estado'] !== 'pendiente') throw new TesoreriaError('El pago ya no está pendiente.');
        if ($estado === 'pagado' && $pago['recurrencia'] !== 'una_vez') throw new TesoreriaError('Un pago recurrente no se marca como pagado: cancélalo cuando termine.');
        $up = $this->db->prepare('UPDATE pagos_programados SET estado = ?, actualizado_en = ? WHERE id = ? AND empresa_id = ?');
        $up->execute([$estado, date('Y-m-d H:i:s'), $id, $this->empresa_id]);
    }

    /**
     * Pagos pendientes con su próxima fecha dentro de los próximos N días (incluye los recurrentes iniciados antes).
     */
    public function listarPagosProgramados($dias = 30): array {
        $dias = max(1, min((int) $dias, 365));
        $hoy = date('Y-m-d');
        $limite = date('Y-m-d', strtotime("+$dias days"));
        $lista = [];
        foreach ($this->pagosPendientes() as $pago) {
            $proxima = self::proximaFecha($pago, $hoy, $limite);
            if ($proxima === null) continue;
            $pago['id'] = (int) $pago['id'];
            $pago['monto'] = (int) $pago['monto'];
            $pago['proxima_fecha'] = $proxima;
            $lista[] = $pago;
        }
        usort($lista, fn($a, $b) => [$a['proxima_fecha'], $a['id']] <=> [$b['proxima_fecha'], $b['id']]);
        return $lista;
    }

    /** Primera fecha entre $desde y $hasta (inclusive) en que cae el pago, o null. */
    public static function proximaFecha(array $pago, string $desde, string $hasta): ?string {
        $f = new DateTime($desde);
        $fin = new DateTime($hasta);
        while ($f <= $fin) {
            $dia = $f->format('Y-m-d');
            if (self::ocurre($pago, $dia)) return $dia;
            $f->modify('+1 day');
        }
        return null;
    }

    private static function fechaValida($valor, string $etiqueta): string {
        $valor = is_string($valor) ? trim($valor) : '';
        $d = DateTime::createFromFormat('!Y-m-d', $valor);
        if (!$d || $d->format('Y-m-d') !== $valor || $valor < '2000-01-01' || $valor > '2100-12-31') {
            throw new TesoreriaError("$etiqueta debe ser una fecha válida (AAAA-MM-DD).");
        }
        return $valor;
    }

    private function nombreDiaSemana(DateTime $fecha): string {
        $dias = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
        return $dias[(int) $fecha->format('w')];
    }
}
