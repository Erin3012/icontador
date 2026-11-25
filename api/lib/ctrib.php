<?php
// Cifrax Tributario: mapeo de dígitos, balance de 8 columnas, homologación de cuentas a códigos del Recuadro 17 y su historial.
// Todo se guarda por empresa y año tributario (AT). El año comercial del balance es AT - 1.
declare(strict_types=1);

require_once __DIR__ . '/vouchers.php';

const CTRIB_TIPOS_CUENTA = ['ACTIVO', 'PASIVO', 'CAPITAL', 'PERDIDA', 'GANANCIA'];
// Cuentas de balance (van a inventario) y de resultado (van a pérdidas o ganancias).
const CTRIB_TIPOS_BALANCE = ['ACTIVO', 'PASIVO', 'CAPITAL'];
const CTRIB_TIPOS_RESULTADO = ['PERDIDA', 'GANANCIA'];
// Propuesta inicial del mapeo (convención de Cifrax). Cada empresa lo ajusta antes de importar el balance.
const CTRIB_MAPEO_PROPUESTO = ['1' => 'ACTIVO', '2' => 'PASIVO', '3' => 'PERDIDA', '4' => 'GANANCIA'];
const CTRIB_CODIGO_CUENTA = '/^[0-9][0-9.\-]{0,19}$/';

final class CtribError extends RuntimeException {}

function ctrib_schema(PDO $pdo): void
{
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $motor = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    $id = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $pdo->exec("CREATE TABLE IF NOT EXISTS ctrib_mapeo_digitos (
        empresa_id INT NOT NULL DEFAULT 0,
        digito CHAR(1) NOT NULL,
        tipo VARCHAR(12) NOT NULL,
        asignado VARCHAR(25) NOT NULL,
        usuario VARCHAR(120) NOT NULL DEFAULT '',
        PRIMARY KEY (empresa_id, digito)
    )$motor");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ctrib_balance (
        empresa_id INT NOT NULL DEFAULT 0,
        anio INT NOT NULL,
        origen VARCHAR(12) NOT NULL,
        archivo VARCHAR(255) NOT NULL DEFAULT '',
        importado VARCHAR(25) NOT NULL,
        usuario VARCHAR(120) NOT NULL DEFAULT '',
        PRIMARY KEY (empresa_id, anio)
    )$motor");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ctrib_balance_cuentas (
        empresa_id INT NOT NULL DEFAULT 0,
        anio INT NOT NULL,
        codigo VARCHAR(20) NOT NULL,
        nombre VARCHAR(120) NOT NULL,
        debitos BIGINT NOT NULL DEFAULT 0,
        creditos BIGINT NOT NULL DEFAULT 0,
        PRIMARY KEY (empresa_id, anio, codigo)
    )$motor");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ctrib_homologacion (
        empresa_id INT NOT NULL DEFAULT 0,
        anio INT NOT NULL,
        cuenta VARCHAR(20) NOT NULL,
        codigo CHAR(4) NOT NULL,
        usuario VARCHAR(120) NOT NULL DEFAULT '',
        actualizado VARCHAR(25) NOT NULL,
        PRIMARY KEY (empresa_id, anio, cuenta)
    )$motor");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ctrib_valores (
        empresa_id INT NOT NULL DEFAULT 0,
        anio INT NOT NULL,
        recuadro VARCHAR(4) NOT NULL,
        codigo CHAR(4) NOT NULL,
        monto BIGINT NOT NULL DEFAULT 0,
        usuario VARCHAR(120) NOT NULL DEFAULT '',
        actualizado VARCHAR(25) NOT NULL,
        PRIMARY KEY (empresa_id, anio, recuadro, codigo)
    )$motor");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ctrib_recuadros (
        empresa_id INT NOT NULL DEFAULT 0,
        anio INT NOT NULL,
        recuadro VARCHAR(4) NOT NULL,
        estado VARCHAR(12) NOT NULL DEFAULT 'BORRADOR',
        finalizado VARCHAR(25) NOT NULL DEFAULT '',
        usuario VARCHAR(120) NOT NULL DEFAULT '',
        snapshot TEXT NULL,
        PRIMARY KEY (empresa_id, anio, recuadro)
    )$motor");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ctrib_rtre_celdas (
        empresa_id INT NOT NULL DEFAULT 0,
        anio INT NOT NULL,
        fila VARCHAR(12) NOT NULL,
        columna VARCHAR(60) NOT NULL,
        monto BIGINT NOT NULL DEFAULT 0,
        usuario VARCHAR(120) NOT NULL DEFAULT '',
        actualizado VARCHAR(25) NOT NULL,
        PRIMARY KEY (empresa_id, anio, fila, columna)
    )$motor");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ctrib_certificados (
        id $id,
        empresa_id INT NOT NULL DEFAULT 0,
        anio INT NOT NULL,
        rut VARCHAR(20) NOT NULL,
        numero INT NOT NULL,
        emitido VARCHAR(25) NOT NULL,
        usuario VARCHAR(120) NOT NULL DEFAULT '',
        UNIQUE (empresa_id, anio, rut)
    )$motor");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ctrib_retiros_config (
        empresa_id INT NOT NULL DEFAULT 0,
        anio INT NOT NULL,
        modo VARCHAR(12) NOT NULL DEFAULT 'importados',
        usuario VARCHAR(120) NOT NULL DEFAULT '',
        actualizado VARCHAR(25) NOT NULL,
        PRIMARY KEY (empresa_id, anio)
    )$motor");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ctrib_socios (
        empresa_id INT NOT NULL DEFAULT 0,
        anio INT NOT NULL,
        rut VARCHAR(20) NOT NULL,
        nombre VARCHAR(120) NOT NULL DEFAULT '',
        participacion DECIMAL(7,4) NOT NULL,
        PRIMARY KEY (empresa_id, anio, rut)
    )$motor");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ctrib_retiros (
        id $id,
        empresa_id INT NOT NULL DEFAULT 0,
        anio INT NOT NULL,
        rut VARCHAR(20) NOT NULL,
        nombre VARCHAR(120) NOT NULL DEFAULT '',
        fecha VARCHAR(10) NOT NULL,
        monto BIGINT NOT NULL DEFAULT 0,
        tipo VARCHAR(20) NOT NULL DEFAULT 'Retiro'
    )$motor");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ctrib_historial (
        id $id,
        empresa_id INT NOT NULL DEFAULT 0,
        anio INT NOT NULL,
        entidad VARCHAR(20) NOT NULL,
        referencia VARCHAR(40) NOT NULL,
        antes VARCHAR(255) NOT NULL DEFAULT '',
        despues VARCHAR(255) NOT NULL DEFAULT '',
        motivo VARCHAR(255) NOT NULL DEFAULT '',
        usuario VARCHAR(120) NOT NULL DEFAULT '',
        creado VARCHAR(25) NOT NULL
    )$motor");
}

function ctrib_ahora(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function ctrib_anio_comercial(int $anioTributario): int
{
    return $anioTributario - 1;
}

/** Catálogo de códigos del Recuadro 17 (iTributario), indexado por código. */
function ctrib_catalogo_r17(): array
{
    static $catalogo = null;
    if ($catalogo === null) {
        $datos = json_decode((string) file_get_contents(__DIR__ . '/ctrib/recuadro17.json'), true);
        $catalogo = [];
        foreach ($datos ?: [] as $fila) {
            $catalogo[$fila['codigo']] = $fila;
        }
    }
    return $catalogo;
}

/** Códigos del R17 que se alimentan con cuentas del balance (naturaleza CUENTA_DEPENDIENTE). */
function ctrib_codigos_dependientes(): array
{
    return array_filter(ctrib_catalogo_r17(), fn($c) => $c['naturalezaConfiguracion'] === 'CUENTA_DEPENDIENTE');
}

// ---------- Mapeo de dígitos ----------

/** Mapeo guardado de la empresa: dígito => tipo. Vacío si aún no se ha definido. */
function ctrib_mapeo(PDO $pdo, int $empresa): array
{
    $st = $pdo->prepare('SELECT digito, tipo FROM ctrib_mapeo_digitos WHERE empresa_id = ? ORDER BY digito');
    $st->execute([$empresa]);
    $mapeo = [];
    foreach ($st as $f) {
        $mapeo[$f['digito']] = $f['tipo'];
    }
    return $mapeo;
}

/** Guarda el mapeo completo (dígito => tipo). Cada cambio queda en el historial. */
function ctrib_mapeo_guardar(PDO $pdo, int $empresa, array $nuevo, string $usuario = ''): array
{
    $anterior = ctrib_mapeo($pdo, $empresa);
    if (!$nuevo) {
        throw new CtribError('Indique el tipo de cuenta de al menos un dígito.');
    }
    foreach ($nuevo as $digito => $tipo) {
        if (!preg_match('/^[1-9]$/', (string) $digito)) {
            throw new CtribError("El dígito $digito no es válido (use del 1 al 9).");
        }
        if (!in_array($tipo, CTRIB_TIPOS_CUENTA, true)) {
            throw new CtribError("Tipo de cuenta no válido para el dígito $digito.");
        }
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM ctrib_mapeo_digitos WHERE empresa_id = ?')->execute([$empresa]);
        $insertar = $pdo->prepare('INSERT INTO ctrib_mapeo_digitos (empresa_id, digito, tipo, asignado, usuario) VALUES (?, ?, ?, ?, ?)');
        $ahora = ctrib_ahora();
        foreach ($nuevo as $digito => $tipo) {
            $insertar->execute([$empresa, (string) $digito, $tipo, $ahora, $usuario]);
        }
        foreach (array_unique(array_merge(array_keys($anterior), array_keys($nuevo))) as $digito) {
            $antes = $anterior[$digito] ?? '';
            $despues = $nuevo[$digito] ?? '';
            if ($antes !== $despues) {
                ctrib_registrar_historial($pdo, $empresa, 0, 'mapeo', "digito $digito", $antes, $despues, 'Cambio de mapeo de dígitos', $usuario);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ctrib_mapeo($pdo, $empresa);
}

// ---------- Historial ----------

function ctrib_registrar_historial(PDO $pdo, int $empresa, int $anio, string $entidad, string $referencia, string $antes, string $despues, string $motivo, string $usuario): void
{
    $pdo->prepare('INSERT INTO ctrib_historial (empresa_id, anio, entidad, referencia, antes, despues, motivo, usuario, creado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$empresa, $anio, $entidad, $referencia, $antes, $despues, $motivo, $usuario, ctrib_ahora()]);
}

function ctrib_historial_listar(PDO $pdo, int $empresa, int $anio, string $entidad = '', int $limite = 50): array
{
    $sql = 'SELECT id, entidad, referencia, antes, despues, motivo, usuario, creado FROM ctrib_historial WHERE empresa_id = ? AND anio = ?';
    $params = [$empresa, $anio];
    if ($entidad !== '') {
        $sql .= ' AND entidad = ?';
        $params[] = $entidad;
    }
    $st = $pdo->prepare($sql . ' ORDER BY id DESC LIMIT ' . max(1, min($limite, 200)));
    $st->execute($params);
    return $st->fetchAll();
}

// ---------- Balance de 8 columnas ----------

/** Cuentas del balance de una fuente: [codigo, nombre, debitos, creditos] con dígito mapeado a tipo. */
function ctrib_clasificar(array $cuentas, array $mapeo): array
{
    $sinMapeo = [];
    foreach ($cuentas as $c) {
        $digito = substr((string) $c['codigo'], 0, 1);
        if (!isset($mapeo[$digito])) {
            $sinMapeo[$digito] = true;
        }
    }
    if ($sinMapeo) {
        throw new CtribError('Falta asignar el tipo de cuenta de los dígitos ' . implode(', ', array_keys($sinMapeo)) . '.');
    }
    $salida = [];
    foreach ($cuentas as $c) {
        $tipo = $mapeo[substr((string) $c['codigo'], 0, 1)];
        $debe = (int) $c['debitos'];
        $haber = (int) $c['creditos'];
        $deudor = max($debe - $haber, 0);
        $acreedor = max($haber - $debe, 0);
        $esResultado = in_array($tipo, CTRIB_TIPOS_RESULTADO, true);
        $salida[] = [
            'codigo' => (string) $c['codigo'], 'nombre' => (string) $c['nombre'], 'tipo' => $tipo,
            'debitos' => $debe, 'creditos' => $haber, 'deudor' => $deudor, 'acreedor' => $acreedor,
            'activo' => !$esResultado ? $deudor : 0,
            'pasivo' => !$esResultado ? $acreedor : 0,
            'perdida' => $esResultado ? $deudor : 0,
            'ganancia' => $esResultado ? $acreedor : 0,
        ];
    }
    return $salida;
}

/** Totales, resultado del ejercicio y la comprobación de sumas iguales del balance de 8 columnas. */
function ctrib_totales(array $cuentas): array
{
    $totales = array_fill_keys(['debitos', 'creditos', 'deudor', 'acreedor', 'activo', 'pasivo', 'perdida', 'ganancia'], 0);
    foreach ($cuentas as $f) {
        foreach ($totales as $k => $_) {
            $totales[$k] += $f[$k];
        }
    }
    $resultado = $totales['ganancia'] - $totales['perdida'];
    $ajuste = [
        'activo' => $resultado < 0 ? -$resultado : 0, 'pasivo' => $resultado > 0 ? $resultado : 0,
        'perdida' => $resultado > 0 ? $resultado : 0, 'ganancia' => $resultado < 0 ? -$resultado : 0,
    ];
    $sumasIguales = [];
    foreach (['activo', 'pasivo', 'perdida', 'ganancia'] as $k) {
        $sumasIguales[$k] = $totales[$k] + $ajuste[$k];
    }
    return [
        'totales' => $totales, 'resultado' => $resultado, 'ajuste' => $ajuste, 'sumasIguales' => $sumasIguales,
        'cuadra' => $totales['debitos'] === $totales['creditos'] && $sumasIguales['activo'] === $sumasIguales['pasivo'] && $sumasIguales['perdida'] === $sumasIguales['ganancia'],
    ];
}

/** Balance completo a partir de cuentas crudas [codigo, nombre, debitos, creditos] y el mapeo de la empresa. */
function ctrib_armar_balance(array $cuentasCrudas, array $mapeo): array
{
    $cuentas = ctrib_clasificar($cuentasCrudas, $mapeo);
    usort($cuentas, fn($a, $b) => strcmp($a['codigo'], $b['codigo']));
    return ['cuentas' => $cuentas] + ctrib_totales($cuentas);
}

/** Balance guardado (snapshot) del año tributario, o null si aún no se ha generado. */
function ctrib_balance_guardado(PDO $pdo, int $empresa, int $anio): ?array
{
    $cab = $pdo->prepare('SELECT origen, archivo, importado, usuario FROM ctrib_balance WHERE empresa_id = ? AND anio = ?');
    $cab->execute([$empresa, $anio]);
    $fila = $cab->fetch();
    if (!$fila) {
        return null;
    }
    $cuentas = $pdo->prepare('SELECT codigo, nombre, debitos, creditos FROM ctrib_balance_cuentas WHERE empresa_id = ? AND anio = ? ORDER BY codigo');
    $cuentas->execute([$empresa, $anio]);
    $crudas = $cuentas->fetchAll();
    $mapeo = ctrib_mapeo($pdo, $empresa);
    return ['anio' => $anio, 'anioComercial' => ctrib_anio_comercial($anio), 'origen' => $fila['origen'], 'archivo' => $fila['archivo'], 'importado' => $fila['importado'], 'usuario' => $fila['usuario']]
        + ctrib_armar_balance($crudas, $mapeo);
}

/** Reemplaza el balance del año por las cuentas dadas (snapshot). Valida que cuadre y que los dígitos tengan tipo. */
function ctrib_balance_guardar(PDO $pdo, int $empresa, int $anio, string $origen, array $cuentas, string $archivo = '', string $usuario = ''): array
{
    $mapeo = ctrib_mapeo($pdo, $empresa);
    if (!$mapeo) {
        throw new CtribError('Defina primero el tipo de cuenta de cada dígito (mapeo de dígitos).');
    }
    $balance = ctrib_armar_balance($cuentas, $mapeo);
    if (!$balance['cuentas']) {
        throw new CtribError('El balance no tiene cuentas con movimientos.');
    }
    if ($balance['totales']['debitos'] !== $balance['totales']['creditos']) {
        throw new CtribError('El balance no cuadra: Débitos ' . formato($balance['totales']['debitos']) . ' y Créditos ' . formato($balance['totales']['creditos']) . '.');
    }
    $anterior = ctrib_balance_guardado($pdo, $empresa, $anio);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM ctrib_balance_cuentas WHERE empresa_id = ? AND anio = ?')->execute([$empresa, $anio]);
        $pdo->prepare('DELETE FROM ctrib_balance WHERE empresa_id = ? AND anio = ?')->execute([$empresa, $anio]);
        $pdo->prepare('INSERT INTO ctrib_balance (empresa_id, anio, origen, archivo, importado, usuario) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$empresa, $anio, $origen, $archivo, ctrib_ahora(), $usuario]);
        $insertar = $pdo->prepare('INSERT INTO ctrib_balance_cuentas (empresa_id, anio, codigo, nombre, debitos, creditos) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($balance['cuentas'] as $c) {
            $insertar->execute([$empresa, $anio, $c['codigo'], $c['nombre'], $c['debitos'], $c['creditos']]);
        }
        ctrib_registrar_historial($pdo, $empresa, $anio, 'balance', $origen, $anterior ? 'balance anterior (' . $anterior['origen'] . ')' : '', 'balance ' . $origen, $archivo !== '' ? $archivo : 'Balance importado', $usuario);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ctrib_balance_guardado($pdo, $empresa, $anio);
}

/** Sumas de los vouchers Tributario del año comercial (AT - 1), listas para guardar como balance. */
function ctrib_cuentas_desde_vouchers(PDO $pdo, int $empresa, int $anio): array
{
    $ac = ctrib_anio_comercial($anio);
    $sumas = sumasPorCuenta($pdo, ['desde' => "$ac-01-01", 'hasta' => "$ac-12-31", 'registro' => 'Tributario'], $empresa);
    $nombres = [];
    foreach (planCuentas($pdo, $empresa) as $c) {
        $nombres[$c['codigo']] = $c['nombre'];
    }
    $cuentas = [];
    foreach ($sumas as $codigo => $s) {
        if ($s['debe'] === 0 && $s['haber'] === 0) {
            continue;
        }
        $cuentas[] = ['codigo' => (string) $codigo, 'nombre' => $nombres[$codigo] ?? '', 'debitos' => (int) $s['debe'], 'creditos' => (int) $s['haber']];
    }
    return $cuentas;
}

// ---------- Importación de Excel (.xlsx) y CSV ----------

/** Lee la primera hoja de un .xlsx y devuelve filas como listas de celdas (texto o número). Sin librerías externas. */
function ctrib_leer_xlsx(string $ruta): array
{
    $zip = new ZipArchive();
    if ($zip->open($ruta) !== true) {
        throw new CtribError('No se pudo abrir el archivo Excel.');
    }
    $compartidas = [];
    $xmlCompartidas = $zip->getFromName('xl/sharedStrings.xml');
    if ($xmlCompartidas !== false) {
        $sx = simplexml_load_string($xmlCompartidas);
        foreach ($sx ? $sx->xpath('//*[local-name()="si"]') : [] as $si) {
            $texto = '';
            foreach ($si->xpath('.//*[local-name()="t"]') as $t) {
                $texto .= (string) $t;
            }
            $compartidas[] = $texto;
        }
    }
    $xmlHoja = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($xmlHoja === false) {
        throw new CtribError('El Excel no tiene una hoja de cálculo válida.');
    }
    $hoja = simplexml_load_string($xmlHoja);
    $filas = [];
    foreach ($hoja ? $hoja->xpath('//*[local-name()="row"]') : [] as $fila) {
        $celdas = [];
        foreach ($fila->xpath('*[local-name()="c"]') as $celda) {
            $ref = (string) $celda['r'];
            $columna = ctrib_indice_columna(preg_replace('/\d+/', '', $ref));
            $tipo = (string) $celda['t'];
            $valor = '';
            if ($tipo === 'inlineStr') {
                foreach ($celda->xpath('.//*[local-name()="t"]') as $t) {
                    $valor .= (string) $t;
                }
            } else {
                $v = $celda->xpath('*[local-name()="v"]');
                $valor = $v ? (string) $v[0] : '';
                if ($tipo === 's') {
                    $valor = $compartidas[(int) $valor] ?? '';
                }
            }
            $celdas[$columna] = $valor;
        }
        // Las celdas vacías no se escriben en el XML: se rellenan para que las columnas no se corran.
        $fila = [];
        $maximo = $celdas ? max(array_keys($celdas)) : -1;
        for ($i = 0; $i <= $maximo; $i++) {
            $fila[] = $celdas[$i] ?? '';
        }
        $filas[] = $fila;
    }
    return $filas;
}

function ctrib_indice_columna(string $letras): int
{
    $n = 0;
    foreach (str_split(strtoupper($letras)) as $l) {
        $n = $n * 26 + (ord($l) - 64);
    }
    return $n - 1;
}

/** Lee un CSV (separador ; o ,) como filas de celdas. */
function ctrib_leer_csv(string $ruta): array
{
    $contenido = (string) file_get_contents($ruta);
    if (str_starts_with($contenido, "\xEF\xBB\xBF")) {
        $contenido = substr($contenido, 3);
    }
    if (!mb_check_encoding($contenido, 'UTF-8')) {
        $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
    }
    $lineas = preg_split('/\R/', trim($contenido));
    $separador = substr_count($lineas[0] ?? '', ';') >= substr_count($lineas[0] ?? '', ',') ? ';' : ',';
    $filas = [];
    foreach ($lineas as $linea) {
        if (trim($linea) === '') {
            continue;
        }
        $filas[] = str_getcsv($linea, $separador);
    }
    return $filas;
}

function ctrib_normalizar_encabezado(string $texto): string
{
    $texto = mb_strtolower(trim($texto), 'UTF-8');
    return strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
}

/** Convierte texto de monto ("5.315.000", "$ 5315000", "-12") a entero. Vacío = 0. */
function ctrib_monto_texto(mixed $valor): int
{
    $texto = trim((string) $valor);
    if ($texto === '') {
        return 0;
    }
    $negativo = str_starts_with($texto, '-') || str_starts_with($texto, '(');
    $solo = preg_replace('/[^0-9]/', '', $texto);
    if ($solo === '') {
        return 0;
    }
    return ($negativo ? -1 : 1) * (int) $solo;
}

/**
 * Extrae cuentas [codigo, nombre, debitos, creditos] de un balance de 8 columnas (Excel o CSV).
 * Busca la fila de encabezados (codigo, nombre, debitos, creditos) en las primeras 15 filas.
 */
function ctrib_cuentas_desde_archivo(string $ruta, string $extension): array
{
    $filas = in_array($extension, ['xlsx', 'xlsm'], true) ? ctrib_leer_xlsx($ruta) : ctrib_leer_csv($ruta);
    $indices = null;
    $inicio = 0;
    foreach (array_slice($filas, 0, 15, true) as $n => $fila) {
        $celdas = is_array($fila) ? array_values($fila) : [];
        $norm = array_map(fn($c) => ctrib_normalizar_encabezado((string) $c), $celdas);
        $cod = $nom = $deb = $cre = null;
        foreach ($norm as $i => $t) {
            if ($cod === null && preg_match('/^(cod|cuenta)/', $t)) $cod = $i;
            elseif ($nom === null && preg_match('/^nom/', $t)) $nom = $i;
            elseif ($deb === null && str_contains($t, 'debito')) $deb = $i;
            elseif ($cre === null && str_contains($t, 'credito')) $cre = $i;
        }
        if ($cod !== null && $nom !== null && $deb !== null && $cre !== null) {
            $indices = ['codigo' => $cod, 'nombre' => $nom, 'debitos' => $deb, 'creditos' => $cre];
            $inicio = $n + 1;
            break;
        }
    }
    if ($indices === null) {
        throw new CtribError('No se encontró la fila de encabezados (Cod. Cuenta, Nom. Cuenta, Débitos, Créditos).');
    }
    $cuentas = [];
    $vistos = [];
    foreach (array_slice($filas, $inicio, null, true) as $fila) {
        $celdas = array_values(is_array($fila) ? $fila : []);
        $codigo = trim((string) ($celdas[$indices['codigo']] ?? ''));
        if ($codigo === '' || !preg_match('/^\d/', $codigo)) {
            continue; // filas de totales, títulos o vacías
        }
        if (preg_match('/^\d+\.0+$/', $codigo)) {
            $codigo = (string) (int) $codigo;
        }
        if (!preg_match(CTRIB_CODIGO_CUENTA, $codigo)) {
            throw new CtribError("Código de cuenta no válido: $codigo.");
        }
        if (isset($vistos[$codigo])) {
            throw new CtribError("La cuenta $codigo aparece más de una vez en el archivo.");
        }
        $vistos[$codigo] = true;
        $cuentas[] = [
            'codigo' => $codigo,
            'nombre' => mb_substr(trim((string) ($celdas[$indices['nombre']] ?? '')), 0, 120),
            'debitos' => ctrib_monto_texto($celdas[$indices['debitos']] ?? 0),
            'creditos' => ctrib_monto_texto($celdas[$indices['creditos']] ?? 0),
        ];
    }
    if (!$cuentas) {
        throw new CtribError('El archivo no tiene cuentas bajo los encabezados.');
    }
    return $cuentas;
}

// ---------- Retiros y dividendos (base del 1479 del R19 y del tope del incentivo 1432) ----------

// Modo de retiros del año: 'importados' (archivo de retiros), 'sin_retiros' (se declara que no hubo) o
// 'totalidad' (retiro de todo: se atribuye completa la base imponible a los socios según su participación; sirve para 14 D N°8).
const CTRIB_MODOS_RETIROS = ['importados', 'sin_retiros', 'totalidad'];

function ctrib_retiros_modo(PDO $pdo, int $empresa, int $anio): string
{
    $st = $pdo->prepare('SELECT modo FROM ctrib_retiros_config WHERE empresa_id = ? AND anio = ?');
    $st->execute([$empresa, $anio]);
    $modo = $st->fetchColumn();
    return in_array($modo, CTRIB_MODOS_RETIROS, true) ? $modo : 'importados';
}

/** Socios con su participación (%), usados en el modo 'totalidad'. */
function ctrib_socios(PDO $pdo, int $empresa, int $anio): array
{
    $st = $pdo->prepare('SELECT rut, nombre, participacion FROM ctrib_socios WHERE empresa_id = ? AND anio = ? ORDER BY rut');
    $st->execute([$empresa, $anio]);
    return array_map(fn($f) => ['rut' => $f['rut'], 'nombre' => $f['nombre'], 'participacion' => (float) $f['participacion']], $st->fetchAll());
}

function ctrib_retiros_modo_fijar(PDO $pdo, int $empresa, int $anio, string $modo, string $usuario): void
{
    $anterior = ctrib_retiros_modo($pdo, $empresa, $anio);
    $pdo->prepare('DELETE FROM ctrib_retiros_config WHERE empresa_id = ? AND anio = ?')->execute([$empresa, $anio]);
    $pdo->prepare('INSERT INTO ctrib_retiros_config (empresa_id, anio, modo, usuario, actualizado) VALUES (?, ?, ?, ?, ?)')
        ->execute([$empresa, $anio, $modo, $usuario, ctrib_ahora()]);
    if ($anterior !== $modo) {
        ctrib_registrar_historial($pdo, $empresa, $anio, 'retiros', 'modo', $anterior, $modo, 'Modo de retiros del año', $usuario);
    }
}

/**
 * Guarda el modo de retiros del año. 'sin_retiros' borra los retiros importados del año.
 * 'totalidad' exige socios con RUT y participación que sume 100%.
 */
function ctrib_retiros_modo_guardar(PDO $pdo, int $empresa, int $anio, string $modo, array $socios, string $usuario): void
{
    if (!in_array($modo, CTRIB_MODOS_RETIROS, true)) {
        throw new CtribError('Modo de retiros no válido.');
    }
    $limpios = [];
    if ($modo === 'totalidad') {
        $suma = 0.0;
        foreach ($socios as $i => $s) {
            $rut = trim((string) ($s['rut'] ?? ''));
            $p = $s['participacion'] ?? null;
            if ($rut === '' && trim((string) ($s['nombre'] ?? '')) === '' && ($p === null || $p === '')) {
                continue; // fila vacía del formulario
            }
            if ($rut === '') {
                throw new CtribError('Socio ' . ($i + 1) . ': falta el RUT.');
            }
            if (!is_numeric($p) || (float) $p <= 0 || (float) $p > 100) {
                throw new CtribError("Socio $rut: la participación debe ser mayor que 0 y hasta 100.");
            }
            if (isset($limpios[$rut])) {
                throw new CtribError("El socio $rut aparece más de una vez.");
            }
            $limpios[$rut] = ['rut' => $rut, 'nombre' => mb_substr(trim((string) ($s['nombre'] ?? '')), 0, 120), 'participacion' => round((float) $p, 4)];
            $suma += (float) $p;
        }
        if (!$limpios) {
            throw new CtribError('Para el retiro de la totalidad indique los socios y su participación.');
        }
        if (abs($suma - 100) > 0.01) {
            throw new CtribError('La participación de los socios debe sumar 100% (suma ' . rtrim(rtrim(number_format($suma, 4, ',', ''), '0'), ',') . '%).');
        }
    }
    $pdo->beginTransaction();
    try {
        if ($modo === 'totalidad') {
            $pdo->prepare('DELETE FROM ctrib_socios WHERE empresa_id = ? AND anio = ?')->execute([$empresa, $anio]);
            $insertar = $pdo->prepare('INSERT INTO ctrib_socios (empresa_id, anio, rut, nombre, participacion) VALUES (?, ?, ?, ?, ?)');
            foreach ($limpios as $s) {
                $insertar->execute([$empresa, $anio, $s['rut'], $s['nombre'], $s['participacion']]);
            }
        }
        if ($modo === 'sin_retiros') {
            $borrados = $pdo->prepare('DELETE FROM ctrib_retiros WHERE empresa_id = ? AND anio = ?');
            $borrados->execute([$empresa, $anio]);
            if ($borrados->rowCount() > 0) {
                ctrib_registrar_historial($pdo, $empresa, $anio, 'retiros', 'retiros', $borrados->rowCount() . ' retiros', '0', 'Se declara que no hubo retiros', $usuario);
            }
        }
        ctrib_retiros_modo_fijar($pdo, $empresa, $anio, $modo, $usuario);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Reparte un monto según porcentajes con el método del mayor resto: la suma de las partes es exacta. */
function ctrib_repartir(int $monto, array $porcentajes): array
{
    $partes = [];
    $restos = [];
    foreach ($porcentajes as $k => $p) {
        $exacto = $monto * $p / 100;
        $partes[$k] = (int) floor($exacto);
        $restos[$k] = $exacto - $partes[$k];
    }
    $faltan = $monto - array_sum($partes);
    arsort($restos);
    foreach (array_keys($restos) as $k) {
        if ($faltan <= 0) {
            break;
        }
        $partes[$k]++;
        $faltan--;
    }
    return $partes;
}

function ctrib_retiros_total(PDO $pdo, int $empresa, int $anio): int
{
    $st = $pdo->prepare('SELECT COALESCE(SUM(monto), 0) FROM ctrib_retiros WHERE empresa_id = ? AND anio = ?');
    $st->execute([$empresa, $anio]);
    return (int) $st->fetchColumn();
}

function ctrib_retiros_listar(PDO $pdo, int $empresa, int $anio): array
{
    $st = $pdo->prepare('SELECT rut, nombre, fecha, monto, tipo FROM ctrib_retiros WHERE empresa_id = ? AND anio = ? ORDER BY fecha, id');
    $st->execute([$empresa, $anio]);
    return array_map(fn($f) => $f + ['monto' => (int) $f['monto']], $st->fetchAll());
}

/** Fecha en formato AAAA-MM-DD desde texto (AAAA-MM-DD, DD/MM/AAAA, DD-MM-AAAA) o número de serie de Excel. */
function ctrib_fecha_texto(mixed $valor): ?string
{
    $texto = trim((string) $valor);
    if ($texto === '') {
        return null;
    }
    if (preg_match('/^\d{4,6}(\.\d+)?$/', $texto) && (float) $texto > 20000) {
        return gmdate('Y-m-d', (int) ((float) $texto - 25569) * 86400);
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $texto, $m)) {
        $f = [(int) $m[1], (int) $m[2], (int) $m[3]];
    } elseif (preg_match('/^(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})$/', $texto, $m)) {
        $f = [(int) $m[3], (int) $m[2], (int) $m[1]];
    } else {
        return null;
    }
    return checkdate($f[1], $f[2], $f[0]) ? sprintf('%04d-%02d-%02d', $f[0], $f[1], $f[2]) : null;
}

/** Busca la fila de encabezados: una columna por cada clave (la celda empieza con alguno de sus prefijos). */
function ctrib_encabezados(array $filas, array $claves): ?array
{
    foreach (array_slice($filas, 0, 15, true) as $n => $fila) {
        $norm = array_map(fn($c) => ctrib_normalizar_encabezado((string) $c), array_values(is_array($fila) ? $fila : []));
        $hallados = [];
        foreach ($claves as $clave => $prefijos) {
            foreach ($norm as $i => $t) {
                if (!in_array($i, $hallados, true) && array_filter($prefijos, fn($p) => str_starts_with($t, $p))) {
                    $hallados[$clave] = $i;
                    break;
                }
            }
        }
        if (count($hallados) === count($claves)) {
            return ['fila' => $n, 'indices' => $hallados];
        }
    }
    return null;
}

/** Lee un archivo de retiros (.xlsx o .csv): RUT, Nombre, Fecha, Monto y Tipo (opcional). */
function ctrib_retiros_desde_archivo(string $ruta, string $extension): array
{
    $filas = in_array($extension, ['xlsx', 'xlsm'], true) ? ctrib_leer_xlsx($ruta) : ctrib_leer_csv($ruta);
    $cab = ctrib_encabezados($filas, ['rut' => ['rut'], 'nombre' => ['nombre', 'socio', 'razon'], 'fecha' => ['fecha'], 'monto' => ['monto', 'valor']]);
    if ($cab === null) {
        throw new CtribError('No se encontraron los encabezados RUT, Nombre, Fecha y Monto del archivo de retiros.');
    }
    $tipoCol = ctrib_encabezados($filas, ['tipo' => ['tipo']])['indices']['tipo'] ?? null;
    $ix = $cab['indices'];
    $retiros = [];
    foreach (array_slice($filas, $cab['fila'] + 1, null, true) as $n => $fila) {
        $celdas = array_values(is_array($fila) ? $fila : []);
        $rut = trim((string) ($celdas[$ix['rut']] ?? ''));
        $monto = ctrib_monto_texto($celdas[$ix['monto']] ?? '');
        $fecha = ctrib_fecha_texto($celdas[$ix['fecha']] ?? '');
        if ($rut === '' && $monto === 0 && trim((string) ($celdas[$ix['fecha']] ?? '')) === '') {
            continue; // fila vacía o de totales
        }
        $fila1 = $n + 1;
        if ($rut === '' || $fecha === null || $monto <= 0) {
            throw new CtribError("Fila $fila1: se necesita RUT, una fecha válida y un monto mayor que cero.");
        }
        $retiros[] = [
            'rut' => $rut, 'nombre' => mb_substr(trim((string) ($celdas[$ix['nombre']] ?? '')), 0, 120),
            'fecha' => $fecha, 'monto' => $monto,
            'tipo' => $tipoCol !== null && trim((string) ($celdas[$tipoCol] ?? '')) !== '' ? mb_substr(trim((string) $celdas[$tipoCol]), 0, 20) : 'Retiro',
        ];
    }
    if (!$retiros) {
        throw new CtribError('El archivo no tiene retiros bajo los encabezados.');
    }
    return $retiros;
}

/** Reemplaza los retiros del año. Las fechas deben caer en el año comercial (AT − 1). */
function ctrib_retiros_importar(PDO $pdo, int $empresa, int $anio, array $retiros, string $archivo, string $usuario): array
{
    $ac = ctrib_anio_comercial($anio);
    foreach ($retiros as $i => $r) {
        if (!str_starts_with($r['fecha'], "$ac-")) {
            throw new CtribError('Fila ' . ($i + 1) . " fuera del año comercial $ac: {$r['fecha']}.");
        }
    }
    $anterior = ctrib_retiros_total($pdo, $empresa, $anio);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM ctrib_retiros WHERE empresa_id = ? AND anio = ?')->execute([$empresa, $anio]);
        $insertar = $pdo->prepare('INSERT INTO ctrib_retiros (empresa_id, anio, rut, nombre, fecha, monto, tipo) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($retiros as $r) {
            $insertar->execute([$empresa, $anio, $r['rut'], $r['nombre'], $r['fecha'], $r['monto'], $r['tipo']]);
        }
        ctrib_registrar_historial($pdo, $empresa, $anio, 'retiros', $archivo !== '' ? $archivo : 'retiros', (string) $anterior, (string) ctrib_retiros_total($pdo, $empresa, $anio), 'Importación de retiros', $usuario);
        ctrib_retiros_modo_fijar($pdo, $empresa, $anio, 'importados', $usuario); // importar vuelve al modo de retiros importados
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['cantidad' => count($retiros), 'total' => ctrib_retiros_total($pdo, $empresa, $anio)];
}

// ---------- Homologación (cuenta del balance → código del Recuadro 17) ----------

/** Asignaciones de la empresa para el año: cuenta => código. */
function ctrib_asignaciones(PDO $pdo, int $empresa, int $anio): array
{
    $st = $pdo->prepare('SELECT cuenta, codigo FROM ctrib_homologacion WHERE empresa_id = ? AND anio = ?');
    $st->execute([$empresa, $anio]);
    $salida = [];
    foreach ($st as $f) {
        $salida[$f['cuenta']] = $f['codigo'];
    }
    return $salida;
}

/** Revisa que una cuenta de resultado sea compatible con el código: ingresos con ganancias, egresos con pérdidas. */
function ctrib_compatible(string $tipoCuenta, string $codigo): ?string
{
    $c = ctrib_catalogo_r17()[$codigo] ?? null;
    if (!$c) {
        return "El código $codigo no existe en el Recuadro 17.";
    }
    if ($c['naturalezaConfiguracion'] !== 'CUENTA_DEPENDIENTE') {
        return "El código $codigo se calcula solo y no admite cuentas.";
    }
    if ($c['seccion'] === 'INGRESOS' && $tipoCuenta !== 'GANANCIA') {
        return "El código $codigo es de ingresos y la cuenta no es de ingresos (ganancias).";
    }
    if ($c['seccion'] === 'EGRESOS' && $tipoCuenta !== 'PERDIDA') {
        return "El código $codigo es de egresos y la cuenta no es de gastos (pérdidas).";
    }
    return null;
}

/** Cuentas de resultado del balance del año con su código asignado y, si corresponde, el motivo de incompatibilidad. */
function ctrib_homologacion_cuentas(PDO $pdo, int $empresa, int $anio): array
{
    $balance = ctrib_balance_guardado($pdo, $empresa, $anio);
    if (!$balance) {
        return [];
    }
    $asignaciones = ctrib_asignaciones($pdo, $empresa, $anio);
    $salida = [];
    foreach ($balance['cuentas'] as $c) {
        if (!in_array($c['tipo'], CTRIB_TIPOS_RESULTADO, true)) {
            continue;
        }
        $codigo = $asignaciones[$c['codigo']] ?? null;
        $salida[] = [
            'cuenta' => $c['codigo'], 'nombre' => $c['nombre'], 'tipo' => $c['tipo'],
            'saldo' => $c['tipo'] === 'PERDIDA' ? $c['deudor'] - $c['acreedor'] : $c['acreedor'] - $c['deudor'],
            'codigo' => $codigo,
            'alerta' => $codigo ? ctrib_compatible($c['tipo'], $codigo) : null,
        ];
    }
    return $salida;
}

/** Vista de la homologación: códigos dependientes con sus cuentas, cuentas de resultado y el estado general. */
function ctrib_homologacion(PDO $pdo, int $empresa, int $anio): array
{
    $cuentas = ctrib_homologacion_cuentas($pdo, $empresa, $anio);
    $porCodigo = [];
    foreach ($cuentas as $c) {
        if ($c['codigo'] !== null) {
            $porCodigo[$c['codigo']][] = ['cuenta' => $c['cuenta'], 'nombre' => $c['nombre'], 'saldo' => $c['saldo'], 'alerta' => $c['alerta']];
        }
    }
    $codigos = [];
    foreach (ctrib_codigos_dependientes() as $codigo => $c) {
        $codigos[] = [
            'codigo' => (string) $codigo, 'glosa' => $c['glosa'], 'seccion' => $c['seccion'], 'signo' => $c['signo'],
            'requiereCuentaAjuste' => $c['requiereCuentaAjuste'], 'cuentaAjusteTipo' => $c['cuentaAjusteTipo'],
            'cuentas' => $porCodigo[$codigo] ?? [],
        ];
    }
    $pendientes = count(array_filter($cuentas, fn($c) => $c['codigo'] === null));
    $alertas = count(array_filter($cuentas, fn($c) => $c['alerta'] !== null));
    return ['codigos' => $codigos, 'cuentasResultado' => $cuentas, 'pendientes' => $pendientes, 'alertas' => $alertas];
}

/**
 * Asigna una cuenta de resultado a un código dependiente del R17 (o la quita con codigo vacío).
 * Corregir siempre está permitido; cada cambio queda en el historial (caso ENYEL 406001: 1402 → 1419).
 */
function ctrib_homologar(PDO $pdo, int $empresa, int $anio, string $cuenta, string $codigo, string $usuario = '', string $motivo = ''): array
{
    $balance = ctrib_balance_guardado($pdo, $empresa, $anio);
    if (!$balance) {
        throw new CtribError('Aún no hay balance para este año. Importe o genere el balance primero.');
    }
    $fila = null;
    foreach ($balance['cuentas'] as $c) {
        if ($c['codigo'] === $cuenta) {
            $fila = $c;
            break;
        }
    }
    if (!$fila) {
        throw new CtribError("La cuenta $cuenta no está en el balance del año.");
    }
    if (!in_array($fila['tipo'], CTRIB_TIPOS_RESULTADO, true)) {
        throw new CtribError("La cuenta $cuenta es de balance y no se homologa al Recuadro 17.");
    }
    $codigo = trim($codigo);
    $antes = ctrib_asignaciones($pdo, $empresa, $anio)[$cuenta] ?? '';
    if ($codigo === '') {
        $pdo->prepare('DELETE FROM ctrib_homologacion WHERE empresa_id = ? AND anio = ? AND cuenta = ?')->execute([$empresa, $anio, $cuenta]);
    } else {
        if (!isset(ctrib_catalogo_r17()[$codigo])) {
            throw new CtribError("El código $codigo no existe en el Recuadro 17.");
        }
        if (!isset(ctrib_codigos_dependientes()[$codigo])) {
            throw new CtribError("El código $codigo se calcula solo y no admite cuentas.");
        }
        if ($error = ctrib_compatible($fila['tipo'], $codigo)) {
            throw new CtribError($error);
        }
        if ($antes === $codigo) {
            return ['cuenta' => $cuenta, 'codigo' => $codigo, 'antes' => $antes, 'cambio' => false];
        }
        $pdo->prepare('DELETE FROM ctrib_homologacion WHERE empresa_id = ? AND anio = ? AND cuenta = ?')->execute([$empresa, $anio, $cuenta]);
        $pdo->prepare('INSERT INTO ctrib_homologacion (empresa_id, anio, cuenta, codigo, usuario, actualizado) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$empresa, $anio, $cuenta, $codigo, $usuario, ctrib_ahora()]);
    }
    if ($antes !== $codigo) {
        ctrib_registrar_historial($pdo, $empresa, $anio, 'homologacion', $cuenta, $antes, $codigo, $motivo, $usuario);
    }
    return ['cuenta' => $cuenta, 'codigo' => $codigo, 'antes' => $antes, 'cambio' => $antes !== $codigo];
}

/** Carga la homologación tal como estaba en iTributario, sin validar, para revisar después su coherencia. */
function ctrib_homologacion_importar(PDO $pdo, int $empresa, int $anio, array $porCodigo, string $usuario = ''): int
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM ctrib_homologacion WHERE empresa_id = ? AND anio = ?')->execute([$empresa, $anio]);
        $insertar = $pdo->prepare('INSERT INTO ctrib_homologacion (empresa_id, anio, cuenta, codigo, usuario, actualizado) VALUES (?, ?, ?, ?, ?, ?)');
        $total = 0;
        foreach ($porCodigo as $codigo => $cuentas) {
            foreach ($cuentas as $cuenta) {
                $insertar->execute([$empresa, $anio, $cuenta, (string) $codigo, $usuario, ctrib_ahora()]);
                $total++;
            }
        }
        ctrib_registrar_historial($pdo, $empresa, $anio, 'homologacion', 'importación', '', "$total cuentas", 'Homologación importada desde iTributario', $usuario);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $total;
}
