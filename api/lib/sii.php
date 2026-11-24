<?php
declare(strict_types=1);
/* Cola centralizada de sincronización RCV. La Clave Tributaria se cifra en reposo y solo se entrega
   al agente central autenticado al reclamar una tarea. Las tareas conservan usuario y empresa de origen. */
require_once __DIR__ . '/rcv.php';
require_once __DIR__ . '/honorarios.php';

const SII_LIBROS_SYNC = ['compras', 'ventas'];
const SII_RECLAMO_MINUTOS = 20; // si el agente no devuelve una tarea en este tiempo, vuelve a quedar pendiente.
const SII_MAX_MENSAJE = 500;
const SII_CLAVE_MAX = 200;

final class SiiError extends RuntimeException {}

function sii_schema(PDO $db): void {
    $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    if ($mysql) {
        $db->exec(file_get_contents(dirname(__DIR__) . '/schema/sii.mysql.sql'));
        $columnaLibro = $db->query("SHOW COLUMNS FROM sii_tareas LIKE 'libro'")->fetch(PDO::FETCH_ASSOC);
        if ($columnaLibro && preg_match('/^varchar\\((\\d+)\\)/i', (string) $columnaLibro['Type'], $m) && (int)$m[1] < 12) {
            $db->exec("ALTER TABLE sii_tareas MODIFY libro VARCHAR(12) NOT NULL DEFAULT ''");
        }
        sii_servicio_schema($db);
        sii_claves_temporales_schema($db);
        return;
    }
    $db->exec("CREATE TABLE IF NOT EXISTS sii_tareas (
        id INTEGER PRIMARY KEY AUTOINCREMENT, empresa_id INTEGER NOT NULL DEFAULT 0, tipo TEXT NOT NULL DEFAULT 'rcv',
        periodo TEXT NOT NULL DEFAULT '', libro TEXT NOT NULL DEFAULT '', rut TEXT NOT NULL DEFAULT '', estado TEXT NOT NULL DEFAULT 'pendiente',
        mensaje TEXT NOT NULL DEFAULT '', resultado_json TEXT NULL, creado_por INTEGER NOT NULL DEFAULT 0,
        creado_en TEXT NOT NULL DEFAULT '', actualizado_en TEXT NOT NULL DEFAULT '', reclamado_en TEXT NULL)");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_sii_empresa_estado ON sii_tareas (empresa_id, estado)');
    $db->exec("CREATE TABLE IF NOT EXISTS sii_eventos (
        id INTEGER PRIMARY KEY AUTOINCREMENT, tarea_id INTEGER NOT NULL, etapa TEXT NOT NULL,
        detalle TEXT NOT NULL DEFAULT '', creado_en TEXT NOT NULL DEFAULT '')");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_sii_eventos_tarea ON sii_eventos (tarea_id, id)');
    $db->exec("CREATE TABLE IF NOT EXISTS sii_capturas (
        tarea_id INTEGER PRIMARY KEY, imagen BLOB NOT NULL, creado_en TEXT NOT NULL DEFAULT '', expira_en TEXT NOT NULL DEFAULT '')");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_sii_capturas_expira ON sii_capturas (expira_en)');
    $db->exec("CREATE TABLE IF NOT EXISTS sii_claves (
        empresa_id INTEGER PRIMARY KEY, clave_cifrada TEXT NOT NULL, actualizado_en TEXT NOT NULL DEFAULT '')");
    $db->exec("CREATE TABLE IF NOT EXISTS sii_agentes (
        id INTEGER PRIMARY KEY AUTOINCREMENT, usuario_id INTEGER NOT NULL, nombre TEXT NOT NULL DEFAULT '',
        token_hash TEXT NOT NULL, creado_en TEXT NOT NULL DEFAULT '', ultimo_uso TEXT NULL, revocado_en TEXT NULL)");
    $db->exec("CREATE TABLE IF NOT EXISTS sii_codigos_emparejamiento (
        codigo_hash TEXT PRIMARY KEY, usuario_id INTEGER NOT NULL, creado_en TEXT NOT NULL DEFAULT '',
        expira_en TEXT NOT NULL DEFAULT '', usado_en TEXT NULL)");
    sii_servicio_schema($db);
    sii_claves_temporales_schema($db);
}

function sii_servicio_schema(PDO $db): void {
    $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $motor = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $db->exec("CREATE TABLE IF NOT EXISTS sii_servicio (
        id INTEGER PRIMARY KEY, token_hash CHAR(64) NULL, nombre VARCHAR(80) NOT NULL DEFAULT '',
        creado_en VARCHAR(25) NULL, ultimo_uso VARCHAR(25) NULL, revocado_en VARCHAR(25) NULL,
        codigo_hash CHAR(64) NULL, codigo_expira_en VARCHAR(25) NULL,
        worker_id VARCHAR(80) NULL, lease_hasta VARCHAR(25) NULL)$motor");
    if ($mysql) $db->exec("INSERT IGNORE INTO sii_servicio (id, nombre) VALUES (1, '')");
    else $db->exec("INSERT OR IGNORE INTO sii_servicio (id, nombre) VALUES (1, '')");
}

/** Secretos de consultas de identidad: cifrados y con expiración, nunca se mezclan con claves RCV persistentes. */
function sii_claves_temporales_schema(PDO $db): void {
    $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $motor = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $tipoId = $mysql ? 'INT UNSIGNED' : 'INTEGER';
    $tipoFecha = $mysql ? 'DATETIME' : 'TEXT';
    $db->exec("CREATE TABLE IF NOT EXISTS sii_claves_temporales (
        tarea_id $tipoId NOT NULL PRIMARY KEY, clave_cifrada TEXT NOT NULL, expira_en $tipoFecha NOT NULL)$motor");
}

function sii_ahora(): string {
    return date('Y-m-d H:i:s');
}

function sii_transporte_seguro(): bool {
    $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    if ($https) return true;
    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? ''), '[]'));
    $host = preg_replace('/:\d+$/', '', $host);
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

/** RUT de la empresa tal como está en su ficha ('' si no tiene). El agente lo normaliza para elegir la clave. */
function sii_rut_empresa(PDO $db, int $empresa): string {
    if ($empresa <= 0) return '';
    $st = $db->prepare('SELECT datos_json FROM empresas WHERE id = ?');
    $st->execute([$empresa]);
    $datos = json_decode((string) $st->fetchColumn(), true);
    return is_array($datos) ? trim((string) ($datos['rut'] ?? '')) : '';
}

function sii_tarea(PDO $db, int $id): ?array {
    $st = $db->prepare('SELECT id, empresa_id, tipo, rut, periodo, libro, estado, mensaje, creado_por, creado_en, actualizado_en FROM sii_tareas WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Encola una tarea por libro. Si ya hay una pendiente o en proceso para ese período y libro, no duplica. */
function sii_crear(PDO $db, int $empresa, int $usuario, $periodo, $libros): array {
    if ($empresa <= 0) throw new SiiError('Elija una empresa para sincronizar con el SII.');
    $periodo = rcv_validar_periodo($periodo);
    if (!is_array($libros) || !array_is_list($libros) || !$libros) throw new SiiError('Elija compras, ventas o ambos.');
    $libros = array_values(array_unique($libros));
    foreach ($libros as $libro) {
        if (!in_array($libro, SII_LIBROS_SYNC, true)) throw new SiiError('Libro inválido; use compras o ventas.');
    }
    $rut = sii_rut_empresa($db, $empresa);
    if ($rut === '') throw new SiiError('La empresa no tiene RUT. Complételo en Empresas antes de sincronizar.');
    if (!sii_clave_guardada($db, $empresa)) throw new SiiError('Guarda la Clave Tributaria del SII para esta empresa antes de sincronizar.');
    sii_clave_cifrado_key(); // no encolar si el hosting no puede descifrar lo guardado
    $creadas = [];
    $yaEnCola = [];
    foreach ($libros as $libro) {
        $st = $db->prepare("SELECT id FROM sii_tareas WHERE empresa_id = ? AND periodo = ? AND libro = ? AND estado IN ('pendiente', 'en_proceso', 'cancelando', 'reencolando')");
        $st->execute([$empresa, $periodo, $libro]);
        if ($st->fetchColumn()) {
            $yaEnCola[] = $libro;
            continue;
        }
        $ahora = sii_ahora();
        $db->prepare("INSERT INTO sii_tareas (empresa_id, periodo, libro, rut, estado, mensaje, creado_por, creado_en, actualizado_en) VALUES (?, ?, ?, ?, 'pendiente', '', ?, ?, ?)")
            ->execute([$empresa, $periodo, $libro, $rut, $usuario, $ahora, $ahora]);
        $creadas[] = (int) $db->lastInsertId();
    }
    return ['creadas' => $creadas, 'ya_en_cola' => $yaEnCola];
}

/** Encola un libro mensual de boletas de honorarios (recibidas o emitidas). */
function sii_crear_honorarios(PDO $db, int $empresa, int $usuario, $periodo, $libro): array {
    if ($empresa <= 0) throw new SiiError('Elija una empresa para consultar boletas de honorarios.');
    try { $periodo = hon_validar_periodo($periodo); $libro = hon_validar_libro($libro); }
    catch (HonorariosError $e) { throw new SiiError($e->getMessage()); }
    $rut = sii_rut_empresa($db, $empresa);
    if ($rut === '') throw new SiiError('La empresa no tiene RUT. Complételo en Empresas antes de sincronizar.');
    if (!sii_clave_guardada($db, $empresa)) throw new SiiError('Guarda la Clave Tributaria del SII para esta empresa antes de sincronizar.');
    sii_clave_cifrado_key();
    $st = $db->prepare("SELECT id FROM sii_tareas WHERE empresa_id = ? AND tipo = 'honorarios' AND periodo = ? AND libro = ? AND estado IN ('pendiente', 'en_proceso', 'cancelando', 'reencolando')");
    $st->execute([$empresa, $periodo, $libro]);
    if ($st->fetchColumn()) return ['creadas' => [], 'ya_en_cola' => [$libro]];
    $ahora = sii_ahora();
    $db->prepare("INSERT INTO sii_tareas (empresa_id, tipo, periodo, libro, rut, estado, mensaje, creado_por, creado_en, actualizado_en) VALUES (?, 'honorarios', ?, ?, ?, 'pendiente', '', ?, ?, ?)")
        ->execute([$empresa, $periodo, $libro, $rut, $usuario, $ahora, $ahora]);
    return ['creadas' => [(int)$db->lastInsertId()], 'ya_en_cola' => []];
}

/** Encola los libros de varios meses de una sola vez; limita cada lote para evitar colas accidentales enormes. */
function sii_crear_rango(PDO $db, int $empresa, int $usuario, $desde, $hasta, $libros, $honorarios = []): array {
    $desde = rcv_validar_periodo($desde);
    $hasta = rcv_validar_periodo($hasta);
    [$anioDesde, $mesDesde] = array_map('intval', explode('-', $desde));
    [$anioHasta, $mesHasta] = array_map('intval', explode('-', $hasta));
    $inicio = $anioDesde * 12 + $mesDesde;
    $fin = $anioHasta * 12 + $mesHasta;
    if ($inicio > $fin) throw new SiiError('El mes “Desde” debe ser anterior o igual al mes “Hasta”.');
    if (!is_array($libros) || !array_is_list($libros)) throw new SiiError('Selección de libros RCV inválida.');
    if (!is_array($honorarios) || !array_is_list($honorarios)) throw new SiiError('Selección de boletas de honorarios inválida.');
    $honorarios = array_values(array_unique($honorarios));
    foreach ($honorarios as $libroHon) { try { hon_validar_libro($libroHon); } catch (HonorariosError $e) { throw new SiiError($e->getMessage()); } }
    if (!$libros && !$honorarios) throw new SiiError('Elija libros RCV o boletas de honorarios.');
    $cantidad = $fin - $inicio + 1;
    if ($cantidad > 60) throw new SiiError('El rango máximo por solicitud es de 60 meses. Puedes dividir períodos más extensos en varios rangos.');

    $propia = !$db->inTransaction();
    if ($propia) $db->beginTransaction();
    try {
        $creadas = [];
        $yaEnCola = [];
        for ($n = 0; $n < $cantidad; $n++) {
            $indice = $inicio + $n;
            $periodo = intdiv($indice - 1, 12) . '-' . str_pad((string)(($indice - 1) % 12 + 1), 2, '0', STR_PAD_LEFT);
            $resultado = $libros ? sii_crear($db, $empresa, $usuario, $periodo, $libros) : ['creadas' => [], 'ya_en_cola' => []];
            foreach ($resultado['creadas'] as $id) $creadas[] = ['periodo' => $periodo, 'tarea_id' => $id];
            foreach ($resultado['ya_en_cola'] as $libro) $yaEnCola[] = ['periodo' => $periodo, 'libro' => $libro];
            foreach ($honorarios as $libroHon) {
                $honResultado = sii_crear_honorarios($db, $empresa, $usuario, $periodo, $libroHon);
                foreach ($honResultado['creadas'] as $id) $creadas[] = ['periodo' => $periodo, 'tarea_id' => $id];
                foreach ($honResultado['ya_en_cola'] as $libroYa) $yaEnCola[] = ['periodo' => $periodo, 'libro' => $libroYa];
            }
        }
        if ($propia) $db->commit();
        return ['periodos' => $cantidad, 'creadas' => $creadas, 'ya_en_cola' => $yaEnCola];
    } catch (Throwable $e) {
        if ($propia && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/** Tareas recientes de una empresa, para mostrar su estado en la pantalla RCV. */
function sii_listar(PDO $db, int $empresa): array {
    $st = $db->prepare("SELECT id, empresa_id, tipo, periodo, libro, estado, mensaje, creado_en, actualizado_en FROM sii_tareas WHERE empresa_id = ? AND tipo IN ('rcv','honorarios') ORDER BY id DESC LIMIT 60");
    $st->execute([$empresa]);
    return $st->fetchAll();
}

/** Eventos de progreso permitidos; solo la tarea de la empresa seleccionada puede consultarse desde la web. */
const SII_ETAPAS_EVENTO = [
    'iniciada', 'autenticando', 'sesion_lista', 'abriendo_rcv', 'abriendo_honorarios', 'seleccionando_empresa',
    'configurando_periodo', 'seleccionando_libro', 'consultando', 'descargando', 'validando',
    'importando', 'completada', 'error', 'cancelada',
];

/** Retiene solo la última imagen por tarea; la lectura queda limitada al solicitante y vence en 48 h. */
function sii_captura_guardar(PDO $db, int $tareaId, string $imagen): void {
    if (strlen($imagen) < 4 || strlen($imagen) > 1200000 || substr($imagen, 0, 2) !== "\xFF\xD8" || substr($imagen, -2) !== "\xFF\xD9") {
        throw new SiiError('La captura de pantalla no tiene un formato o tamaño válido.');
    }
    $st = $db->prepare("SELECT estado FROM sii_tareas WHERE id = ? AND tipo IN ('rcv','honorarios')");
    $st->execute([$tareaId]);
    if ($st->fetchColumn() !== 'en_proceso') throw new SiiError('La tarea ya no está en proceso.');
    $ahora = sii_ahora();
    $expira = date('Y-m-d H:i:s', time() + 48 * 60 * 60);
    $db->prepare('DELETE FROM sii_capturas WHERE expira_en < ?')->execute([$ahora]);
    $st = $db->prepare('SELECT tarea_id FROM sii_capturas WHERE tarea_id = ?');
    $st->execute([$tareaId]);
    if ($st->fetchColumn()) {
        $db->prepare('UPDATE sii_capturas SET imagen = ?, creado_en = ?, expira_en = ? WHERE tarea_id = ?')->execute([$imagen, $ahora, $expira, $tareaId]);
    } else {
        $db->prepare('INSERT INTO sii_capturas (tarea_id, imagen, creado_en, expira_en) VALUES (?, ?, ?, ?)')->execute([$tareaId, $imagen, $ahora, $expira]);
    }
}

/** Imagen visible solo para quien creó la tarea, con acceso vigente a la empresa. */
function sii_captura_leer(PDO $db, int $tareaId, int $usuario, int $empresa): ?array {
    $db->prepare('DELETE FROM sii_capturas WHERE expira_en < ?')->execute([sii_ahora()]);
    $st = $db->prepare("SELECT c.imagen, c.creado_en FROM sii_capturas c JOIN sii_tareas t ON t.id = c.tarea_id
        WHERE c.tarea_id = ? AND t.empresa_id = ? AND t.creado_por = ? AND t.tipo IN ('rcv','honorarios') AND c.expira_en >= ?");
    $st->execute([$tareaId, $empresa, $usuario, sii_ahora()]);
    $fila = $st->fetch();
    return $fila ?: null;
}

function sii_evento_registrar(PDO $db, int $tareaId, string $etapa, string $detalle = ''): void {
    if (!in_array($etapa, SII_ETAPAS_EVENTO, true)) throw new SiiError('Etapa de progreso no válida.');
    $detalle = $etapa === 'completada' && preg_match('/^\\d{1,9}$/', $detalle) ? $detalle : '';
    $st = $db->prepare("SELECT estado FROM sii_tareas WHERE id = ? AND tipo IN ('rcv','honorarios')");
    $st->execute([$tareaId]);
    $estado = $st->fetchColumn();
    if ($estado === false) throw new SiiError('La tarea no existe.');
    if ($etapa === 'completada' && $estado !== 'completada') throw new SiiError('La tarea aún no está completada.');
    if ($etapa === 'error' && !in_array($estado, ['en_proceso', 'error'], true)) throw new SiiError('La tarea no está en ejecución.');
    if ($etapa === 'cancelada' && $estado !== 'cancelada') throw new SiiError('La tarea no está cancelada.');
    if (!in_array($etapa, ['completada', 'error', 'cancelada'], true) && $estado !== 'en_proceso') throw new SiiError('La tarea no está en ejecución.');
    $db->prepare('INSERT INTO sii_eventos (tarea_id, etapa, detalle, creado_en) VALUES (?, ?, ?, ?)')
        ->execute([$tareaId, $etapa, $detalle, sii_ahora()]);
    $st = $db->prepare('SELECT id FROM sii_eventos WHERE tarea_id = ? ORDER BY id DESC LIMIT 100');
    $st->execute([$tareaId]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    if (count($ids) === 100) {
        $db->prepare('DELETE FROM sii_eventos WHERE tarea_id = ? AND id < ?')->execute([$tareaId, (int) end($ids)]);
    }
}

/** Devuelve el registro solo cuando la tarea pertenece a la empresa activa del usuario. */
function sii_eventos_listar(PDO $db, int $tareaId, int $empresa): ?array {
    $st = $db->prepare("SELECT id FROM sii_tareas WHERE id = ? AND empresa_id = ? AND tipo IN ('rcv','honorarios')");
    $st->execute([$tareaId, $empresa]);
    if (!$st->fetchColumn()) return null;
    $st = $db->prepare('SELECT etapa, detalle, creado_en FROM sii_eventos WHERE tarea_id = ? ORDER BY id DESC LIMIT 100');
    $st->execute([$tareaId]);
    return array_reverse($st->fetchAll());
}

/** Registro operativo reciente del agente central: solo etapas tipadas, sin texto libre ni secretos. */
function sii_logs_agente_listar(PDO $db, int $limite = 150): array {
    $limite = max(1, min(150, $limite));
    $st = $db->query("SELECT e.id, e.tarea_id, e.etapa, e.detalle, e.creado_en, t.tipo, t.libro, t.periodo, t.estado
        FROM sii_eventos e JOIN sii_tareas t ON t.id = e.tarea_id
        WHERE t.tipo IN ('rcv','honorarios') ORDER BY e.id DESC LIMIT $limite");
    return array_reverse($st->fetchAll());
}

/** Entrega hasta $maximo tareas pendientes al agente y las marca 'en_proceso'. Las que quedaron reservadas hace mucho vuelven a pendiente. */
function sii_reclamar(PDO $db, int $maximo = 1, ?int $usuarioAgente = null): array {
    $ahora = sii_ahora();
    $limite = date('Y-m-d H:i:s', time() - SII_RECLAMO_MINUTOS * 60);
    sii_claves_temporales_expirar($db);
    $db->prepare("UPDATE sii_tareas SET estado = 'cancelada', mensaje = 'Cancelación finalizada tras expirar el agente.', reclamado_en = NULL, actualizado_en = ? WHERE estado = 'cancelando' AND reclamado_en < ?")
        ->execute([$ahora, $limite]);
    $db->prepare("UPDATE sii_tareas SET estado = 'pendiente', mensaje = 'Tarea reencolada tras expirar el agente.', reclamado_en = NULL, actualizado_en = ? WHERE estado = 'reencolando' AND reclamado_en < ?")
        ->execute([$ahora, $limite]);
    $db->prepare("DELETE FROM sii_claves_temporales WHERE tarea_id IN (SELECT id FROM sii_tareas WHERE estado = 'cancelada' AND tipo = 'datos_empresa')")->execute();
    if ($usuarioAgente !== null) {
        $db->prepare("UPDATE sii_tareas SET estado = 'pendiente', actualizado_en = ? WHERE creado_por = ? AND tipo IN ('rcv', 'honorarios', 'datos_empresa') AND estado = 'en_proceso' AND reclamado_en < ?")
            ->execute([$ahora, $usuarioAgente, $limite]);
        $st = $db->prepare("SELECT id FROM sii_tareas WHERE creado_por = ? AND tipo IN ('rcv', 'honorarios', 'datos_empresa') AND estado = 'pendiente' ORDER BY id");
        $st->execute([$usuarioAgente]);
        $candidatas = $st->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $db->prepare("UPDATE sii_tareas SET estado = 'pendiente', actualizado_en = ? WHERE tipo IN ('rcv', 'honorarios', 'datos_empresa') AND estado = 'en_proceso' AND reclamado_en < ?")->execute([$ahora, $limite]);
        $candidatas = $db->query("SELECT id FROM sii_tareas WHERE tipo IN ('rcv', 'honorarios', 'datos_empresa') AND estado = 'pendiente' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    }
    $reclamadas = [];
    foreach (array_slice($candidatas, 0, $maximo) as $id) {
        $st = $db->prepare("UPDATE sii_tareas SET estado = 'en_proceso', reclamado_en = ?, actualizado_en = ? WHERE id = ? AND estado = 'pendiente'");
        $st->execute([$ahora, $ahora, $id]);
        if ($st->rowCount() !== 1) continue;
        $tarea = sii_tarea($db, (int) $id);
        if (!$tarea) continue;
        if ($tarea['tipo'] === 'datos_empresa') {
            try {
                $clave = sii_clave_temporal_leer($db, (int)$id);
            } catch (Throwable $e) {
                $clave = null;
            }
            if ($clave === null || $clave === '') {
                sii_actualizar_estado($db, (int)$id, 'error', 'No se pudo leer la Clave Tributaria temporal. Vuelve a solicitar los datos.');
                sii_clave_temporal_borrar($db, (int)$id);
                continue;
            }
            $reclamadas[] = [
                'id' => (int)$tarea['id'], 'tipo' => 'datos_empresa', 'rut' => (string)$tarea['rut'], 'clave' => $clave,
            ];
            continue;
        }
        if (!in_array($tarea['tipo'], ['rcv', 'honorarios'], true)) {
            sii_actualizar_estado($db, (int)$id, 'error', 'Tipo de tarea SII no admitido.');
            continue;
        }
        $rut = sii_rut_empresa($db, (int) $tarea['empresa_id']);
        if (($tarea['rut'] ?? '') !== '' && rut_limpio($tarea['rut']) !== rut_limpio($rut)) {
            sii_actualizar_estado($db, (int)$id, 'error', 'El RUT de la ficha cambió después de solicitar la sincronización.');
            continue;
        }
        if (($tarea['rut'] ?? '') === '') {
            $db->prepare('UPDATE sii_tareas SET rut = ? WHERE id = ?')->execute([$rut, (int)$id]);
            $tarea['rut'] = $rut;
        }
        try {
            $clave = sii_clave_descifrar($db, (int)$tarea['empresa_id']);
        } catch (Throwable $e) {
            sii_actualizar_estado($db, (int)$id, 'error', 'No se pudo descifrar la Clave Tributaria guardada. Vuelve a guardarla.');
            continue;
        }
        if ($clave === null || $clave === '') {
            sii_actualizar_estado($db, (int)$id, 'error', 'No hay una Clave Tributaria guardada para esta empresa.');
            continue;
        }
        $reclamadas[] = [
            'id' => (int) $tarea['id'],
            'tipo' => $tarea['tipo'],
            'periodo' => $tarea['periodo'],
            'libro' => $tarea['libro'],
            'rut' => $rut,
            'clave' => $clave,
        ];
    }
    return $reclamadas;
}

function sii_actualizar_estado(PDO $db, int $id, string $estado, string $mensaje): void {
    $db->prepare('UPDATE sii_tareas SET estado = ?, mensaje = ?, actualizado_en = ? WHERE id = ?')
        ->execute([$estado, mb_substr($mensaje, 0, SII_MAX_MENSAJE), sii_ahora(), $id]);
}

/** Guarda el libro que descargó el agente. El período, el libro y la empresa salen de la tarea, no del archivo. */
function sii_importar(PDO $db, int $id, array $datos, ?int $usuarioAgente = null): int {
    $db->beginTransaction();
    try {
    $st = $db->prepare('SELECT id, empresa_id, tipo, rut, periodo, libro, estado, mensaje, creado_por, creado_en, actualizado_en FROM sii_tareas WHERE id = ?' . ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
    $st->execute([$id]); $tarea = $st->fetch() ?: null;
    if (!$tarea || $tarea['estado'] !== 'en_proceso' || !in_array($tarea['tipo'], ['rcv', 'honorarios'], true)) throw new SiiError('La tarea no está en proceso.');
    if ($usuarioAgente !== null && (int) $tarea['creado_por'] !== $usuarioAgente) throw new SiiError('La tarea no pertenece a este agente.');
    $rutActual = sii_rut_empresa($db, (int)$tarea['empresa_id']);
    if ($rutActual === '' || rut_limpio($tarea['rut']) !== rut_limpio($rutActual)
        || rut_limpio((string)($datos['rutEmpresa'] ?? '')) !== rut_limpio($rutActual)) {
        sii_actualizar_estado($db, $id, 'error', 'El RUT del archivo no coincide con el RUT vigente de la empresa.');
        throw new SiiError('El RUT del archivo no coincide con el RUT vigente de la empresa.');
    }
        if (($datos['period'] ?? null) !== $tarea['periodo']) {
            throw new SiiError('El archivo es del período ' . ($datos['period'] ?? 'desconocido') . ' y la tarea pide ' . $tarea['periodo'] . '.');
        }
        if (($datos['kind'] ?? null) !== $tarea['libro']) {
            throw new SiiError('El archivo es de ' . ($datos['kind'] ?? 'otro libro') . ' y la tarea pide ' . $tarea['libro'] . '.');
        }
        if ($tarea['tipo'] === 'honorarios') {
            $paqueteHonorarios = ['kind' => $tarea['libro'], 'period' => $tarea['periodo'],
                'fileName' => is_string($datos['fileName'] ?? null) ? $datos['fileName'] : '',
                'rutEmpresa' => $datos['rutEmpresa'] ?? '', 'boletas' => $datos['boletas'] ?? null];
            try { $guardados = hon_guardar($db, $paqueteHonorarios, (int)$tarea['empresa_id']); }
            catch (HonorariosError $e) { throw new SiiError($e->getMessage()); }
            $mensaje = $guardados
                ? "$guardados boletas de honorarios guardadas."
                : 'El SII confirmó que no hay boletas de honorarios para este período.';
            $db->prepare("UPDATE sii_tareas SET estado = 'completada', mensaje = ?, actualizado_en = ? WHERE id = ? AND estado = 'en_proceso'")
                ->execute([mb_substr($mensaje, 0, SII_MAX_MENSAJE), sii_ahora(), $id]);
            $db->commit();
            return $guardados;
        }
        $paquete = [
            'kind' => $tarea['libro'],
            'period' => $tarea['periodo'],
            'fileName' => is_string($datos['fileName'] ?? null) ? $datos['fileName'] : '',
            'rutEmpresa' => $datos['rutEmpresa'] ?? '',
            'docs' => $datos['docs'] ?? [],
        ];
        if (isset($datos['resumen'])) {
            if ($tarea['libro'] !== 'ventas') throw new SiiError('El resumen agregado solo se admite para ventas.');
            $paquete['resumen'] = $datos['resumen'];
            $guardados = rcv_guardar_resumen($db, $paquete, (int)$tarea['empresa_id']);
        } else {
            $guardados = rcv_guardar($db, $paquete, (int) $tarea['empresa_id']);
        }
    $mensajeImportacion = isset($datos['resumen'])
        ? $guardados . ' comprobantes guardados como resumen mensual de ventas, sin vouchers individuales.'
        : (count($paquete['docs']) === 0
            ? 'Sin documentos de ' . $tarea['libro'] . ' para el período solicitado.'
            : $guardados . ' documentos guardados desde el SII.');
    $db->prepare("UPDATE sii_tareas SET estado = 'completada', mensaje = ?, actualizado_en = ? WHERE id = ? AND estado = 'en_proceso'")
        ->execute([mb_substr($mensajeImportacion, 0, SII_MAX_MENSAJE), sii_ahora(), $id]);
    $db->commit();
    return $guardados;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        if (!str_contains($e->getMessage(), 'no está en proceso') && !str_contains($e->getMessage(), 'no pertenece')) sii_actualizar_estado($db, $id, 'error', $e->getMessage());
        throw $e;
    }
}

/** Resumen global de cola para el PC del agente. Nunca devuelve RUT, datos de resultado ni secretos. */
function sii_servicio_cola(PDO $db): array {
    empresas_schema($db);
    auth_schema($db);
    $estado = sii_servicio_estado($db, false);
    $sql = "SELECT t.id, t.tipo, t.periodo, t.libro, t.estado, t.mensaje, t.creado_en, t.actualizado_en,
                   e.razon_social AS empresa, u.nombre AS solicitante
            FROM sii_tareas t LEFT JOIN empresas e ON e.id = t.empresa_id
            LEFT JOIN usuarios u ON u.id = t.creado_por
            WHERE t.estado IN ('pendiente','en_proceso','cancelando','reencolando','completada','error','cancelada')
            ORDER BY CASE WHEN t.estado IN ('en_proceso','cancelando','reencolando') THEN 0 WHEN t.estado='pendiente' THEN 1 ELSE 2 END, t.id DESC LIMIT 80";
    $filas = $db->query($sql)->fetchAll();
    $limpias = array_map(static fn($t) => [
        'id'=>(int)$t['id'], 'empresa'=>(string)($t['empresa'] ?: ($t['tipo']==='datos_empresa' ? 'Consulta MiSII' : 'Empresa')),
        'tipo'=>(string)$t['tipo'], 'periodo'=>(string)$t['periodo'], 'libro'=>(string)$t['libro'],
        'estado'=>(string)$t['estado'], 'mensaje'=>(string)preg_replace('/\b(?:\d{1,2}(?:\.\d{3}){2,3}|\d{7,8})-[\dkK]\b/', '[RUT oculto]', (string)$t['mensaje']),
        'solicitante'=>(string)($t['solicitante'] ?? ''), 'creado_en'=>(string)$t['creado_en'],
        'actualizado_en'=>(string)$t['actualizado_en'],
    ], $filas);
    return ['agente'=>['conectado'=>$estado['conectado'], 'activo'=>$estado['activo'], 'nombre'=>$estado['nombre'], 'ultimo_uso'=>$estado['ultimo_uso']],
        'tareas'=>$limpias];
}

/** Solicita cancelación inmediata o reencolado controlado. */
function sii_control_tarea(PDO $db, int $id, string $accion): array {
    if (!in_array($accion, ['cancelar','reencolar'], true)) throw new SiiError('Control de tarea inválido.');
    $db->beginTransaction();
    try {
        $sql = 'SELECT id, tipo, estado FROM sii_tareas WHERE id = ?' . ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '');
        $st=$db->prepare($sql); $st->execute([$id]); $t=$st->fetch();
        if (!$t) throw new SiiError('No se encontró la tarea.');
        if ($accion === 'cancelar' && $t['estado'] === 'pendiente') {
            sii_actualizar_estado($db,$id,'cancelada','Cancelada desde el agente central.');
            if ($t['tipo'] === 'datos_empresa') sii_clave_temporal_borrar($db, $id);
        } elseif ($accion === 'cancelar' && $t['estado'] === 'en_proceso') {
            sii_actualizar_estado($db,$id,'cancelando','Cancelación solicitada; deteniendo el navegador.');
        } elseif ($accion === 'reencolar' && $t['estado'] === 'en_proceso') {
            sii_actualizar_estado($db,$id,'reencolando','Reinicio solicitado; devolviendo la tarea a la cola.');
        } elseif ($accion === 'reencolar' && $t['estado'] === 'pendiente') {
            $db->commit(); return ['estado'=>'pendiente'];
        } else throw new SiiError('La tarea ya terminó o está siendo controlada.');
        $nuevo = $accion === 'cancelar' ? ($t['estado']==='pendiente'?'cancelada':'cancelando') : 'reencolando';
        $db->commit(); return ['estado'=>$nuevo];
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

function sii_finalizar_control(PDO $db, int $id, string $accion): void {
    $estado = $accion === 'cancelar' ? 'cancelada' : 'pendiente';
    $db->prepare('UPDATE sii_tareas SET estado = ?, mensaje = ?, reclamado_en = NULL, actualizado_en = ? WHERE id = ? AND estado = ?')
        ->execute([$estado, $accion === 'cancelar' ? 'Tarea cancelada; no se importaron resultados.' : 'Tarea devuelta a la cola para reintentar.', sii_ahora(), $id, $accion === 'cancelar' ? 'cancelando' : 'reencolando']);
    if ($accion === 'cancelar') sii_clave_temporal_borrar($db, $id);
}

/** El agente informa un fallo (clave incorrecta, SII caído, selector que cambió...). */
function sii_fallar(PDO $db, int $id, string $mensaje, ?int $usuarioAgente = null): void {
    $tarea = sii_tarea($db, $id);
    if (!$tarea || !in_array($tarea['estado'], ['pendiente', 'en_proceso'], true)) throw new SiiError('La tarea ya no está pendiente.');
    if ($usuarioAgente !== null && (int) $tarea['creado_por'] !== $usuarioAgente) throw new SiiError('La tarea no pertenece a este agente.');
    sii_actualizar_estado($db, $id, 'error', $mensaje);
    if ($tarea['tipo'] === 'datos_empresa') sii_clave_temporal_borrar($db, $id);
}

/* ---------- Clave tributaria guardada cifrada por empresa ---------- */

/** Llave AES-256 (32 bytes, base64) desde entorno o configuración privada fuera de DOCUMENT_ROOT. */
function sii_clave_cifrado_key(): string {
    $env = getenv('ICONTADOR_SII_CLAVE_CIFRADO');
    $valor = is_string($env) ? $env : '';
    if ($valor === '') {
        $ruta = getenv('ICONTADOR_PRIVATE_CONFIG');
        $real = is_string($ruta) && $ruta !== '' ? realpath($ruta) : false;
        $docroot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
        if ($real === false || !is_file($real)) {
            throw new SiiError('Configura ICONTADOR_SII_CLAVE_CIFRADO o ICONTADOR_PRIVATE_CONFIG fuera del directorio público.');
        }
        if ($docroot === false) throw new SiiError('No se pudo verificar DOCUMENT_ROOT para proteger la configuración de cifrado.');
        $prefijo = rtrim(str_replace('\\', '/', $docroot), '/') . '/';
        $rutaNormal = str_replace('\\', '/', $real);
        if (strncasecmp($rutaNormal, $prefijo, strlen($prefijo)) === 0 || strcasecmp($rutaNormal, $docroot) === 0) {
            throw new SiiError('La configuración de cifrado debe estar fuera del directorio público del sitio.');
        }
        $config = require $real;
        $valor = is_array($config) ? (string) ($config['sii_clave_cifrado'] ?? '') : '';
    }
    $llave = $valor === '' ? false : base64_decode($valor, true);
    if ($llave === false || strlen($llave) !== 32) {
        throw new SiiError('El servidor no tiene configurada la llave para guardar la clave del SII (sii_clave_cifrado: 32 bytes en base64).');
    }
    return $llave;
}

function sii_clave_cifrado_disponible(): bool {
    try { sii_clave_cifrado_key(); return true; }
    catch (Throwable) { return false; }
}

function sii_clave_guardada(PDO $db, int $empresa): bool {
    $st = $db->prepare('SELECT 1 FROM sii_claves WHERE empresa_id = ?');
    $st->execute([$empresa]);
    return (bool) $st->fetchColumn();
}

/** Guarda la clave cifrada de la empresa. Una clave vacía borra la guardada. Nunca devuelve la clave. */
function sii_clave_guardar(PDO $db, int $empresa, string $clave): void {
    if ($empresa <= 0) throw new SiiError('Elija una empresa para guardar su clave del SII.');
    $clave = trim($clave);
    if (strlen($clave) > SII_CLAVE_MAX) throw new SiiError('La clave tributaria es demasiado larga.');
    $sobre = null;
    if ($clave !== '') {
        $llave = sii_clave_cifrado_key(); // antes de borrar: si falta la llave, no se pierde la clave anterior
        $iv = random_bytes(12);
        $tag = '';
        $cifrado = openssl_encrypt($clave, 'aes-256-gcm', $llave, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cifrado === false) throw new SiiError('No se pudo cifrar la clave tributaria.');
        $sobre = base64_encode($iv . $tag . $cifrado);
    }
    if ($sobre === null) throw new SiiError('La Clave Tributaria no puede estar vacía.');
    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM sii_claves WHERE empresa_id = ?')->execute([$empresa]);
        $db->prepare('INSERT INTO sii_claves (empresa_id, clave_cifrada, actualizado_en) VALUES (?, ?, ?)')
            ->execute([$empresa, $sobre, sii_ahora()]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function sii_clave_borrar(PDO $db, int $empresa): void {
    if ($empresa <= 0) throw new SiiError('Elija una empresa para administrar su clave del SII.');
    $db->prepare('DELETE FROM sii_claves WHERE empresa_id = ?')->execute([$empresa]);
}

/** Clave tributaria de la empresa en claro (solo para entregarla al agente). Null si no hay ninguna. */
function sii_clave_descifrar(PDO $db, int $empresa): ?string {
    $st = $db->prepare('SELECT clave_cifrada FROM sii_claves WHERE empresa_id = ?');
    $st->execute([$empresa]);
    $sobre = $st->fetchColumn();
    if (!is_string($sobre) || $sobre === '') return null;
    $bin = base64_decode($sobre, true);
    if ($bin === false || strlen($bin) < 29) throw new SiiError('La clave guardada está dañada. Vuelva a guardarla.');
    $clave = openssl_decrypt(substr($bin, 28), 'aes-256-gcm', sii_clave_cifrado_key(), OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16));
    if ($clave === false) throw new SiiError('No se pudo descifrar la clave guardada. Revise la llave del servidor.');
    return $clave;
}

/** Cifra secretos de consultas puntuales usando el mismo sobre AES-256-GCM que las claves por empresa. */
function sii_secreto_cifrar(string $secreto): string {
    $iv = random_bytes(12);
    $tag = '';
    $cifrado = openssl_encrypt($secreto, 'aes-256-gcm', sii_clave_cifrado_key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($cifrado === false) throw new SiiError('No se pudo cifrar la Clave Tributaria.');
    return base64_encode($iv . $tag . $cifrado);
}

function sii_secreto_descifrar(string $sobre): string {
    $bin = base64_decode($sobre, true);
    if ($bin === false || strlen($bin) < 29) throw new SiiError('La clave temporal está dañada.');
    $secreto = openssl_decrypt(substr($bin, 28), 'aes-256-gcm', sii_clave_cifrado_key(), OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16));
    if ($secreto === false) throw new SiiError('No se pudo descifrar la Clave Tributaria temporal.');
    return $secreto;
}

function sii_clave_temporal_guardar(PDO $db, int $tarea, string $clave): void {
    $sobre = sii_secreto_cifrar($clave);
    $expira = date('Y-m-d H:i:s', time() + 15 * 60);
    $db->prepare('DELETE FROM sii_claves_temporales WHERE tarea_id = ?')->execute([$tarea]);
    $db->prepare('INSERT INTO sii_claves_temporales (tarea_id, clave_cifrada, expira_en) VALUES (?, ?, ?)')->execute([$tarea, $sobre, $expira]);
}

function sii_clave_temporal_leer(PDO $db, int $tarea): ?string {
    $st = $db->prepare('SELECT clave_cifrada, expira_en FROM sii_claves_temporales WHERE tarea_id = ?');
    $st->execute([$tarea]);
    $fila = $st->fetch();
    if (!$fila) return null;
    if ((string)$fila['expira_en'] < sii_ahora()) {
        $db->prepare('DELETE FROM sii_claves_temporales WHERE tarea_id = ?')->execute([$tarea]);
        return null;
    }
    return sii_secreto_descifrar((string)$fila['clave_cifrada']);
}

function sii_clave_temporal_borrar(PDO $db, int $tarea): void {
    $db->prepare('DELETE FROM sii_claves_temporales WHERE tarea_id = ?')->execute([$tarea]);
}

function sii_claves_temporales_expirar(PDO $db): void {
    $st = $db->prepare('SELECT tarea_id FROM sii_claves_temporales WHERE expira_en < ?');
    $st->execute([sii_ahora()]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $db->prepare("UPDATE sii_tareas SET estado = 'error', mensaje = ?, actualizado_en = ? WHERE id = ? AND tipo = 'datos_empresa' AND estado IN ('pendiente', 'en_proceso')")
            ->execute(['La consulta venció. Vuelve a ingresar la Clave Tributaria.', sii_ahora(), (int)$id]);
        sii_clave_temporal_borrar($db, (int)$id);
    }
}

const SII_CODIGO_MINUTOS = 10;
// Sin 0, O, 1 ni I para que se pueda dictar o copiar sin confusiones.
const SII_CODIGO_ALFABETO = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

function sii_codigo_normalizar(string $codigo): string {
    return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $codigo));
}

/* ---------- Servicio central: código de enrolamiento, token único y lease del worker ---------- */

function sii_servicio_codigo_crear(PDO $db, string $nombre = 'Agente central qlc.cl'): array {
    $codigo = '';
    for ($i = 0; $i < 20; $i++) $codigo .= SII_CODIGO_ALFABETO[random_int(0, strlen(SII_CODIGO_ALFABETO) - 1)];
    $expira = date('Y-m-d H:i:s', time() + SII_CODIGO_MINUTOS * 60);
    $db->beginTransaction();
    try {
        // Cambio de modelo: al comenzar el enrolamiento central, invalida todos los emparejamientos individuales.
        $db->prepare('UPDATE sii_agentes SET revocado_en = ? WHERE revocado_en IS NULL')->execute([sii_ahora()]);
        $db->prepare('DELETE FROM sii_codigos_emparejamiento WHERE usado_en IS NULL')->execute();
        $db->prepare("UPDATE sii_servicio SET token_hash = NULL, nombre = ?, revocado_en = ?, codigo_hash = ?, codigo_expira_en = ?, worker_id = NULL, lease_hasta = NULL WHERE id = 1")
            ->execute([mb_substr($nombre, 0, 80), sii_ahora(), hash('sha256', $codigo), $expira]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return ['codigo' => substr($codigo, 0, 5) . '-' . substr($codigo, 5, 5) . '-' . substr($codigo, 10, 5) . '-' . substr($codigo, 15), 'expira_en' => $expira];
}

function sii_servicio_canjear(PDO $db, string $codigo, string $nombre): string {
    $normal = sii_codigo_normalizar($codigo);
    if (strlen($normal) !== 20) throw new SiiError('Código de servicio inválido o vencido.');
    $st = $db->prepare('SELECT codigo_hash, codigo_expira_en FROM sii_servicio WHERE id = 1');
    $st->execute();
    $fila = $st->fetch();
    if (!$fila || !hash_equals((string)$fila['codigo_hash'], hash('sha256', $normal)) || (string)$fila['codigo_expira_en'] < sii_ahora()) {
        throw new SiiError('Código de servicio inválido o vencido.');
    }
    $token = bin2hex(random_bytes(32));
    $actualizar = $db->prepare('UPDATE sii_servicio SET token_hash = ?, nombre = ?, creado_en = ?, ultimo_uso = NULL, revocado_en = NULL, codigo_hash = NULL, codigo_expira_en = NULL, worker_id = NULL, lease_hasta = NULL WHERE id = 1 AND codigo_hash = ?');
    $actualizar->execute([hash('sha256', $token), mb_substr(trim($nombre), 0, 80), sii_ahora(), hash('sha256', $normal)]);
    if ($actualizar->rowCount() !== 1) throw new SiiError('Código de servicio inválido o vencido.');
    return $token;
}

function sii_servicio_revocar(PDO $db): void {
    $db->prepare('UPDATE sii_servicio SET token_hash = NULL, revocado_en = ?, codigo_hash = NULL, codigo_expira_en = NULL, worker_id = NULL, lease_hasta = NULL WHERE id = 1')->execute([sii_ahora()]);
}

function sii_servicio_estado(PDO $db, bool $admin): array {
    $st = $db->query('SELECT nombre, creado_en, ultimo_uso, revocado_en, token_hash FROM sii_servicio WHERE id = 1');
    $fila = $st->fetch() ?: [];
    $ultimo = (string)($fila['ultimo_uso'] ?? '');
    $online = !empty($fila['token_hash']) && empty($fila['revocado_en']) && $ultimo !== '' && strtotime($ultimo) >= time() - 90;
    return ['activo' => !empty($fila['token_hash']) && empty($fila['revocado_en']), 'conectado' => $online,
        'nombre' => (string)($fila['nombre'] ?? ''), 'ultimo_uso' => $ultimo ?: null, 'puede_administrar' => $admin];
}

function sii_servicio_autorizar(PDO $db): void {
    $recibido = (string)($_SERVER['HTTP_X_AGENTE_TOKEN'] ?? '');
    if ($recibido === '' || strlen($recibido) < 32) auth_json(401, ['error' => 'Token del servicio inválido.']);
    $st = $db->prepare('SELECT token_hash, revocado_en FROM sii_servicio WHERE id = 1');
    $st->execute();
    $fila = $st->fetch();
    if (!$fila || !empty($fila['revocado_en']) || empty($fila['token_hash']) || !hash_equals((string)$fila['token_hash'], hash('sha256', $recibido))) {
        auth_json(401, ['error' => 'Token del servicio inválido o revocado.']);
    }
}

function sii_servicio_heartbeat(PDO $db, string $workerId): array {
    if (!preg_match('/^[a-f0-9-]{20,80}$/i', $workerId)) throw new SiiError('Identificador de proceso inválido.');
    $db->beginTransaction();
    try {
        $st = $db->query('SELECT worker_id, lease_hasta, revocado_en, token_hash FROM sii_servicio WHERE id = 1' . ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
        $fila = $st->fetch() ?: [];
        if (empty($fila['token_hash']) || !empty($fila['revocado_en'])) throw new SiiError('El agente central está revocado.');
        $ocupado = !empty($fila['worker_id']) && (string)$fila['worker_id'] !== $workerId
            && !empty($fila['lease_hasta']) && (string)$fila['lease_hasta'] >= sii_ahora();
        if ($ocupado) {
            $db->commit();
            return ['activo' => true, 'ocupado' => true];
        }
        $ahora = sii_ahora();
        $vence = date('Y-m-d H:i:s', time() + 90);
        $db->prepare('UPDATE sii_servicio SET worker_id = ?, lease_hasta = ?, ultimo_uso = ? WHERE id = 1')->execute([$workerId, $vence, $ahora]);
        $db->commit();
        return ['activo' => true, 'ocupado' => false];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function sii_servicio_liberar(PDO $db, string $workerId): void {
    $db->prepare('UPDATE sii_servicio SET worker_id = NULL, lease_hasta = NULL WHERE id = 1 AND worker_id = ?')->execute([$workerId]);
}

/* ---------- Datos de una empresa nueva, consultados en MiSII por el agente central ---------- */

function sii_rut_normalizar(string $rut): ?string {
    $limpio = strtoupper(preg_replace('/[.\s]/', '', $rut));
    if (!preg_match('/^(\d{1,8})-?([\dK])$/', $limpio, $m)) return null;
    $suma = 0; $factor = 2;
    for ($i = strlen($m[1]) - 1; $i >= 0; $i--) { $suma += (int)$m[1][$i] * $factor; $factor = $factor === 7 ? 2 : $factor + 1; }
    $dv = 11 - $suma % 11;
    $esperado = $dv === 11 ? '0' : ($dv === 10 ? 'K' : (string)$dv);
    return $esperado === $m[2] ? ltrim($m[1], '0') . '-' . $m[2] : null;
}

/** Encola la consulta con clave temporal cifrada. La clave se borra al resolver o después de 15 minutos. */
function sii_crear_consulta_empresa(PDO $db, int $usuario, $rut, $clave): array {
    $rut = is_string($rut) ? sii_rut_normalizar($rut) : null;
    if ($rut === null) throw new SiiError('Ingresa un RUT válido de la empresa.');
    if (!is_string($clave) || trim($clave) === '') throw new SiiError('Ingresa la Clave Tributaria del SII.');
    $clave = trim($clave);
    if (strlen($clave) > SII_CLAVE_MAX) throw new SiiError('La Clave Tributaria es demasiado larga.');
    if (!sii_servicio_estado($db, false)['conectado']) throw new SiiError('El agente central está desconectado. Vuelve a intentarlo cuando aparezca conectado en RCV.');
    sii_clave_cifrado_key();

    $db->beginTransaction();
    try {
        $st = $db->prepare("SELECT id, estado FROM sii_tareas WHERE tipo = 'datos_empresa' AND creado_por = ? AND rut = ? AND estado IN ('pendiente', 'en_proceso') ORDER BY id DESC LIMIT 1" . ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
        $st->execute([$usuario, $rut]);
        $existente = $st->fetch();
        if ($existente && $existente['estado'] === 'en_proceso') throw new SiiError('Ya hay una consulta de este RUT en curso. Espera a que termine.');
        if ($existente) {
            $id = (int)$existente['id'];
            $db->prepare('UPDATE sii_tareas SET actualizado_en = ?, mensaje = ? WHERE id = ?')->execute([sii_ahora(), '', $id]);
        } else {
            $ahora = sii_ahora();
            $db->prepare("INSERT INTO sii_tareas (empresa_id, tipo, periodo, libro, rut, estado, mensaje, creado_por, creado_en, actualizado_en) VALUES (0, 'datos_empresa', '', '', ?, 'pendiente', '', ?, ?, ?)")
                ->execute([$rut, $usuario, $ahora, $ahora]);
            $id = (int)$db->lastInsertId();
        }
        sii_clave_temporal_guardar($db, $id, $clave);
        $db->commit();
        return ['id' => $id];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/** Estado de una consulta de datos. Solo la ve quien la pidió. */
function sii_consulta_empresa(PDO $db, int $id, int $usuario): ?array {
    $st = $db->prepare("SELECT id, estado, mensaje, resultado_json FROM sii_tareas WHERE id = ? AND tipo = 'datos_empresa' AND creado_por = ?");
    $st->execute([$id, $usuario]);
    $fila = $st->fetch();
    if (!$fila) return null;
    return ['id' => (int) $fila['id'], 'estado' => $fila['estado'], 'mensaje' => $fila['mensaje'],
        'datos' => $fila['estado'] === 'completada' ? (json_decode((string) $fila['resultado_json'], true) ?: null) : null];
}

/** El agente devuelve solo datos de perfil, actividad y domicilio; nunca personas relacionadas ni obligaciones. */
function sii_resultado_empresa(PDO $db, int $id, array $datos, ?int $usuarioAgente = null): array {
    $db->beginTransaction();
    try {
    $st = $db->prepare("SELECT id, empresa_id, tipo, rut, periodo, libro, estado, mensaje, creado_por, creado_en, actualizado_en FROM sii_tareas WHERE id = ?" . ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
    $st->execute([$id]); $tarea = $st->fetch() ?: null;
    if (!$tarea || $tarea['estado'] !== 'en_proceso' || $tarea['tipo'] !== 'datos_empresa') throw new SiiError('La consulta no está en proceso.');
    if ($usuarioAgente !== null && (int) $tarea['creado_por'] !== $usuarioAgente) throw new SiiError('La consulta no pertenece a este agente.');
    $razon = trim((string) ($datos['razon_social'] ?? ''));
    if ($razon === '') {
        sii_actualizar_estado($db, $id, 'error', 'El SII no devolvió la razón social.');
        sii_clave_temporal_borrar($db, $id);
        throw new SiiError('El SII no devolvió la razón social.');
    }
    $rutRespuesta = sii_rut_normalizar((string)($datos['rut'] ?? ''));
    if ($rutRespuesta === null || $rutRespuesta !== $tarea['rut']) {
        sii_actualizar_estado($db, $id, 'error', 'El RUT mostrado por el SII no coincide con el solicitado.');
        sii_clave_temporal_borrar($db, $id);
        throw new SiiError('El RUT mostrado por el SII no coincide con el solicitado.');
    }
    $texto = static fn($campo, $max) => mb_substr(trim(is_scalar($datos[$campo] ?? null) ? (string)$datos[$campo] : ''), 0, $max);
    $resultado = [
        'razon_social' => mb_substr($razon, 0, 191), 'rut' => $rutRespuesta,
        'giro' => $texto('giro', 120), 'regimen' => $texto('regimen', 80),
        'telefono' => $texto('telefono', 30), 'email' => $texto('email', 120),
        'domicilio' => $texto('domicilio', 191), 'comuna' => $texto('comuna', 80), 'region' => $texto('region', 80),
        'fecha_constitucion' => $texto('fecha_constitucion', 10), 'fecha_inicio_actividades' => $texto('fecha_inicio_actividades', 10),
        'categoria_tributaria' => $texto('categoria_tributaria', 40), 'tipo_contribuyente' => $texto('tipo_contribuyente', 80),
        'subtipo_contribuyente' => $texto('subtipo_contribuyente', 80), 'segmento' => $texto('segmento', 60),
        'glosa_descriptiva' => $texto('glosa_descriptiva', 191),
    ];
    foreach (['actividades', 'sucursales', 'caracteristicas', 'documentos_autorizados'] as $coleccion) {
        $resultado[$coleccion] = [];
        if (!is_array($datos[$coleccion] ?? null)) continue;
        foreach (array_slice($datos[$coleccion], 0, 30) as $fila) {
            if (!is_array($fila)) continue;
            $limpia = [];
            foreach ($fila as $campo => $valor) {
                if (!in_array($campo, ['descripcion', 'codigo', 'categoria', 'afecta_iva', 'desde', 'direccion', 'tipo', 'hasta', 'fecha', 'nombre'], true)) continue;
                if (is_scalar($valor)) $limpia[$campo] = mb_substr(trim((string)$valor), 0, 191);
            }
            if ($limpia) $resultado[$coleccion][] = $limpia;
        }
    }
    $db->prepare("UPDATE sii_tareas SET estado = 'completada', mensaje = ?, resultado_json = ?, actualizado_en = ? WHERE id = ? AND estado = 'en_proceso'")
        ->execute(['Datos traídos del SII.', json_encode($resultado, JSON_UNESCAPED_UNICODE), sii_ahora(), $id]);
    sii_clave_temporal_borrar($db, $id);
    $db->commit();
    return $resultado;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ($e instanceof SiiError && (str_contains($e->getMessage(), 'no devolvió') || str_contains($e->getMessage(), 'no coincide'))) {
            sii_actualizar_estado($db, $id, 'error', $e->getMessage());
            sii_clave_temporal_borrar($db, $id);
        }
        throw $e;
    }
}
