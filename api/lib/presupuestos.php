<?php
/**
 * Presupuestos - comparativa Presupuestado vs Real
 *
 * Un presupuesto es anual o de un mes y se compone de líneas por cuenta contable. El "real" sale de los comprobantes de la empresa
 * (voucher_lineas) del período, sumando todos los tipos de comprobante:
 *   - cuentas de ingreso (código 4.x):   Haber - Debe
 *   - cualquier otra (gastos 3.x, etc.): Debe - Haber
 * Las alertas se disparan con una varianza mayor al 10 %: en gastos preocupa gastar de más; en ingresos, recibir de menos.
 * Por empresa y período solo puede haber un presupuesto activo; para cambiarlo se archiva y se crea otro.
 */

class PresupuestoError extends RuntimeException {}

class Presupuestos {
    public const UMBRAL_ALERTA = 10;
    public const MONTO_MAXIMO = 1000000000000;
    public const MAX_LINEAS = 200;

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
        $llavesPresupuestos = $mysql ? ",
            KEY idx_presupuestos_empresa (empresa_id),
            KEY idx_presupuestos_empresa_anio_mes (empresa_id, anio, mes),
            KEY idx_presupuestos_estado (estado)" : '';
        $llavesLineas = $mysql ? ",
            KEY idx_presupuesto_lineas_presupuesto (presupuesto_id),
            KEY idx_presupuesto_lineas_cuenta (cuenta_codigo)" : '';

        $db->exec("CREATE TABLE IF NOT EXISTS presupuestos (
            id $id,
            empresa_id INT NOT NULL DEFAULT 0,
            nombre VARCHAR(120) NOT NULL DEFAULT '',
            periodo VARCHAR(20) NOT NULL DEFAULT 'anual',
            anio INT NOT NULL,
            mes INT NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'activo',
            descripcion TEXT,
            creado_en VARCHAR(25) NOT NULL,
            actualizado_en VARCHAR(25) NOT NULL$llavesPresupuestos
        )$motor");

        $db->exec("CREATE TABLE IF NOT EXISTS presupuesto_lineas (
            id $id,
            presupuesto_id INT UNSIGNED NOT NULL,
            orden INT NOT NULL DEFAULT 0,
            cuenta_codigo VARCHAR(20) NOT NULL,
            concepto VARCHAR(200) NOT NULL DEFAULT '',
            monto_presupuestado BIGINT NOT NULL DEFAULT 0,
            FOREIGN KEY (presupuesto_id) REFERENCES presupuestos(id) ON DELETE CASCADE$llavesLineas
        )$motor");

        if (!$mysql) {
            $db->exec('CREATE INDEX IF NOT EXISTS idx_presupuestos_empresa_anio_mes ON presupuestos (empresa_id, anio, mes)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_presupuesto_lineas_presupuesto ON presupuesto_lineas (presupuesto_id)');
        }
    }

    /** 'ingreso' para las cuentas 4.x; 'gasto' para el resto. */
    public static function naturaleza(string $codigo): string {
        return str_starts_with($codigo, '4') ? 'ingreso' : 'gasto';
    }

    /** Primer y último día del período (anual si $mes es null). */
    public static function rangoPeriodo(int $anio, ?int $mes): array {
        if ($mes === null) return [sprintf('%04d-01-01', $anio), sprintf('%04d-12-31', $anio)];
        return [sprintf('%04d-%02d-01', $anio, $mes), (new DateTime(sprintf('%04d-%02d-01', $anio, $mes)))->format('Y-m-t')];
    }

    /**
     * Comparativa presupuesto vs real de un período. Sin presupuesto activo devuelve líneas vacías.
     * 'totales' separa gastos e ingresos porque sumarlos en un solo número no significa nada.
     * @throws PresupuestoError si el año o el mes no son válidos
     */
    public function obtenerComparativa($anio, $mes = null): array {
        [$anio, $mes] = self::periodo($anio, $mes);
        $etiqueta = $mes === null ? (string) $anio : sprintf('%04d-%02d', $anio, $mes);
        $vacio = ['presupuesto_id' => null, 'nombre' => null, 'periodo' => $etiqueta, 'lineas' => [], 'alertas' => [],
            'totales' => ['gasto' => self::totales([]), 'ingreso' => self::totales([])]];

        $presupuesto = $this->obtenerPresupuesto($anio, $mes);
        if (!$presupuesto) return $vacio;

        $lineas = $this->obtenerLineasConReal((int) $presupuesto['id'], $anio, $mes);
        $alertas = [];
        foreach ($lineas as &$l) {
            $l['varianza'] = $l['monto_real'] - $l['monto_presupuestado'];
            $l['varianza_pct'] = $l['monto_presupuestado'] > 0 ? round($l['varianza'] / $l['monto_presupuestado'] * 100, 2) : 0;
            $alerta = $this->alertaDeLinea($l);
            if ($alerta) $alertas[] = $alerta;
        }
        unset($l);

        return ['presupuesto_id' => (int) $presupuesto['id'], 'nombre' => $presupuesto['nombre'], 'periodo' => $etiqueta, 'lineas' => $lineas, 'alertas' => $alertas,
            'totales' => [
                'gasto' => self::totales(array_filter($lineas, fn($l) => $l['naturaleza'] === 'gasto')),
                'ingreso' => self::totales(array_filter($lineas, fn($l) => $l['naturaleza'] === 'ingreso')),
            ]];
    }

    private function alertaDeLinea(array $l): ?array {
        $pct = $l['varianza_pct'];
        $base = ['titulo' => $l['concepto'] !== '' ? $l['concepto'] : $l['cuenta_codigo'], 'cuenta' => $l['cuenta_codigo'],
            'presupuestado' => $l['monto_presupuestado'], 'real' => $l['monto_real']];
        if ($l['naturaleza'] === 'gasto') {
            if ($l['monto_presupuestado'] === 0 && $l['monto_real'] > 0) return $base + ['tipo' => 'warning', 'mensaje' => 'Gasto sin presupuesto asignado'];
            if ($pct > self::UMBRAL_ALERTA) return $base + ['tipo' => 'warning', 'mensaje' => "Gasto $pct% sobre el presupuesto"];
            if ($pct < -self::UMBRAL_ALERTA) return $base + ['tipo' => 'info', 'mensaje' => 'Ahorro del ' . abs($pct) . '%'];
        } else {
            if ($pct < -self::UMBRAL_ALERTA) return $base + ['tipo' => 'warning', 'mensaje' => 'Ingresos ' . abs($pct) . '% bajo lo presupuestado'];
            if ($pct > self::UMBRAL_ALERTA) return $base + ['tipo' => 'info', 'mensaje' => "Ingresos $pct% sobre lo presupuestado"];
        }
        return null;
    }

    private static function totales(iterable $lineas): array {
        $presupuestado = $real = 0;
        foreach ($lineas as $l) {
            $presupuestado += $l['monto_presupuestado'];
            $real += $l['monto_real'];
        }
        return ['presupuestado' => $presupuestado, 'real' => $real, 'varianza' => $real - $presupuestado,
            'varianza_pct' => $presupuestado > 0 ? round(($real - $presupuestado) / $presupuestado * 100, 2) : 0];
    }

    /** Presupuesto activo del período. */
    private function obtenerPresupuesto(int $anio, ?int $mes): ?array {
        $sql = "SELECT id, nombre FROM presupuestos WHERE empresa_id = ? AND anio = ? AND estado = 'activo' AND " . ($mes === null ? 'mes IS NULL' : 'mes = ?') . ' ORDER BY id DESC LIMIT 1';
        $st = $this->db->prepare($sql);
        $st->execute($mes === null ? [$this->empresa_id, $anio] : [$this->empresa_id, $anio, $mes]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Líneas del presupuesto con el monto real del período (una consulta agrupada por cuenta). */
    private function obtenerLineasConReal(int $presupuestoId, int $anio, ?int $mes): array {
        $st = $this->db->prepare('SELECT id, cuenta_codigo, concepto, monto_presupuestado FROM presupuesto_lineas WHERE presupuesto_id = ? ORDER BY orden, id');
        $st->execute([$presupuestoId]);
        $lineas = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$lineas) return [];

        $codigos = array_values(array_unique(array_column($lineas, 'cuenta_codigo')));
        [$desde, $hasta] = self::rangoPeriodo($anio, $mes);
        $sumas = [];
        // Sin tablas de comprobantes (todavía no se usó la contabilidad) el real es cero. Cualquier otro error de la base de datos
        // se propaga: antes un fallo transitorio se mostraba como "real 0" y las líneas salían con un falso "Ahorro del 100%".
        if ($this->tablaExiste('vouchers') && $this->tablaExiste('voucher_lineas')) {
            $marcas = implode(',', array_fill(0, count($codigos), '?'));
            $q = $this->db->prepare("SELECT vl.cuenta, SUM(vl.debe) AS debe, SUM(vl.haber) AS haber
                FROM voucher_lineas vl JOIN vouchers v ON v.id = vl.voucher_id
                WHERE v.empresa_id = ? AND v.fecha >= ? AND v.fecha <= ? AND vl.cuenta IN ($marcas) GROUP BY vl.cuenta");
            $q->execute(array_merge([$this->empresa_id, $desde, $hasta], $codigos));
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $f) $sumas[(string) $f['cuenta']] = ['debe' => (int) $f['debe'], 'haber' => (int) $f['haber']];
        }
        $nombres = $this->nombresDeCuentas();

        return array_map(function (array $l) use ($sumas, $nombres): array {
            $codigo = (string) $l['cuenta_codigo'];
            $naturaleza = self::naturaleza($codigo);
            $s = $sumas[$codigo] ?? ['debe' => 0, 'haber' => 0];
            return ['id' => (int) $l['id'], 'cuenta_codigo' => $codigo, 'cuenta_nombre' => $nombres[$codigo] ?? '', 'concepto' => (string) $l['concepto'], 'naturaleza' => $naturaleza,
                'monto_presupuestado' => (int) $l['monto_presupuestado'],
                'monto_real' => $naturaleza === 'ingreso' ? $s['haber'] - $s['debe'] : $s['debe'] - $s['haber']];
        }, $lineas);
    }

    /** Código => nombre de las cuentas del plan de la empresa. */
    private function nombresDeCuentas(): array {
        if (!$this->tablaExiste('cuentas')) return [];
        $st = $this->db->prepare('SELECT codigo, nombre FROM cuentas WHERE empresa_id = ?');
        $st->execute([$this->empresa_id]);
        $mapa = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) $mapa[(string) $c['codigo']] = (string) $c['nombre'];
        return $mapa;
    }

    /** ¿Existe la tabla? Consulta el catálogo del motor, así que un error de conexión o de SQL no se confunde con "no existe". */
    private function tablaExiste(string $tabla): bool {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $st = $this->db->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
        } else {
            $st = $this->db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1");
        }
        $st->execute([$tabla]);
        return (bool) $st->fetchColumn();
    }

    /** Cuentas de gasto e ingreso (3.x y 4.x) del plan de la empresa, para armar presupuestos. */
    public function listarCuentasPresupuestables(): array {
        $cuentas = [];
        foreach ($this->nombresDeCuentas() as $codigo => $nombre) {
            if (str_starts_with((string) $codigo, '3') || str_starts_with((string) $codigo, '4')) {
                $cuentas[] = ['codigo' => (string) $codigo, 'nombre' => $nombre, 'naturaleza' => self::naturaleza((string) $codigo)];
            }
        }
        usort($cuentas, fn($a, $b) => strcmp($a['codigo'], $b['codigo']));
        return $cuentas;
    }

    /**
     * Crea un presupuesto activo (anual si $mes es null) con sus líneas y devuelve su id.
     * Cada línea: cuenta_codigo (de un plan de la empresa), concepto opcional y monto_presupuestado entero >= 0.
     * @throws PresupuestoError si algún dato no es válido o ya hay un presupuesto activo para ese período
     */
    public function crearPresupuesto($nombre, $anio, $mes, $lineas): int {
        $nombre = self::texto($nombre, 'El nombre');
        if ($nombre === '' || mb_strlen($nombre) > 120) throw new PresupuestoError('El nombre es obligatorio (máximo 120 caracteres).');
        [$anio, $mes] = self::periodo($anio, $mes);
        if (!is_array($lineas) || !array_is_list($lineas) || !$lineas) throw new PresupuestoError('El presupuesto necesita al menos una línea.');
        if (count($lineas) > self::MAX_LINEAS) throw new PresupuestoError('Un presupuesto admite hasta ' . self::MAX_LINEAS . ' líneas.');

        $nombres = $this->nombresDeCuentas();
        $vistos = [];
        $normalizadas = [];
        foreach ($lineas as $i => $l) {
            $n = $i + 1;
            if (!is_array($l)) throw new PresupuestoError("Línea $n: formato no válido.");
            $codigo = self::texto($l['cuenta_codigo'] ?? null, "Línea $n: la cuenta");
            if (!isset($nombres[$codigo])) throw new PresupuestoError("Línea $n: la cuenta $codigo no existe en el plan de cuentas de la empresa.");
            if (isset($vistos[$codigo])) throw new PresupuestoError("Línea $n: la cuenta $codigo está repetida.");
            $vistos[$codigo] = true;
            $concepto = self::texto($l['concepto'] ?? null, "Línea $n: el concepto");
            if (mb_strlen($concepto) > 200) throw new PresupuestoError("Línea $n: el concepto no puede superar 200 caracteres.");
            $monto = self::entero($l['monto_presupuestado'] ?? null);
            if ($monto === null || $monto < 0 || $monto > self::MONTO_MAXIMO) throw new PresupuestoError("Línea $n: el monto debe ser un número entero mayor o igual a cero.");
            $normalizadas[] = [$codigo, $concepto, $monto];
        }

        // "Un presupuesto activo por período" no se puede expresar como índice único (depende del estado), y la lectura previa no bloquea en
        // InnoDB: dos envíos simultáneos verían "no hay presupuesto" e insertarían dos. En MySQL se serializa con un bloqueo por empresa y período.
        return $this->conBloqueoDePeriodo($anio, $mes, fn() => $this->enTransaccion(function () use ($nombre, $anio, $mes, $normalizadas): int {
            if ($this->obtenerPresupuesto($anio, $mes)) {
                throw new PresupuestoError('Ya hay un presupuesto activo para ese período. Archívalo antes de crear otro.');
            }
            $ahora = date('Y-m-d H:i:s');
            $st = $this->db->prepare("INSERT INTO presupuestos (empresa_id, nombre, periodo, anio, mes, estado, descripcion, creado_en, actualizado_en) VALUES (?, ?, ?, ?, ?, 'activo', '', ?, ?)");
            $st->execute([$this->empresa_id, $nombre, $mes === null ? 'anual' : 'mensual', $anio, $mes, $ahora, $ahora]);
            $id = (int) $this->db->lastInsertId();
            $ins = $this->db->prepare('INSERT INTO presupuesto_lineas (presupuesto_id, orden, cuenta_codigo, concepto, monto_presupuestado) VALUES (?, ?, ?, ?, ?)');
            foreach ($normalizadas as $orden => [$codigo, $concepto, $monto]) $ins->execute([$id, $orden, $codigo, $concepto, $monto]);
            return $id;
        }));
    }

    /** Ejecuta $f con un bloqueo GET_LOCK por empresa y período (solo MySQL; SQLite ya serializa las escrituras). El bloqueo se libera tras el commit. */
    private function conBloqueoDePeriodo(int $anio, ?int $mes, callable $f) {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return $f();
        $nombre = 'icontador_presupuesto_' . $this->empresa_id . '_' . $anio . '_' . ($mes ?? 0);
        $st = $this->db->prepare('SELECT GET_LOCK(?, 5)');
        $st->execute([$nombre]);
        if ((int) $st->fetchColumn() !== 1) throw new PresupuestoError('Hay otra creación de presupuesto en curso para ese período. Inténtalo de nuevo en unos segundos.');
        try {
            return $f();
        } finally {
            $this->db->prepare('SELECT RELEASE_LOCK(?)')->execute([$nombre]);
        }
    }

    /** Archiva un presupuesto activo de la empresa (deja de comparar y libera el período). Sus líneas se conservan. */
    public function archivarPresupuesto($id): void {
        $id = self::entero($id);
        if ($id === null || $id < 1) throw new PresupuestoError('El presupuesto indicado no es válido.');
        $up = $this->db->prepare("UPDATE presupuestos SET estado = 'archivado', actualizado_en = ? WHERE id = ? AND empresa_id = ? AND estado = 'activo'");
        $up->execute([date('Y-m-d H:i:s'), $id, $this->empresa_id]);
        if ($up->rowCount() !== 1) throw new PresupuestoError('El presupuesto no existe en esta empresa o ya está archivado.');
    }

    /** Presupuestos del año (activos y archivados), los anuales primero y luego por mes. */
    public function listarPresupuestos($anio): array {
        [$anio] = self::periodo($anio, null);
        $st = $this->db->prepare('SELECT id, nombre, anio, mes, periodo, estado FROM presupuestos WHERE empresa_id = ? AND anio = ? ORDER BY (mes IS NOT NULL), mes, id');
        $st->execute([$this->empresa_id, $anio]);
        return array_map(function (array $p): array {
            $p['id'] = (int) $p['id'];
            $p['anio'] = (int) $p['anio'];
            $p['mes'] = $p['mes'] === null ? null : (int) $p['mes'];
            return $p;
        }, $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Valida año (2000-2099) y mes (null o 1-12) que llegan de la URL o del JSON. */
    private static function periodo($anio, $mes): array {
        $a = self::entero($anio);
        if ($a === null || $a < 2000 || $a > 2099) throw new PresupuestoError('El año debe estar entre 2000 y 2099.');
        if ($mes === null || $mes === '') return [$a, null];
        $m = self::entero($mes);
        if ($m === null || $m < 1 || $m > 12) throw new PresupuestoError('El mes debe estar entre 1 y 12.');
        return [$a, $m];
    }

    private function enTransaccion(callable $f) {
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $resultado = $f();
            if ($propia) $this->db->commit();
            return $resultado;
        } catch (Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** Texto recortado. Acepta null (queda vacío), cadenas y números; arreglos, objetos y booleanos se rechazan. */
    private static function texto($valor, string $etiqueta): string {
        if ($valor === null) return '';
        if (is_string($valor) || is_int($valor) || is_float($valor)) return trim((string) $valor);
        throw new PresupuestoError("$etiqueta debe ser texto.");
    }

    /** Acepta enteros, textos de solo dígitos y decimales sin parte fraccionaria; cualquier otra cosa devuelve null. */
    private static function entero($valor): ?int {
        if (is_int($valor)) return $valor;
        if (is_string($valor) && preg_match('/^-?\d{1,15}$/', trim($valor))) return (int) trim($valor);
        if (is_float($valor) && is_finite($valor) && floor($valor) === $valor && abs($valor) < 1e15) return (int) $valor;
        return null;
    }
}
