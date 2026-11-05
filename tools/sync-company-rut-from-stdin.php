<?php
declare(strict_types=1);

// Uso por SSH, fuera de public_html. Lee {"razon_social":"...","rut":"..."} de STDIN.
if (PHP_SAPI !== 'cli') exit(2);
$id = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$apply = ($argv[2] ?? '') === '--apply';
if ($id === false || count($argv) !== ($apply ? 3 : 2)) {
    fwrite(STDERR, "Uso: php sync-company-rut-from-stdin.php ID [--apply] < datos.json\n");
    exit(2);
}
$input = stream_get_contents(STDIN, 4096);
$payload = json_decode($input === false ? '' : $input, true);
if (!is_array($payload) || !is_string($payload['razon_social'] ?? null) || !is_string($payload['rut'] ?? null)) {
    fwrite(STDERR, "Entrada inválida.\n");
    exit(2);
}

function rut_normalizado(string $valor): ?string {
    $rut = strtoupper((string) preg_replace('/[.\s]/', '', $valor));
    if (!preg_match('/^(\d{1,8})-?([\dK])$/', $rut, $m)) return null;
    $factor = 2;
    $suma = 0;
    for ($i = strlen($m[1]) - 1; $i >= 0; --$i) {
        $suma += (int) $m[1][$i] * $factor;
        $factor = $factor === 7 ? 2 : $factor + 1;
    }
    $dv = 11 - $suma % 11;
    if (($dv === 11 ? '0' : ($dv === 10 ? 'K' : (string) $dv)) !== $m[2]) return null;
    return ltrim($m[1], '0') . '-' . $m[2];
}

$rut = rut_normalizado($payload['rut']);
if ($rut === null) {
    fwrite(STDERR, "El RUT del archivo no supera la validación del dígito verificador.\n");
    exit(2);
}
$appRoot = getenv('ICONTADOR_APP_ROOT') ?: '/home/qlccl/public_html';
$dbFile = rtrim($appRoot, '/\\') . '/api/db.php';
if (!is_file($dbFile)) exit(1);
require_once $dbFile;

try {
    $db = icontador_db();
    $db->beginTransaction();
    $st = $db->prepare('SELECT razon_social, datos_json FROM empresas WHERE id = ?' . ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || trim((string) $row['razon_social']) !== trim($payload['razon_social'])) throw new RuntimeException('La razón social no coincide exactamente con la empresa indicada.');
    $datos = json_decode((string) $row['datos_json'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($datos)) throw new RuntimeException('La ficha de empresa no es válida.');
    $actual = trim(is_scalar($datos['rut'] ?? null) ? (string) $datos['rut'] : '');
    if ($actual !== '' && rut_normalizado($actual) !== $rut) throw new RuntimeException('La ficha ya tiene un RUT diferente; no se sobrescribió.');
    $otros = $db->prepare('SELECT id, datos_json FROM empresas WHERE id <> ?');
    $otros->execute([$id]);
    foreach ($otros as $otro) {
        $datosOtro = json_decode((string) $otro['datos_json'], true);
        if (is_array($datosOtro) && rut_normalizado((string) ($datosOtro['rut'] ?? '')) === $rut) throw new RuntimeException('El RUT ya está asociado a otra empresa.');
    }
    if ($actual === '') {
        if ($apply) {
            $datos['rut'] = $rut;
            $upd = $db->prepare('UPDATE empresas SET datos_json = ?, actualizado = ? WHERE id = ?');
            $upd->execute([json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), gmdate('c'), $id]);
            echo "RUT validado y agregado a la ficha.\n";
        } else {
            echo "RUT válido y razón social coincidente; listo para aplicar.\n";
        }
    } else {
        echo "La ficha ya contiene el mismo RUT válido.\n";
    }
    if ($apply) $db->commit(); else $db->rollBack();
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    fwrite(STDERR, $e instanceof PDOException ? "No se pudo consultar o actualizar la base.\n" : $e->getMessage() . "\n");
    exit(1);
}
