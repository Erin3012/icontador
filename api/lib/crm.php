<?php
/**
 * CRM Básico - Gestión de contactos, interacciones y oportunidades de venta
 *
 * Mantiene registro de clientes, historial de interacciones,
 * oportunidades de venta y tareas de seguimiento
 */

class CRM {
    private $db;
    private $empresa_id;

    public function __construct($db, $empresa_id) {
        $this->db = $db;
        $this->empresa_id = $empresa_id;
    }

    /**
     * Crear tablas de esquema si no existen
     */
    public static function crearEsquema($db): void {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $db->exec(file_get_contents(dirname(__DIR__) . '/schema/crm.mysql.sql'));
            return;
        }

        $db->exec("CREATE TABLE IF NOT EXISTS crm_contactos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
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
            actualizado_en VARCHAR(25) NOT NULL,
            UNIQUE (empresa_id, email)
        )");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_crm_empresa_tipo ON crm_contactos (empresa_id, tipo)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_crm_empresa_estado ON crm_contactos (empresa_id, estado)');

        $db->exec("CREATE TABLE IF NOT EXISTS crm_interacciones (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            contacto_id INTEGER NOT NULL,
            empresa_id INT NOT NULL DEFAULT 0,
            tipo_interaccion VARCHAR(50) NOT NULL DEFAULT 'llamada',
            descripcion TEXT,
            resultado VARCHAR(255),
            usuario_id INT,
            fecha_interaccion DATETIME NOT NULL,
            duracion_minutos INT,
            proxima_accion VARCHAR(255),
            proximafecha_seguimiento DATE,
            creado_en VARCHAR(25) NOT NULL,
            FOREIGN KEY (contacto_id) REFERENCES crm_contactos(id) ON DELETE CASCADE
        )");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_interacciones_contacto ON crm_interacciones (contacto_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_interacciones_empresa_fecha ON crm_interacciones (empresa_id, fecha_interaccion)');

        $db->exec("CREATE TABLE IF NOT EXISTS crm_oportunidades (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            contacto_id INTEGER NOT NULL,
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
            actualizado_en VARCHAR(25) NOT NULL,
            FOREIGN KEY (contacto_id) REFERENCES crm_contactos(id) ON DELETE CASCADE
        )");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_oportunidades_contacto ON crm_oportunidades (contacto_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_oportunidades_empresa_etapa ON crm_oportunidades (empresa_id, etapa)');

        $db->exec("CREATE TABLE IF NOT EXISTS crm_tareas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            contacto_id INTEGER NOT NULL,
            empresa_id INT NOT NULL DEFAULT 0,
            descripcion TEXT NOT NULL,
            tipo_tarea VARCHAR(50) NOT NULL DEFAULT 'seguimiento',
            estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
            prioridad VARCHAR(20) NOT NULL DEFAULT 'media',
            fecha_vencimiento DATE NOT NULL,
            usuario_asignado INT,
            completada_en VARCHAR(25),
            creado_en VARCHAR(25) NOT NULL,
            FOREIGN KEY (contacto_id) REFERENCES crm_contactos(id) ON DELETE CASCADE
        )");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_tareas_contacto ON crm_tareas (contacto_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_tareas_empresa_estado ON crm_tareas (empresa_id, estado)');
    }

    /**
     * Crear nuevo contacto
     */
    public function crearContacto($nombre, $tipo = 'cliente', $email = null, $telefono = null, $empresa = null): int {
        $creado_en = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            "INSERT INTO crm_contactos
             (empresa_id, nombre, tipo, email, telefono, empresa, creado_en, actualizado_en)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->execute([
            $this->empresa_id,
            $nombre,
            $tipo,
            $email,
            $telefono,
            $empresa,
            $creado_en,
            $creado_en,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Listar contactos con filtros
     */
    public function listarContactos($tipo = 'cliente', $estado = 'activo'): array {
        try {
            $stmt = $this->db->prepare(
                "SELECT id, nombre, email, telefono, empresa, cargo, tipo, estado, 
                        categoria, probabilidad_compra, monto_estimado, ultima_interaccion, proximo_seguimiento
                 FROM crm_contactos
                 WHERE empresa_id = ? AND tipo = ? AND estado = ?
                 ORDER BY (ultima_interaccion IS NULL), ultima_interaccion DESC"
            );
            $stmt->execute([$this->empresa_id, $tipo, $estado]);
            $result = $stmt->fetchAll();

            return $result ?: [];
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Registrar interacción con contacto
     */
    public function registrarInteraccion($contactoId, $tipoInteraccion, $descripcion, $resultado = null, $duracion = null): int {
        $creado_en = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            "INSERT INTO crm_interacciones
             (contacto_id, empresa_id, tipo_interaccion, descripcion, resultado, duracion_minutos, fecha_interaccion, creado_en)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->execute([
            $contactoId,
            $this->empresa_id,
            $tipoInteraccion,
            $descripcion,
            $resultado,
            $duracion,
            $creado_en,
            $creado_en,
        ]);

        // Actualizar última interacción en contacto
        $this->db->prepare(
            "UPDATE crm_contactos SET ultima_interaccion = ?, actualizado_en = ? WHERE id = ? AND empresa_id = ?"
        )->execute([date('Y-m-d'), date('Y-m-d H:i:s'), $contactoId, $this->empresa_id]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Crear oportunidad de venta
     */
    public function crearOportunidad($contactoId, $nombre, $montoEstimado, $fechaCierre, $etapa = 'prospecto'): int {
        $creado_en = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            "INSERT INTO crm_oportunidades
             (contacto_id, empresa_id, nombre, monto_estimado, fecha_cierre_estimada, etapa, creado_en, actualizado_en)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->execute([
            $contactoId,
            $this->empresa_id,
            $nombre,
            $montoEstimado,
            $fechaCierre,
            $etapa,
            $creado_en,
            $creado_en,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Obtener oportunidades abiertas
     */
    public function obtenerOportunidadesAbiertas(): array {
        try {
            $stmt = $this->db->prepare(
                "SELECT o.id, o.nombre, o.monto_estimado, o.probabilidad, o.etapa, o.fecha_cierre_estimada,
                        c.nombre as contacto_nombre, c.email, c.telefono
                 FROM crm_oportunidades o
                 JOIN crm_contactos c ON o.contacto_id = c.id
                 WHERE o.empresa_id = ? AND o.estado = 'abierta'
                 ORDER BY o.fecha_cierre_estimada ASC"
            );
            $stmt->execute([$this->empresa_id]);
            $result = $stmt->fetchAll();

            foreach ($result as &$oportunidad) {
                $oportunidad['valor_esperado'] = (int)($oportunidad['monto_estimado'] * ($oportunidad['probabilidad'] / 100));
            }

            return $result ?: [];
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Obtener tareas pendientes
     */
    public function obtenerTareasPendientes(): array {
        try {
            $stmt = $this->db->prepare(
                "SELECT t.id, t.descripcion, t.prioridad, t.fecha_vencimiento,
                        c.nombre as contacto_nombre, c.email
                 FROM crm_tareas t
                 JOIN crm_contactos c ON t.contacto_id = c.id
                 WHERE t.empresa_id = ? AND t.estado = 'pendiente'
                 ORDER BY t.prioridad DESC, t.fecha_vencimiento ASC"
            );
            $stmt->execute([$this->empresa_id]);
            $result = $stmt->fetchAll();

            return $result ?: [];
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Obtener dashboard CRM
     */
    public function obtenerDashboard(): array {
        try {
            $stmtClientes = $this->db->prepare(
                "SELECT COUNT(*) as total FROM crm_contactos WHERE empresa_id = ? AND tipo = 'cliente' AND estado = 'activo'"
            );
            $stmtClientes->execute([$this->empresa_id]);
            $clientes = $stmtClientes->fetch();

            $stmtOp = $this->db->prepare(
                "SELECT COUNT(*) as total, SUM(monto_estimado * probabilidad / 100) as valor_esperado 
                 FROM crm_oportunidades 
                 WHERE empresa_id = ? AND estado = 'abierta'"
            );
            $stmtOp->execute([$this->empresa_id]);
            $oportunidades = $stmtOp->fetch();

            $stmtInt = $this->db->prepare(
                "SELECT COUNT(*) as total FROM crm_interacciones 
                 WHERE empresa_id = ? AND DATE(fecha_interaccion) = ?"
            );
            $stmtInt->execute([$this->empresa_id, date('Y-m-d')]);
            $interacciones = $stmtInt->fetch();

            $stmtTar = $this->db->prepare(
                "SELECT COUNT(*) as total FROM crm_tareas 
                 WHERE empresa_id = ? AND estado = 'pendiente'"
            );
            $stmtTar->execute([$this->empresa_id]);
            $tareas = $stmtTar->fetch();

            return [
                'clientes_totales' => $clientes['total'] ?? 0,
                'oportunidades_abiertas' => $oportunidades['total'] ?? 0,
                'valor_esperado' => $oportunidades['valor_esperado'] ?? 0,
                'interacciones_hoy' => $interacciones['total'] ?? 0,
                'tareas_pendientes' => $tareas['total'] ?? 0,
                'oportunidades' => $this->obtenerOportunidadesAbiertas(),
                'tareas' => $this->obtenerTareasPendientes(),
            ];
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Obtener tipos de interacción disponibles
     */
    public function obtenerTiposInteraccion(): array {
        return ['llamada', 'email', 'reunion', 'mensaje', 'visita', 'otro'];
    }

    /**
     * Obtener etapas de oportunidad
     */
    public function obtenerEtapasOportunidad(): array {
        return ['prospecto', 'contacto_inicial', 'propuesta', 'negociacion', 'cierre'];
    }
}
