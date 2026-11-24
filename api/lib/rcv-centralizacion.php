<?php
declare(strict_types=1);

require_once __DIR__ . '/rcv.php';
require_once __DIR__ . '/vouchers.php';
require_once __DIR__ . '/honorarios.php';

const RCV_CENTRALIZACION_MAX_DOCUMENTOS = 1000;
const RCV_CENTRALIZACION_MAX_REDONDEO = 10;
const RCV_CENTRALIZACION_OPERACIONES = ['Del Giro', 'Activo Fijo', 'Bienes Raíces', 'Supermercado', 'IVA Uso Común', 'IVA No Recuperable'];
const RCV_CENTRALIZACION_CAMPOS_CUENTA = [
    'cuenta_compra_default', 'cuenta_iva_credito', 'cuenta_iva_uso_comun', 'cuenta_iva_no_rec', 'cuenta_otros_compra', 'cuenta_proveedor_default',
    'cuenta_venta_default', 'cuenta_iva_debito', 'cuenta_retencion_venta', 'cuenta_otros_venta', 'cuenta_cliente', 'cuenta_redondeo',
    'cuenta_hon_recibidas', 'cuenta_hon_retencion_pagar', 'cuenta_hon_proveedor_pagar',
    'cuenta_hon_emitidas', 'cuenta_hon_retencion_recuperar', 'cuenta_hon_cliente',
];

final class RcvCentralizacionError extends RuntimeException {}

function rcv_centralizacion_schema(PDO $db): void
{
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $schema = file_get_contents(dirname(__DIR__) . '/schema/rcv-centralizacion.mysql.sql');
        if ($schema === false) throw new RuntimeException('No se pudo leer el esquema de centralización RCV.');
        foreach (array_filter(array_map('trim', explode(';', $schema))) as $sql) $db->exec($sql);
        $columna = $db->query("SHOW COLUMNS FROM rcv_centralizaciones LIKE 'libro'")->fetch();
        if ($columna && preg_match('/varchar\((\d+)\)/i', (string) ($columna['Type'] ?? ''), $m) && (int) $m[1] < 24) {
            $db->exec('ALTER TABLE rcv_centralizaciones MODIFY libro VARCHAR(24) NOT NULL');
        }
        return;
    }
    $db->exec('CREATE TABLE IF NOT EXISTS rcv_centralizacion_config (
        empresa_id INTEGER PRIMARY KEY, config_json TEXT NOT NULL, actualizado_en TEXT NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS rcv_proveedor_cuentas (
        empresa_id INTEGER NOT NULL, rut TEXT NOT NULL, nombre TEXT NOT NULL DEFAULT \'\', cuenta TEXT NOT NULL, actualizado_en TEXT NOT NULL,
        PRIMARY KEY (empresa_id, rut))');
    $db->exec('CREATE TABLE IF NOT EXISTS rcv_centralizaciones (
        id INTEGER PRIMARY KEY AUTOINCREMENT, empresa_id INTEGER NOT NULL, source_key TEXT NOT NULL, source_hash TEXT NOT NULL,
        voucher_id INTEGER NOT NULL, periodo TEXT NOT NULL, libro TEXT NOT NULL, rut TEXT NOT NULL DEFAULT \'\', folio TEXT NOT NULL DEFAULT \'\',
        creado_en TEXT NOT NULL, UNIQUE (empresa_id, source_key), FOREIGN KEY (voucher_id) REFERENCES vouchers(id) ON DELETE CASCADE)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_rcv_centralizaciones_voucher ON rcv_centralizaciones (voucher_id)');
    $db->exec('CREATE TABLE IF NOT EXISTS rcv_centralizacion_pagos (
        empresa_id INTEGER NOT NULL, source_key TEXT NOT NULL, pago_voucher_id INTEGER NOT NULL, creado_en TEXT NOT NULL,
        PRIMARY KEY (empresa_id, source_key),
        FOREIGN KEY (empresa_id, source_key) REFERENCES rcv_centralizaciones (empresa_id, source_key) ON DELETE CASCADE,
        FOREIGN KEY (pago_voucher_id) REFERENCES vouchers(id) ON DELETE CASCADE)');
}

function rcv_centralizacion_normalizar(string $texto): string
{
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', trim($texto));
    $texto = strtolower($ascii === false ? $texto : $ascii);
    return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $texto));
}

function rcv_centralizacion_config_vacia(): array
{
    return array_fill_keys(RCV_CENTRALIZACION_CAMPOS_CUENTA, '');
}

/** Sugiere códigos habituales del plan de ejemplo y busca equivalentes por nombre en planes personalizados. */
function rcv_centralizacion_buscar_cuenta(array $cuentas, array $codigos, array $nombres, array $prefijos = []): string
{
    $porCodigo = [];
    foreach ($cuentas as $cuenta) $porCodigo[(string) $cuenta['codigo']] = $cuenta;
    foreach ($codigos as $codigo) if (isset($porCodigo[$codigo])) return $codigo;

    $normalizadas = [];
    foreach ($cuentas as $cuenta) $normalizadas[] = ['codigo' => (string) $cuenta['codigo'], 'nombre' => rcv_centralizacion_normalizar((string) $cuenta['nombre'])];
    foreach ($nombres as $nombre) {
        $busqueda = rcv_centralizacion_normalizar($nombre);
        foreach ($normalizadas as $cuenta) if ($cuenta['nombre'] === $busqueda) return $cuenta['codigo'];
    }
    foreach ($nombres as $nombre) {
        $busqueda = rcv_centralizacion_normalizar($nombre);
        foreach ($normalizadas as $cuenta) if ($busqueda !== '' && str_contains($cuenta['nombre'], $busqueda)) return $cuenta['codigo'];
    }
    foreach ($prefijos as $prefijo) foreach ($cuentas as $cuenta) {
        if (str_starts_with((string) $cuenta['codigo'], $prefijo)) return (string) $cuenta['codigo'];
    }
    return '';
}

function rcv_centralizacion_aplicar_predeterminadas(PDO $db, int $empresa, array $config): array
{
    $cuentas = planCuentas($db, $empresa);
    $validas = array_fill_keys(array_column($cuentas, 'codigo'), true);
    $reglas = [
        'cuenta_compra_default' => [['3.1.01'], ['costo de ventas', 'gastos generales', 'compras del giro'], ['3.']],
        'cuenta_iva_credito' => [['1.1.05'], ['iva credito fiscal', 'iva credito'], []],
        'cuenta_iva_uso_comun' => [['1.1.05'], ['iva uso comun', 'iva credito fiscal', 'iva credito'], []],
        'cuenta_iva_no_rec' => [['3.1.05'], ['iva no recuperable', 'gastos generales'], ['3.']],
        'cuenta_otros_compra' => [['3.1.05'], ['gastos generales', 'otros gastos'], ['3.']],
        'cuenta_proveedor_default' => [['2.1.01'], ['proveedores', 'proveedores por pagar'], []],
        'cuenta_venta_default' => [['4.1.01'], ['ventas'], []],
        'cuenta_iva_debito' => [['2.1.02'], ['iva debito fiscal', 'iva debito'], []],
        'cuenta_retencion_venta' => [['1.1.11'], ['impuestos por recuperar', 'iva retenido por recuperar'], []],
        'cuenta_otros_venta' => [['2.1.11'], ['acreedores varios', 'impuestos por pagar'], []],
        'cuenta_cliente' => [['1.1.03'], ['clientes', 'clientes por cobrar'], []],
        'cuenta_redondeo' => [[], ['diferencias de redondeo', 'ajustes por redondeo', 'redondeo'], []],
        'cuenta_hon_recibidas' => [['3.1.03'], ['honorarios'], ['3.']],
        'cuenta_hon_retencion_pagar' => [['2.1.03'], ['retenciones por pagar'], []],
        'cuenta_hon_proveedor_pagar' => [['2.1.07'], ['honorarios por pagar'], []],
        'cuenta_hon_emitidas' => [['4.1.02'], ['otros ingresos', 'honorarios'], ['4.']],
        'cuenta_hon_retencion_recuperar' => [['1.1.11'], ['impuestos por recuperar', 'retenciones por recuperar'], []],
        'cuenta_hon_cliente' => [['1.1.03'], ['clientes', 'clientes por cobrar'], []],
    ];
    foreach ($reglas as $campo => [$codigos, $nombres, $prefijos]) {
        if (empty($config[$campo]) || !isset($validas[(string) $config[$campo]])) {
            $config[$campo] = rcv_centralizacion_buscar_cuenta($cuentas, $codigos, $nombres, $prefijos);
        }
    }
    // Si el plan de la empresa no tiene una cuenta separada de honorarios por pagar,
    // usa la cuenta común de proveedores por pagar para el líquido de las BHE recibidas.
    if (empty($config['cuenta_hon_proveedor_pagar'])
        && !empty($config['cuenta_proveedor_default'])
        && isset($validas[(string) $config['cuenta_proveedor_default']])) {
        $config['cuenta_hon_proveedor_pagar'] = (string) $config['cuenta_proveedor_default'];
    }

    $operaciones = [
        'activo fijo' => [['1.2.04'], ['activo fijo', 'maquinarias y equipos', 'equipos computacionales'], []],
        'bienes raices' => [['1.2.06', '1.2.05'], ['bienes raices', 'construcciones', 'terrenos'], []],
        'supermercado' => [['3.1.05'], ['gastos generales', 'costo de ventas'], ['3.']],
        'iva uso comun' => [['1.1.05'], ['iva credito fiscal', 'iva credito'], []],
        'iva no recuperable' => [['3.1.05'], ['iva no recuperable', 'gastos generales'], ['3.']],
    ];
    $config['cuentas_operacion'] = is_array($config['cuentas_operacion'] ?? null) ? $config['cuentas_operacion'] : [];
    foreach (rcv_centralizacion_operaciones($db, $empresa) as $operacion) {
        $key = $operacion['key'];
        if (!empty($config['cuentas_operacion'][$key]) && isset($validas[(string) $config['cuentas_operacion'][$key]])) continue;
        $regla = $operaciones[$key] ?? null;
        $config['cuentas_operacion'][$key] = $regla === null
            ? (string) ($config['cuenta_compra_default'] ?? '')
            : rcv_centralizacion_buscar_cuenta($cuentas, ...$regla);
    }
    return $config;
}

function rcv_centralizacion_config_leer(PDO $db, int $empresa): array
{
    $st = $db->prepare('SELECT config_json FROM rcv_centralizacion_config WHERE empresa_id = ?');
    $st->execute([$empresa]);
    $json = $st->fetchColumn();
    $guardada = is_string($json) ? json_decode($json, true) : null;
    $config = rcv_centralizacion_config_vacia();
    if (is_array($guardada)) {
        foreach (RCV_CENTRALIZACION_CAMPOS_CUENTA as $campo) {
            if (is_scalar($guardada[$campo] ?? null)) $config[$campo] = (string) $guardada[$campo];
        }
        if (is_array($guardada['cuentas_operacion'] ?? null)) $config['cuentas_operacion'] = $guardada['cuentas_operacion'];
    }
    $config['cuentas_operacion'] ??= [];
    $config = rcv_centralizacion_aplicar_predeterminadas($db, $empresa, $config);
    $jsonNuevo = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (!is_string($json) || $jsonNuevo !== json_encode($guardada, JSON_UNESCAPED_UNICODE)) {
        $sql = 'INSERT INTO rcv_centralizacion_config (empresa_id, config_json, actualizado_en) VALUES (?, ?, ?)'
            . ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                ? ' ON DUPLICATE KEY UPDATE config_json = VALUES(config_json), actualizado_en = VALUES(actualizado_en)'
                : ' ON CONFLICT(empresa_id) DO UPDATE SET config_json = excluded.config_json, actualizado_en = excluded.actualizado_en');
        $db->prepare($sql)->execute([$empresa, $jsonNuevo, gmdate('Y-m-d\TH:i:s\Z')]);
    }
    return $config;
}

/** Operaciones detectadas y etiquetas comunes para preparar asignaciones antes de la primera importación. */
function rcv_centralizacion_operaciones(PDO $db, int $empresa): array
{
    $st = $db->prepare("SELECT DISTINCT tipo_operacion FROM rcv_documentos WHERE empresa_id = ? AND libro = 'compras' AND tipo_operacion <> '' ORDER BY tipo_operacion");
    $st->execute([$empresa]);
    $labels = array_merge(RCV_CENTRALIZACION_OPERACIONES, $st->fetchAll(PDO::FETCH_COLUMN));
    $salida = [];
    foreach ($labels as $label) {
        $label = trim((string) $label);
        $key = rcv_centralizacion_normalizar($label);
        if ($key !== '' && $key !== 'no incluir') $salida[$key] = ['key' => $key, 'nombre' => $label];
    }
    return array_values($salida);
}

/** Roster de proveedores basado en documentos importados; cada uno hereda la cuenta común si no tiene reemplazo. */
function rcv_centralizacion_proveedores(PDO $db, int $empresa, ?string $default = null): array
{
    $st = $db->prepare("SELECT rut, razon_social FROM rcv_documentos WHERE empresa_id = ? AND libro = 'compras' ORDER BY id DESC");
    $st->execute([$empresa]);
    $nombres = [];
    foreach ($st as $fila) {
        $rut = rut_limpio((string) $fila['rut']);
        if ($rut === '' || isset($nombres[$rut])) continue;
        $nombres[$rut] = trim((string) $fila['razon_social']);
    }
    $cuentas = $db->prepare('SELECT rut, cuenta FROM rcv_proveedor_cuentas WHERE empresa_id = ?');
    $cuentas->execute([$empresa]);
    $sobrescrituras = [];
    foreach ($cuentas->fetchAll() as $fila) $sobrescrituras[rut_limpio((string) $fila['rut'])] = (string) $fila['cuenta'];
    $salida = [];
    foreach ($nombres as $rut => $nombre) {
        $rut = (string) $rut;
        $override = $sobrescrituras[$rut] ?? '';
        $salida[] = ['rut' => $rut, 'nombre' => $nombre, 'cuenta' => $override, 'cuenta_efectiva' => $override !== '' ? $override : (string) ($default ?? '')];
    }
    return $salida;
}

function rcv_centralizacion_configuracion(PDO $db, int $empresa): array
{
    $config = rcv_centralizacion_config_leer($db, $empresa);
    $cuentas = planCuentas($db, $empresa);
    return ['config' => $config, 'cuentas' => $cuentas, 'cuentas_pago' => rcv_centralizacion_cuentas_pago($cuentas),
        'operaciones' => rcv_centralizacion_operaciones($db, $empresa),
        'proveedores' => rcv_centralizacion_proveedores($db, $empresa, $config['cuenta_proveedor_default'])];
}

function rcv_centralizacion_tipo_cuenta_pago(array $cuenta): ?string
{
    if (!str_starts_with((string) ($cuenta['codigo'] ?? ''), '1.')) return null;
    $nombre = rcv_centralizacion_normalizar((string) ($cuenta['nombre'] ?? ''));
    if (preg_match('/(^| )(caja|efectivo)( |$)/', $nombre)) return 'caja';
    if (str_contains($nombre, 'banco') || str_contains($nombre, 'cuenta corriente') || str_contains($nombre, 'cuenta vista')) return 'banco';
    return null;
}

function rcv_centralizacion_cuentas_pago(array $cuentas): array
{
    $salida = ['caja' => [], 'banco' => []];
    foreach ($cuentas as $cuenta) {
        $tipo = rcv_centralizacion_tipo_cuenta_pago($cuenta);
        if ($tipo !== null) $salida[$tipo][] = ['codigo' => (string) $cuenta['codigo'], 'nombre' => (string) $cuenta['nombre']];
    }
    return $salida;
}

/** Estado persistente por período/libro para que RCV muestre si ya quedó contabilizado. */
function rcv_centralizacion_estados_periodos(PDO $db, int $empresa): array
{
    $esperados = [];
    $st = $db->prepare("SELECT periodo, libro, rut, tipo_doc AS tipo, folio, fecha, tipo_operacion AS tipoOperacion
        FROM rcv_documentos WHERE empresa_id = ? ORDER BY periodo, libro, id");
    $st->execute([$empresa]);
    $vistos = [];
    foreach ($st->fetchAll() as $fila) {
        if ($fila['libro'] === 'compras' && rcv_centralizacion_normalizar((string) $fila['tipoOperacion']) === 'no incluir') continue;
        $identidad = [(string) $fila['periodo'], (string) $fila['libro'], rut_limpio((string) $fila['rut']), (int) $fila['tipo'], trim((string) $fila['folio']), rcv_centralizacion_fecha((string) $fila['fecha'])];
        $key = hash('sha256', json_encode($identidad, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if (isset($vistos[$key])) continue;
        $vistos[$key] = true;
        $periodo = (string) $fila['periodo'];
        $libro = (string) $fila['libro'];
        $esperados[$periodo][$libro] = ($esperados[$periodo][$libro] ?? 0) + 1;
    }
    $st = $db->prepare("SELECT periodo, SUM(documentos) AS documentos FROM rcv_resumenes
        WHERE empresa_id = ? AND libro = 'ventas' AND tipo = 48 GROUP BY periodo");
    $st->execute([$empresa]);
    foreach ($st->fetchAll() as $fila) if ((int) $fila['documentos'] > 0) {
        $periodo = (string) $fila['periodo'];
        $esperados[$periodo]['ventas'] = ($esperados[$periodo]['ventas'] ?? 0) + 1;
    }
    $honorarios = $db->prepare('SELECT * FROM honorarios_boletas WHERE empresa_id = ? ORDER BY periodo, libro, id');
    $honorarios->execute([$empresa]);
    $vistosHon = [];
    foreach ($honorarios->fetchAll() as $fila) {
        $doc = hon_doc($fila);
        $libro = 'honorarios_' . (string) $fila['libro'];
        $identidad = [$empresa, (string) $fila['periodo'], $libro, rut_limpio((string) $doc['rut']), trim((string) $doc['numero'])];
        $key = hash('sha256', json_encode($identidad, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if (isset($vistosHon[$key])) continue;
        $vistosHon[$key] = true;
        if (hon_anulada($doc)) continue;
        $periodo = (string) $fila['periodo'];
        $esperados[$periodo][$libro] = ($esperados[$periodo][$libro] ?? 0) + 1;
    }
    $contabilizados = [];
    $st = $db->prepare('SELECT periodo, libro, COUNT(*) AS documentos FROM rcv_centralizaciones WHERE empresa_id = ? GROUP BY periodo, libro');
    $st->execute([$empresa]);
    foreach ($st->fetchAll() as $fila) $contabilizados[(string) $fila['periodo']][(string) $fila['libro']] = (int) $fila['documentos'];

    $periodos = array_unique(array_merge(array_keys($esperados), array_keys($contabilizados)));
    rsort($periodos, SORT_STRING);
    $resultado = [];
    foreach ($periodos as $periodo) {
        $libros = [];
        foreach (['compras', 'ventas', 'honorarios_recibidas', 'honorarios_emitidas'] as $libro) {
            $total = (int) ($esperados[$periodo][$libro] ?? 0);
            $hechos = (int) ($contabilizados[$periodo][$libro] ?? 0);
            if ($total === 0 && $hechos === 0) continue;
            $estado = $hechos > $total ? 'revision' : ($total > 0 && $hechos >= $total ? 'contabilizado' : ($hechos > 0 ? 'parcial' : 'pendiente'));
            $libros[$libro] = ['esperados' => $total, 'contabilizados' => $hechos, 'estado' => $estado];
        }
        $resultado[] = ['periodo' => $periodo, 'libros' => $libros];
    }
    return ['periodos' => $resultado];
}

function rcv_centralizacion_guardar_configuracion(PDO $db, int $empresa, array $datos): array
{
    $configIn = $datos['config'] ?? null;
    $proveedoresIn = $datos['proveedores'] ?? null;
    if (!is_array($configIn) || !is_array($proveedoresIn) || !array_is_list($proveedoresIn)) {
        throw new RcvCentralizacionError('Falta la configuración contable o la lista de proveedores.');
    }
    $cuentasValidas = array_fill_keys(array_column(planCuentas($db, $empresa), 'codigo'), true);
    $config = rcv_centralizacion_config_vacia();
    foreach (RCV_CENTRALIZACION_CAMPOS_CUENTA as $campo) {
        $valor = $configIn[$campo] ?? '';
        if (!is_string($valor) || mb_strlen($valor) > 20) throw new RcvCentralizacionError('Una cuenta de centralización no es válida.');
        $valor = trim($valor);
        if ($valor !== '' && !isset($cuentasValidas[$valor])) throw new RcvCentralizacionError("La cuenta $valor no pertenece al plan de esta empresa.");
        $config[$campo] = $valor;
    }
    $operacionesIn = $configIn['cuentas_operacion'] ?? [];
    if (!is_array($operacionesIn)) throw new RcvCentralizacionError('Las cuentas por naturaleza de compra no son válidas.');
    $config['cuentas_operacion'] = [];
    foreach ($operacionesIn as $key => $cuenta) {
        if (!is_string($key) || mb_strlen($key) > 80 || !is_string($cuenta) || mb_strlen($cuenta) > 20) throw new RcvCentralizacionError('Una asignación por naturaleza no es válida.');
        $key = rcv_centralizacion_normalizar($key);
        $cuenta = trim($cuenta);
        if ($key === '' || $key === 'no incluir') continue;
        if ($cuenta !== '' && !isset($cuentasValidas[$cuenta])) throw new RcvCentralizacionError("La cuenta $cuenta no pertenece al plan de esta empresa.");
        $config['cuentas_operacion'][$key] = $cuenta;
    }

    $conocidos = [];
    foreach (rcv_centralizacion_proveedores($db, $empresa) as $proveedor) $conocidos[$proveedor['rut']] = $proveedor['nombre'];
    $stUpsert = $db->prepare('INSERT INTO rcv_proveedor_cuentas (empresa_id, rut, nombre, cuenta, actualizado_en) VALUES (?, ?, ?, ?, ?)'
        . ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), cuenta = VALUES(cuenta), actualizado_en = VALUES(actualizado_en)' : ' ON CONFLICT(empresa_id, rut) DO UPDATE SET nombre = excluded.nombre, cuenta = excluded.cuenta, actualizado_en = excluded.actualizado_en'));
    $stDelete = $db->prepare('DELETE FROM rcv_proveedor_cuentas WHERE empresa_id = ? AND rut = ?');
    $propia = !$db->inTransaction();
    if ($propia) $db->beginTransaction();
    try {
        $ahora = gmdate('Y-m-d\TH:i:s\Z');
        $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $st = $db->prepare('INSERT INTO rcv_centralizacion_config (empresa_id, config_json, actualizado_en) VALUES (?, ?, ?)'
            . ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ON DUPLICATE KEY UPDATE config_json = VALUES(config_json), actualizado_en = VALUES(actualizado_en)' : ' ON CONFLICT(empresa_id) DO UPDATE SET config_json = excluded.config_json, actualizado_en = excluded.actualizado_en'));
        $st->execute([$empresa, $json, $ahora]);
        $vistos = [];
        foreach ($proveedoresIn as $proveedor) {
            if (!is_array($proveedor) || !is_string($proveedor['rut'] ?? null) || !is_string($proveedor['cuenta'] ?? null)) throw new RcvCentralizacionError('Una asignación de proveedor no es válida.');
            $rut = rut_limpio($proveedor['rut']);
            $cuenta = trim($proveedor['cuenta']);
            if ($rut === '' || !isset($conocidos[$rut])) throw new RcvCentralizacionError('Solo se pueden configurar proveedores presentes en los RCV importados de esta empresa.');
            if (isset($vistos[$rut])) throw new RcvCentralizacionError('La lista contiene un proveedor repetido.');
            $vistos[$rut] = true;
            if ($cuenta !== '' && !isset($cuentasValidas[$cuenta])) throw new RcvCentralizacionError("La cuenta $cuenta no pertenece al plan de esta empresa.");
            if ($cuenta === '' || $cuenta === $config['cuenta_proveedor_default']) {
                $stDelete->execute([$empresa, $rut]);
            } else {
                $stUpsert->execute([$empresa, $rut, $conocidos[$rut], $cuenta, $ahora]);
            }
        }
        if ($propia) $db->commit();
    } catch (Throwable $e) {
        if ($propia && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return rcv_centralizacion_configuracion($db, $empresa);
}

function rcv_centralizacion_fecha(string $fecha): ?string
{
    return rcv_fecha_iso($fecha);
}

function rcv_centralizacion_fila_doc(array $fila): array
{
    $doc = ['periodo' => (string) $fila['periodo'], 'libro' => (string) $fila['libro']];
    foreach (RCV_CAMPOS as $campo => $columna) {
        $doc[$campo] = ($columna === 'tipo_doc' || in_array($columna, RCV_MONTOS, true))
            ? (int) $fila[$columna]
            : (string) ($fila[$columna] ?? '');
    }
    return $doc;
}

/** El RCV repite un documento por cada impuesto adicional/retención; el resto de montos y el total se repiten. */
function rcv_centralizacion_agrupar_filas(array $filas): array
{
    $grupos = [];
    foreach ($filas as $fila) {
        $doc = rcv_centralizacion_fila_doc($fila);
        $identidad = [$doc['periodo'], $doc['libro'], rut_limpio((string) $doc['rut']), (int) $doc['tipo'], trim((string) $doc['folio']), rcv_centralizacion_fecha((string) $doc['fecha'])];
        $key = hash('sha256', json_encode($identidad, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if (!isset($grupos[$key])) {
            $grupos[$key] = ['doc' => $doc, 'otros' => 0, 'codigos' => [], 'tasas' => []];
        }
        $grupos[$key]['otros'] += (int) $doc['otros'];
        $codigo = trim((string) ($doc['otroImpCodigo'] ?? ''));
        $tasa = trim((string) ($doc['otroImpTasa'] ?? ''));
        if ($codigo !== '' && !in_array($codigo, $grupos[$key]['codigos'], true)) $grupos[$key]['codigos'][] = $codigo;
        if ($tasa !== '' && !in_array($tasa, $grupos[$key]['tasas'], true)) $grupos[$key]['tasas'][] = $tasa;
    }
    $resultado = [];
    foreach ($grupos as $grupo) {
        $doc = $grupo['doc'];
        $doc['otros'] = $grupo['otros'];
        $doc['otroImpCodigo'] = implode(',', $grupo['codigos']);
        $doc['otroImpTasa'] = implode(',', $grupo['tasas']);
        $resultado[] = $doc;
    }
    return $resultado;
}

function rcv_centralizacion_source_key(int $empresa, array $doc): string
{
    $identidad = [$empresa, $doc['periodo'], $doc['libro'], rut_limpio((string) $doc['rut']), (string) $doc['tipo'], trim((string) $doc['folio']), rcv_centralizacion_fecha((string) $doc['fecha'])];
    return hash('sha256', json_encode($identidad, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

function rcv_centralizacion_fingerprint(array $doc): string
{
    return hash('sha256', json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

function rcv_centralizacion_agregar_linea(array &$lineas, string $cuenta, string $glosa, string $lado, int $monto): void
{
    if ($monto <= 0) return;
    if ($cuenta === '') throw new RcvCentralizacionError('Falta configurar la cuenta para “' . $glosa . '”.');
    $lineas[] = ['cuenta' => $cuenta, 'glosa' => mb_substr($glosa, 0, 200), 'debe' => $lado === 'debe' ? $monto : 0, 'haber' => $lado === 'haber' ? $monto : 0];
}

function rcv_centralizacion_construir_voucher(array $doc, array $config, array $proveedorCuentas, string $registro, array $cuentasValidas): array
{
    $camposMonto = ['exento', 'neto', 'iva', 'ivaNoRec', 'ivaUsoComun', 'ivaRetenido', 'otros', 'total', 'netoActivoFijo', 'ivaActivoFijo', 'impSinCredito'];
    foreach ($camposMonto as $campo) {
        if ((int) $doc[$campo] < 0) throw new RcvCentralizacionError("El documento tiene un monto negativo en “$campo”; revisa el RCV antes de centralizarlo.");
    }
    $libro = $doc['libro'];
    $rut = rut_limpio((string) $doc['rut']);
    $fecha = rcv_centralizacion_fecha((string) $doc['fecha']);
    if ($rut === '' && empty($doc['resumen48'])) throw new RcvCentralizacionError('El documento no tiene RUT de contraparte.');
    if ($fecha === null) throw new RcvCentralizacionError('El documento no tiene una fecha válida.');
    if (trim((string) $doc['folio']) === '') throw new RcvCentralizacionError('El documento no tiene folio.');

    $exento = abs((int) $doc['exento']);
    $neto = abs((int) $doc['neto']);
    $iva = abs((int) $doc['iva']);
    $ivaUsoComun = abs((int) $doc['ivaUsoComun']);
    $otros = abs((int) $doc['otros']);
    $total = abs((int) $doc['total']);
    $noRec = abs((int) $doc['ivaNoRec']);
    $sinCredito = abs((int) $doc['impSinCredito']);
    $ivaRetenido = abs((int) $doc['ivaRetenido']);
    $lineas = [];
    $tipo = $libro === 'compras' ? 'E' : 'I';
    $nombre = trim((string) $doc['razon']);
    $ref = 'RCV ' . ucfirst($libro) . ' · Doc ' . $doc['tipo'] . ' folio ' . trim((string) $doc['folio']) . ($nombre !== '' ? ' · ' . $nombre : '') . ($rut !== '' ? ' · RUT ' . $rut : '');

    if ($libro === 'compras') {
        $naturaleza = rcv_centralizacion_normalizar((string) $doc['tipoOperacion']);
        if ($naturaleza === '' || $naturaleza === 'del giro') $cuentaBase = (string) $config['cuenta_compra_default'];
        else $cuentaBase = (string) ($config['cuentas_operacion'][$naturaleza] ?? $config['cuenta_compra_default']);
        $cuentaProveedor = (string) ($proveedorCuentas[$rut] ?? $config['cuenta_proveedor_default']);
        $brutoCalculado = $neto + $exento + $iva + $noRec + $ivaUsoComun + $otros + $sinCredito;
        if ($brutoCalculado !== $total) {
            $diferencia = $total - $brutoCalculado;
            throw new RcvCentralizacionError('Los componentes de compras suman $' . number_format($brutoCalculado, 0, ',', '.') . ' y el total del RCV es $' . number_format($total, 0, ',', '.') . ' (diferencia $' . number_format($diferencia, 0, ',', '.') . '). Revisa IVA de uso común, retenciones y otros impuestos.');
        }
        $base = $neto + $exento;
        rcv_centralizacion_agregar_linea($lineas, $cuentaBase, 'Neto y exento · ' . ($doc['tipoOperacion'] ?: 'Del Giro'), 'debe', $base);
        rcv_centralizacion_agregar_linea($lineas, (string) $config['cuenta_iva_credito'], 'IVA recuperable', 'debe', $iva);
        rcv_centralizacion_agregar_linea($lineas, (string) $config['cuenta_iva_uso_comun'], 'IVA de uso común', 'debe', $ivaUsoComun);
        rcv_centralizacion_agregar_linea($lineas, (string) $config['cuenta_iva_no_rec'], 'IVA no recuperable', 'debe', $noRec);
        rcv_centralizacion_agregar_linea($lineas, (string) $config['cuenta_otros_compra'], 'Otros impuestos y montos sin crédito', 'debe', $otros + $sinCredito);
        rcv_centralizacion_agregar_linea($lineas, $cuentaProveedor, 'Proveedor por pagar', 'haber', $total);
    } else {
        $brutoCalculado = $neto + $exento + $iva + $otros;
        $ajusteRedondeo = 0;
        if (!($brutoCalculado === $total + $ivaRetenido || $brutoCalculado === $total)) {
            if (!empty($doc['resumen48']) && $ivaRetenido === 0 && abs($brutoCalculado - $total) <= RCV_CENTRALIZACION_MAX_REDONDEO) {
                $ajusteRedondeo = $total - $brutoCalculado;
            } else {
                if (!empty($doc['resumen48'])) {
                    throw new RcvCentralizacionError('El resumen 48 difiere en $' . number_format(abs($total - $brutoCalculado), 0, ',', '.') . ', por sobre el máximo de redondeo de $' . RCV_CENTRALIZACION_MAX_REDONDEO . '. Revisa los montos del RCV.');
                }
                throw new RcvCentralizacionError('Los montos de venta e IVA retenido no coinciden con el total del RCV.');
            }
        }
        $montoCliente = $brutoCalculado === $total + $ivaRetenido ? $total : $total - $ivaRetenido;
        if ($montoCliente < 0) throw new RcvCentralizacionError('La retención de IVA supera el total de la venta.');
        rcv_centralizacion_agregar_linea($lineas, (string) $config['cuenta_cliente'], 'Cliente por cobrar', 'debe', $montoCliente);
        rcv_centralizacion_agregar_linea($lineas, (string) $config['cuenta_retencion_venta'], 'IVA retenido por recuperar', 'debe', $ivaRetenido);
        rcv_centralizacion_agregar_linea($lineas, (string) $config['cuenta_venta_default'], 'Neto y exento de venta', 'haber', $neto + $exento);
        rcv_centralizacion_agregar_linea($lineas, (string) $config['cuenta_iva_debito'], 'IVA débito fiscal', 'haber', $iva);
        rcv_centralizacion_agregar_linea($lineas, (string) $config['cuenta_otros_venta'], 'Otros impuestos de venta', 'haber', $otros);
        if ($ajusteRedondeo !== 0) {
            if (empty($config['cuenta_redondeo'])) throw new RcvCentralizacionError('Configura la cuenta “Diferencias de redondeo” para contabilizar el ajuste del resumen 48.');
            $lado = $ajusteRedondeo < 0 ? 'debe' : 'haber';
            rcv_centralizacion_agregar_linea($lineas, (string) $config['cuenta_redondeo'], 'Ajuste de redondeo resumen 48', $lado, abs($ajusteRedondeo));
        }
    }

    if (in_array((int) $doc['tipo'], RCV_NOTAS_CREDITO, true)) {
        foreach ($lineas as &$linea) [$linea['debe'], $linea['haber']] = [$linea['haber'], $linea['debe']];
        unset($linea);
    }
    $voucher = ['tipo' => $tipo, 'fecha' => $fecha, 'registro' => $registro, 'glosa' => mb_substr($ref, 0, 500), 'lineas' => $lineas];
    $validado = validarVoucher($voucher, array_keys($cuentasValidas));
    return $voucher + ['lineas' => $validado['lineas']];
}

function rcv_centralizacion_construir_pago_voucher(array $doc, array $voucher, string $cuentaPago, array $cuentas): array
{
    $tipoCuenta = null;
    foreach ($cuentas as $cuenta) if ((string) $cuenta['codigo'] === $cuentaPago) { $tipoCuenta = rcv_centralizacion_tipo_cuenta_pago($cuenta); break; }
    if ($tipoCuenta === null) throw new RcvCentralizacionError('La cuenta de pago debe pertenecer al activo y corresponder a una cuenta Caja o Banco.');
    $compra = ($doc['libro'] ?? '') === 'compras';
    $glosaContraparte = $compra ? 'Proveedor por pagar' : 'Cliente por cobrar';
    $contraparte = null;
    foreach ($voucher['lineas'] as $linea) if (str_starts_with((string) $linea['glosa'], $glosaContraparte)) { $contraparte = $linea; break; }
    if ($contraparte === null) throw new RcvCentralizacionError('No se encontró la cuenta de proveedor/cliente del voucher para generar su pago.');
    $monto = max((int) $contraparte['debe'], (int) $contraparte['haber']);
    if ($monto <= 0) throw new RcvCentralizacionError('El documento no tiene un saldo de proveedor/cliente para registrar como pago.');
    $ladoPago = (int) $contraparte['debe'] > 0 ? 'debe' : 'haber';
    $ladoContraparte = $ladoPago === 'debe' ? 'haber' : 'debe';
    $nombre = trim((string) ($doc['razon'] ?? ''));
    $ref = 'Pago RCV ' . ucfirst((string) $doc['libro']) . ' · Doc ' . ($doc['tipo'] ?? '') . ' folio ' . trim((string) ($doc['folio'] ?? ''));
    if ($nombre !== '') $ref .= ' · ' . $nombre;
    $lineas = [
        ['cuenta' => (string) $contraparte['cuenta'], 'glosa' => $glosaContraparte . ' · pago', 'debe' => $ladoContraparte === 'debe' ? $monto : 0, 'haber' => $ladoContraparte === 'haber' ? $monto : 0],
        ['cuenta' => $cuentaPago, 'glosa' => 'Pago con ' . ucfirst($tipoCuenta), 'debe' => $ladoPago === 'debe' ? $monto : 0, 'haber' => $ladoPago === 'haber' ? $monto : 0],
    ];
    return ['tipo' => $voucher['tipo'], 'fecha' => $voucher['fecha'], 'registro' => $voucher['registro'],
        'glosa' => mb_substr($ref, 0, 500), 'lineas' => $lineas];
}

/** Boletas vigentes de los libros elegidos; la clave estable no cambia si se corrigen sus montos o fecha. */
function rcv_centralizacion_honorarios_documentos(PDO $db, int $empresa, string $periodo, array $libros): array
{
    if (!$libros) return [];
    $marcas = implode(',', array_fill(0, count($libros), '?'));
    $st = $db->prepare('SELECT * FROM honorarios_boletas WHERE empresa_id = ? AND periodo = ? AND libro IN (' . $marcas . ') ORDER BY id');
    $st->execute(array_merge([$empresa, $periodo], $libros));
    $salida = [];
    foreach ($st->fetchAll() as $fila) {
        $boleta = hon_doc($fila);
        $libro = 'honorarios_' . (string) $fila['libro'];
        $folio = trim((string) $boleta['numero']);
        $rut = rut_limpio((string) $boleta['rut']);
        $doc = ['periodo' => (string) $fila['periodo'], 'tipo' => 'honorarios', 'libro' => $libro,
            'libro_honorarios' => (string) $fila['libro'], 'rut' => $rut, 'folio' => $folio,
            'fecha' => (string) $boleta['fecha'], 'fecha_iso' => hon_fecha_iso((string) $boleta['fecha']),
            'estado' => (string) $boleta['estado'], 'fecha_anulacion' => (string) $boleta['fechaAnulacion'],
            'contraparte' => trim((string) $boleta['nombre']), 'bruto' => (int) $boleta['bruto'],
            'retenido' => (int) $boleta['retenido'], 'pagado' => (int) $boleta['pagado']];
        $identidad = [$empresa, $doc['periodo'], $libro, $rut, $folio];
        $doc['source_key'] = hash('sha256', json_encode($identidad, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $doc['source_hash'] = hash('sha256', json_encode([$doc['fecha'], $doc['estado'], $doc['fecha_anulacion'], $doc['contraparte'], $doc['bruto'], $doc['retenido'], $doc['pagado']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $salida[$doc['source_key']] = $doc;
    }
    return array_values($salida);
}

function rcv_centralizacion_construir_honorario_voucher(array $doc, array $config, string $registro, array $cuentasValidas): array
{
    $bruto = (int) $doc['bruto']; $retenido = (int) $doc['retenido']; $pagado = (int) $doc['pagado'];
    if ($bruto < 0 || $retenido < 0 || $pagado < 0) throw new RcvCentralizacionError('La boleta contiene montos negativos; revisa el informe de honorarios.');
    if ($bruto <= 0 || $bruto !== $retenido + $pagado) {
        throw new RcvCentralizacionError('El bruto de la boleta debe coincidir con la retención más el líquido informado.');
    }
    if ($doc['fecha_iso'] === null) throw new RcvCentralizacionError('La boleta no tiene una fecha válida.');
    if ($doc['folio'] === '') throw new RcvCentralizacionError('La boleta no tiene número.');
    $emitida = $doc['libro_honorarios'] === 'emitidas';
    $lineas = [];
    if ($emitida) {
        rcv_centralizacion_agregar_linea($lineas, (string) $config['cuenta_hon_cliente'], 'Líquido por cobrar · Honorarios', 'debe', $pagado);
        rcv_centralizacion_agregar_linea($lineas, (string) $config['cuenta_hon_retencion_recuperar'], 'Retención de honorarios por recuperar', 'debe', $retenido);
        rcv_centralizacion_agregar_linea($lineas, (string) $config['cuenta_hon_emitidas'], 'Ingresos por honorarios', 'haber', $bruto);
    } else {
        rcv_centralizacion_agregar_linea($lineas, (string) $config['cuenta_hon_recibidas'], 'Gasto por honorarios', 'debe', $bruto);
        rcv_centralizacion_agregar_linea($lineas, (string) $config['cuenta_hon_retencion_pagar'], 'Retención de honorarios por pagar', 'haber', $retenido);
        rcv_centralizacion_agregar_linea($lineas, (string) $config['cuenta_hon_proveedor_pagar'], 'Honorarios líquidos por pagar', 'haber', $pagado);
    }
    $lado = $emitida ? 'emitida' : 'recibida';
    $glosa = 'Honorarios ' . $lado . ' · Boleta ' . $doc['folio'] . ($doc['contraparte'] !== '' ? ' · ' . $doc['contraparte'] : '') . ($doc['rut'] !== '' ? ' · RUT ' . $doc['rut'] : '');
    $voucher = ['tipo' => $emitida ? 'I' : 'E', 'fecha' => $doc['fecha_iso'], 'registro' => $registro,
        'glosa' => mb_substr($glosa, 0, 500), 'lineas' => $lineas];
    $validado = validarVoucher($voucher, array_keys($cuentasValidas));
    return $voucher + ['lineas' => $validado['lineas']];
}

/** Prepara un lote sin escribir vouchers; la misma rutina se vuelve a ejecutar dentro de la transacción al confirmar. */
function rcv_centralizacion_preparar(PDO $db, int $empresa, array $datos): array
{
    $desde = rcv_validar_periodo($datos['desde'] ?? null);
    $hasta = rcv_validar_periodo($datos['hasta'] ?? null);
    if ($desde !== $hasta) throw new RcvCentralizacionError('La centralización se realiza de a un período. Selecciona un solo mes y vuelve a generar la vista previa.');
    $libros = $datos['libros'] ?? null;
    if (!is_array($libros) || !array_is_list($libros)) throw new RcvCentralizacionError('La selección de libros RCV no es válida.');
    $libros = array_values(array_unique($libros));
    foreach ($libros as $libro) if (!is_string($libro) || !in_array($libro, RCV_LIBROS, true)) throw new RcvCentralizacionError('Libro RCV inválido.');
    $honorarios = $datos['honorarios'] ?? [];
    if (!is_array($honorarios) || !array_is_list($honorarios)) throw new RcvCentralizacionError('La selección de libros de honorarios no es válida.');
    $honorarios = array_values(array_unique($honorarios));
    foreach ($honorarios as $libro) if (!is_string($libro) || !in_array($libro, HON_LIBROS, true)) throw new RcvCentralizacionError('Libro de honorarios inválido.');
    if (!$libros && !$honorarios) throw new RcvCentralizacionError('Selecciona Compras, Ventas u Honorarios recibidas/emitidas.');
    $registro = $datos['registro'] ?? 'Tributario';
    if (!is_string($registro) || !in_array($registro, REGISTROS, true)) throw new RcvCentralizacionError('El registro contable debe ser Ambos, IFRS o Tributario.');
    $registrarPagos = $datos['registrar_pagos'] ?? false;
    if (!is_bool($registrarPagos)) throw new RcvCentralizacionError('La opción de registrar pagos no es válida.');
    $pagosSourceKeys = $datos['pagos_source_keys'] ?? [];
    if (!is_array($pagosSourceKeys) || !array_is_list($pagosSourceKeys) || count($pagosSourceKeys) > RCV_CENTRALIZACION_MAX_DOCUMENTOS) {
        throw new RcvCentralizacionError('La selección de documentos para pago no es válida.');
    }
    foreach ($pagosSourceKeys as $key) if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/', $key)) throw new RcvCentralizacionError('La selección de documentos para pago contiene una referencia inválida.');
    $pagosSourceKeys = array_values(array_unique($pagosSourceKeys));
    if (!$registrarPagos && $pagosSourceKeys) throw new RcvCentralizacionError('Activa la selección de conciliación antes de elegir documentos para pago.');
    $cuentaPago = $datos['cuenta_pago'] ?? '';
    $tipoCuentaPago = $datos['tipo_cuenta_pago'] ?? '';
    if ($pagosSourceKeys && (!is_string($cuentaPago) || !is_string($tipoCuentaPago) || !in_array($tipoCuentaPago, ['caja', 'banco'], true))) {
        throw new RcvCentralizacionError('Selecciona si los pagos saldrán de Caja o Banco y elige una cuenta.');
    }
    $seleccionPagos = array_fill_keys($pagosSourceKeys, true);

    $filas = [];
    if ($libros) {
        $marcas = implode(',', array_fill(0, count($libros), '?'));
        $sql = 'SELECT * FROM rcv_documentos WHERE empresa_id = ? AND periodo = ? AND libro IN (' . $marcas . ') ORDER BY periodo, libro, id LIMIT 20001';
        $st = $db->prepare($sql);
        $st->execute(array_merge([$empresa, $desde], $libros));
        $filas = $st->fetchAll();
    }
    if (count($filas) > 20000) throw new RcvCentralizacionError('El rango contiene más de 20.000 filas RCV. Divide el rango antes de centralizar.');
    $documentos = rcv_centralizacion_agrupar_filas($filas);
    if (count($documentos) > RCV_CENTRALIZACION_MAX_DOCUMENTOS) throw new RcvCentralizacionError('El rango contiene más de 1.000 documentos. Divide el rango antes de centralizar.');
    $encontrados = [];
    foreach ($filas as $fila) $encontrados[$fila['periodo'] . '|' . $fila['libro']] = true;
    $resumenes48 = [];
    if (in_array('ventas', $libros, true)) {
        $resumenSt = $db->prepare("SELECT periodo, SUM(documentos) AS documentos, SUM(exento) AS exento, SUM(neto) AS neto, SUM(iva) AS iva, SUM(total) AS total
            FROM rcv_resumenes WHERE empresa_id = ? AND libro = 'ventas' AND tipo = 48 AND periodo = ? GROUP BY periodo ORDER BY periodo");
        $resumenSt->execute([$empresa, $desde]);
        foreach ($resumenSt->fetchAll() as $resumen) {
            if ((int) $resumen['documentos'] <= 0) continue;
            $resumen['periodo'] = (string) $resumen['periodo'];
            $resumenes48[] = $resumen;
            $encontrados[$resumen['periodo'] . '|ventas'] = true;
        }
    }
    $documentosHonorarios = rcv_centralizacion_honorarios_documentos($db, $empresa, $desde, $honorarios);
    if (count($documentos) + count($documentosHonorarios) > RCV_CENTRALIZACION_MAX_DOCUMENTOS) throw new RcvCentralizacionError('La selección supera 1.000 documentos entre RCV y honorarios. Divide la operación antes de centralizar.');
    $honorariosEncontrados = [];
    foreach ($documentosHonorarios as $doc) if (!hon_anulada(['estado' => $doc['estado']])) $honorariosEncontrados[$doc['libro_honorarios']] = true;
    $periodosSinDatos = [];
    foreach ($libros as $libro) if (!isset($encontrados[$desde . '|' . $libro])) $periodosSinDatos[] = ['periodo' => $desde, 'libro' => $libro];
    foreach ($honorarios as $libro) if (!isset($honorariosEncontrados[$libro])) $periodosSinDatos[] = ['periodo' => $desde, 'libro' => 'honorarios ' . $libro];

    $config = rcv_centralizacion_config_leer($db, $empresa);
    $cuentasPlan = planCuentas($db, $empresa);
    $cuentas = array_column($cuentasPlan, 'codigo');
    $cuentasValidas = array_fill_keys($cuentas, true);
    if ($pagosSourceKeys) {
        $categoriaPago = null;
        foreach ($cuentasPlan as $cuenta) if ((string) $cuenta['codigo'] === $cuentaPago) { $categoriaPago = rcv_centralizacion_tipo_cuenta_pago($cuenta); break; }
        if ($categoriaPago === null || $categoriaPago !== $tipoCuentaPago) throw new RcvCentralizacionError('La cuenta seleccionada no corresponde a la categoría Caja/Banco indicada.');
    }
    $proveedorCuentas = [];
    $stCuentas = $db->prepare('SELECT rut, cuenta FROM rcv_proveedor_cuentas WHERE empresa_id = ?');
    $stCuentas->execute([$empresa]);
    foreach ($stCuentas->fetchAll() as $fila) $proveedorCuentas[rut_limpio((string) $fila['rut'])] = (string) $fila['cuenta'];
    $existentes = $db->prepare('SELECT source_hash, voucher_id FROM rcv_centralizaciones WHERE empresa_id = ? AND source_key = ?');
    $pagoExistente = $db->prepare('SELECT pago_voucher_id FROM rcv_centralizacion_pagos WHERE empresa_id = ? AND source_key = ?');

    $items = $errores = $repetidos = $cambiados = [];
    $noIncluir = 0;
    $pagosNuevos = 0;
    $pagosYaCentralizados = 0;
    $pagosDisponibles = [];
    $pagosSeleccionProcesados = [];
    $totales = ['compras' => 0, 'ventas' => 0, 'honorarios_recibidas' => 0, 'honorarios_emitidas' => 0];
    foreach ($documentos as $doc) {
        if ($doc['libro'] === 'compras' && rcv_centralizacion_normalizar((string) $doc['tipoOperacion']) === 'no incluir') {
            $noIncluir++;
            continue;
        }
        $sourceKey = rcv_centralizacion_source_key($empresa, $doc);
        $sourceHash = rcv_centralizacion_fingerprint($doc);
        $seleccionPago = isset($seleccionPagos[$sourceKey]);
        $existentes->execute([$empresa, $sourceKey]);
        $registroExistente = $existentes->fetch();
        if ($registroExistente) {
            if (hash_equals((string) $registroExistente['source_hash'], $sourceHash)) {
                $pagoExistente->execute([$empresa, $sourceKey]);
                $yaTienePago = $pagoExistente->fetchColumn() !== false;
                $repetidos[] = ['source_key' => $sourceKey, 'periodo' => $doc['periodo'], 'libro' => $doc['libro'], 'folio' => $doc['folio'], 'rut' => rut_limpio((string) $doc['rut']),
                    'contraparte' => trim((string) $doc['razon']), 'total' => abs((int) $doc['total']), 'pago_ya_centralizado' => $yaTienePago];
                if ($registrarPagos) $pagosDisponibles[] = ['source_key' => $sourceKey, 'periodo' => $doc['periodo'], 'libro' => $doc['libro'],
                    'folio' => trim((string) $doc['folio']), 'contraparte' => trim((string) $doc['razon']), 'total' => abs((int) $doc['total']), 'centralizado' => true, 'pago_ya_centralizado' => $yaTienePago];
                if ($registrarPagos) {
                    if ($yaTienePago) $pagosYaCentralizados++;
                    elseif ($seleccionPago) try {
                        $voucherOriginal = obtenerVoucher($db, (int) $registroExistente['voucher_id'], $empresa);
                        if (!$voucherOriginal) throw new RcvCentralizacionError('No se encontró el voucher principal ya contabilizado.');
                        $pago = rcv_centralizacion_construir_pago_voucher($doc, $voucherOriginal, $cuentaPago, $cuentasPlan);
                        $items[] = ['source_key' => $sourceKey, 'source_hash' => $sourceHash, 'periodo' => $doc['periodo'], 'libro' => $doc['libro'],
                            'rut' => rut_limpio((string) $doc['rut']), 'folio' => trim((string) $doc['folio']), 'voucher' => $voucherOriginal,
                            'voucher_id_existente' => (int) $registroExistente['voucher_id'], 'solo_pago' => true, 'pago_voucher' => $pago,
                            'total' => abs((int) $doc['total']), 'contraparte' => trim((string) $doc['razon'])];
                        $pagosNuevos++;
                        $pagosSeleccionProcesados[$sourceKey] = true;
                    } catch (Throwable $e) {
                        $errores[] = ['periodo' => $doc['periodo'], 'libro' => $doc['libro'], 'folio' => trim((string) $doc['folio']), 'rut' => rut_limpio((string) $doc['rut']), 'mensaje' => $e->getMessage()];
                    }
                }
            }
            else $cambiados[] = ['periodo' => $doc['periodo'], 'libro' => $doc['libro'], 'folio' => $doc['folio'], 'rut' => rut_limpio((string) $doc['rut'])];
            continue;
        }
        try {
            $voucher = rcv_centralizacion_construir_voucher($doc, $config, $proveedorCuentas, $registro, $cuentasValidas);
            $item = ['source_key' => $sourceKey, 'source_hash' => $sourceHash, 'periodo' => $doc['periodo'], 'libro' => $doc['libro'],
                'rut' => rut_limpio((string) $doc['rut']), 'folio' => trim((string) $doc['folio']), 'voucher' => $voucher,
                'total' => abs((int) $doc['total']), 'contraparte' => trim((string) $doc['razon']), 'pago_seleccionado' => $seleccionPago];
            if ($registrarPagos) $pagosDisponibles[] = ['source_key' => $sourceKey, 'periodo' => $doc['periodo'], 'libro' => $doc['libro'],
                'folio' => trim((string) $doc['folio']), 'contraparte' => trim((string) $doc['razon']), 'total' => abs((int) $doc['total']), 'centralizado' => false];
            if ($seleccionPago) { $item['pago_voucher'] = rcv_centralizacion_construir_pago_voucher($doc, $voucher, $cuentaPago, $cuentasPlan); $pagosNuevos++; $pagosSeleccionProcesados[$sourceKey] = true; }
            $items[] = $item;
            $totales[$doc['libro']] += $item['total'];
        } catch (Throwable $e) {
            $errores[] = ['periodo' => $doc['periodo'], 'libro' => $doc['libro'], 'folio' => trim((string) $doc['folio']), 'rut' => rut_limpio((string) $doc['rut']), 'mensaje' => $e->getMessage()];
        }
    }
    foreach ($documentosHonorarios as $doc) {
        $sourceKey = $doc['source_key'];
        $sourceHash = $doc['source_hash'];
        $existentes->execute([$empresa, $sourceKey]);
        $registroExistente = $existentes->fetch();
        if (hon_anulada(['estado' => $doc['estado']])) {
            if ($registroExistente && !hash_equals((string) $registroExistente['source_hash'], $sourceHash)) {
                $cambiados[] = ['tipo' => 'honorarios', 'periodo' => $doc['periodo'], 'libro' => $doc['libro'], 'folio' => $doc['folio'], 'rut' => $doc['rut']];
            }
            continue;
        }
        if ($registroExistente) {
            if (hash_equals((string) $registroExistente['source_hash'], $sourceHash)) {
                $repetidos[] = ['source_key' => $sourceKey, 'tipo' => 'honorarios', 'periodo' => $doc['periodo'], 'libro' => $doc['libro'], 'folio' => $doc['folio'],
                    'rut' => $doc['rut'], 'contraparte' => $doc['contraparte'], 'total' => $doc['bruto']];
            } else {
                $cambiados[] = ['tipo' => 'honorarios', 'periodo' => $doc['periodo'], 'libro' => $doc['libro'], 'folio' => $doc['folio'], 'rut' => $doc['rut']];
            }
            continue;
        }
        try {
            $voucher = rcv_centralizacion_construir_honorario_voucher($doc, $config, $registro, $cuentasValidas);
            $items[] = ['source_key' => $sourceKey, 'source_hash' => $sourceHash, 'tipo' => 'honorarios', 'periodo' => $doc['periodo'],
                'libro' => $doc['libro'], 'rut' => $doc['rut'], 'folio' => $doc['folio'], 'voucher' => $voucher,
                'total' => $doc['bruto'], 'contraparte' => $doc['contraparte']];
            $totales[$doc['libro']] += $doc['bruto'];
        } catch (Throwable $e) {
            $errores[] = ['tipo' => 'honorarios', 'periodo' => $doc['periodo'], 'libro' => $doc['libro'], 'folio' => $doc['folio'], 'rut' => $doc['rut'], 'mensaje' => $e->getMessage()];
        }
    }
    foreach ($resumenes48 as $resumen) {
        $periodo = $resumen['periodo'];
        $documento = [
            'periodo' => $periodo, 'libro' => 'ventas', 'tipo' => 48, 'rut' => '', 'resumen48' => true,
            'razon' => 'Comprobantes de pago electrónico', 'tipoOperacion' => 'Resumen mensual',
            'folio' => 'RESUMEN-48-' . str_replace('-', '', $periodo),
            'fecha' => date('Y-m-t', strtotime($periodo . '-01')),
            'exento' => (int) $resumen['exento'], 'neto' => (int) $resumen['neto'], 'iva' => (int) $resumen['iva'],
            'ivaNoRec' => 0, 'ivaUsoComun' => 0, 'ivaRetenido' => 0, 'otros' => 0, 'total' => (int) $resumen['total'],
            'netoActivoFijo' => 0, 'ivaActivoFijo' => 0, 'impSinCredito' => 0,
        ];
        $documento['otros'] = 0;
        $diferenciaResumen48 = $documento['total'] - $documento['exento'] - $documento['neto'] - $documento['iva'];
        $sourceKey = hash('sha256', json_encode([$empresa, $periodo, 'ventas', 'resumen-48'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $sourceHash = hash('sha256', json_encode([$periodo, 48, (int) $resumen['documentos'], $documento['exento'], $documento['neto'], $documento['iva'], $documento['total']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $seleccionPago = isset($seleccionPagos[$sourceKey]);
        $existentes->execute([$empresa, $sourceKey]);
        $registroExistente = $existentes->fetch();
        if ($registroExistente) {
            $ref = ['periodo' => $periodo, 'libro' => 'ventas', 'folio' => 'Resumen 48', 'rut' => ''];
            if (hash_equals((string) $registroExistente['source_hash'], $sourceHash)) {
                $pagoExistente->execute([$empresa, $sourceKey]);
                $yaTienePago = $pagoExistente->fetchColumn() !== false;
                $repetidos[] = $ref + ['source_key' => $sourceKey, 'contraparte' => 'Comprobantes de pago electrónico · ' . (int) $resumen['documentos'], 'total' => $documento['total'], 'pago_ya_centralizado' => $yaTienePago];
                if ($registrarPagos) $pagosDisponibles[] = ['source_key' => $sourceKey, 'periodo' => $periodo, 'libro' => 'ventas', 'folio' => 'Resumen 48',
                    'contraparte' => 'Comprobantes de pago electrónico · ' . (int) $resumen['documentos'], 'total' => $documento['total'], 'centralizado' => true, 'pago_ya_centralizado' => $yaTienePago];
                if ($registrarPagos) {
                    if ($yaTienePago) $pagosYaCentralizados++;
                    elseif ($seleccionPago) try {
                        $voucherOriginal = obtenerVoucher($db, (int) $registroExistente['voucher_id'], $empresa);
                        if (!$voucherOriginal) throw new RcvCentralizacionError('No se encontró el voucher principal ya contabilizado del resumen 48.');
                        $pago = rcv_centralizacion_construir_pago_voucher($documento, $voucherOriginal, $cuentaPago, $cuentasPlan);
                        $items[] = ['source_key' => $sourceKey, 'source_hash' => $sourceHash, 'periodo' => $periodo, 'libro' => 'ventas', 'rut' => '',
                            'folio' => 'Resumen 48', 'voucher' => $voucherOriginal, 'voucher_id_existente' => (int) $registroExistente['voucher_id'],
                            'solo_pago' => true, 'pago_voucher' => $pago, 'total' => $documento['total'],
                            'contraparte' => 'Comprobantes de pago electrónico · ' . (int) $resumen['documentos'], 'resumen_48' => true];
                        $pagosNuevos++;
                        $pagosSeleccionProcesados[$sourceKey] = true;
                    } catch (Throwable $e) {
                        $errores[] = ['periodo' => $periodo, 'libro' => 'ventas', 'folio' => 'Resumen 48', 'rut' => '', 'mensaje' => $e->getMessage()];
                    }
                }
            }
            else $cambiados[] = $ref;
            continue;
        }
        try {
            $voucher = rcv_centralizacion_construir_voucher($documento, $config, $proveedorCuentas, $registro, $cuentasValidas);
            $item = ['source_key' => $sourceKey, 'source_hash' => $sourceHash, 'periodo' => $periodo, 'libro' => 'ventas',
                'rut' => '', 'folio' => 'Resumen 48', 'voucher' => $voucher, 'total' => $documento['total'],
                'contraparte' => 'Comprobantes de pago electrónico · ' . (int) $resumen['documentos'] . ' documentos', 'resumen_48' => true,
                'ajuste_redondeo' => abs($diferenciaResumen48) <= RCV_CENTRALIZACION_MAX_REDONDEO ? $diferenciaResumen48 : 0, 'pago_seleccionado' => $seleccionPago];
            if ($registrarPagos) $pagosDisponibles[] = ['source_key' => $sourceKey, 'periodo' => $periodo, 'libro' => 'ventas', 'folio' => 'Resumen 48',
                'contraparte' => $item['contraparte'], 'total' => $documento['total'], 'centralizado' => false];
            if ($seleccionPago) { $item['pago_voucher'] = rcv_centralizacion_construir_pago_voucher($documento, $voucher, $cuentaPago, $cuentasPlan); $pagosNuevos++; $pagosSeleccionProcesados[$sourceKey] = true; }
            $items[] = $item;
            $totales['ventas'] += $documento['total'];
        } catch (Throwable $e) {
            $errores[] = ['periodo' => $periodo, 'libro' => 'ventas', 'folio' => 'Resumen 48', 'rut' => '', 'mensaje' => $e->getMessage()];
        }
    }
    $seleccionesInvalidas = array_values(array_diff($pagosSourceKeys, array_keys($pagosSeleccionProcesados)));
    if ($seleccionesInvalidas) throw new RcvCentralizacionError('Uno o más documentos seleccionados para pago ya no están disponibles o cambiaron desde la vista previa. Actualízala y vuelve a seleccionar.');
    return ['desde' => $desde, 'hasta' => $hasta, 'registro' => $registro, 'registrar_pagos' => $registrarPagos,
        'pagos_source_keys' => $pagosSourceKeys, 'pagos_disponibles' => $pagosDisponibles,
        'cuenta_pago' => $registrarPagos ? $cuentaPago : '', 'tipo_cuenta_pago' => $registrarPagos ? $tipoCuentaPago : '',
        'documentos_seleccionados' => count($documentos),
        'resumenes_48_seleccionados' => count($resumenes48),
        'vouchers_nuevos' => count(array_filter($items, static fn(array $item): bool => empty($item['solo_pago']))),
        'pagos_nuevos' => $pagosNuevos, 'pagos_ya_centralizados' => $pagosYaCentralizados,
        'vouchers_totales' => count(array_filter($items, static fn(array $item): bool => empty($item['solo_pago']))) + $pagosNuevos,
        'no_incluir' => $noIncluir, 'ya_centralizados' => count($repetidos), 'cambiados' => count($cambiados),
        'totales' => $totales, 'periodos_sin_datos' => $periodosSinDatos, 'repetidos' => $repetidos, 'cambiados_lista' => $cambiados, 'errores' => $errores, 'items' => $items];
}

function rcv_centralizacion_aplicar_ediciones(PDO $db, int $empresa, array $datos, array &$previa): void
{
    if (!array_key_exists('ediciones', $datos)) return;
    $ediciones = $datos['ediciones'];
    if (!is_array($ediciones) || !array_is_list($ediciones) || count($ediciones) !== count($previa['items'])) {
        throw new RcvCentralizacionError('La vista previa cambió. Vuelve a generarla antes de confirmar.');
    }
    $plan = planCuentas($db, $empresa);
    $codigos = array_column($plan, 'codigo');
    $pagosSeleccionados = array_fill_keys($previa['pagos_source_keys'] ?? [], true);
    $porClave = [];
    foreach ($previa['items'] as $i => $item) $porClave[$item['source_key']] = $i;
    $vistos = [];
    foreach ($ediciones as $edicion) {
        if (!is_array($edicion) || !is_string($edicion['source_key'] ?? null) || !isset($porClave[$edicion['source_key']])
            || isset($vistos[$edicion['source_key']])) {
            throw new RcvCentralizacionError('Una edición no corresponde a los vouchers de esta vista previa.');
        }
        $vistos[$edicion['source_key']] = true;
        $i = $porClave[$edicion['source_key']];
        if (empty($previa['items'][$i]['solo_pago'])) {
            if (!is_array($edicion['voucher'] ?? null)) throw new RcvCentralizacionError('Falta el voucher principal de un documento.');
            $previa['items'][$i]['voucher'] = validarVoucher($edicion['voucher'], $codigos);
        }
        $pago = $edicion['pago_voucher'] ?? null;
        $requierePago = !empty($pagosSeleccionados[$previa['items'][$i]['source_key']]);
        if ($requierePago) {
            if (!is_array($pago)) throw new RcvCentralizacionError('Falta el voucher de pago de un documento. Vuelve a generar la vista previa.');
            $pago = validarVoucher($pago, $codigos);
            $cuentaPago = (string) $previa['cuenta_pago'];
            if (!in_array($cuentaPago, array_column($pago['lineas'], 'cuenta'), true)) {
                throw new RcvCentralizacionError('Cada voucher de pago debe conservar la cuenta Caja/Banco seleccionada.');
            }
            if ($pago['tipo'] !== $previa['items'][$i]['voucher']['tipo']) {
                throw new RcvCentralizacionError('El voucher de pago debe conservar el tipo correspondiente al libro RCV.');
            }
            $prefijo = $previa['items'][$i]['libro'] === 'compras' ? 'Proveedor por pagar' : 'Cliente por cobrar';
            $lineaOrigen = null;
            foreach ($previa['items'][$i]['voucher']['lineas'] as $linea) if (str_starts_with((string) $linea['glosa'], $prefijo)) { $lineaOrigen = $linea; break; }
            if ($lineaOrigen === null) throw new RcvCentralizacionError('No se encontró la cuenta de contraparte del voucher principal.');
            $saldo = max((int) $lineaOrigen['debe'], (int) $lineaOrigen['haber']);
            $ladoOrigen = (int) $lineaOrigen['debe'] > 0 ? 'debe' : 'haber';
            $ladoPago = $ladoOrigen === 'debe' ? 'haber' : 'debe';
            $cuentaPagoTipo = null;
            foreach ($plan as $cuenta) if ((string) $cuenta['codigo'] === $cuentaPago) { $cuentaPagoTipo = rcv_centralizacion_tipo_cuenta_pago($cuenta); break; }
            if ($cuentaPagoTipo !== $previa['tipo_cuenta_pago']) throw new RcvCentralizacionError('La cuenta de pago ya no corresponde a Caja/Banco. Vuelve a generar la vista previa.');
            $montoContrapartePago = 0; $montoCajaBanco = 0;
            foreach ($pago['lineas'] as $linea) {
                if ($linea['cuenta'] === (string) $lineaOrigen['cuenta']) $montoContrapartePago += (int) $linea[$ladoPago];
                if ($linea['cuenta'] === $cuentaPago) $montoCajaBanco += (int) $linea[$ladoOrigen];
            }
            if ($montoContrapartePago !== $saldo || $montoCajaBanco !== $saldo) {
                throw new RcvCentralizacionError('El voucher de pago debe cancelar el saldo completo de la cuenta por pagar/cobrar usando la cuenta Caja/Banco elegida.');
            }
            $previa['items'][$i]['pago_voucher'] = $pago;
        } elseif ($pago !== null) {
            throw new RcvCentralizacionError('La vista previa incluye pagos no solicitados. Vuelve a generarla.');
        }
    }
}

function rcv_centralizacion_confirmar(PDO $db, int $empresa, array $datos): array
{
    if ($db->inTransaction()) throw new RuntimeException('La centralización necesita controlar su propia transacción.');
    $db->beginTransaction();
    try {
        $previa = rcv_centralizacion_preparar($db, $empresa, $datos);
        if ($previa['errores']) {
            $mensajes = array_slice(array_column($previa['errores'], 'mensaje'), 0, 8);
            throw new RcvCentralizacionError('No se guardó ningún voucher. Corrige estos problemas: ' . implode(' ', array_unique($mensajes)));
        }
        rcv_centralizacion_aplicar_ediciones($db, $empresa, $datos, $previa);
        $insertar = $db->prepare('INSERT INTO rcv_centralizaciones (empresa_id, source_key, source_hash, voucher_id, periodo, libro, rut, folio, creado_en) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insertarPago = $db->prepare('INSERT INTO rcv_centralizacion_pagos (empresa_id, source_key, pago_voucher_id, creado_en) VALUES (?, ?, ?, ?)');
        $guardados = [];
        $vouchersCreados = 0;
        $resumenes48Guardados = 0;
        $pagosGuardados = 0;
        foreach ($previa['items'] as $item) {
            if (!empty($item['solo_pago'])) {
                $voucher = obtenerVoucher($db, (int) $item['voucher_id_existente'], $empresa);
                if (!$voucher) throw new RcvCentralizacionError('El voucher principal ya contabilizado fue eliminado. No se guardó ningún pago.');
            } else {
                $voucher = guardarVoucher($db, $item['voucher'], null, $empresa);
                $insertar->execute([$empresa, $item['source_key'], $item['source_hash'], $voucher['id'], $item['periodo'], $item['libro'], $item['rut'], $item['folio'], gmdate('Y-m-d\TH:i:s\Z')]);
                $vouchersCreados++;
                if (!empty($item['resumen_48'])) $resumenes48Guardados++;
            }
            $pagoId = null;
            if (isset($item['pago_voucher'])) {
                $pago = guardarVoucher($db, $item['pago_voucher'], null, $empresa);
                $insertarPago->execute([$empresa, $item['source_key'], $pago['id'], gmdate('Y-m-d\TH:i:s\Z')]);
                $pagoId = $pago['id'];
                $pagosGuardados++;
            }
            $guardados[] = ['voucher_id' => $voucher['id'], 'pago_voucher_id' => $pagoId, 'periodo' => $item['periodo'], 'libro' => $item['libro'], 'folio' => $item['folio'], 'numero' => $voucher['numero']];
        }
        $db->commit();
        return ['creados' => $vouchersCreados, 'pagos_creados' => $pagosGuardados, 'vouchers_creados' => $vouchersCreados + $pagosGuardados,
            'resumenes_48_centralizados' => $resumenes48Guardados, 'ya_centralizados' => $previa['ya_centralizados'], 'cambiados' => $previa['cambiados'],
            'no_incluir' => $previa['no_incluir'], 'vouchers' => $guardados];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
