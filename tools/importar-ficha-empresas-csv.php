<?php
declare(strict_types=1);

require dirname(__DIR__) . '/api/lib/empresa-paquete.php';

$archivo = $argv[1] ?? '';
$aplicar = in_array('--apply', $argv, true);
if ($archivo === '' || !is_file($archivo)) {
    fwrite(STDERR, "Uso: php importar-ficha-empresas-csv.php archivo.csv [--apply]\n");
    exit(2);
}

function texto_utf8(string $contenido): string
{
    if (preg_match('//u', $contenido)) {
        return preg_replace('/^\xEF\xBB\xBF/', '', $contenido) ?? $contenido;
    }
    return mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
}

function clave(string $texto): string
{
    $texto = mb_strtolower(trim($texto), 'UTF-8');
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto) ?: $texto;
    return preg_replace('/[^a-z0-9]+/', '_', trim($ascii)) ?? '';
}

function valor(array $fila, array $nombres): string
{
    foreach ($nombres as $nombre) {
        if (array_key_exists($nombre, $fila)) {
            return trim((string) $fila[$nombre]);
        }
    }
    return '';
}

$contenido = texto_utf8((string) file_get_contents($archivo));
$flujo = fopen('php://temp', 'w+');
fwrite($flujo, $contenido);
rewind($flujo);

// El primer registro es el título del informe; el segundo contiene los encabezados.
fgetcsv($flujo, 0, ';', '"', '\\');
$encabezadosOriginales = fgetcsv($flujo, 0, ';', '"', '\\');
if (!$encabezadosOriginales) {
    throw new RuntimeException('El CSV no contiene encabezados.');
}
$encabezados = array_map(fn($v) => clave((string) $v), $encabezadosOriginales);

$filas = [];
$invalidos = [];
$leidas = 0;
while (($valores = fgetcsv($flujo, 0, ';', '"', '\\')) !== false) {
    if (count(array_filter($valores, fn($v) => trim((string) $v) !== '')) === 0) {
        continue;
    }
    $leidas++;
    $valores = array_pad($valores, count($encabezados), '');
    $fila = array_combine($encabezados, array_slice($valores, 0, count($encabezados)));
    $rutOriginal = valor($fila, ['rut']);
    $rut = normalizarRut($rutOriginal);
    $razon = valor($fila, ['razon_social']);
    if ($rut === null || $razon === '') {
        $invalidos[] = ['fila' => $leidas + 2, 'motivo' => $rut === null ? 'RUT inválido' : 'Razón social vacía'];
        continue;
    }
    $estado = valor($fila, ['estado']);
    $datos = [
        'razon_social' => $razon,
        'rut' => $rut,
        'tipo_contribuyente' => valor($fila, ['tipo_contribuyente']),
        'comuna' => valor($fila, ['comuna']),
        'direccion' => valor($fila, ['direccion']),
        'ciudad' => valor($fila, ['ciudad']),
        'telefono_movil' => valor($fila, ['telefono_movil']),
        'telefono_fijo' => valor($fila, ['telefono_fijo']),
        'telefono' => valor($fila, ['telefono_movil']) ?: valor($fila, ['telefono_fijo']),
        'email' => valor($fila, ['e_mail', 'email']),
        'tributacion' => valor($fila, ['tributacion']),
        'transa_en_bolsa' => valor($fila, ['transa_en_la_bolsa']),
        'regimen' => valor($fila, ['regimen_tributario_actual']),
        'codigo_actividad_economica' => valor($fila, ['codigo_actividad_economica']),
        'giro' => valor($fila, ['giro_o_actividad']),
        'rut_representante' => valor($fila, ['rut_representante']),
        'nombre_representante' => valor($fila, ['nombre_representante']),
        'estado' => $estado,
        'fuente' => basename($archivo),
    ];
    // Si el informe repite un RUT, conserva la fila activa.
    if (!isset($filas[$rut]) || (strcasecmp($estado, 'Activa') === 0 && strcasecmp($filas[$rut]['datos']['estado'], 'Activa') !== 0)) {
        $filas[$rut] = ['razon_social' => $razon, 'datos' => $datos];
    }
}
fclose($flujo);

$db = icontador_db();
empresas_esquema_completo($db);
$existentes = [];
$existentesNombres = [];
foreach ($db->query('SELECT id, origen_id, razon_social, datos_json FROM empresas')->fetchAll() as $empresa) {
    $datos = json_decode((string) $empresa['datos_json'], true) ?: [];
    $rut = normalizarRut((string) ($datos['rut'] ?? ''));
    if ($rut !== null) {
        $existentes[$rut] = (int) $empresa['id'];
    }
    $existentesNombres[clave((string) $empresa['razon_social'])] = (int) $empresa['id'];
}

$nuevas = [];
$yaExistentes = 0;
foreach ($filas as $rut => $empresa) {
    if (isset($existentes[$rut]) || isset($existentesNombres[clave($empresa['razon_social'])])) {
        $yaExistentes++;
        continue;
    }
    $nuevas[$rut] = $empresa;
}
$repetidasCsv = $leidas - count($filas) - count($invalidos);
$resumen = [
    'modo' => $aplicar ? 'aplicar' : 'revision',
    'filas_leidas' => $leidas,
    'empresas_unicas_validas' => count($filas),
    'duplicados_en_csv' => $repetidasCsv,
    'filas_invalidas' => count($invalidos),
    'ya_existentes' => $yaExistentes,
    'nuevas' => count($nuevas),
    'insertadas' => 0,
];

if ($aplicar && $nuevas) {
    $directorioBackup = dirname(__DIR__) . '/../backups-icontador';
    if (!is_dir($directorioBackup) && !mkdir($directorioBackup, 0700, true) && !is_dir($directorioBackup)) {
        throw new RuntimeException('No fue posible crear el directorio de respaldo.');
    }
    $antes = $db->query('SELECT id, origen_id, razon_social, datos_json, actualizado FROM empresas ORDER BY id')->fetchAll();
    $backup = $directorioBackup . '/empresas-antes-csv-' . gmdate('Ymd-His') . '.json';
    file_put_contents($backup, json_encode($antes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX);

    $insertar = $db->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)');
    $db->beginTransaction();
    try {
        foreach ($nuevas as $rut => $empresa) {
            $origen = 'csv:' . preg_replace('/[^0-9K]/', '', $rut);
            $insertar->execute([$origen, mb_substr($empresa['razon_social'], 0, 191), json_encode($empresa['datos'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), gmdate('c')]);
            $resumen['insertadas']++;
        }
        $db->commit();
        $resumen['respaldo'] = $backup;
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}

$resumen['total_empresas_despues'] = (int) $db->query('SELECT COUNT(*) FROM empresas')->fetchColumn();
$resumen['invalidas'] = $invalidos;
echo json_encode($resumen, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
