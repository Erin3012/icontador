<?php
// Empresas: listar, crear, y exportar/importar una empresa con todos sus datos en un archivo JSON
// (para pasar una empresa de la base SQLite local a la base MySQL del sitio publicado).
declare(strict_types=1);

require_once __DIR__ . '/vouchers.php';
require_once __DIR__ . '/rcv.php';
require_once __DIR__ . '/liquidaciones.php';

const PAQUETE_FORMATO = 'icontador-empresa';
const EMPRESA_CAMPOS = ['rut' => 12, 'giro' => 120, 'regimen' => 60, 'telefono' => 30, 'email' => 120];
// Columnas que viajan en el paquete, sin id ni empresa_id (se asignan al importar).
const PAQUETE_COLUMNAS = [
    'vouchers' => ['tipo', 'periodo', 'numero', 'fecha', 'registro', 'glosa', 'creado', 'modificado'],
    'voucher_lineas' => ['orden', 'cuenta', 'glosa', 'debe', 'haber'],
    'rcv_documentos' => ['periodo', 'libro', 'archivo', 'tipo_doc', 'tipo_operacion', 'rut', 'razon_social', 'folio', 'fecha', 'exento', 'neto', 'iva', 'iva_no_rec', 'iva_uso_comun', 'iva_retenido', 'otros', 'total'],
    'liquidaciones' => ['periodo', 'trabajador', 'sueldo_base', 'gratificacion', 'imponible', 'total_haberes', 'afp', 'salud', 'afc', 'impuesto', 'total_descuentos', 'liquido', 'costo_empresa', 'detalle'],
];

function empresas_esquema_completo(PDO $db): void
{
    empresas_schema($db);
    crearEsquema($db);
    rcv_schema($db);
    liq_schema($db);
}

// Los datos de la ficha (RUT, régimen, contacto) vienen del importador de iContador o de "Crear Empresa".
function empresas_listar(PDO $db): array
{
    $filas = $db->query(
        'SELECT e.id, e.origen_id, e.razon_social, e.datos_json,
                (SELECT COUNT(*) FROM importacion_vistas v WHERE v.empresa_id = e.id) AS vistas,
                (SELECT COUNT(*) FROM importacion_registros r WHERE r.empresa_id = e.id) AS registros,
                (SELECT COUNT(*) FROM cuentas c WHERE c.empresa_id = e.id) AS cuentas,
                (SELECT COUNT(*) FROM vouchers v WHERE v.empresa_id = e.id) AS vouchers
           FROM empresas e ORDER BY e.razon_social'
    )->fetchAll();
    return array_map(function ($f) {
        $datos = json_decode((string) $f['datos_json'], true) ?: [];
        $empresa = ['id' => (int) $f['id'], 'origen_id' => $f['origen_id'], 'razon_social' => $f['razon_social']];
        foreach (array_keys(EMPRESA_CAMPOS) as $campo) {
            $empresa[$campo] = is_scalar($datos[$campo] ?? null) ? (string) $datos[$campo] : '';
        }
        foreach (['vistas', 'registros', 'cuentas', 'vouchers'] as $n) {
            $empresa[$n] = (int) $f[$n];
        }
        return $empresa;
    }, $filas);
}

// RUT chileno con dígito verificador (módulo 11). Devuelve el RUT normalizado como 12345678-K.
function normalizarRut(string $rut): ?string
{
    $limpio = strtoupper(preg_replace('/[.\s]/', '', $rut));
    if (!preg_match('/^(\d{1,8})-?([\dK])$/', $limpio, $m)) {
        return null;
    }
    $suma = 0;
    $factor = 2;
    for ($i = strlen($m[1]) - 1; $i >= 0; $i--) {
        $suma += (int) $m[1][$i] * $factor;
        $factor = $factor === 7 ? 2 : $factor + 1;
    }
    $dv = 11 - $suma % 11;
    $esperado = $dv === 11 ? '0' : ($dv === 10 ? 'K' : (string) $dv);
    return $esperado === $m[2] ? ltrim($m[1], '0') . '-' . $m[2] : null;
}

function empresa_crear(PDO $db, array $datos): array
{
    $razon = trim((string) ($datos['razon_social'] ?? ''));
    $errores = [];
    if ($razon === '') {
        $errores[] = 'Ingrese la razón social.';
    }
    $ficha = [];
    foreach (EMPRESA_CAMPOS as $campo => $largo) {
        $ficha[$campo] = mb_substr(trim(is_scalar($datos[$campo] ?? null) ? (string) $datos[$campo] : ''), 0, $largo);
    }
    if ($ficha['rut'] !== '') {
        $rut = normalizarRut($ficha['rut']);
        if ($rut === null) {
            $errores[] = 'El RUT no es válido; revise el dígito verificador.';
        } else {
            $ficha['rut'] = $rut;
            $existe = array_filter(empresas_listar($db), fn($e) => $e['rut'] === $rut);
            if ($existe) {
                $errores[] = "Ya existe una empresa con el RUT $rut.";
            }
        }
    }
    if ($ficha['email'] !== '' && !filter_var($ficha['email'], FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'El e-mail no es válido.';
    }
    if ($errores) {
        throw new ErrorValidacion($errores);
    }
    $db->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')
        ->execute(['local:' . bin2hex(random_bytes(8)), mb_substr($razon, 0, 191), json_encode(['razon_social' => $razon] + $ficha, JSON_UNESCAPED_UNICODE), gmdate('c')]);
    $id = (int) $db->lastInsertId();
    $agregadas = !empty($datos['plan_ejemplo']) ? cargarPlanEjemplo($db, $id) : 0;
    $empresa = array_values(array_filter(empresas_listar($db), fn($e) => $e['id'] === $id))[0];
    return ['empresa' => $empresa, 'cuentas_agregadas' => $agregadas];
}

function filas_empresa(PDO $db, string $tabla, int $empresa): array
{
    $columnas = PAQUETE_COLUMNAS[$tabla];
    $consulta = $db->prepare('SELECT ' . implode(', ', $columnas) . " FROM $tabla WHERE empresa_id = ? ORDER BY id");
    $consulta->execute([$empresa]);
    return $consulta->fetchAll();
}

function empresa_exportar(PDO $db, int $id): ?array
{
    $consulta = $db->prepare('SELECT * FROM empresas WHERE id = ?');
    $consulta->execute([$id]);
    $empresa = $consulta->fetch();
    if (!$empresa) {
        return null;
    }
    $vistas = $db->prepare('SELECT vista, datos_json, actualizado FROM importacion_vistas WHERE empresa_id = ? ORDER BY id');
    $vistas->execute([$id]);
    $registros = $db->prepare('SELECT vista, tabla, huella, datos_json FROM importacion_registros WHERE empresa_id = ? ORDER BY id');
    $registros->execute([$id]);
    $vouchers = [];
    $ids = $db->prepare('SELECT id FROM vouchers WHERE empresa_id = ? ORDER BY id');
    $ids->execute([$id]);
    $lineas = $db->prepare('SELECT ' . implode(', ', PAQUETE_COLUMNAS['voucher_lineas']) . ' FROM voucher_lineas WHERE voucher_id = ? ORDER BY orden');
    $cabecera = $db->prepare('SELECT ' . implode(', ', PAQUETE_COLUMNAS['vouchers']) . ' FROM vouchers WHERE id = ?');
    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $vid) {
        $cabecera->execute([$vid]);
        $lineas->execute([$vid]);
        $vouchers[] = $cabecera->fetch() + ['lineas' => $lineas->fetchAll()];
    }
    return [
        'formato' => PAQUETE_FORMATO, 'version' => 1, 'exportado' => gmdate('c'),
        'empresa' => ['origen_id' => $empresa['origen_id'], 'razon_social' => $empresa['razon_social'], 'datos' => json_decode((string) $empresa['datos_json'], true) ?: new stdClass()],
        'vistas' => $vistas->fetchAll(), 'registros' => $registros->fetchAll(), 'cuentas' => planCuentas($db, $id), 'vouchers' => $vouchers,
        'rcv' => filas_empresa($db, 'rcv_documentos', $id), 'liquidaciones' => filas_empresa($db, 'liquidaciones', $id),
    ];
}

function paquete_lista(array $paquete, string $clave): array
{
    $lista = $paquete[$clave] ?? [];
    if (!is_array($lista) || !array_is_list($lista)) {
        throw new ErrorValidacion(["El archivo no es válido: \"$clave\" debe ser una lista."]);
    }
    foreach ($lista as $fila) {
        if (!is_array($fila)) {
            throw new ErrorValidacion(["El archivo no es válido: \"$clave\" contiene filas incorrectas."]);
        }
    }
    return $lista;
}

function valores(array $fila, array $columnas): array
{
    return array_map(fn($c) => is_array($fila[$c] ?? null) ? json_encode($fila[$c], JSON_UNESCAPED_UNICODE) : ($fila[$c] ?? null), $columnas);
}

function insertar_filas(PDO $db, string $tabla, int $empresa, array $filas): void
{
    $columnas = PAQUETE_COLUMNAS[$tabla];
    $insertar = $db->prepare("INSERT INTO $tabla (empresa_id, " . implode(', ', $columnas) . ') VALUES (?' . str_repeat(', ?', count($columnas)) . ')');
    foreach ($filas as $fila) {
        $insertar->execute(array_merge([$empresa], valores($fila, $columnas)));
    }
}

// Crea la empresa o, si ya existe con el mismo origen, reemplaza todos sus datos por los del archivo.
function empresa_importar(PDO $db, array $paquete): array
{
    if (($paquete['formato'] ?? null) !== PAQUETE_FORMATO || !is_array($paquete['empresa'] ?? null)) {
        throw new ErrorValidacion(['El archivo no es una exportación de empresa de iContador local.']);
    }
    $origen = trim((string) ($paquete['empresa']['origen_id'] ?? ''));
    $razon = trim((string) ($paquete['empresa']['razon_social'] ?? ''));
    if ($origen === '' || $razon === '') {
        throw new ErrorValidacion(['El archivo no trae la identificación de la empresa.']);
    }
    $listas = [];
    foreach (['vistas', 'registros', 'cuentas', 'vouchers', 'rcv', 'liquidaciones'] as $clave) {
        $listas[$clave] = paquete_lista($paquete, $clave);
    }
    foreach ($listas['vouchers'] as $v) {
        paquete_lista($v, 'lineas');
    }
    $datos = json_encode($paquete['empresa']['datos'] ?? new stdClass(), JSON_UNESCAPED_UNICODE);
    $db->beginTransaction();
    try {
        $buscar = $db->prepare('SELECT id FROM empresas WHERE origen_id = ?');
        $buscar->execute([$origen]);
        $id = $buscar->fetchColumn();
        if ($id) {
            $id = (int) $id;
            $db->prepare('UPDATE empresas SET razon_social = ?, datos_json = ?, actualizado = ? WHERE id = ?')->execute([$razon, $datos, gmdate('c'), $id]);
            $db->prepare('DELETE FROM voucher_lineas WHERE voucher_id IN (SELECT id FROM vouchers WHERE empresa_id = ?)')->execute([$id]);
            foreach (['vouchers', 'cuentas', 'rcv_documentos', 'liquidaciones', 'importacion_registros', 'importacion_vistas'] as $tabla) {
                $db->prepare("DELETE FROM $tabla WHERE empresa_id = ?")->execute([$id]);
            }
        } else {
            $db->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')->execute([$origen, $razon, $datos, gmdate('c')]);
            $id = (int) $db->lastInsertId();
        }
        $vista = $db->prepare('INSERT INTO importacion_vistas (empresa_id, vista, datos_json, actualizado) VALUES (?, ?, ?, ?)');
        foreach ($listas['vistas'] as $f) {
            $vista->execute(array_merge([$id], valores($f, ['vista', 'datos_json', 'actualizado'])));
        }
        $registro = $db->prepare('INSERT INTO importacion_registros (empresa_id, vista, tabla, huella, datos_json) VALUES (?, ?, ?, ?, ?)');
        foreach ($listas['registros'] as $f) {
            $registro->execute(array_merge([$id], valores($f, ['vista', 'tabla', 'huella', 'datos_json'])));
        }
        $cuenta = $db->prepare('INSERT INTO cuentas (empresa_id, codigo, nombre) VALUES (?, ?, ?)');
        foreach ($listas['cuentas'] as $f) {
            $cuenta->execute(array_merge([$id], valores($f, ['codigo', 'nombre'])));
        }
        $voucher = $db->prepare('INSERT INTO vouchers (empresa_id, ' . implode(', ', PAQUETE_COLUMNAS['vouchers']) . ') VALUES (?' . str_repeat(', ?', count(PAQUETE_COLUMNAS['vouchers'])) . ')');
        $linea = $db->prepare('INSERT INTO voucher_lineas (voucher_id, ' . implode(', ', PAQUETE_COLUMNAS['voucher_lineas']) . ') VALUES (?' . str_repeat(', ?', count(PAQUETE_COLUMNAS['voucher_lineas'])) . ')');
        foreach ($listas['vouchers'] as $v) {
            $voucher->execute(array_merge([$id], valores($v, PAQUETE_COLUMNAS['vouchers'])));
            $vid = (int) $db->lastInsertId();
            foreach ($v['lineas'] as $l) {
                $linea->execute(array_merge([$vid], valores($l, PAQUETE_COLUMNAS['voucher_lineas'])));
            }
        }
        insertar_filas($db, 'rcv_documentos', $id, $listas['rcv']);
        insertar_filas($db, 'liquidaciones', $id, $listas['liquidaciones']);
        $db->commit();
    } catch (PDOException $e) {
        $db->rollBack();
        error_log('importar empresa: ' . $e->getMessage());
        throw new ErrorValidacion(['El archivo tiene datos incompletos o repetidos y no se importó nada.']);
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return ['empresa' => array_values(array_filter(empresas_listar($db), fn($e) => $e['id'] === $id))[0],
        'importado' => array_map('count', $listas)];
}
