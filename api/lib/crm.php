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
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $motor = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

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
            actualizado_en VARCHAR(25) NOT NULL,
            KEY idx_crm_empresa_tipo (empresa_id, tipo),
            KEY idx_crm_empresa_estado (empresa_id, estado),
            KEY idx_crm_ultima_interaccion (ultima_interaccion),
            KEY idx_crm_proximo_seguimiento (proximo_seguimiento)
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
            creado_en VARCHAR(25) NOT NULL,
            KEY idx_interacciones_contacto (contacto_id),
            KEY idx_interacciones_empresa_fecha (empresa_id, fecha_interaccion)
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
            actualizado_en VARCHAR(25) NOT NULL,
            KEY idx_oportunidades_contacto (contacto_id),
            KEY idx_oportunidades_empresa_etapa (empresa_id, etapa),
            KEY idx_oportunidades_estado (estado)
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
            creado_en VARCHAR(25) NOT NULL,
            KEY idx_tareas_contacto (contacto_id),
            KEY idx_tareas_empresa_estado (empresa_id, estado),
            KEY idx_tareas_fecha_vencimiento (fecha_vencimiento)
        )$motor");
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

        return $this->db->lastInsertId();
    }

    /**
     * Listar contactos con filtros
     */
    public function listarContactos($tipo = 'cliente', $estado = 'activo'): array {
        try {
            $result = $this->db->query(
                "SELECT id, nombre, email, telefono, empresa, cargo, tipo, estado, 
                        categoria, probabilidad_compra, monto_estimado, ultima_interaccion, proximo_seguimiento
                 FROM crm_contactos
                 WHERE empresa_id = ? AND tipo = ? AND estado = ?
                 ORDER BY ultima_interaccion DESC NULLS LAST",
                [$this->empresa_id, $tipo, $estado]
            )->fetchAll();

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
             VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)"
        );

        $stmt->execute([
            $contactoId,
            $this->empresa_id,
            $tipoInteraccion,
            $descripcion,
            $resultado,
            $duracion,
            $creado_en,
        ]);

        // Actualizar última interacción en contacto
        $this->db->prepare(
            "UPDATE crm_contactos SET ultima_interaccion = CURDATE(), actualizado_en = ? WHERE id = ? AND empresa_id = ?"
        )->execute([date('Y-m-d H:i:s'), $contactoId, $this->empresa_id]);

        return $this->db->lastInsertId();
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

        return $this->db->lastInsertId();
    }

    /**
     * Obtener oportunidades abiertas
     */
    public function obtenerOportunidadesAbiertas(): array {
        try {
            $result = $this->db->query(
                "SELECT o.id, o.nombre, o.monto_estimado, o.probabilidad, o.etapa, o.fecha_cierre_estimada,
                        c.nombre as contacto_nombre, c.email, c.telefono
                 FROM crm_oportunidades o
                 JOIN crm_contactos c ON o.contacto_id = c.id
                 WHERE o.empresa_id = ? AND o.estado = 'abierta'
                 ORDER BY o.fecha_cierre_estimada ASC",
                [$this->empresa_id]
            )->fetchAll();

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
            $result = $this->db->query(
                "SELECT t.id, t.descripcion, t.prioridad, t.fecha_vencimiento,
                        c.nombre as contacto_nombre, c.email
                 FROM crm_tareas t
                 JOIN crm_contactos c ON t.contacto_id = c.id
                 WHERE t.empresa_id = ? AND t.estado = 'pendiente'
                 ORDER BY t.prioridad DESC, t.fecha_vencimiento ASC",
                [$this->empresa_id]
            )->fetchAll();

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
            $clientes = $this->db->query(
                "SELECT COUNT(*) as total FROM crm_contactos WHERE empresa_id = ? AND tipo = 'cliente' AND estado = 'activo'",
                [$this->empresa_id]
            )->fetch();

            $oportunidades = $this->db->query(
                "SELECT COUNT(*) as total, SUM(monto_estimado * probabilidad / 100) as valor_esperado 
                 FROM crm_oportunidades 
                 WHERE empresa_id = ? AND estado = 'abierta'",
                [$this->empresa_id]
            )->fetch();

            $interacciones = $this->db->query(
                "SELECT COUNT(*) as total FROM crm_interacciones 
                 WHERE empresa_id = ? AND DATE(fecha_interaccion) = CURDATE()",
                [$this->empresa_id]
            )->fetch();

            $tareas = $this->db->query(
                "SELECT COUNT(*) as total FROM crm_tareas 
                 WHERE empresa_id = ? AND estado = 'pendiente'",
                [$this->empresa_id]
            )->fetch();

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
