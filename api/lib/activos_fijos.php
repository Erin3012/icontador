<?php
/**
 * Activos Fijos - Gestión de activos, depreciaciones y valuaciones
 *
 * Calcula depreciaciones (lineal/acelerada), mantiene valuaciones
 * de activos y genera reportes de estado patrimonial
 */

class ActivosFijos {
    private $db;
    private $empresa_id;

    public function __construct($db, $empresa_id) {
        $this->db = $db;
        $this->empresa_id = $empresa_id;
    }

    /**
     * Crear tabla de esquema si no existe
     */
    public static function crearEsquema($db): void {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $motor = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

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
            actualizado_en VARCHAR(25) NOT NULL,
            KEY idx_activos_empresa_estado (empresa_id, estado),
            KEY idx_activos_empresa_categoria (empresa_id, categoria),
            KEY idx_activos_fecha_adquisicion (fecha_adquisicion)
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
            creado_en VARCHAR(25) NOT NULL,
            KEY idx_depreciaciones_activo (activo_id),
            KEY idx_depreciaciones_empresa_periodo (empresa_id, ano, mes)
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
            creado_en VARCHAR(25) NOT NULL,
            KEY idx_movimientos_activo (activo_id),
            KEY idx_movimientos_empresa_fecha (empresa_id, fecha_movimiento)
        )$motor");
    }

    /**
     * Crear nuevo activo fijo
     */
    public function crearActivo($codigo, $nombre, $categoria, $fechaAdquisicion, $valorAdquisicion, $vidaUtil = 5, $metodoDepreciacion = 'lineal', $tasaAcelerada = null): int {
        $creado_en = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            "INSERT INTO activos_fijos
             (empresa_id, codigo, nombre, categoria, fecha_adquisicion, valor_adquisicion, vida_util_anos, metodo_depreciacion, tasa_depreciacion_acelerada, creado_en, actualizado_en)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->execute([
            $this->empresa_id,
            $codigo,
            $nombre,
            $categoria,
            $fechaAdquisicion,
            $valorAdquisicion,
            $vidaUtil,
            $metodoDepreciacion,
            $tasaAcelerada,
            $creado_en,
            $creado_en,
        ]);

        $activoId = $this->db->lastInsertId();

        // Registrar movimiento de adquisición
        $this->registrarMovimiento($activoId, 'adquisicion', $fechaAdquisicion, $valorAdquisicion, 'Adquisición de activo fijo');

        return $activoId;
    }

    /**
     * Obtener lista de activos por estado
     */
    public function listarActivos($estado = 'vigente'): array {
        try {
            $result = $this->db->query(
                "SELECT id, codigo, nombre, categoria, fecha_adquisicion, valor_adquisicion, 
                        vida_util_anos, metodo_depreciacion, estado, valor_residual, ubicacion, responsable
                 FROM activos_fijos
                 WHERE empresa_id = ? AND estado = ?
                 ORDER BY fecha_adquisicion DESC",
                [$this->empresa_id, $estado]
            )->fetchAll();

            foreach ($result as &$activo) {
                $valuacion = $this->calcularValuacionActual($activo['id']);
                $activo['valor_neto'] = $valuacion['valor_neto'];
                $activo['depreciacion_acumulada'] = $valuacion['depreciacion_acumulada'];
                $activo['depreciacion_anual'] = $valuacion['depreciacion_anual'];
            }

            return $result ?: [];
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Calcular valuación actual de un activo (valor neto + depreciación)
     */
    public function calcularValuacionActual($activoId): array {
        try {
            $activo = $this->db->query(
                "SELECT valor_adquisicion, vida_util_anos, metodo_depreciacion, tasa_depreciacion_acelerada, 
                        fecha_adquisicion, valor_residual, estado, fecha_baja
                 FROM activos_fijos
                 WHERE id = ? AND empresa_id = ?",
                [$activoId, $this->empresa_id]
            )->fetch();

            if (!$activo) {
                return ['valor_neto' => 0, 'depreciacion_acumulada' => 0, 'depreciacion_anual' => 0];
            }

            $hoy = $activo['estado'] === 'baja' ? $activo['fecha_baja'] : date('Y-m-d');
            $mesActual = (int)date('m', strtotime($hoy));
            $anoActual = (int)date('Y', strtotime($hoy));
            $mesAdquisicion = (int)date('m', strtotime($activo['fecha_adquisicion']));
            $anoAdquisicion = (int)date('Y', strtotime($activo['fecha_adquisicion']));

            // Calcular depreciación anual
            $valorDepreciable = $activo['valor_adquisicion'] - $activo['valor_residual'];
            $deprecacionAnual = 0;

            if ($activo['metodo_depreciacion'] === 'lineal') {
                $deprecacionAnual = (int)($valorDepreciable / $activo['vida_util_anos']);
            } elseif ($activo['metodo_depreciacion'] === 'acelerada' && $activo['tasa_depreciacion_acelerada']) {
                // Depreciación acelerada: 2x tasa lineal
                $tasaLineal = 1 / $activo['vida_util_anos'];
                $tasaAcelerada = $tasaLineal * $activo['tasa_depreciacion_acelerada'];
                $deprecacionAnual = (int)($activo['valor_adquisicion'] * $tasaAcelerada);
            }

            // Contar años de uso
            $anosUso = $anoActual - $anoAdquisicion;
            if ($anoActual === $anoAdquisicion) {
                // Primer año: prorratea por mes
                $mesesUso = $mesActual - $mesAdquisicion + 1;
                $anosUso = $mesesUso / 12;
            } else {
                // Años completos + fracción del año actual
                $mesesAnoActual = $mesActual;
                $anosUso = $anosUso + ($mesesAnoActual / 12);
            }

            $deprecacionAcumulada = (int)($deprecacionAnual * $anosUso);
            $valorNeto = max(0, $activo['valor_adquisicion'] - $deprecacionAcumulada);

            return [
                'valor_bruto' => $activo['valor_adquisicion'],
                'depreciacion_acumulada' => $deprecacionAcumulada,
                'valor_neto' => $valorNeto,
                'depreciacion_anual' => $deprecacionAnual,
                'anos_uso' => round($anosUso, 2),
            ];
        } catch (Exception $e) {
            return ['valor_neto' => 0, 'depreciacion_acumulada' => 0, 'depreciacion_anual' => 0];
        }
    }

    /**
     * Obtener valuación total de activos (por categoría)
     */
    public function obtenerValuacionPorCategoria(): array {
        try {
            $activos = $this->listarActivos('vigente');
            $valuaciones = [];

            foreach ($activos as $activo) {
                if (!isset($valuaciones[$activo['categoria']])) {
                    $valuaciones[$activo['categoria']] = [
                        'cantidad' => 0,
                        'valor_bruto' => 0,
                        'depreciacion_acumulada' => 0,
                        'valor_neto' => 0,
                    ];
                }

                $valuaciones[$activo['categoria']]['cantidad']++;
                $valuaciones[$activo['categoria']]['valor_bruto'] += $activo['valor_adquisicion'];
                $valuaciones[$activo['categoria']]['depreciacion_acumulada'] += $activo['depreciacion_acumulada'];
                $valuaciones[$activo['categoria']]['valor_neto'] += $activo['valor_neto'];
            }

            return $valuaciones;
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Registrar movimiento de activo
     */
    private function registrarMovimiento($activoId, $tipoMovimiento, $fecha, $valor, $descripcion = null): int {
        $creado_en = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            "INSERT INTO movimientos_activos
             (activo_id, empresa_id, tipo_movimiento, fecha_movimiento, valor_movimiento, descripcion, creado_en)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->execute([
            $activoId,
            $this->empresa_id,
            $tipoMovimiento,
            $fecha,
            $valor,
            $descripcion,
            $creado_en,
        ]);

        return $this->db->lastInsertId();
    }

    /**
     * Dar de baja un activo (depreciation total instantánea)
     */
    public function darDeBaja($activoId, $fechaBaja, $razon = null): bool {
        try {
            // Obtener valuación antes de baja
            $valuacion = $this->calcularValuacionActual($activoId);

            // Registrar pérdida por baja (diferencia entre valor neto y valor residual)
            $perdida = $valuacion['valor_neto'];
            if ($perdida > 0) {
                $this->registrarMovimiento($activoId, 'baja', $fechaBaja, $perdida, 'Pérdida por baja: ' . $razon);
            }

            // Actualizar estado
            $stmt = $this->db->prepare(
                "UPDATE activos_fijos SET estado = ?, fecha_baja = ?, actualizado_en = ? WHERE id = ? AND empresa_id = ?"
            );

            return $stmt->execute(['baja', $fechaBaja, date('Y-m-d H:i:s'), $activoId, $this->empresa_id]);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Obtener reporte de activos fijos
     */
    public function obtenerReporteActivos(): array {
        try {
            $activos = $this->listarActivos('vigente');
            $valuacionPorCategoria = $this->obtenerValuacionPorCategoria();

            $totalValorBruto = 0;
            $totalDepreciacion = 0;
            $totalValorNeto = 0;

            foreach ($activos as $activo) {
                $totalValorBruto += $activo['valor_adquisicion'];
                $totalDepreciacion += $activo['depreciacion_acumulada'];
                $totalValorNeto += $activo['valor_neto'];
            }

            return [
                'cantidad_activos' => count($activos),
                'valor_bruto_total' => $totalValorBruto,
                'depreciacion_acumulada_total' => $totalDepreciacion,
                'valor_neto_total' => $totalValorNeto,
                'activos' => $activos,
                'por_categoria' => $valuacionPorCategoria,
                'promedio_depreciacion_anual' => count($activos) > 0 ? (int)($totalDepreciacion / count($activos)) : 0,
            ];
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Obtener opciones de categorías
     */
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
}
