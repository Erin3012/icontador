<?php
declare(strict_types=1);
/* Sugerencias y reportes de problemas enviados por los usuarios, con adjuntos.
   Los adjuntos se guardan fuera de la carpeta pública (data/adjuntos o la ruta configurada) con un nombre
   aleatorio y solo se entregan a través de api/sugerencias.php?adjunto=ID. */

const SUG_ESTADOS = ['nueva', 'en_revision', 'resuelta'];
const SUG_TIPOS = ['problema', 'sugerencia'];
const SUG_MAX_COMENTARIO = 5000;
const SUG_MAX_ARCHIVOS = 5;
const SUG_MAX_BYTES = 10 * 1024 * 1024;
/* Extensión permitida => tipos MIME aceptados según el contenido real del archivo (finfo). */
const SUG_TIPOS_ARCHIVO = [
    'png' => ['image/png'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'gif' => ['image/gif'], 'webp' => ['image/webp'],
    'pdf' => ['application/pdf'], 'txt' => ['text/plain'], 'csv' => ['text/csv', 'text/plain', 'application/csv'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
    'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2', 'application/octet-stream'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
];
/* Tipo con que se entrega cada extensión; solo imágenes y PDF se muestran en el navegador. */
const SUG_ENTREGA = [
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf',
    'txt' => 'text/plain; charset=utf-8', 'csv' => 'text/csv; charset=utf-8', 'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];

final class SugerenciaError extends RuntimeException {}

function sug_schema(PDO $db): void {
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        foreach (array_filter(array_map('trim', explode(';', file_get_contents(dirname(__DIR__) . '/schema/sugerencias.mysql.sql')))) as $sql) $db->exec($sql);
        return;
    }
    $db->exec("CREATE TABLE IF NOT EXISTS sugerencias (
        id INTEGER PRIMARY KEY AUTOINCREMENT, usuario_id TEXT NOT NULL, usuario_nombre TEXT NOT NULL DEFAULT '', usuario_email TEXT NOT NULL DEFAULT '',
        tipo TEXT NOT NULL, comentario TEXT NOT NULL, pagina TEXT NOT NULL DEFAULT '', navegador TEXT NOT NULL DEFAULT '',
        estado TEXT NOT NULL DEFAULT 'nueva', nota_admin TEXT NOT NULL DEFAULT '',
        creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, actualizado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_sugerencias_estado ON sugerencias (estado)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_sugerencias_usuario ON sugerencias (usuario_id)');
    $db->exec("CREATE TABLE IF NOT EXISTS sugerencia_adjuntos (
        id INTEGER PRIMARY KEY AUTOINCREMENT, sugerencia_id INTEGER NOT NULL REFERENCES sugerencias(id) ON DELETE CASCADE,
        nombre TEXT NOT NULL, archivo TEXT NOT NULL, extension TEXT NOT NULL, bytes INTEGER NOT NULL,
        creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_sugerencia_adjuntos_sugerencia ON sugerencia_adjuntos (sugerencia_id)');
}

/** Carpeta privada de adjuntos: ICONTADOR_ADJUNTOS_DIR, 'adjuntos_dir' en api/config.php o data/adjuntos. */
function sug_dir_adjuntos(): string {
    $config = is_file(dirname(__DIR__) . '/config.php') ? require dirname(__DIR__) . '/config.php' : [];
    $dir = getenv('ICONTADOR_ADJUNTOS_DIR') ?: (is_array($config) ? ($config['adjuntos_dir'] ?? '') : '');
    if ($dir === '') $dir = dirname(__DIR__, 2) . '/data/adjuntos';
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('No se pudo crear la carpeta de adjuntos.');
    // Si la carpeta queda dentro del sitio publicado, Apache no debe servirla directamente.
    if (!is_file($dir . '/.htaccess')) file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    return $dir;
}

function sug_texto($valor, int $max): string {
    return is_string($valor) ? mb_substr(trim($valor), 0, $max) : '';
}

/** Normaliza $_FILES['adjuntos'] (uno o varios) a una lista de archivos. */
function sug_archivos_subidos(?array $campo): array {
    if (!$campo || !isset($campo['name'])) return [];
    if (!is_array($campo['name'])) return [$campo];
    $lista = [];
    foreach (array_keys($campo['name']) as $i) {
        $lista[] = ['name' => $campo['name'][$i], 'tmp_name' => $campo['tmp_name'][$i], 'error' => $campo['error'][$i], 'size' => $campo['size'][$i]];
    }
    return array_values(array_filter($lista, fn($f) => $f['error'] !== UPLOAD_ERR_NO_FILE));
}

/** Valida un archivo subido y devuelve su extensión permitida. */
function sug_validar_archivo(array $f, bool $esSubida = true): string {
    $nombre = (string)$f['name'];
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) throw new SugerenciaError("«{$nombre}» supera el tamaño permitido por el servidor.");
    if ($f['error'] !== UPLOAD_ERR_OK) throw new SugerenciaError("No se pudo recibir «{$nombre}».");
    if ($esSubida && !is_uploaded_file($f['tmp_name'])) throw new SugerenciaError("«{$nombre}» no es un archivo subido válido.");
    $bytes = filesize($f['tmp_name']);
    if ($bytes === false || $bytes === 0) throw new SugerenciaError("«{$nombre}» está vacío.");
    if ($bytes > SUG_MAX_BYTES) throw new SugerenciaError("«{$nombre}» pesa más de 10 MB.");
    $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
    if (!isset(SUG_TIPOS_ARCHIVO[$ext])) throw new SugerenciaError("«{$nombre}»: solo se aceptan imágenes (PNG, JPG, GIF, WEBP), PDF, TXT, CSV, Excel o Word.");
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
    if (!in_array($mime, SUG_TIPOS_ARCHIVO[$ext], true)) throw new SugerenciaError("«{$nombre}» no corresponde a un archivo ." . $ext . ' válido.');
    return $ext;
}

/**
 * Guarda una sugerencia con sus adjuntos y devuelve su id.
 * $usuario: ['id', 'nombre', 'email'] del usuario con sesión. $esSubida=false solo en pruebas.
 */
function sug_crear(PDO $db, array $usuario, array $datos, array $archivos, bool $esSubida = true): int {
    $tipo = in_array($datos['tipo'] ?? '', SUG_TIPOS, true) ? $datos['tipo'] : 'problema';
    $comentario = sug_texto($datos['comentario'] ?? '', SUG_MAX_COMENTARIO + 1);
    if ($comentario === '') throw new SugerenciaError('Escribe un comentario.');
    if (mb_strlen($comentario) > SUG_MAX_COMENTARIO) throw new SugerenciaError('El comentario no puede superar ' . SUG_MAX_COMENTARIO . ' caracteres.');
    if (count($archivos) > SUG_MAX_ARCHIVOS) throw new SugerenciaError('Puedes adjuntar hasta ' . SUG_MAX_ARCHIVOS . ' archivos.');
    $extensiones = array_map(fn($f) => sug_validar_archivo($f, $esSubida), $archivos);

    $dir = $archivos ? sug_dir_adjuntos() : '';
    $movidos = [];
    $db->beginTransaction();
    try {
        $st = $db->prepare("INSERT INTO sugerencias (usuario_id, usuario_nombre, usuario_email, tipo, comentario, pagina, navegador, nota_admin)
                            VALUES (?, ?, ?, ?, ?, ?, ?, '')");
        $st->execute([(string)$usuario['id'], sug_texto($usuario['nombre'] ?? '', 120), sug_texto($usuario['email'] ?? '', 190),
            $tipo, $comentario, sug_texto($datos['pagina'] ?? '', 255), sug_texto($datos['navegador'] ?? '', 255)]);
        $id = (int)$db->lastInsertId();
        $adj = $db->prepare('INSERT INTO sugerencia_adjuntos (sugerencia_id, nombre, archivo, extension, bytes) VALUES (?, ?, ?, ?, ?)');
        foreach ($archivos as $i => $f) {
            $archivo = bin2hex(random_bytes(16));
            $destino = $dir . '/' . $archivo;
            $ok = $esSubida ? move_uploaded_file($f['tmp_name'], $destino) : copy($f['tmp_name'], $destino);
            if (!$ok) throw new RuntimeException('No se pudo guardar el adjunto.');
            $movidos[] = $destino;
            chmod($destino, 0600);
            $nombre = sug_texto(basename(str_replace('\\', '/', (string)$f['name'])), 200);
            $adj->execute([$id, $nombre, $archivo, $extensiones[$i], filesize($destino)]);
        }
        $db->commit();
        return $id;
    } catch (Throwable $e) {
        $db->rollBack();
        foreach ($movidos as $m) @unlink($m);
        throw $e;
    }
}

/** Sugerencias visibles: todas para el administrador, las propias para el resto. Filtro opcional por estado. */
function sug_listar(PDO $db, array $usuario, bool $esAdmin, ?string $estado = null): array {
    $where = [];
    $params = [];
    if (!$esAdmin) { $where[] = 'usuario_id = ?'; $params[] = (string)$usuario['id']; }
    if ($estado !== null && $estado !== '') {
        if (!in_array($estado, SUG_ESTADOS, true)) throw new SugerenciaError('Estado inválido.');
        $where[] = 'estado = ?'; $params[] = $estado;
    }
    $st = $db->prepare('SELECT * FROM sugerencias' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT 500');
    $st->execute($params);
    $filas = $st->fetchAll();
    if (!$filas) return [];
    $ids = array_column($filas, 'id');
    $adj = $db->prepare('SELECT id, sugerencia_id, nombre, extension, bytes FROM sugerencia_adjuntos WHERE sugerencia_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id');
    $adj->execute($ids);
    $porSugerencia = [];
    foreach ($adj->fetchAll() as $a) $porSugerencia[(int)$a['sugerencia_id']][] = ['id' => (int)$a['id'], 'nombre' => $a['nombre'], 'extension' => $a['extension'], 'bytes' => (int)$a['bytes']];
    return array_map(function ($f) use ($porSugerencia, $esAdmin) {
        $s = ['id' => (int)$f['id'], 'tipo' => $f['tipo'], 'comentario' => $f['comentario'], 'pagina' => $f['pagina'], 'estado' => $f['estado'],
            'nota_admin' => $f['nota_admin'], 'creado_en' => $f['creado_en'], 'actualizado_en' => $f['actualizado_en'], 'adjuntos' => $porSugerencia[(int)$f['id']] ?? []];
        if ($esAdmin) $s += ['usuario_nombre' => $f['usuario_nombre'], 'usuario_email' => $f['usuario_email'], 'navegador' => $f['navegador']];
        return $s;
    }, $filas);
}

/** Cambia el estado y la nota de revisión (solo administrador). */
function sug_actualizar(PDO $db, $id, array $datos): void {
    $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) throw new SugerenciaError('Sugerencia inválida.');
    $campos = [];
    $params = [];
    if (array_key_exists('estado', $datos)) {
        if (!in_array($datos['estado'], SUG_ESTADOS, true)) throw new SugerenciaError('Estado inválido.');
        $campos[] = 'estado = ?'; $params[] = $datos['estado'];
    }
    if (array_key_exists('nota_admin', $datos)) { $campos[] = 'nota_admin = ?'; $params[] = sug_texto($datos['nota_admin'], 2000); }
    if (!$campos) throw new SugerenciaError('Nada que actualizar.');
    $params[] = $id;
    $st = $db->prepare('UPDATE sugerencias SET ' . implode(', ', $campos) . ', actualizado_en = CURRENT_TIMESTAMP WHERE id = ?');
    $st->execute($params);
    if ($st->rowCount() === 0 && !sug_existe($db, $id)) throw new SugerenciaError('La sugerencia no existe.');
}

function sug_existe(PDO $db, int $id): bool {
    $st = $db->prepare('SELECT 1 FROM sugerencias WHERE id = ?');
    $st->execute([$id]);
    return (bool)$st->fetchColumn();
}

/** Adjunto con su ruta en disco, si el usuario puede verlo (dueño de la sugerencia o administrador). */
function sug_adjunto(PDO $db, $id, array $usuario, bool $esAdmin): ?array {
    $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) return null;
    $st = $db->prepare('SELECT a.nombre, a.archivo, a.extension, a.bytes, s.usuario_id FROM sugerencia_adjuntos a JOIN sugerencias s ON s.id = a.sugerencia_id WHERE a.id = ?');
    $st->execute([$id]);
    $a = $st->fetch();
    if (!$a || (!$esAdmin && (string)$a['usuario_id'] !== (string)$usuario['id'])) return null;
    if (!preg_match('/^[0-9a-f]{32}$/', $a['archivo'])) return null;
    $ruta = sug_dir_adjuntos() . '/' . $a['archivo'];
    return is_file($ruta) ? ['nombre' => $a['nombre'], 'extension' => $a['extension'], 'bytes' => (int)$a['bytes'], 'ruta' => $ruta] : null;
}
