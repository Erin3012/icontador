<?php
// Importa la "Ficha de Empresas" que exporta iContador (CSV separado por punto y coma, normalmente en Windows-1252).
// Cada empresa se busca por RUT (o por razón social si la empresa existente no tiene RUT) y se actualiza su ficha;
// las que no existen se crean. Nunca toca vouchers, plan de cuentas ni otros datos de una empresa.
declare(strict_types=1);

require_once __DIR__ . '/empresa-paquete.php';

// Encabezado del archivo (sin tildes, en minúsculas) => campo de la ficha.
const FICHA_CSV_COLUMNAS = [
    'tipo contribuyente' => 'tipo_contribuyente', 'razon social' => 'razon_social', 'rut' => 'rut', 'comuna' => 'comuna',
    'direccion' => 'direccion', 'ciudad' => 'ciudad', 'telefono movil' => 'telefono', 'telefono fijo' => 'telefono_fijo',
    'e-mail' => 'email', 'tributacion' => 'tributacion', 'transa en la bolsa' => 'transa_bolsa',
    'regimen tributario actual' => 'regimen', 'codigo actividad economica' => 'actividad', 'giro o actividad' => 'giro',
    'rut representante' => 'rut_representante', 'nombre representante' => 'representante', 'estado' => 'estado',
];

function ficha_csv_texto(string $valor): string
{
    return trim(html_entity_decode($valor, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function ficha_csv_clave(string $encabezado): string
{
    $sinTildes = strtr(mb_strtolower(ficha_csv_texto($encabezado)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    return preg_replace('/\s+/', ' ', $sinTildes);
}

/** Lee el archivo y devuelve [filas por RUT (la última repetida gana), errores por fila]. */
function ficha_csv_leer(string $contenido): array
{
    if (!mb_check_encoding($contenido, 'UTF-8')) {
        $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
    }
    $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);
    $archivo = fopen('php://temp', 'r+');
    fwrite($archivo, $contenido);
    rewind($archivo);
    $columnas = null;
    $separador = ';';
    $filas = [];
    $errores = [];
    $linea = 0;
    while (($primera = fgets($archivo)) !== false) {
        $linea++;
        $separador = substr_count($primera, ';') >= substr_count($primera, ',') ? ';' : ',';
        $campos = array_map('ficha_csv_clave', str_getcsv($primera, $separador, '"', ''));
        if (in_array('rut', $campos, true) && in_array('razon social', $campos, true)) {
            $columnas = array_map(fn($c) => FICHA_CSV_COLUMNAS[$c] ?? null, $campos);
            break;
        }
    }
    if ($columnas === null) {
        throw new ErrorValidacion(['El archivo no tiene el encabezado de la Ficha de Empresas (columnas "Razón Social" y "RUT").']);
    }
    while (($valores = fgetcsv($archivo, 0, $separador, '"', '')) !== false) {
        $linea++;
        if ($valores === [null] || implode('', $valores) === '') {
            continue;
        }
        $ficha = [];
        foreach ($columnas as $i => $campo) {
            if ($campo !== null) {
                $ficha[$campo] = mb_substr(ficha_csv_texto((string) ($valores[$i] ?? '')), 0, EMPRESA_CAMPOS[$campo] ?? 191);
            }
        }
        $rut = normalizarRut($ficha['rut'] ?? '');
        if (($ficha['razon_social'] ?? '') === '') {
            $errores[] = "Línea $linea: falta la razón social.";
        } elseif ($rut === null) {
            $errores[] = "Línea $linea ({$ficha['razon_social']}): el RUT \"" . ($ficha['rut'] ?? '') . '" no es válido.';
        } else {
            $ficha['rut'] = $rut;
            if (($ficha['rut_representante'] ?? '') !== '') {
                $ficha['rut_representante'] = normalizarRut($ficha['rut_representante']) ?? $ficha['rut_representante'];
            }
            unset($filas[$rut]);
            $filas[$rut] = $ficha;
        }
    }
    fclose($archivo);
    return [$filas, $errores];
}

function ficha_csv_nombre(string $razon): string
{
    return preg_replace('/\s+/', ' ', mb_strtoupper(trim($razon)));
}

/** $simular: hace todo dentro de la transacción y la deshace, para revisar el resultado antes de cargar. */
function empresas_importar_ficha_csv(PDO $db, string $contenido, bool $simular = false): array
{
    [$filas, $errores] = ficha_csv_leer($contenido);
    if (!$filas) {
        throw new ErrorValidacion($errores ?: ['El archivo no trae empresas.']);
    }
    $porRut = [];
    $porNombre = [];
    foreach ($db->query('SELECT id, razon_social, datos_json FROM empresas ORDER BY id') as $e) {
        $datos = json_decode((string) $e['datos_json'], true) ?: [];
        $rut = is_scalar($datos['rut'] ?? null) ? normalizarRut((string) $datos['rut']) : null;
        $existente = ['id' => (int) $e['id'], 'datos' => $datos];
        if ($rut !== null) {
            $porRut[$rut] ??= $existente;
        } else {
            $porNombre[ficha_csv_nombre($e['razon_social'])] ??= $existente;
        }
    }
    $creadas = 0;
    $actualizadas = [];
    $ahora = gmdate('c');
    $actualizar = $db->prepare('UPDATE empresas SET razon_social = ?, datos_json = ?, actualizado = ? WHERE id = ?');
    $insertar = $db->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)');
    $db->beginTransaction();
    try {
        foreach ($filas as $rut => $ficha) {
            $existente = $porRut[$rut] ?? $porNombre[ficha_csv_nombre($ficha['razon_social'])] ?? null;
            $razon = mb_substr($ficha['razon_social'], 0, 191);
            if ($existente) {
                // Los campos vacíos del archivo no borran lo que la empresa ya tenía.
                $datos = array_merge($existente['datos'], array_filter($ficha, fn($v) => $v !== ''));
                $actualizar->execute([$razon, json_encode($datos, JSON_UNESCAPED_UNICODE), $ahora, $existente['id']]);
                $actualizadas[] = $razon;
            } else {
                $insertar->execute(['local:' . bin2hex(random_bytes(8)), $razon, json_encode($ficha, JSON_UNESCAPED_UNICODE), $ahora]);
                $creadas++;
            }
        }
        $simular ? $db->rollBack() : $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return ['creadas' => $creadas, 'actualizadas' => count($actualizadas), 'empresas_actualizadas' => $actualizadas, 'errores' => $errores];
}
