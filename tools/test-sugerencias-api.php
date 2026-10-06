<?php
// Prueba de sugerencias con SQLite en memoria y una carpeta temporal de adjuntos: php tools/test-sugerencias-api.php
declare(strict_types=1);
$dir = sys_get_temp_dir() . '/icontador-sug-' . bin2hex(random_bytes(4));
putenv('ICONTADOR_ADJUNTOS_DIR=' . $dir);
require dirname(__DIR__) . '/api/lib/sugerencias.php';
function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FALLA: $msg\n"); exit(1); } }
function rechaza(callable $f): bool { try { $f(); return false; } catch (SugerenciaError) { return true; } }
function archivo(string $nombre, string $contenido): array {
    $tmp = tempnam(sys_get_temp_dir(), 'sug');
    file_put_contents($tmp, $contenido);
    return ['name' => $nombre, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($contenido)];
}

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
sug_schema($db);
sug_schema($db); // idempotente
$ana = ['id' => 2, 'nombre' => 'Ana', 'email' => 'ana@ejemplo.cl'];
$luis = ['id' => 3, 'nombre' => 'Luis', 'email' => 'luis@ejemplo.cl'];
$mary = ['id' => 1, 'nombre' => 'Mary', 'email' => 'mary@ejemplo.cl'];
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

$id = sug_crear($db, $ana, ['tipo' => 'problema', 'comentario' => "  No cuadra el voucher \n", 'pagina' => 'voucher-crear'],
    [archivo('captura.png', $png), archivo('detalle.csv', "a,b\n1,2\n")], false);
check($id === 1, 'crear');
sug_crear($db, $luis, ['tipo' => 'sugerencia', 'comentario' => 'Agregar filtro por mes'], [], false);

$deAna = sug_listar($db, $ana, false);
check(count($deAna) === 1 && $deAna[0]['comentario'] === 'No cuadra el voucher' && $deAna[0]['estado'] === 'nueva' && $deAna[0]['pagina'] === 'voucher-crear', 'un usuario ve solo lo suyo');
check(count($deAna[0]['adjuntos']) === 2 && $deAna[0]['adjuntos'][0]['nombre'] === 'captura.png' && !isset($deAna[0]['usuario_email']), 'adjuntos listados sin datos de otros');
check(count(sug_listar($db, $mary, true)) === 2 && sug_listar($db, $mary, true)[0]['usuario_nombre'] === 'Luis', 'el administrador ve todo');

$adj = $deAna[0]['adjuntos'][0]['id'];
$a = sug_adjunto($db, $adj, $ana, false);
check($a !== null && file_get_contents($a['ruta']) === $png && dirname($a['ruta']) === $dir, 'el dueño descarga su adjunto desde la carpeta privada');
check(!str_contains(basename($a['ruta']), 'captura'), 'el archivo en disco tiene nombre aleatorio');
check(sug_adjunto($db, $adj, $luis, false) === null, 'otro usuario no puede descargarlo');
check(sug_adjunto($db, $adj, $mary, true) !== null, 'el administrador sí puede');
check(is_file($dir . '/.htaccess'), 'la carpeta de adjuntos bloquea el acceso directo');

check(rechaza(fn() => sug_crear($db, $ana, ['comentario' => '  '], [], false)), 'exige comentario');
check(rechaza(fn() => sug_crear($db, $ana, ['comentario' => str_repeat('x', 5001)], [], false)), 'limita el largo');
check(rechaza(fn() => sug_crear($db, $ana, ['comentario' => 'x'], [archivo('virus.php', '<?php echo 1;')], false)), 'rechaza .php');
check(rechaza(fn() => sug_crear($db, $ana, ['comentario' => 'x'], [archivo('falsa.png', '<?php echo 1;')], false)), 'rechaza un .png que no es imagen');
check(rechaza(fn() => sug_crear($db, $ana, ['comentario' => 'x'], array_fill(0, 6, archivo('a.txt', 'hola')), false)), 'máximo 5 archivos');
$grande = archivo('grande.txt', '');
$h = fopen($grande['tmp_name'], 'w'); ftruncate($h, SUG_MAX_BYTES + 1); fclose($h);
check(rechaza(fn() => sug_crear($db, $ana, ['comentario' => 'x'], [$grande], false)), 'máximo 10 MB');
check(rechaza(fn() => sug_crear($db, $ana, ['comentario' => 'x'], [['name' => 'a.png', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0]], false)), 'avisa del límite del servidor');
check(count(sug_listar($db, $mary, true)) === 2 && count(glob($dir . '/*')) === 2, 'un rechazo no guarda nada');

sug_actualizar($db, $id, ['estado' => 'en_revision', 'nota_admin' => 'Lo reviso hoy']);
check(sug_listar($db, $ana, false)[0]['estado'] === 'en_revision' && sug_listar($db, $ana, false)[0]['nota_admin'] === 'Lo reviso hoy', 'cambiar estado y nota');
check(count(sug_listar($db, $mary, true, 'en_revision')) === 1 && count(sug_listar($db, $mary, true, 'nueva')) === 1, 'filtrar por estado');
check(rechaza(fn() => sug_actualizar($db, $id, ['estado' => 'borrada'])), 'estado inválido');
check(rechaza(fn() => sug_actualizar($db, 99, ['estado' => 'resuelta'])), 'sugerencia inexistente');
check(rechaza(fn() => sug_listar($db, $mary, true, 'x')), 'filtro inválido');

$multi = sug_archivos_subidos(['name' => ['a.png', ''], 'tmp_name' => ['/tmp/a', ''], 'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE], 'size' => [1, 0]]);
check(count($multi) === 1 && $multi[0]['name'] === 'a.png', 'normaliza adjuntos[]');

array_map('unlink', glob($dir . '/{,.}[!.]*', GLOB_BRACE));
rmdir($dir);
echo "Sugerencias PHP: crear, adjuntos privados, permisos, validación y estados verificados\n";
