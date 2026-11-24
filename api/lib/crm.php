<?php
/**
 * CRM Básico - contactos, interacciones, oportunidades de venta y tareas de seguimiento
 *
 * Todo se guarda por empresa. Cada operación que recibe un contacto, oportunidad o tarea comprueba que pertenezca
 * a la empresa actual, así que un id de otra empresa se trata como inexistente.
 */

class CrmError extends RuntimeException {}

class CRM {
    public const TIPOS_CONTACTO = ['cliente', 'prospecto', 'proveedor'];
    public const TIPOS_INTERACCION = ['llamada', 'email', 'reunion', 'mensaje', 'visita', 'otro'];
    public const TIPOS_TAREA = ['seguimiento', 'llamada', 'email', 'reunion', 'otro'];
    public const PRIORIDADES = ['alta', 'media', 'baja'];
    /** Etapa de la oportunidad => probabilidad de cierre (%). */
    public const ETAPAS = ['prospecto' => 10, 'contacto_inicial' => 25, 'propuesta' => 50, 'negociacion' => 75, 'cierre' => 90];
    public const MONTO_MAXIMO = 1000000000000;

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
        $llaves = fn(string $lista) => $mysql ? ",\n            $lista" : '';

        $db->exec("CREATE TABLE IF NOT EXISTS crm_contactos (
            id $id,
            empresa_id INT NOT NULL DEFAULT 0,
            tipo VARCHAR(20) NOT NULL DEFAULT 'cliente',
            nombre VARCHAR(255) NOT NULL DEFAULT '',
            email VARCHAR(255),
            telefono VARCHAR(20),
            empresa VARCHAR(255),
            cargo VARCHAR(255),
            direccion TEXT,
            ciudad VARCHAR(100),
            pais VARCHAR(100),
            rut VARCHAR(20),
            estado VARCHAR(20) NOT NULL DEFAULT 'activo',
            categoria VARCHAR(50),
            probabilidad_compra INT NOT NULL DEFAULT 0,
            monto_estimado BIGINT NOT NULL DEFAULT 0,
            ultima_interaccion DATE,
            proximo_seguimiento DATE,
            vendedor_id INT,
            notas TEXT,
            creado_en VARCHAR(25) NOT NULL,
            actualizado_en VARCHAR(25) NOT NULL" . $llaves("KEY idx_crm_empresa_tipo (empresa_id, tipo),
            KEY idx_crm_empresa_estado (empresa_id, estado),
            KEY idx_crm_ultima_interaccion (ultima_interaccion),
            KEY idx_crm_proximo_seguimiento (proximo_seguimiento)") . "
        )$motor");

        $db->exec("CREATE TABLE IF NOT EXISTS crm_interacciones (
            id $id,
            contacto_id INT UNSIGNED NOT NULL,
            empresa_id INT NOT NULL DEFAULT 0,
            tipo_interaccion VARCHAR(50) NOT NULL DEFAULT 'llamada',
            descripcion TEXT,
            resultado VARCHAR(255),
            usuario_id INT,
            fecha_interaccion DATETIME NOT NULL,
            duracion_minutos INT,
            proxima_accion VARCHAR(255),
            proximafecha_seguimiento DATE,
            creado_en VARCHAR(25) NOT NULL" . $llaves("KEY idx_interacciones_contacto (contacto_id),
            KEY idx_interacciones_empresa_fecha (empresa_id, fecha_interaccion)") . "
        )$motor");

        $db->exec("CREATE TABLE IF NOT EXISTS crm_oportunidades (
            id $id,
            contacto_id INT UNSIGNED NOT NULL,
            empresa_id INT NOT NULL DEFAULT 0,
            nombre VARCHAR(255) NOT NULL DEFAULT '',
            descripcion TEXT,
            monto_estimado BIGINT NOT NULL DEFAULT 0,
            etapa VARCHAR(50) NOT NULL DEFAULT 'prospecto',
            probabilidad INT NOT NULL DEFAULT 10,
            fecha_cierre_estimada DATE,
            fecha_cierre_real DATE,
            vendedor_id INT,
            estado VARCHAR(20) NOT NULL DEFAULT 'abierta',
            motivo_cierre VARCHAR(255),
            creado_en VARCHAR(25) NOT NULL,
            actualizado_en VARCHAR(25) NOT NULL" . $llaves("KEY idx_oportunidades_contacto (contacto_id),
            KEY idx_oportunidades_empresa_etapa (empresa_id, etapa),
            KEY idx_oportunidades_estado (estado)") . "
        )$motor");

        $db->exec("CREATE TABLE IF NOT EXISTS crm_tareas (
            id $id,
            contacto_id INT UNSIGNED NOT NULL,
            empresa_id INT NOT NULL DEFAULT 0,
            descripcion TEXT NOT NULL,
            tipo_tarea VARCHAR(50) NOT NULL DEFAULT 'seguimiento',
            estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
            prioridad VARCHAR(20) NOT NULL DEFAULT 'media',
            fecha_vencimiento DATE NOT NULL,
            usuario_asignado INT,
            completada_en VARCHAR(25),
            creado_en VARCHAR(25) NOT NULL" . $llaves("KEY idx_tareas_contacto (contacto_id),
            KEY idx_tareas_empresa_estado (empresa_id, estado),
            KEY idx_tareas_fecha_vencimiento (fecha_vencimiento)") . "
        )$motor");

        if (!$mysql) {
            $db->exec('CREATE INDEX IF NOT EXISTS idx_crm_empresa_tipo ON crm_contactos (empresa_id, tipo)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_interacciones_contacto ON crm_interacciones (contacto_id)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_oportunidades_empresa_etapa ON crm_oportunidades (empresa_id, etapa)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_tareas_empresa_estado ON crm_tareas (empresa_id, estado)');
        }
    }

    // ------------------------------------------------------------------ Contactos

    /**
     * Crea un contacto y devuelve su id.
     * @throws CrmError si algún dato no es válido
     */
    public function crearContacto($nombre, $tipo = 'cliente', $email = null, $telefono = null, $empresa = null): int {
        $nombre = self::texto($nombre, 'El nombre');
        if ($nombre === '' || mb_strlen($nombre) > 255) throw new CrmError('El nombre es obligatorio (máximo 255 caracteres).');
        if (!in_array($tipo, self::TIPOS_CONTACTO, true)) throw new CrmError('El tipo de contacto debe ser cliente, prospecto o proveedor.');
        $email = self::texto($email, 'El correo');
        if ($email !== '' && (mb_strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL))) throw new CrmError('El correo electrónico no es válido.');
        $telefono = self::texto($telefono, 'El teléfono');
        if ($telefono !== '' && !preg_match('/^[0-9+()\-.\s]{3,20}$/', $telefono)) throw new CrmError('El teléfono solo puede llevar números, espacios y + ( ) - . (3 a 20 caracteres).');
        $empresa = self::texto($empresa, 'La empresa');
        if (mb_strlen($empresa) > 255) throw new CrmError('El nombre de la empresa no puede superar 255 caracteres.');

        $ahora = date('Y-m-d H:i:s');
        $st = $this->db->prepare('INSERT INTO crm_contactos (empresa_id, nombre, tipo, email, telefono, empresa, creado_en, actualizado_en) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([$this->empresa_id, $nombre, $tipo, $email === '' ? null : $email, $telefono === '' ? null : $telefono, $empresa === '' ? null : $empresa, $ahora, $ahora]);
        return (int) $this->db->lastInsertId();
    }

    /** Contactos de la empresa; $tipo null o 'todos' no filtra por tipo. Los que nunca tuvieron interacción van al final. */
    public function listarContactos($tipo = null, $estado = 'activo'): array {
        $tipo = ($tipo === null || $tipo === '') ? 'todos' : $tipo;
        if ($tipo !== 'todos' && !in_array($tipo, self::TIPOS_CONTACTO, true)) throw new CrmError('El tipo de contacto no es válido.');
        if (!in_array($estado, ['activo', 'inactivo'], true)) throw new CrmError('El estado debe ser activo o inactivo.');
        $sql = 'SELECT id, nombre, email, telefono, empresa, cargo, tipo, estado, categoria, probabilidad_compra, monto_estimado, ultima_interaccion, proximo_seguimiento
            FROM crm_contactos WHERE empresa_id = ? AND estado = ?';
        $params = [$this->empresa_id, $estado];
        if ($tipo !== 'todos') {
            $sql .= ' AND tipo = ?';
            $params[] = $tipo;
        }
        $st = $this->db->prepare($sql . ' ORDER BY (ultima_interaccion IS NULL), ultima_interaccion DESC, id DESC');
        $st->execute($params);
        return array_map(function (array $c): array {
            $c['id'] = (int) $c['id'];
            $c['probabilidad_compra'] = (int) $c['probabilidad_compra'];
            $c['monto_estimado'] = (int) $c['monto_estimado'];
            return $c;
        }, $st->fetchAll(PDO::FETCH_ASSOC));
    }

    // ------------------------------------------------------------------ Interacciones

    /**
     * Registra una interacción con un contacto de la empresa y actualiza su última interacción.
     * @throws CrmError si el contacto no existe en la empresa o algún dato no es válido
     */
    public function registrarInteraccion($contactoId, $tipoInteraccion, $descripcion, $resultado = null, $duracion = null): int {
        $contacto = $this->contactoVigente($contactoId);
        if (!in_array($tipoInteraccion, self::TIPOS_INTERACCION, true)) throw new CrmError('El tipo de interacción no es válido.');
        $descripcion = self::texto($descripcion, 'La descripción');
        if ($descripcion === '' || mb_strlen($descripcion) > 2000) throw new CrmError('La descripción es obligatoria (máximo 2.000 caracteres).');
        $resultado = self::texto($resultado, 'El resultado');
        if (mb_strlen($resultado) > 255) throw new CrmError('El resultado no puede superar 255 caracteres.');
        $minutos = null;
        if ($duracion !== null && $duracion !== '') {
            $minutos = self::entero($duracion);
            if ($minutos === null || $minutos < 0 || $minutos > 1440) throw new CrmError('La duración debe ser un número entero de minutos entre 0 y 1.440.');
        }

        return $this->enTransaccion(function () use ($contacto, $tipoInteraccion, $descripcion, $resultado, $minutos): int {
            $ahora = date('Y-m-d H:i:s');
            $st = $this->db->prepare('INSERT INTO crm_interacciones (contacto_id, empresa_id, tipo_interaccion, descripcion, resultado, duracion_minutos, fecha_interaccion, creado_en)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $st->execute([$contacto, $this->empresa_id, $tipoInteraccion, $descripcion, $resultado === '' ? null : $resultado, $minutos, $ahora, $ahora]);
            $id = (int) $this->db->lastInsertId();
            $this->db->prepare('UPDATE crm_contactos SET ultima_interaccion = ?, actualizado_en = ? WHERE id = ? AND empresa_id = ?')
                ->execute([date('Y-m-d'), $ahora, $contacto, $this->empresa_id]);
            return $id;
        });
    }

    /** Historial de interacciones de un contacto de la empresa, la más reciente primero. */
    public function listarInteracciones($contactoId, int $limite = 50): array {
        $contacto = $this->contactoVigente($contactoId, false);
        $limite = max(1, min($limite, 200));
        $st = $this->db->prepare("SELECT id, tipo_interaccion, descripcion, resultado, duracion_minutos, fecha_interaccion
            FROM crm_interacciones WHERE contacto_id = ? AND empresa_id = ? ORDER BY fecha_interaccion DESC, id DESC LIMIT $limite");
        $st->execute([$contacto, $this->empresa_id]);
        return array_map(function (array $i): array {
            $i['id'] = (int) $i['id'];
            $i['duracion_minutos'] = $i['duracion_minutos'] === null ? null : (int) $i['duracion_minutos'];
            return $i;
        }, $st->fetchAll(PDO::FETCH_ASSOC));
    }

    // ------------------------------------------------------------------ Oportunidades

    /**
     * Crea una oportunidad de venta abierta para un contacto de la empresa; la probabilidad depende de la etapa.
     * @throws CrmError si el contacto no existe en la empresa o algún dato no es válido
     */
    public function crearOportunidad($contactoId, $nombre, $montoEstimado, $fechaCierre = null, $etapa = 'prospecto'): int {
        $contacto = $this->contactoVigente($contactoId);
        $nombre = self::texto($nombre, 'El nombre de la oportunidad');
        if ($nombre === '' || mb_strlen($nombre) > 255) throw new CrmError('El nombre de la oportunidad es obligatorio (máximo 255 caracteres).');
        $monto = self::entero($montoEstimado ?? 0);
        if ($monto === null || $monto < 0 || $monto > self::MONTO_MAXIMO) throw new CrmError('El monto estimado debe ser un número entero mayor o igual a cero.');
        $fecha = self::fecha($fechaCierre === null || $fechaCierre === '' ? date('Y-m-d', strtotime('+30 days')) : $fechaCierre, 'La fecha de cierre estimada');
        if (!self::etapaValida($etapa)) throw new CrmError('La etapa no es válida.');

        $ahora = date('Y-m-d H:i:s');
        $st = $this->db->prepare('INSERT INTO crm_oportunidades (contacto_id, empresa_id, nombre, monto_estimado, etapa, probabilidad, fecha_cierre_estimada, creado_en, actualizado_en)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([$contacto, $this->empresa_id, $nombre, $monto, $etapa, self::ETAPAS[$etapa], $fecha, $ahora, $ahora]);
        return (int) $this->db->lastInsertId();
    }

    /** Mueve una oportunidad abierta a otra etapa (y actualiza su probabilidad). */
    public function cambiarEtapa($oportunidadId, $etapa): void {
        if (!self::etapaValida($etapa)) throw new CrmError('La etapa no es válida.');
        $id = $this->oportunidadAbierta($oportunidadId);
        // La condición de estado va también en el UPDATE: si otra petición cerró la oportunidad entre la lectura y la escritura, no se pisa.
        $up = $this->db->prepare("UPDATE crm_oportunidades SET etapa = ?, probabilidad = ?, actualizado_en = ? WHERE id = ? AND empresa_id = ? AND estado = 'abierta'");
        $up->execute([$etapa, self::ETAPAS[$etapa], date('Y-m-d H:i:s'), $id, $this->empresa_id]);
        // MySQL cuenta filas cambiadas: reenviar la misma etapa en el mismo segundo no cambia nada. Se relee el estado
        // (lanza si ya está cerrada; si sigue abierta, simplemente no había nada que cambiar).
        if ($up->rowCount() !== 1) $this->oportunidadAbierta($id);
    }

    /** Cierra una oportunidad abierta como ganada (100 %) o perdida (0 %). */
    public function cerrarOportunidad($oportunidadId, $resultado, $motivo = null): void {
        if (!in_array($resultado, ['ganada', 'perdida'], true)) throw new CrmError('El resultado debe ser ganada o perdida.');
        $motivo = self::texto($motivo, 'El motivo');
        if (mb_strlen($motivo) > 255) throw new CrmError('El motivo no puede superar 255 caracteres.');
        $id = $this->oportunidadAbierta($oportunidadId);
        $up = $this->db->prepare("UPDATE crm_oportunidades SET estado = ?, probabilidad = ?, fecha_cierre_real = ?, motivo_cierre = ?, actualizado_en = ? WHERE id = ? AND empresa_id = ? AND estado = 'abierta'");
        $up->execute([$resultado, $resultado === 'ganada' ? 100 : 0, date('Y-m-d'), $motivo === '' ? null : $motivo, date('Y-m-d H:i:s'), $id, $this->empresa_id]);
        if ($up->rowCount() !== 1) throw new CrmError('La oportunidad ya está cerrada.');
    }

    /** Oportunidades abiertas con su valor esperado (monto x probabilidad), la que cierra antes primero. */
    public function obtenerOportunidadesAbiertas(): array {
        $st = $this->db->prepare("SELECT o.id, o.nombre, o.monto_estimado, o.probabilidad, o.etapa, o.fecha_cierre_estimada,
                c.id AS contacto_id, c.nombre AS contacto_nombre, c.email, c.telefono
            FROM crm_oportunidades o JOIN crm_contactos c ON c.id = o.contacto_id AND c.empresa_id = o.empresa_id
            WHERE o.empresa_id = ? AND o.estado = 'abierta'
            ORDER BY o.fecha_cierre_estimada ASC, o.id ASC");
        $st->execute([$this->empresa_id]);
        return array_map(function (array $o): array {
            $o['id'] = (int) $o['id'];
            $o['contacto_id'] = (int) $o['contacto_id'];
            $o['monto_estimado'] = (int) $o['monto_estimado'];
            $o['probabilidad'] = (int) $o['probabilidad'];
            // En flotante: un monto heredado enorme (BIGINT sin validar) no debe tumbar todo el listado con un TypeError de intdiv.
            $o['valor_esperado'] = (int) floor(max(0, $o['monto_estimado']) * max(0, min(100, $o['probabilidad'])) / 100);
            return $o;
        }, $st->fetchAll(PDO::FETCH_ASSOC));
    }

    // ------------------------------------------------------------------ Tareas

    /**
     * Crea una tarea de seguimiento pendiente para un contacto de la empresa.
     * @throws CrmError si el contacto no existe en la empresa o algún dato no es válido
     */
    public function crearTarea($contactoId, $descripcion, $fechaVencimiento, $prioridad = 'media', $tipo = 'seguimiento'): int {
        $contacto = $this->contactoVigente($contactoId);
        $descripcion = self::texto($descripcion, 'La descripción');
        if ($descripcion === '' || mb_strlen($descripcion) > 1000) throw new CrmError('La descripción es obligatoria (máximo 1.000 caracteres).');
        $fecha = self::fecha($fechaVencimiento, 'La fecha de vencimiento');
        if (!in_array($prioridad, self::PRIORIDADES, true)) throw new CrmError('La prioridad debe ser alta, media o baja.');
        if (!in_array($tipo, self::TIPOS_TAREA, true)) throw new CrmError('El tipo de tarea no es válido.');

        $st = $this->db->prepare('INSERT INTO crm_tareas (contacto_id, empresa_id, descripcion, tipo_tarea, prioridad, fecha_vencimiento, creado_en) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $st->execute([$contacto, $this->empresa_id, $descripcion, $tipo, $prioridad, $fecha, date('Y-m-d H:i:s')]);
        return (int) $this->db->lastInsertId();
    }

    /** Marca como completada una tarea pendiente de la empresa. */
    public function completarTarea($tareaId): void {
        $id = self::entero($tareaId);
        if ($id === null || $id < 1) throw new CrmError('La tarea indicada no es válida.');
        $up = $this->db->prepare("UPDATE crm_tareas SET estado = 'completada', completada_en = ? WHERE id = ? AND empresa_id = ? AND estado = 'pendiente'");
        $up->execute([date('Y-m-d H:i:s'), $id, $this->empresa_id]);
        if ($up->rowCount() !== 1) throw new CrmError('La tarea no existe en esta empresa o ya no está pendiente.');
    }

    /** Tareas pendientes: primero las de prioridad alta y las que vencen antes; 'vencida' marca las atrasadas. */
    public function obtenerTareasPendientes(): array {
        $st = $this->db->prepare("SELECT t.id, t.descripcion, t.tipo_tarea, t.prioridad, t.fecha_vencimiento, c.id AS contacto_id, c.nombre AS contacto_nombre, c.email
            FROM crm_tareas t JOIN crm_contactos c ON c.id = t.contacto_id AND c.empresa_id = t.empresa_id
            WHERE t.empresa_id = ? AND t.estado = 'pendiente'
            ORDER BY t.fecha_vencimiento ASC, CASE t.prioridad WHEN 'alta' THEN 0 WHEN 'media' THEN 1 ELSE 2 END, t.id ASC");
        $st->execute([$this->empresa_id]);
        $hoy = date('Y-m-d');
        return array_map(function (array $t) use ($hoy): array {
            $t['id'] = (int) $t['id'];
            $t['contacto_id'] = (int) $t['contacto_id'];
            $t['vencida'] = substr((string) $t['fecha_vencimiento'], 0, 10) < $hoy;
            return $t;
        }, $st->fetchAll(PDO::FETCH_ASSOC));
    }

    // ------------------------------------------------------------------ Dashboard

    public function obtenerDashboard(): array {
        $uno = function (string $sql, array $params = []): int {
            $st = $this->db->prepare($sql);
            $st->execute($params);
            return (int) $st->fetchColumn();
        };
        $manana = date('Y-m-d', strtotime('+1 day'));
        $oportunidades = $this->obtenerOportunidadesAbiertas();
        $tareas = $this->obtenerTareasPendientes();
        return [
            'clientes_totales' => $uno("SELECT COUNT(*) FROM crm_contactos WHERE empresa_id = ? AND tipo = 'cliente' AND estado = 'activo'", [$this->empresa_id]),
            'oportunidades_abiertas' => count($oportunidades),
            'valor_esperado' => array_sum(array_column($oportunidades, 'valor_esperado')),
            'interacciones_hoy' => $uno('SELECT COUNT(*) FROM crm_interacciones WHERE empresa_id = ? AND fecha_interaccion >= ? AND fecha_interaccion < ?',
                [$this->empresa_id, date('Y-m-d') . ' 00:00:00', $manana . ' 00:00:00']),
            'tareas_pendientes' => count($tareas),
            'tareas_vencidas' => count(array_filter($tareas, fn($t) => $t['vencida'])),
            'oportunidades' => $oportunidades,
            'tareas' => $tareas,
        ];
    }

    public function obtenerTiposInteraccion(): array {
        return self::TIPOS_INTERACCION;
    }

    public function obtenerEtapasOportunidad(): array {
        return array_keys(self::ETAPAS);
    }

    // ------------------------------------------------------------------ Internos

    /** Id (entero) de un contacto de la empresa. Con $soloActivos, un contacto inactivo se trata como inexistente. */
    private function contactoVigente($contactoId, bool $soloActivos = true): int {
        $id = self::entero($contactoId);
        if ($id === null || $id < 1) throw new CrmError('El contacto indicado no es válido.');
        $st = $this->db->prepare('SELECT estado FROM crm_contactos WHERE id = ? AND empresa_id = ?');
        $st->execute([$id, $this->empresa_id]);
        $estado = $st->fetchColumn();
        if ($estado === false || ($soloActivos && $estado !== 'activo')) throw new CrmError('El contacto no existe en esta empresa.');
        return $id;
    }

    private function oportunidadAbierta($oportunidadId): int {
        $id = self::entero($oportunidadId);
        if ($id === null || $id < 1) throw new CrmError('La oportunidad indicada no es válida.');
        $st = $this->db->prepare('SELECT estado FROM crm_oportunidades WHERE id = ? AND empresa_id = ?');
        $st->execute([$id, $this->empresa_id]);
        $estado = $st->fetchColumn();
        if ($estado === false) throw new CrmError('La oportunidad no existe en esta empresa.');
        if ($estado !== 'abierta') throw new CrmError('La oportunidad ya está cerrada.');
        return $id;
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

    /** La etapa llega del JSON: un arreglo u objeto no puede usarse como clave (TypeError), así que primero se exige texto. */
    private static function etapaValida($etapa): bool {
        return is_string($etapa) && isset(self::ETAPAS[$etapa]);
    }

    /** Texto recortado. Acepta null (queda vacío), cadenas y números; arreglos, objetos y booleanos se rechazan. */
    private static function texto($valor, string $etiqueta): string {
        if ($valor === null) return '';
        if (is_string($valor) || is_int($valor) || is_float($valor)) return trim((string) $valor);
        throw new CrmError("$etiqueta debe ser texto.");
    }

    /** Acepta enteros, textos de solo dígitos y decimales sin parte fraccionaria; cualquier otra cosa devuelve null. */
    private static function entero($valor): ?int {
        if (is_int($valor)) return $valor;
        if (is_string($valor) && preg_match('/^-?\d{1,15}$/', trim($valor))) return (int) trim($valor);
        if (is_float($valor) && is_finite($valor) && floor($valor) === $valor && abs($valor) < 1e15) return (int) $valor;
        return null;
    }

    private static function fecha($valor, string $etiqueta): string {
        $valor = is_string($valor) ? trim($valor) : '';
        $d = DateTime::createFromFormat('!Y-m-d', $valor);
        if (!$d || $d->format('Y-m-d') !== $valor || $valor < '2000-01-01' || $valor > '2100-12-31') {
            throw new CrmError("$etiqueta debe ser una fecha válida (AAAA-MM-DD).");
        }
        return $valor;
    }
}
