<?php
/**
 * Notificaciones - Sistema de alertas y notificaciones
 *
 * Gestiona alertas por umbral, notificaciones a usuarios,
 * preferencias de notificación y envío de emails
 */

class Notificaciones {
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

        $db->exec("CREATE TABLE IF NOT EXISTS alertas_configuracion (
            id $id,
            empresa_id INT NOT NULL DEFAULT 0,
            tipo_alerta VARCHAR(50) NOT NULL DEFAULT '',
            descripcion VARCHAR(255),
            umbral_minimo BIGINT,
            umbral_maximo BIGINT,
            habilitada BOOLEAN NOT NULL DEFAULT 1,
            frecuencia VARCHAR(20) NOT NULL DEFAULT 'inmediata',
            canal_notificacion VARCHAR(50) NOT NULL DEFAULT 'email',
            creado_en VARCHAR(25) NOT NULL,
            actualizado_en VARCHAR(25) NOT NULL,
            KEY idx_alertas_empresa (empresa_id),
            KEY idx_alertas_tipo (tipo_alerta)
        )$motor");

        $db->exec("CREATE TABLE IF NOT EXISTS notificaciones (
            id $id,
            empresa_id INT NOT NULL DEFAULT 0,
            usuario_id INT,
            tipo_notificacion VARCHAR(50) NOT NULL DEFAULT '',
            titulo VARCHAR(255) NOT NULL DEFAULT '',
            mensaje TEXT,
            datos_json TEXT,
            estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',
            fecha_enviada VARCHAR(25),
            fecha_leida VARCHAR(25),
            canal VARCHAR(50) NOT NULL DEFAULT 'sistema',
            creado_en VARCHAR(25) NOT NULL,
            KEY idx_notificaciones_usuario (usuario_id),
            KEY idx_notificaciones_empresa_estado (empresa_id, estado),
            KEY idx_notificaciones_fecha (creado_en)
        )$motor");

        $db->exec("CREATE TABLE IF NOT EXISTS preferencias_notificacion (
            id $id,
            usuario_id INT NOT NULL,
            empresa_id INT NOT NULL DEFAULT 0,
            notificaciones_email BOOLEAN NOT NULL DEFAULT 1,
            notificaciones_sistema BOOLEAN NOT NULL DEFAULT 1,
            resumen_diario BOOLEAN NOT NULL DEFAULT 0,
            hora_resumen_diario TIME,
            alertas_criticas BOOLEAN NOT NULL DEFAULT 1,
            alertas_advertencia BOOLEAN NOT NULL DEFAULT 1,
            actualizado_en VARCHAR(25) NOT NULL,
            KEY idx_preferencias_usuario (usuario_id)
        )$motor");
    }

    /**
     * Crear nueva notificación
     */
    public function crearNotificacion($usuarioId, $tipoNotificacion, $titulo, $mensaje, $canal = 'sistema', $datosJson = null): int {
        $creado_en = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            "INSERT INTO notificaciones
             (empresa_id, usuario_id, tipo_notificacion, titulo, mensaje, canal, datos_json, creado_en)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->execute([
            $this->empresa_id,
            $usuarioId,
            $tipoNotificacion,
            $titulo,
            $mensaje,
            $canal,
            $datosJson,
            $creado_en,
        ]);

        return $this->db->lastInsertId();
    }

    /**
     * Obtener notificaciones no leídas de usuario
     */
    public function obtenerNotificacionesPendientes($usuarioId): array {
        try {
            $result = $this->db->query(
                "SELECT id, tipo_notificacion, titulo, mensaje, creado_en, datos_json
                 FROM notificaciones
                 WHERE empresa_id = ? AND usuario_id = ? AND estado = 'pendiente'
                 ORDER BY creado_en DESC
                 LIMIT 20",
                [$this->empresa_id, $usuarioId]
            )->fetchAll();

            return $result ?: [];
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Marcar notificación como leída
     */
    public function marcarComoLeida($notificacionId, $usuarioId): bool {
        try {
            $stmt = $this->db->prepare(
                "UPDATE notificaciones SET estado = 'leida', fecha_leida = ? 
                 WHERE id = ? AND usuario_id = ? AND empresa_id = ?"
            );

            return $stmt->execute([
                date('Y-m-d H:i:s'),
                $notificacionId,
                $usuarioId,
                $this->empresa_id,
            ]);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Configurar alerta
     */
    public function configurarAlerta($tipoAlerta, $umbralMinimo = null, $umbralMaximo = null, $frecuencia = 'inmediata'): int {
        $creado_en = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            "INSERT INTO alertas_configuracion
             (empresa_id, tipo_alerta, umbral_minimo, umbral_maximo, frecuencia, habilitada, creado_en, actualizado_en)
             VALUES (?, ?, ?, ?, ?, 1, ?, ?)"
        );

        $stmt->execute([
            $this->empresa_id,
            $tipoAlerta,
            $umbralMinimo,
            $umbralMaximo,
            $frecuencia,
            $creado_en,
            $creado_en,
        ]);

        return $this->db->lastInsertId();
    }

    /**
     * Generar alertas automáticas basadas en datos
     */
    public function generarAlertasAutomaticas($datosKPI): array {
        $alertas = [];

        // Alerta de saldo bajo (tesorería)
        if (isset($datosKPI['saldo_actual']) && $datosKPI['saldo_actual'] < 1000000) {
            $alertas[] = [
                'tipo' => 'saldo_bajo',
                'severidad' => 'critica',
                'titulo' => '⚠️ Saldo Bajo',
                'mensaje' => 'Saldo actual inferior a $1.000.000',
                'valor' => $datosKPI['saldo_actual'],
            ];
        }

        // Alerta de insolvencia proyectada
        if (isset($datosKPI['saldo_minimo']) && $datosKPI['saldo_minimo'] < 0) {
            $alertas[] = [
                'tipo' => 'insolvencia_proyectada',
                'severidad' => 'critica',
                'titulo' => '🔴 Insolvencia Proyectada',
                'mensaje' => 'Saldo negativo proyectado en los próximos 30 días',
                'valor' => $datosKPI['saldo_minimo'],
            ];
        }

        // Alerta de varianza presupuestaria
        if (isset($datosKPI['varianza_presupuesto'])) {
            if (abs($datosKPI['varianza_presupuesto']) > 0.15) {
                $tipoVarianza = $datosKPI['varianza_presupuesto'] > 0 ? 'sobre' : 'sub';
                $alertas[] = [
                    'tipo' => "varianza_{$tipoVarianza}ejecutada",
                    'severidad' => 'advertencia',
                    'titulo' => "📊 Varianza Presupuestaria",
                    'mensaje' => "Desviación presupuestaria superior al 15%",
                    'valor' => $datosKPI['varianza_presupuesto'],
                ];
            }
        }

        // Alerta de oportunidades próximas a cerrar
        if (isset($datosKPI['oportunidades_proximas_cierre']) && count($datosKPI['oportunidades_proximas_cierre']) > 0) {
            $alertas[] = [
                'tipo' => 'oportunidad_proximo_cierre',
                'severidad' => 'info',
                'titulo' => '🎯 Oportunidades Próximas a Cerrar',
                'mensaje' => count($datosKPI['oportunidades_proximas_cierre']) . ' oportunidades con fecha de cierre esta semana',
                'cantidad' => count($datosKPI['oportunidades_proximas_cierre']),
            ];
        }

        return $alertas;
    }

    /**
     * Enviar notificación por email (placeholder)
     */
    public function enviarEmailNotificacion($destinatario, $asunto, $mensaje): bool {
        // Implementar envío real de email aquí
        // Por ahora, solo registramos como enviado
        return true;
    }

    /**
     * Obtener tipos de alerta disponibles
     */
    public function obtenerTiposAlerta(): array {
        return [
            'saldo_bajo' => 'Saldo Bajo',
            'insolvencia_proyectada' => 'Insolvencia Proyectada',
            'varianza_presupuesto' => 'Varianza Presupuestaria',
            'oportunidad_proximo_cierre' => 'Oportunidad Próxima a Cerrar',
            'activo_proximo_depreciation' => 'Activo Próximo a Depreciación Completa',
            'tarea_vencida' => 'Tarea Vencida',
        ];
    }

    /**
     * Obtener preferencias de notificación
     */
    public function obtenerPreferencias($usuarioId): array {
        try {
            $result = $this->db->query(
                "SELECT * FROM preferencias_notificacion 
                 WHERE usuario_id = ? AND empresa_id = ?",
                [$usuarioId, $this->empresa_id]
            )->fetch();

            return $result ?: [
                'notificaciones_email' => true,
                'notificaciones_sistema' => true,
                'alertas_criticas' => true,
            ];
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Actualizar preferencias de notificación
     */
    public function actualizarPreferencias($usuarioId, $preferencias): bool {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO preferencias_notificacion 
                 (usuario_id, empresa_id, notificaciones_email, notificaciones_sistema, 
                  resumen_diario, alertas_criticas, actualizado_en)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                 notificaciones_email = VALUES(notificaciones_email),
                 notificaciones_sistema = VALUES(notificaciones_sistema),
                 resumen_diario = VALUES(resumen_diario),
                 alertas_criticas = VALUES(alertas_criticas),
                 actualizado_en = VALUES(actualizado_en)"
            );

            return $stmt->execute([
                $usuarioId,
                $this->empresa_id,
                $preferencias['notificaciones_email'] ?? true ? 1 : 0,
                $preferencias['notificaciones_sistema'] ?? true ? 1 : 0,
                $preferencias['resumen_diario'] ?? false ? 1 : 0,
                $preferencias['alertas_criticas'] ?? true ? 1 : 0,
                date('Y-m-d H:i:s'),
            ]);
        } catch (Exception $e) {
            return false;
        }
    }
}
