<?php
/**
 * Activos Fijos - registro, depreciación y baja
 *
 * Depreciación: se cuenta por meses completos desde el mes de adquisición (inclusive) hasta la fecha de cálculo,
 * o hasta la fecha de baja si el activo ya fue dado de baja. Nunca supera el valor depreciable
 * (valor de adquisición menos valor residual), así que el valor neto no baja del valor residual.
 *   - lineal:     valor depreciable / vida útil, repartido por mes.
 *   - acelerada:  valor de adquisición x factor / vida útil por año (factor 2 = el doble de la lineal), con el mismo tope.
 */

class ActivosError extends RuntimeException {}

class ActivosFijos {
    public const ESTADOS = ['vigente', 'baja'];
    public const METODOS = ['lineal', 'acelerada'];
    public const VALOR_MAXIMO = 1000000000000;
    public const FACTOR_ACELERADO_MAXIMO = 5.0;

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
        $llavesActivos = $mysql ? ",
            UNIQUE KEY uq_activos_empresa_codigo (empresa_id, codigo),
            KEY idx_activos_empresa_estado (empresa_id, estado),
            KEY idx_activos_empresa_categoria (empresa_id, categoria),
            KEY idx_activos_fecha_adquisicion (fecha_adquisicion)" : '';
        $llavesDepreciaciones = $mysql ? ",
            KEY idx_depreciaciones_activo (activo_id),
            KEY idx_depreciaciones_empresa_periodo (empresa_id, ano, mes)" : '';
        $llavesMovimientos = $mysql ? ",
            KEY idx_movimientos_activo (activo_id),
            KEY idx_movimientos_empresa_fecha (empresa_id, fecha_movimiento)" : '';

        $db->exec("CREATE TABLE IF NOT EXISTS activos_fijos (
            id $id,
            empresa_id INT NOT NULL DEFAULT 0,
            codigo VARCHAR(50) NOT NULL DEFAULT '',
            nombre VARCHAR(255) NOT NULL DEFAULT '',
            descripcion TEXT,
            categoria VARCHAR(100) NOT NULL DEFAULT '',
            fecha_adquisicion DATE NOT NULL,
            valor_adquisicion BIGINT NOT NULL DEFAULT 0,
            moneda VARCHAR(10) NOT NULL DEFAULT 'CLP',
            vida_util_anos INT NOT NULL DEFAULT 5,
            metodo_depreciacion VARCHAR(20) NOT NULL DEFAULT 'lineal',
            tasa_depreciacion_acelerada DECIMAL(5,2) NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'vigente',
            fecha_baja DATE NULL,
            valor_residual BIGINT NOT NULL DEFAULT 0,
            ubicacion VARCHAR(255),
            responsable VARCHAR(255),
            numero_serie VARCHAR(100),
            creado_en VARCHAR(25) NOT NULL,
            actualizado_en VARCHAR(25) NOT NULL$llavesActivos
        )$motor");

        $db->exec("CREATE TABLE IF NOT EXISTS depreciaciones_mensuales (
            id $id,
            activo_id INT UNSIGNED NOT NULL,
            empresa_id INT NOT NULL DEFAULT 0,
            ano INT NOT NULL,
            mes INT NOT NULL,
            valor_depreciation BIGINT NOT NULL DEFAULT 0,
            valor_acumulado BIGINT NOT NULL DEFAULT 0,
            valor_neto BIGINT NOT NULL DEFAULT 0,
            creado_en VARCHAR(25) NOT NULL$llavesDepreciaciones
        )$motor");

        $db->exec("CREATE TABLE IF NOT EXISTS movimientos_activos (
            id $id,
            activo_id INT UNSIGNED NOT NULL,
            empresa_id INT NOT NULL DEFAULT 0,
            tipo_movimiento VARCHAR(50) NOT NULL DEFAULT 'adquisicion',
            fecha_movimiento DATE NOT NULL,
            valor_movimiento BIGINT NOT NULL DEFAULT 0,
            descripcion TEXT,
            comprobante_numero VARCHAR(50),
            comprobante_tipo VARCHAR(20),
            creado_en VARCHAR(25) NOT NULL$llavesMovimientos
        )$motor");

        if (!$mysql) {
            $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_activos_empresa_codigo ON activos_fijos (empresa_id, codigo)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_activos_empresa_estado ON activos_fijos (empresa_id, estado)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_movimientos_activo ON movimientos_activos (activo_id)');
        } else {
            self::asegurarIndiceUnicoMysql($db);
        }
    }

    /**
     * Las tablas creadas con el esquema anterior no tienen el índice único (empresa_id, codigo) y CREATE TABLE IF NOT EXISTS no lo agrega.
     * Se agrega una vez, solo si no hay códigos repetidos (si los hay, se deja registrado y la unicidad queda a cargo de crearActivo).
     */
    private static function asegurarIndiceUnicoMysql(PDO $db): void {
        static $hecho = false;
        if ($hecho) return;
        $hecho = true;
        try {
            $existe = $db->query("SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE()
                AND table_name = 'activos_fijos' AND index_name = 'uq_activos_empresa_codigo' LIMIT 1")->fetchColumn();
            if ($existe) return;
            if ($db->query('SELECT 1 FROM activos_fijos GROUP BY empresa_id, codigo HAVING COUNT(*) > 1 LIMIT 1')->fetchColumn()) {
                error_log('activos_fijos: hay códigos repetidos por empresa; no se pudo agregar el índice único uq_activos_empresa_codigo.');
                return;
            }
            $db->exec('ALTER TABLE activos_fijos ADD UNIQUE KEY uq_activos_empresa_codigo (empresa_id, codigo)');
        } catch (PDOException $e) {
            error_log('activos_fijos: no se pudo agregar el índice único: ' . $e->getMessage());
        }
    }

    /**
     * Valuación de un activo (fila de activos_fijos) a una fecha. Función pura: no toca la base de datos.
     * Devuelve valor_bruto, depreciacion_acumulada, valor_neto, depreciacion_anual, meses_uso, anos_uso y totalmente_depreciado.
     */
    public static function valuar(array $activo, ?string $fecha = null): array {
        $fecha ??= date('Y-m-d');
        $baja = ($activo['estado'] ?? 'vigente') === 'baja' && !empty($activo['fecha_baja']);
        $efectiva = $baja ? substr((string) $activo['fecha_baja'], 0, 10) : substr($fecha, 0, 10);
        $adquisicion = substr((string) $activo['fecha_adquisicion'], 0, 10);

        $bruto = (int) $activo['valor_adquisicion'];
        $residual = min($bruto, max(0, (int) ($activo['valor_residual'] ?? 0)));
        $depreciable = $bruto - $residual;
        $vida = max(1, (int) $activo['vida_util_anos']);
        $mesesVida = $vida * 12;

        $meses = $efectiva < $adquisicion ? 0
            : ((int) substr($efectiva, 0, 4) - (int) substr($adquisicion, 0, 4)) * 12
              + ((int) substr($efectiva, 5, 2) - (int) substr($adquisicion, 5, 2)) + 1;

        // Aritmética entera (el factor se guarda con 2 decimales): con floats, 1.000.000 x 4,10 x 12 / 60 daba 819.999 en vez de 820.000.
        // Pasada la vida útil el tope ya aplica, así que los meses se limitan para que los productos no desborden.
        $mesesCalculo = min($meses, $mesesVida);
        $centesimos = (int) round((float) ($activo['tasa_depreciacion_acelerada'] ?? 0) * 100);
        if (($activo['metodo_depreciacion'] ?? 'lineal') === 'acelerada' && $centesimos > 100) {
            $anual = intdiv($bruto * $centesimos, 100 * $vida);
            $acumulada = intdiv($bruto * $centesimos * $mesesCalculo, 100 * $mesesVida);
        } else {
            $anual = intdiv($depreciable, $vida);
            $acumulada = intdiv($depreciable * $mesesCalculo, $mesesVida);
        }
        $acumulada = min($depreciable, $acumulada);
        $anual = min($depreciable, $anual);

        return [
            'valor_bruto' => $bruto,
            'depreciacion_acumulada' => $acumulada,
            'valor_neto' => $bruto - $acumulada,
            'depreciacion_anual' => $anual,
            'meses_uso' => $meses,
            'anos_uso' => round($meses / 12, 2),
            'totalmente_depreciado' => $depreciable > 0 && $acumulada >= $depreciable,
        ];
    }

    /** Activos de la empresa en un estado (vigente o baja), con su valuación. */
    public function listarActivos($estado = 'vigente'): array {
        if (!in_array($estado, self::ESTADOS, true)) throw new ActivosError('El estado debe ser vigente o baja.');
        $st = $this->db->prepare('SELECT id, codigo, nombre, descripcion, categoria, fecha_adquisicion, valor_adquisicion, vida_util_anos,
                metodo_depreciacion, tasa_depreciacion_acelerada, estado, fecha_baja, valor_residual, ubicacion, responsable
            FROM activos_fijos WHERE empresa_id = ? AND estado = ? ORDER BY fecha_adquisicion DESC, id DESC');
        $st->execute([$this->empresa_id, $estado]);
        $hoy = date('Y-m-d');
        $lista = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $v = self::valuar($a, $hoy);
            $a['id'] = (int) $a['id'];
            $a['valor_adquisicion'] = (int) $a['valor_adquisicion'];
            $a['vida_util_anos'] = (int) $a['vida_util_anos'];
            $a['valor_residual'] = (int) $a['valor_residual'];
            $a['ubicacion'] = (string) ($a['ubicacion'] ?? '');
            $a['responsable'] = (string) ($a['responsable'] ?? '');
            $a['valor_neto'] = $v['valor_neto'];
            $a['depreciacion_acumulada'] = $v['depreciacion_acumulada'];
            $a['depreciacion_anual'] = $v['depreciacion_anual'];
            $a['totalmente_depreciado'] = $v['totalmente_depreciado'];
            $lista[] = $a;
        }
        return $lista;
    }

    /** Valuación de los activos vigentes agrupada por categoría. */
    public function obtenerValuacionPorCategoria(?array $activos = null): array {
        $valuaciones = [];
        foreach ($activos ?? $this->listarActivos('vigente') as $a) {
            $c = &$valuaciones[$a['categoria']];
            $c ??= ['cantidad' => 0, 'valor_bruto' => 0, 'depreciacion_acumulada' => 0, 'valor_neto' => 0];
            $c['cantidad']++;
            $c['valor_bruto'] += $a['valor_adquisicion'];
            $c['depreciacion_acumulada'] += $a['depreciacion_acumulada'];
            $c['valor_neto'] += $a['valor_neto'];
            unset($c);
        }
        return $valuaciones;
    }

    /**
     * Registra un activo fijo y su movimiento de adquisición; devuelve su id.
     * @throws ActivosError si algún dato no es válido o el código ya existe en la empresa
     */
    public function crearActivo($codigo, $nombre, $categoria, $fechaAdquisicion, $valorAdquisicion, $vidaUtil = 5, $metodoDepreciacion = 'lineal', $tasaAcelerada = null, $descripcion = null): int {
        $codigo = self::texto($codigo, 'El código');
        if ($codigo === '' || mb_strlen($codigo) > 50) throw new ActivosError('El código es obligatorio (máximo 50 caracteres).');
        $nombre = self::texto($nombre, 'El nombre');
        if ($nombre === '' || mb_strlen($nombre) > 255) throw new ActivosError('El nombre es obligatorio (máximo 255 caracteres).');
        if (!in_array($categoria, $this->obtenerCategorias(), true)) throw new ActivosError('La categoría no es válida.');
        $fecha = self::fechaValida($fechaAdquisicion, 'La fecha de adquisición');
        $valor = self::entero($valorAdquisicion);
        if ($valor === null || $valor <= 0 || $valor > self::VALOR_MAXIMO) throw new ActivosError('El valor de adquisición debe ser un número entero positivo.');
        $vida = self::entero($vidaUtil);
        if ($vida === null || $vida < 1 || $vida > 100) throw new ActivosError('La vida útil debe ser un número entero de años entre 1 y 100.');
        if (!in_array($metodoDepreciacion, self::METODOS, true)) throw new ActivosError('El método de depreciación debe ser lineal o acelerada.');
        $factor = null;
        if ($metodoDepreciacion === 'acelerada') {
            if (!is_numeric($tasaAcelerada) || (float) $tasaAcelerada < 1.0 || (float) $tasaAcelerada > self::FACTOR_ACELERADO_MAXIMO) {
                throw new ActivosError('El factor de aceleración debe estar entre 1 y 5 (2 = el doble de la depreciación lineal).');
            }
            $factor = round((float) $tasaAcelerada, 2);
        }
        $descripcion = $descripcion === null ? null : mb_substr(self::texto($descripcion, 'La descripción'), 0, 2000);

        return $this->enTransaccion(function () use ($codigo, $nombre, $categoria, $fecha, $valor, $vida, $metodoDepreciacion, $factor, $descripcion): int {
            $dup = $this->db->prepare('SELECT 1 FROM activos_fijos WHERE empresa_id = ? AND codigo = ?');
            $dup->execute([$this->empresa_id, $codigo]);
            if ($dup->fetchColumn()) throw new ActivosError("Ya existe un activo con el código $codigo en esta empresa.");

            $ahora = date('Y-m-d H:i:s');
            $st = $this->db->prepare('INSERT INTO activos_fijos
                (empresa_id, codigo, nombre, descripcion, categoria, fecha_adquisicion, valor_adquisicion, vida_util_anos, metodo_depreciacion, tasa_depreciacion_acelerada, creado_en, actualizado_en)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            try {
                $st->execute([$this->empresa_id, $codigo, $nombre, $descripcion, $categoria, $fecha, $valor, $vida, $metodoDepreciacion, $factor, $ahora, $ahora]);
            } catch (PDOException $e) {
                // Otro envío simultáneo con el mismo código ganó la carrera: lo detecta el índice único.
                if ($e->getCode() === '23000') throw new ActivosError("Ya existe un activo con el código $codigo en esta empresa.");
                throw $e;
            }
            $id = (int) $this->db->lastInsertId();
            $this->registrarMovimiento($id, 'adquisicion', $fecha, $valor, 'Adquisición de activo fijo');
            return $id;
        });
    }

    /**
     * Da de baja un activo vigente: valora el activo a la fecha de baja y registra la pérdida (su valor neto).
     * Devuelve fecha_baja, valor_neto (la pérdida) y depreciacion_acumulada a esa fecha.
     * @throws ActivosError si el activo no existe en la empresa, ya está dado de baja o la fecha no es válida
     */
    public function darDeBaja($activoId, $fechaBaja = null, $razon = null): array {
        $fecha = ($fechaBaja === null || $fechaBaja === '') ? date('Y-m-d') : self::fechaValida($fechaBaja, 'La fecha de baja');
        $razon = self::texto($razon, 'La razón de la baja');
        $razon = $razon === '' ? 'Baja de activo fijo' : mb_substr($razon, 0, 500);
        // Un id mal formado (arreglo, "1abc", 1.9) no debe resolverse a otro activo al convertirlo con (int).
        $id = self::entero($activoId);
        if ($id === null || $id < 1) throw new ActivosError('El activo indicado no es válido.');

        return $this->enTransaccion(function () use ($id, $fecha, $razon): array {
            $st = $this->db->prepare('SELECT * FROM activos_fijos WHERE id = ? AND empresa_id = ?');
            $st->execute([$id, $this->empresa_id]);
            $activo = $st->fetch(PDO::FETCH_ASSOC);
            if (!$activo) throw new ActivosError('El activo no existe en esta empresa.');
            if ($activo['estado'] !== 'vigente') throw new ActivosError('El activo ya está dado de baja.');
            if ($fecha < substr((string) $activo['fecha_adquisicion'], 0, 10)) throw new ActivosError('La fecha de baja no puede ser anterior a la de adquisición.');

            $v = self::valuar($activo, $fecha);
            $this->registrarMovimiento((int) $activo['id'], 'baja', $fecha, $v['valor_neto'], 'Pérdida por baja: ' . $razon);
            $up = $this->db->prepare("UPDATE activos_fijos SET estado = 'baja', fecha_baja = ?, actualizado_en = ? WHERE id = ? AND empresa_id = ? AND estado = 'vigente'");
            $up->execute([$fecha, date('Y-m-d H:i:s'), (int) $activo['id'], $this->empresa_id]);
            if ($up->rowCount() !== 1) throw new ActivosError('El activo ya está dado de baja.');
            return ['fecha_baja' => $fecha, 'valor_neto' => $v['valor_neto'], 'depreciacion_acumulada' => $v['depreciacion_acumulada']];
        });
    }

    /** Reporte de los activos vigentes: totales, detalle y resumen por categoría. */
    public function obtenerReporteActivos(): array {
        $activos = $this->listarActivos('vigente');
        $bruto = $depreciacion = $neto = $anual = 0;
        foreach ($activos as $a) {
            $bruto += $a['valor_adquisicion'];
            $depreciacion += $a['depreciacion_acumulada'];
            $neto += $a['valor_neto'];
            $anual += $a['depreciacion_anual'];
        }
        return [
            'cantidad_activos' => count($activos),
            'valor_bruto_total' => $bruto,
            'depreciacion_acumulada_total' => $depreciacion,
            'valor_neto_total' => $neto,
            'depreciacion_anual_total' => $anual,
            'activos' => $activos,
            'por_categoria' => $this->obtenerValuacionPorCategoria($activos),
            'promedio_depreciacion_anual' => $activos ? intdiv($anual, count($activos)) : 0,
        ];
    }

    public function obtenerCategorias(): array {
        return [
            'Computadores y Equipos',
            'Muebles y Accesorios',
            'Vehículos',
            'Maquinaria',
            'Construcciones e Infraestructura',
            'Equipos de Oficina',
            'Otros Activos Fijos',
        ];
    }

    private function registrarMovimiento(int $activoId, string $tipo, string $fecha, int $valor, ?string $descripcion): void {
        $st = $this->db->prepare('INSERT INTO movimientos_activos
            (activo_id, empresa_id, tipo_movimiento, fecha_movimiento, valor_movimiento, descripcion, creado_en)
            VALUES (?, ?, ?, ?, ?, ?, ?)');
        $st->execute([$activoId, $this->empresa_id, $tipo, $fecha, $valor, $descripcion, date('Y-m-d H:i:s')]);
    }

    /** Ejecuta $f dentro de una transacción (o en la que ya esté abierta) y revierte si lanza una excepción. */
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

    /** Texto recortado. Acepta null (queda vacío), cadenas y números; arreglos, objetos y booleanos se rechazan en vez de guardarse como "Array". */
    private static function texto($valor, string $etiqueta): string {
        if ($valor === null) return '';
        if (is_string($valor) || is_int($valor) || is_float($valor)) return trim((string) $valor);
        throw new ActivosError("$etiqueta debe ser texto.");
    }

    /** Acepta enteros, textos de solo dígitos y decimales sin parte fraccionaria; cualquier otra cosa devuelve null. */
    private static function entero($valor): ?int {
        if (is_int($valor)) return $valor;
        if (is_string($valor) && preg_match('/^-?\d{1,15}$/', trim($valor))) return (int) trim($valor);
        if (is_float($valor) && is_finite($valor) && floor($valor) === $valor && abs($valor) < 1e15) return (int) $valor;
        return null;
    }

    private static function fechaValida($valor, string $etiqueta): string {
        $valor = ($valor === null || $valor === '') ? date('Y-m-d') : (is_string($valor) ? trim($valor) : '');
        $d = DateTime::createFromFormat('!Y-m-d', $valor);
        if (!$d || $d->format('Y-m-d') !== $valor || $valor < '1900-01-01' || $valor > '2100-12-31') {
            throw new ActivosError("$etiqueta debe ser una fecha válida (AAAA-MM-DD).");
        }
        return $valor;
    }
}
