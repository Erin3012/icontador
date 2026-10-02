<?php
// Lógica contable compartida por los endpoints de la API: esquema, vouchers y libros.
declare(strict_types=1);

const TIPOS = ['I' => 'Ingreso', 'E' => 'Egreso', 'T' => 'Traspaso'];
const REGISTROS = ['Ambos', 'IFRS', 'Tributario'];
const PLAN_BASE = [
    ['1.1.01', 'Caja'], ['1.1.02', 'Banco'], ['1.1.03', 'Clientes'], ['1.1.04', 'Documentos por cobrar'],
    ['1.1.05', 'IVA Crédito Fiscal'], ['1.1.06', 'PPM por recuperar'], ['1.1.07', 'Mercaderías'],
    ['1.2.01', 'Muebles y útiles'], ['1.2.02', 'Equipos computacionales'], ['1.2.03', 'Vehículos'], ['1.2.09', 'Depreciación acumulada'],
    ['2.1.01', 'Proveedores'], ['2.1.02', 'IVA Débito Fiscal'], ['2.1.03', 'Retenciones por pagar'], ['2.1.04', 'Remuneraciones por pagar'],
    ['2.1.05', 'Leyes sociales por pagar'], ['2.1.06', 'Préstamos bancarios'],
    ['2.3.01', 'Capital'], ['2.3.02', 'Resultados acumulados'], ['2.3.03', 'Resultado del ejercicio'],
    ['3.1.01', 'Costo de ventas'], ['3.1.02', 'Remuneraciones'], ['3.1.03', 'Honorarios'], ['3.1.04', 'Arriendos'],
    ['3.1.05', 'Gastos generales'], ['3.1.06', 'Depreciación del ejercicio'], ['3.1.07', 'Gastos financieros'],
    ['4.1.01', 'Ventas'], ['4.1.02', 'Otros ingresos'], ['4.1.03', 'Ingresos financieros'],
];

class ErrorValidacion extends Exception
{
    public function __construct(public array $errores)
    {
        parent::__construct(implode(' ', $errores));
    }
}

function conectar(?array $config = null): PDO
{
    $config ??= require __DIR__ . '/config.php';
    if (str_starts_with($config['dsn'], 'sqlite:') && $config['dsn'] !== 'sqlite::memory:') {
        $dir = dirname(substr($config['dsn'], 7));
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
    $pdo = new PDO($config['dsn'], $config['user'] ?? null, $config['pass'] ?? null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    crearEsquema($pdo);
    return $pdo;
}

function crearEsquema(PDO $pdo): void
{
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $id = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $motor = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    if (!$mysql) {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS cuentas (
        codigo VARCHAR(20) NOT NULL PRIMARY KEY,
        nombre VARCHAR(120) NOT NULL
    )$motor");
    $pdo->exec("CREATE TABLE IF NOT EXISTS vouchers (
        id $id,
        tipo CHAR(1) NOT NULL,
        periodo CHAR(7) NOT NULL,
        numero INT NOT NULL,
        fecha DATE NOT NULL,
        registro VARCHAR(12) NOT NULL,
        glosa VARCHAR(500) NOT NULL DEFAULT '',
        creado VARCHAR(25) NOT NULL,
        modificado VARCHAR(25) NOT NULL,
        UNIQUE (tipo, periodo, numero)
    )$motor");
    $pdo->exec("CREATE TABLE IF NOT EXISTS voucher_lineas (
        id $id,
        voucher_id INT NOT NULL,
        orden INT NOT NULL,
        cuenta VARCHAR(20) NOT NULL,
        glosa VARCHAR(200) NOT NULL DEFAULT '',
        debe BIGINT NOT NULL DEFAULT 0,
        haber BIGINT NOT NULL DEFAULT 0,
        FOREIGN KEY (voucher_id) REFERENCES vouchers(id) ON DELETE CASCADE,
        FOREIGN KEY (cuenta) REFERENCES cuentas(codigo)
    )$motor");
    if ((int) $pdo->query('SELECT COUNT(*) FROM cuentas')->fetchColumn() === 0) {
        $insertar = $pdo->prepare('INSERT INTO cuentas (codigo, nombre) VALUES (?, ?)');
        foreach (PLAN_BASE as $cuenta) {
            $insertar->execute($cuenta);
        }
    }
}

function planCuentas(PDO $pdo): array
{
    return $pdo->query('SELECT codigo, nombre FROM cuentas ORDER BY codigo')->fetchAll();
}

function monto(mixed $valor): ?int
{
    if ($valor === null || $valor === '') {
        return 0;
    }
    if (is_int($valor)) {
        return $valor;
    }
    if (is_float($valor) && floor($valor) === $valor) {
        return (int) $valor;
    }
    return is_string($valor) && preg_match('/^\d+$/', trim($valor)) ? (int) trim($valor) : null;
}

function fechaValida(mixed $fecha): bool
{
    if (!is_string($fecha) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fecha, $m)) {
        return false;
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

// Devuelve el voucher normalizado o lanza ErrorValidacion con todos los problemas encontrados.
function validarVoucher(array $datos, array $codigos): array
{
    $errores = [];
    $tipo = $datos['tipo'] ?? '';
    $fecha = $datos['fecha'] ?? '';
    $registro = $datos['registro'] ?? '';
    if (!isset(TIPOS[$tipo])) {
        $errores[] = 'Seleccione el tipo de comprobante.';
    }
    if (!fechaValida($fecha)) {
        $errores[] = 'Ingrese una fecha válida.';
    }
    if (!in_array($registro, REGISTROS, true)) {
        $errores[] = 'Seleccione el tipo de voucher (Ambos, IFRS o Tributario).';
    }
    $lineas = is_array($datos['lineas'] ?? null) ? array_values($datos['lineas']) : [];
    if (count($lineas) < 2) {
        $errores[] = 'El voucher necesita al menos dos líneas.';
    }
    $debe = $haber = 0;
    $normalizadas = [];
    foreach ($lineas as $i => $linea) {
        $n = $i + 1;
        $d = monto($linea['debe'] ?? 0);
        $h = monto($linea['haber'] ?? 0);
        $cuenta = (string) ($linea['cuenta'] ?? '');
        if (!in_array($cuenta, $codigos, true)) {
            $errores[] = "Línea $n: seleccione una cuenta.";
        }
        if ($d === null || $h === null) {
            $errores[] = "Línea $n: los montos deben ser números enteros positivos.";
            continue;
        }
        if (($d > 0) === ($h > 0)) {
            $errores[] = "Línea $n: ingrese un monto en Debe o en Haber, no en ambos.";
        }
        $debe += $d;
        $haber += $h;
        $normalizadas[] = ['cuenta' => $cuenta, 'glosa' => mb_substr(trim((string) ($linea['glosa'] ?? '')), 0, 200), 'debe' => $d, 'haber' => $h];
    }
    if ($debe !== $haber) {
        $errores[] = sprintf('El voucher está descuadrado: Debe %s y Haber %s (diferencia %s).', formato($debe), formato($haber), formato(abs($debe - $haber)));
    } elseif ($debe === 0 && count($lineas) >= 2) {
        $errores[] = 'Los totales no pueden ser cero.';
    }
    if ($errores) {
        throw new ErrorValidacion($errores);
    }
    return ['tipo' => $tipo, 'fecha' => $fecha, 'registro' => $registro, 'glosa' => mb_substr(trim((string) ($datos['glosa'] ?? '')), 0, 500), 'lineas' => $normalizadas];
}

function formato(int $n): string
{
    return number_format($n, 0, ',', '.');
}

function obtenerVoucher(PDO $pdo, int $id): ?array
{
    $consulta = $pdo->prepare('SELECT * FROM vouchers WHERE id = ?');
    $consulta->execute([$id]);
    $voucher = $consulta->fetch();
    if (!$voucher) {
        return null;
    }
    $lineas = $pdo->prepare('SELECT cuenta, glosa, debe, haber FROM voucher_lineas WHERE voucher_id = ? ORDER BY orden');
    $lineas->execute([$id]);
    return formatearVoucher($voucher, $lineas->fetchAll());
}

function formatearVoucher(array $v, array $lineas): array
{
    $lineas = array_map(fn($l) => ['cuenta' => $l['cuenta'], 'glosa' => $l['glosa'], 'debe' => (int) $l['debe'], 'haber' => (int) $l['haber']], $lineas);
    return [
        'id' => (int) $v['id'], 'tipo' => $v['tipo'], 'tipoNombre' => TIPOS[$v['tipo']], 'numero' => (int) $v['numero'],
        'fecha' => substr((string) $v['fecha'], 0, 10), 'registro' => $v['registro'], 'glosa' => $v['glosa'],
        'creado' => $v['creado'], 'modificado' => $v['modificado'], 'lineas' => $lineas,
        'debe' => array_sum(array_column($lineas, 'debe')), 'haber' => array_sum(array_column($lineas, 'haber')),
    ];
}

function listarVouchers(PDO $pdo, array $filtros = []): array
{
    [$where, $params] = condiciones($filtros);
    if (($filtros['numero'] ?? '') !== '') {
        $where[] = 'v.numero = ?';
        $params[] = (int) $filtros['numero'];
    }
    $sql = 'SELECT v.*, l.cuenta, l.glosa AS lglosa, l.debe, l.haber FROM vouchers v JOIN voucher_lineas l ON l.voucher_id = v.id'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY v.fecha, v.tipo, v.numero, l.orden';
    $consulta = $pdo->prepare($sql);
    $consulta->execute($params);
    $agrupados = [];
    foreach ($consulta as $fila) {
        $agrupados[$fila['id']] ??= ['voucher' => $fila, 'lineas' => []];
        $agrupados[$fila['id']]['lineas'][] = ['cuenta' => $fila['cuenta'], 'glosa' => $fila['lglosa'], 'debe' => $fila['debe'], 'haber' => $fila['haber']];
    }
    return array_values(array_map(fn($g) => formatearVoucher($g['voucher'], $g['lineas']), $agrupados));
}

function condiciones(array $filtros): array
{
    $where = [];
    $params = [];
    if (fechaValida($filtros['desde'] ?? null)) {
        $where[] = 'v.fecha >= ?';
        $params[] = $filtros['desde'];
    }
    if (fechaValida($filtros['hasta'] ?? null)) {
        $where[] = 'v.fecha <= ?';
        $params[] = $filtros['hasta'];
    }
    if (in_array($filtros['registro'] ?? '', ['IFRS', 'Tributario'], true)) {
        $where[] = "(v.registro = 'Ambos' OR v.registro = ?)";
        $params[] = $filtros['registro'];
    }
    return [$where, $params];
}

// Crea un voucher (sin $id) o reemplaza uno existente. El número es correlativo por tipo y mes.
function guardarVoucher(PDO $pdo, array $datos, ?int $id = null): array
{
    $codigos = array_column(planCuentas($pdo), 'codigo');
    $v = validarVoucher($datos, $codigos);
    $periodo = substr($v['fecha'], 0, 7);
    $ahora = gmdate('Y-m-d\TH:i:s\Z');
    $pdo->beginTransaction();
    try {
        $previo = null;
        if ($id !== null) {
            $previo = $pdo->prepare('SELECT * FROM vouchers WHERE id = ?');
            $previo->execute([$id]);
            $previo = $previo->fetch() ?: null;
            if (!$previo) {
                throw new ErrorValidacion(['El voucher no existe.']);
            }
        }
        if ($previo && $previo['tipo'] === $v['tipo'] && $previo['periodo'] === $periodo) {
            $numero = (int) $previo['numero'];
        } else {
            $max = $pdo->prepare('SELECT COALESCE(MAX(numero), 0) FROM vouchers WHERE tipo = ? AND periodo = ?');
            $max->execute([$v['tipo'], $periodo]);
            $numero = (int) $max->fetchColumn() + 1;
        }
        if ($previo) {
            $pdo->prepare('UPDATE vouchers SET tipo = ?, periodo = ?, numero = ?, fecha = ?, registro = ?, glosa = ?, modificado = ? WHERE id = ?')
                ->execute([$v['tipo'], $periodo, $numero, $v['fecha'], $v['registro'], $v['glosa'], $ahora, $id]);
            $pdo->prepare('DELETE FROM voucher_lineas WHERE voucher_id = ?')->execute([$id]);
        } else {
            $pdo->prepare('INSERT INTO vouchers (tipo, periodo, numero, fecha, registro, glosa, creado, modificado) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$v['tipo'], $periodo, $numero, $v['fecha'], $v['registro'], $v['glosa'], $ahora, $ahora]);
            $id = (int) $pdo->lastInsertId();
        }
        $insertar = $pdo->prepare('INSERT INTO voucher_lineas (voucher_id, orden, cuenta, glosa, debe, haber) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($v['lineas'] as $i => $l) {
            $insertar->execute([$id, $i + 1, $l['cuenta'], $l['glosa'], $l['debe'], $l['haber']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return obtenerVoucher($pdo, $id);
}

function eliminarVoucher(PDO $pdo, int $id): bool
{
    $pdo->prepare('DELETE FROM voucher_lineas WHERE voucher_id = ?')->execute([$id]);
    $consulta = $pdo->prepare('DELETE FROM vouchers WHERE id = ?');
    $consulta->execute([$id]);
    return $consulta->rowCount() > 0;
}

function libroDiario(PDO $pdo, array $filtros = []): array
{
    $nombres = array_column(planCuentas($pdo), 'nombre', 'codigo');
    $asientos = array_map(function ($v) use ($nombres) {
        $v['lineas'] = array_map(fn($l) => $l + ['nombre' => $nombres[$l['cuenta']] ?? $l['cuenta']], $v['lineas']);
        return $v;
    }, listarVouchers($pdo, ['desde' => $filtros['desde'] ?? null, 'hasta' => $filtros['hasta'] ?? null, 'registro' => $filtros['registro'] ?? null]));
    return ['asientos' => $asientos, 'debe' => array_sum(array_column($asientos, 'debe')), 'haber' => array_sum(array_column($asientos, 'haber'))];
}

function libroMayor(PDO $pdo, array $filtros = []): array
{
    $desde = fechaValida($filtros['desde'] ?? null) ? $filtros['desde'] : null;
    $cuenta = (string) ($filtros['cuenta'] ?? '');
    $porCuenta = [];
    // Se leen los movimientos hasta "hasta"; los anteriores a "desde" forman el saldo anterior.
    foreach (listarVouchers($pdo, ['hasta' => $filtros['hasta'] ?? null, 'registro' => $filtros['registro'] ?? null]) as $v) {
        foreach ($v['lineas'] as $l) {
            if ($cuenta !== '' && $l['cuenta'] !== $cuenta) {
                continue;
            }
            $porCuenta[$l['cuenta']] ??= ['saldoAnterior' => 0, 'movimientos' => []];
            if ($desde && $v['fecha'] < $desde) {
                $porCuenta[$l['cuenta']]['saldoAnterior'] += $l['debe'] - $l['haber'];
            } else {
                $porCuenta[$l['cuenta']]['movimientos'][] = [
                    'fecha' => $v['fecha'], 'tipo' => $v['tipo'], 'tipoNombre' => $v['tipoNombre'], 'numero' => $v['numero'],
                    'voucherId' => $v['id'], 'glosa' => $l['glosa'] !== '' ? $l['glosa'] : $v['glosa'], 'debe' => $l['debe'], 'haber' => $l['haber'],
                ];
            }
        }
    }
    $resultado = [];
    foreach (planCuentas($pdo) as $c) {
        if (!isset($porCuenta[$c['codigo']])) {
            continue;
        }
        $datos = $porCuenta[$c['codigo']];
        $saldo = $datos['saldoAnterior'];
        $debe = $haber = 0;
        $movimientos = [];
        foreach ($datos['movimientos'] as $m) {
            $saldo += $m['debe'] - $m['haber'];
            $debe += $m['debe'];
            $haber += $m['haber'];
            $movimientos[] = $m + ['saldo' => $saldo];
        }
        $resultado[] = ['codigo' => $c['codigo'], 'nombre' => $c['nombre'], 'saldoAnterior' => $datos['saldoAnterior'], 'movimientos' => $movimientos, 'debe' => $debe, 'haber' => $haber, 'saldo' => $saldo];
    }
    return $resultado;
}

// ---------- Respuestas HTTP ----------
function responder(mixed $datos, int $estado = 200): never
{
    http_response_code($estado);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

function ejecutar(callable $accion): never
{
    try {
        responder($accion(conectar()));
    } catch (ErrorValidacion $e) {
        responder(['errores' => $e->errores], 422);
    } catch (Throwable $e) {
        error_log('iContador API: ' . $e);
        responder(['errores' => ['Error interno de la base de datos. Revise el registro del servidor PHP.']], 500);
    }
}

function cuerpoJson(): array
{
    $datos = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($datos)) {
        throw new ErrorValidacion(['La solicitud no contiene datos válidos.']);
    }
    return $datos;
}
