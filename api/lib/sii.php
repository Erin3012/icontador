<?php
declare(strict_types=1);
/* Sincronización del RCV con el SII a través del agente local (sii-agent/, Playwright en el PC del usuario).
   El servidor encola tareas (empresa, período, libro), entrega al agente emparejado solo las tareas de su dueño,
   y recibe el RCV ya leído para guardarlo con rcv_guardar(). La clave tributaria no se guarda ni se envía al servidor. */
require_once __DIR__ . '/rcv.php';

const SII_LIBROS_SYNC = ['compras', 'ventas'];
const SII_RECLAMO_MINUTOS = 20; // si el agente no devuelve una tarea en este tiempo, vuelve a quedar pendiente.
const SII_MAX_MENSAJE = 500;
const SII_CLAVE_MAX = 200;

final class SiiError extends RuntimeException {}

function sii_schema(PDO $db): void {
    $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    if ($mysql) {
        $db->exec(file_get_contents(dirname(__DIR__) . '/schema/sii.mysql.sql'));
        return;
    }
    $db->exec("CREATE TABLE IF NOT EXISTS sii_tareas (
        id INTEGER PRIMARY KEY AUTOINCREMENT, empresa_id INTEGER NOT NULL DEFAULT 0, tipo TEXT NOT NULL DEFAULT 'rcv',
        periodo TEXT NOT NULL DEFAULT '', libro TEXT NOT NULL DEFAULT '', rut TEXT NOT NULL DEFAULT '', estado TEXT NOT NULL DEFAULT 'pendiente',
        mensaje TEXT NOT NULL DEFAULT '', resultado_json TEXT NULL, creado_por INTEGER NOT NULL DEFAULT 0,
        creado_en TEXT NOT NULL DEFAULT '', actualizado_en TEXT NOT NULL DEFAULT '', reclamado_en TEXT NULL)");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_sii_empresa_estado ON sii_tareas (empresa_id, estado)');
    $db->exec("CREATE TABLE IF NOT EXISTS sii_claves (
        empresa_id INTEGER PRIMARY KEY, clave_cifrada TEXT NOT NULL, actualizado_en TEXT NOT NULL DEFAULT '')");
    $db->exec("CREATE TABLE IF NOT EXISTS sii_agentes (
        id INTEGER PRIMARY KEY AUTOINCREMENT, usuario_id INTEGER NOT NULL, nombre TEXT NOT NULL DEFAULT '',
        token_hash TEXT NOT NULL, creado_en TEXT NOT NULL DEFAULT '', ultimo_uso TEXT NULL, revocado_en TEXT NULL)");
    $db->exec("CREATE TABLE IF NOT EXISTS sii_codigos_emparejamiento (
        codigo_hash TEXT PRIMARY KEY, usuario_id INTEGER NOT NULL, creado_en TEXT NOT NULL DEFAULT '',
        expira_en TEXT NOT NULL DEFAULT '', usado_en TEXT NULL)");
}

function sii_ahora(): string {
    return date('Y-m-d H:i:s');
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
    if (sii_rut_empresa($db, $empresa) === '') throw new SiiError('La empresa no tiene RUT. Complételo en Empresas antes de sincronizar.');
    $creadas = [];
    $yaEnCola = [];
    foreach ($libros as $libro) {
        $st = $db->prepare("SELECT id FROM sii_tareas WHERE empresa_id = ? AND periodo = ? AND libro = ? AND estado IN ('pendiente', 'en_proceso')");
        $st->execute([$empresa, $periodo, $libro]);
        if ($st->fetchColumn()) {
            $yaEnCola[] = $libro;
            continue;
        }
        $ahora = sii_ahora();
        $db->prepare("INSERT INTO sii_tareas (empresa_id, periodo, libro, estado, mensaje, creado_por, creado_en, actualizado_en) VALUES (?, ?, ?, 'pendiente', '', ?, ?, ?)")
            ->execute([$empresa, $periodo, $libro, $usuario, $ahora, $ahora]);
        $creadas[] = (int) $db->lastInsertId();
    }
    return ['creadas' => $creadas, 'ya_en_cola' => $yaEnCola];
}

/** Tareas recientes de una empresa, para mostrar su estado en la pantalla RCV. */
function sii_listar(PDO $db, int $empresa): array {
    $st = $db->prepare("SELECT id, empresa_id, periodo, libro, estado, mensaje, creado_en, actualizado_en FROM sii_tareas WHERE empresa_id = ? AND tipo = 'rcv' ORDER BY id DESC LIMIT 30");
    $st->execute([$empresa]);
    return $st->fetchAll();
}

/** Entrega hasta $maximo tareas pendientes al agente y las marca 'en_proceso'. Las que quedaron reservadas hace mucho vuelven a pendiente. */
function sii_reclamar(PDO $db, int $maximo = 3, ?int $usuarioAgente = null): array {
    $ahora = sii_ahora();
    $limite = date('Y-m-d H:i:s', time() - SII_RECLAMO_MINUTOS * 60);
    if ($usuarioAgente !== null) {
        $db->prepare("UPDATE sii_tareas SET estado = 'pendiente', actualizado_en = ? WHERE creado_por = ? AND estado = 'en_proceso' AND reclamado_en < ?")
            ->execute([$ahora, $usuarioAgente, $limite]);
        $st = $db->prepare("SELECT id FROM sii_tareas WHERE creado_por = ? AND estado = 'pendiente' ORDER BY id");
        $st->execute([$usuarioAgente]);
        $candidatas = $st->fetchAll(PDO::FETCH_COLUMN);
    } else {
        // Compatibilidad para llamadas internas antiguas. El endpoint siempre entrega el dueño del agente.
        $db->prepare("UPDATE sii_tareas SET estado = 'pendiente', actualizado_en = ? WHERE estado = 'en_proceso' AND reclamado_en < ?")->execute([$ahora, $limite]);
        $candidatas = $db->query("SELECT id FROM sii_tareas WHERE estado = 'pendiente' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    }
    $reclamadas = [];
    foreach (array_slice($candidatas, 0, $maximo) as $id) {
        $st = $db->prepare("UPDATE sii_tareas SET estado = 'en_proceso', reclamado_en = ?, actualizado_en = ? WHERE id = ? AND estado = 'pendiente'");
        $st->execute([$ahora, $ahora, $id]);
        if ($st->rowCount() !== 1) continue;
        $tarea = sii_tarea($db, (int) $id);
        $reclamadas[] = [
            'id' => (int) $tarea['id'],
            'tipo' => $tarea['tipo'],
            'periodo' => $tarea['periodo'],
            'libro' => $tarea['libro'],
            'rut' => $tarea['tipo'] === 'datos_empresa' ? $tarea['rut'] : sii_rut_empresa($db, (int) $tarea['empresa_id']),
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
    $tarea = sii_tarea($db, $id);
    if (!$tarea || $tarea['estado'] !== 'en_proceso' || $tarea['tipo'] !== 'rcv') throw new SiiError('La tarea no está en proceso.');
    if ($usuarioAgente !== null && (int) $tarea['creado_por'] !== $usuarioAgente) throw new SiiError('La tarea no pertenece a este agente.');
    try {
        if (($datos['period'] ?? null) !== $tarea['periodo']) {
            throw new SiiError('El archivo es del período ' . ($datos['period'] ?? 'desconocido') . ' y la tarea pide ' . $tarea['periodo'] . '.');
        }
        if (($datos['kind'] ?? null) !== $tarea['libro']) {
            throw new SiiError('El archivo es de ' . ($datos['kind'] ?? 'otro libro') . ' y la tarea pide ' . $tarea['libro'] . '.');
        }
        $guardados = rcv_guardar($db, [
            'kind' => $tarea['libro'],
            'period' => $tarea['periodo'],
            'fileName' => is_string($datos['fileName'] ?? null) ? $datos['fileName'] : '',
            'rutEmpresa' => $datos['rutEmpresa'] ?? '',
            'docs' => $datos['docs'] ?? null,
        ], (int) $tarea['empresa_id']);
    } catch (Throwable $e) {
        sii_actualizar_estado($db, $id, 'error', $e->getMessage());
        throw $e;
    }
    sii_actualizar_estado($db, $id, 'completada', $guardados . ' documentos guardados desde el SII.');
    return $guardados;
}

/** El agente informa un fallo (clave incorrecta, SII caído, selector que cambió...). */
function sii_fallar(PDO $db, int $id, string $mensaje, ?int $usuarioAgente = null): void {
    $tarea = sii_tarea($db, $id);
    if (!$tarea || !in_array($tarea['estado'], ['pendiente', 'en_proceso'], true)) throw new SiiError('La tarea ya no está pendiente.');
    if ($usuarioAgente !== null && (int) $tarea['creado_por'] !== $usuarioAgente) throw new SiiError('La tarea no pertenece a este agente.');
    sii_actualizar_estado($db, $id, 'error', $mensaje);
}

/* ---------- Token del agente ---------- */

function sii_token_configurado(): string {
    $env = getenv('ICONTADOR_SII_TOKEN');
    if (is_string($env) && $env !== '') return $env;
    $config = is_file(dirname(__DIR__) . '/config.php') ? require dirname(__DIR__) . '/config.php' : [];
    return is_array($config) ? (string) ($config['sii_agente_token'] ?? '') : '';
}

function sii_token_valido(string $recibido): bool {
    $esperado = sii_token_configurado();
    return $esperado !== '' && $recibido !== '' && hash_equals($esperado, $recibido);
}

/** Dueño opcional de un token compartido legado. No se aceptan tokens compartidos sin alcance explícito. */
function sii_token_usuario_configurado(): int {
    $env = getenv('ICONTADOR_SII_AGENT_USER_ID');
    if (is_string($env) && ctype_digit($env) && (int) $env > 0) return (int) $env;
    $config = is_file(dirname(__DIR__) . '/config.php') ? require dirname(__DIR__) . '/config.php' : [];
    $usuario = is_array($config) ? ($config['sii_agente_usuario_id'] ?? 0) : 0;
    return filter_var($usuario, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
}

/* ---------- Clave tributaria guardada cifrada por empresa ---------- */

/** Llave AES-256 (32 bytes, en base64) desde ICONTADOR_SII_CLAVE_CIFRADO o sii_clave_cifrado de api/config.php. */
function sii_clave_cifrado_key(): string {
    $env = getenv('ICONTADOR_SII_CLAVE_CIFRADO');
    $valor = is_string($env) ? $env : '';
    if ($valor === '') {
        $config = is_file(dirname(__DIR__) . '/config.php') ? require dirname(__DIR__) . '/config.php' : [];
        $valor = is_array($config) ? (string) ($config['sii_clave_cifrado'] ?? '') : '';
    }
    $llave = $valor === '' ? false : base64_decode($valor, true);
    if ($llave === false || strlen($llave) !== 32) {
        throw new SiiError('El servidor no tiene configurada la llave para guardar la clave del SII (sii_clave_cifrado: 32 bytes en base64).');
    }
    return $llave;
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
    $db->prepare('DELETE FROM sii_claves WHERE empresa_id = ?')->execute([$empresa]);
    if ($sobre !== null) {
        $db->prepare('INSERT INTO sii_claves (empresa_id, clave_cifrada, actualizado_en) VALUES (?, ?, ?)')
            ->execute([$empresa, $sobre, sii_ahora()]);
    }
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

/** Acepta el token compartido de api/config.php (instalaciones anteriores) o el token propio de un agente emparejado. */
function sii_autorizar_agente(PDO $db): int {
    $recibido = (string) ($_SERVER['HTTP_X_AGENTE_TOKEN'] ?? '');
    if ($recibido === '' && preg_match('/^Bearer\s+(.+)$/i', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $m)) $recibido = $m[1];
    if ($recibido !== '') {
        $usuario = sii_agente_por_token($db, $recibido);
        if ($usuario !== null) return $usuario;
        if (sii_token_valido($recibido)) {
            $usuario = sii_token_usuario_configurado();
            if ($usuario > 0) return $usuario;
            auth_json(403, ['error' => 'El token compartido no tiene un usuario asignado. Empareje este PC desde Cifrax.']);
        }
    }
    if (sii_token_configurado() === '' && sii_agentes_activos($db) === 0) {
        auth_json(503, ['error' => 'La sincronización con el SII no está configurada en el servidor (falta emparejar un agente).']);
    }
    auth_json(401, ['error' => 'Token del agente inválido.']);
}

/* ---------- Emparejamiento de agentes: código de un solo uso -> token propio por PC ---------- */

const SII_CODIGO_MINUTOS = 10;
// Sin 0, O, 1 ni I para que se pueda dictar o copiar sin confusiones.
const SII_CODIGO_ALFABETO = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

function sii_codigo_normalizar(string $codigo): string {
    return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $codigo));
}

/** Genera un código para que el usuario lo escriba en el instalador del PC. Solo se guarda su hash. */
function sii_codigo_crear(PDO $db, int $usuario): array {
    $codigo = '';
    for ($i = 0; $i < 10; $i++) $codigo .= SII_CODIGO_ALFABETO[random_int(0, strlen(SII_CODIGO_ALFABETO) - 1)];
    $expira = date('Y-m-d H:i:s', time() + SII_CODIGO_MINUTOS * 60);
    // Solo un código vigente por usuario: los anteriores sin usar quedan invalidados.
    $db->prepare('DELETE FROM sii_codigos_emparejamiento WHERE usuario_id = ? AND usado_en IS NULL')->execute([$usuario]);
    $db->prepare('INSERT INTO sii_codigos_emparejamiento (codigo_hash, usuario_id, creado_en, expira_en) VALUES (?, ?, ?, ?)')
        ->execute([hash('sha256', $codigo), $usuario, sii_ahora(), $expira]);
    return ['codigo' => substr($codigo, 0, 5) . '-' . substr($codigo, 5), 'expira_en' => $expira];
}

/** Canjea un código por un token propio del agente. El código queda usado y no se puede volver a canjear. */
function sii_codigo_canjear(PDO $db, string $codigo, string $nombre): string {
    $normal = sii_codigo_normalizar($codigo);
    if (strlen($normal) !== 10) throw new SiiError('Código inválido o vencido.');
    $hash = hash('sha256', $normal);
    $st = $db->prepare('SELECT usuario_id, expira_en FROM sii_codigos_emparejamiento WHERE codigo_hash = ? AND usado_en IS NULL');
    $st->execute([$hash]);
    $fila = $st->fetch();
    if (!$fila || (string) $fila['expira_en'] < sii_ahora()) throw new SiiError('Código inválido o vencido.');
    // Se marca usado solo si nadie lo canjeó antes (dos canjes a la vez no pasan los dos).
    $marcar = $db->prepare('UPDATE sii_codigos_emparejamiento SET usado_en = ? WHERE codigo_hash = ? AND usado_en IS NULL');
    $marcar->execute([sii_ahora(), $hash]);
    if ($marcar->rowCount() !== 1) throw new SiiError('Código inválido o vencido.');
    $token = bin2hex(random_bytes(24));
    $db->prepare('INSERT INTO sii_agentes (usuario_id, nombre, token_hash, creado_en) VALUES (?, ?, ?, ?)')
        ->execute([(int) $fila['usuario_id'], mb_substr(trim($nombre), 0, 80), hash('sha256', $token), sii_ahora()]);
    return $token;
}

/** Si el token pertenece a un agente activo, anota su último uso y devuelve true. */
function sii_agente_por_token(PDO $db, string $token): ?int {
    $st = $db->prepare('SELECT id, usuario_id FROM sii_agentes WHERE token_hash = ? AND revocado_en IS NULL');
    $st->execute([hash('sha256', $token)]);
    $agente = $st->fetch();
    if (!$agente) return null;
    $db->prepare('UPDATE sii_agentes SET ultimo_uso = ? WHERE id = ?')->execute([sii_ahora(), (int) $agente['id']]);
    return (int) $agente['usuario_id'];
}

function sii_agentes_activos(PDO $db): int {
    return (int) $db->query('SELECT COUNT(*) FROM sii_agentes WHERE revocado_en IS NULL')->fetchColumn();
}

/** Agentes emparejados por un usuario (sin el token: nunca se guarda en claro). */
function sii_agentes_listar(PDO $db, int $usuario): array {
    $st = $db->prepare('SELECT id, nombre, creado_en, ultimo_uso, revocado_en FROM sii_agentes WHERE usuario_id = ? ORDER BY id DESC LIMIT 20');
    $st->execute([$usuario]);
    return $st->fetchAll();
}

/** Revoca un agente de ese usuario. Los demás PCs siguen funcionando. */
function sii_agente_revocar(PDO $db, int $usuario, int $id): void {
    $st = $db->prepare('UPDATE sii_agentes SET revocado_en = ? WHERE id = ? AND usuario_id = ? AND revocado_en IS NULL');
    $st->execute([sii_ahora(), $id, $usuario]);
    if ($st->rowCount() !== 1) throw new SiiError('Agente no encontrado o ya revocado.');
}

/* ---------- Datos de una empresa nueva, traídos del SII con la clave que tiene el agente ---------- */

/** Pide al agente los datos (razón social y giro) del contribuyente con ese RUT. Sin empresa todavía. */
function sii_crear_consulta_empresa(PDO $db, int $usuario, $rut): array {
    $limpio = rut_limpio($rut);
    if (strlen($limpio) < 2) throw new SiiError('Ingresa el RUT de la empresa para traer sus datos.');
    $rut = mb_substr(trim((string) $rut), 0, 12);
    $st = $db->prepare("SELECT id FROM sii_tareas WHERE tipo = 'datos_empresa' AND creado_por = ? AND rut = ? AND estado IN ('pendiente', 'en_proceso')");
    $st->execute([$usuario, $rut]);
    if ($id = $st->fetchColumn()) return ['id' => (int) $id];
    $ahora = sii_ahora();
    $db->prepare("INSERT INTO sii_tareas (empresa_id, tipo, periodo, libro, rut, estado, mensaje, creado_por, creado_en, actualizado_en) VALUES (0, 'datos_empresa', '', '', ?, 'pendiente', '', ?, ?, ?)")
        ->execute([$rut, $usuario, $ahora, $ahora]);
    return ['id' => (int) $db->lastInsertId()];
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

/** El agente devuelve razón social y giro del contribuyente. */
function sii_resultado_empresa(PDO $db, int $id, array $datos, ?int $usuarioAgente = null): array {
    $tarea = sii_tarea($db, $id);
    if (!$tarea || $tarea['estado'] !== 'en_proceso' || $tarea['tipo'] !== 'datos_empresa') throw new SiiError('La consulta no está en proceso.');
    if ($usuarioAgente !== null && (int) $tarea['creado_por'] !== $usuarioAgente) throw new SiiError('La consulta no pertenece a este agente.');
    $razon = trim((string) ($datos['razon_social'] ?? ''));
    if ($razon === '') {
        sii_actualizar_estado($db, $id, 'error', 'El SII no devolvió la razón social.');
        throw new SiiError('El SII no devolvió la razón social.');
    }
    $resultado = ['razon_social' => mb_substr($razon, 0, 191), 'giro' => mb_substr(trim((string) ($datos['giro'] ?? '')), 0, 120)];
    $db->prepare("UPDATE sii_tareas SET estado = 'completada', mensaje = ?, resultado_json = ?, actualizado_en = ? WHERE id = ?")
        ->execute(['Datos traídos del SII.', json_encode($resultado, JSON_UNESCAPED_UNICODE), sii_ahora(), $id]);
    return $resultado;
}
