<?php
declare(strict_types=1);

/**
 * Comprobación de solo lectura previa a una descarga RCV autorizada.
 * Instalar fuera de public_html y ejecutar exclusivamente por SSH.
 * No recibe claves, no consulta al SII y no modifica la base de datos.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function uso(): never
{
    fwrite(STDERR, "Uso: php rcv-sii-preflight.php --empresa=ID|--buscar=TEXTO --periodo=AAAA-MM\n");
    exit(2);
}

function rut_valido(string $rut): bool
{
    $limpio = strtoupper((string) preg_replace('/[.\s]/', '', $rut));
    if (!preg_match('/^(\d{1,8})-?([\dK])$/', $limpio, $m)) return false;
    $suma = 0;
    $factor = 2;
    for ($i = strlen($m[1]) - 1; $i >= 0; $i--) {
        $suma += (int) $m[1][$i] * $factor;
        $factor = $factor === 7 ? 2 : $factor + 1;
    }
    $dv = 11 - $suma % 11;
    return ($dv === 11 ? '0' : ($dv === 10 ? 'K' : (string) $dv)) === $m[2];
}

$opciones = [];
foreach (array_slice($argv, 1) as $arg) {
    if (!preg_match('/^--(empresa|buscar|periodo)=(.+)$/u', $arg, $m) || isset($opciones[$m[1]])) uso();
    $opciones[$m[1]] = $m[2];
}
if (!isset($opciones['periodo']) || (isset($opciones['empresa']) === isset($opciones['buscar']))) uso();
$periodo = $opciones['periodo'];
if (!preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', $periodo, $m)) {
    fwrite(STDERR, "Período inválido; use AAAA-MM.\n");
    exit(2);
}
$inicioMesActual = new DateTimeImmutable('first day of this month', new DateTimeZone('America/Santiago'));
if ($periodo >= $inicioMesActual->format('Y-m')) {
    fwrite(STDERR, "El período debe ser un mes cerrado.\n");
    exit(2);
}

$appRoot = getenv('ICONTADOR_APP_ROOT') ?: '/home/qlccl/public_html';
$dbFile = rtrim($appRoot, '/\\') . '/api/db.php';
if (!is_file($dbFile)) {
    fwrite(STDERR, "No se encontró la conexión de la aplicación.\n");
    exit(1);
}
require_once $dbFile;

try {
    $db = icontador_db();
    if (isset($opciones['empresa'])) {
        $id = filter_var($opciones['empresa'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) throw new RuntimeException('ID de empresa inválido.');
        $st = $db->prepare('SELECT id, razon_social, datos_json FROM empresas WHERE id = ?');
        $st->execute([$id]);
    } else {
        $buscar = trim($opciones['buscar']);
        if (mb_strlen($buscar) < 3 || mb_strlen($buscar) > 80 || preg_match('/[%_\\\\]/u', $buscar)) throw new RuntimeException('Texto de búsqueda inválido.');
        $st = $db->prepare('SELECT id, razon_social, datos_json FROM empresas WHERE razon_social LIKE ? LIMIT 2');
        $st->execute(['%' . $buscar . '%']);
    }
    $empresas = $st->fetchAll(PDO::FETCH_ASSOC);
    if (count($empresas) !== 1) throw new RuntimeException(count($empresas) ? 'La búsqueda coincide con varias empresas; use el ID.' : 'No se encontró la empresa.');
    $empresa = $empresas[0];
    $datos = json_decode((string) $empresa['datos_json'], true);
    $rut = is_array($datos) && is_string($datos['rut'] ?? null) ? $datos['rut'] : '';
    $estado = trim($rut) === '' ? 'faltante' : (rut_valido($rut) ? 'válido' : 'inválido');
    echo 'Empresa ID: ' . (int) $empresa['id'] . PHP_EOL;
    echo 'Razón social: ' . $empresa['razon_social'] . PHP_EOL;
    echo 'RUT en ficha: ' . $estado . PHP_EOL;
    echo 'Período cerrado: ' . $periodo . PHP_EOL;
    echo "Descarga SII: no ejecutada. Falta confirmar autorización y documentación del canal.\n";
    if ($estado !== 'válido') exit(3);
} catch (Throwable $e) {
    // No imprimir mensajes de PDO: pueden contener detalles de la conexión.
    $mensaje = $e instanceof PDOException ? 'No se pudo consultar la base de datos.' : $e->getMessage();
    fwrite(STDERR, $mensaje . PHP_EOL);
    exit(1);
}
