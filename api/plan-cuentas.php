<?php
// GET    plan-cuentas.php                     plan de cuentas de la empresa, con el detalle importado de iContador cuando existe
// POST   plan-cuentas.php {"ejemplo": true}   agrega las cuentas del plan de ejemplo que falten
// POST   plan-cuentas.php {codigo, nombre}    agrega una cuenta
// PUT    plan-cuentas.php?codigo=X {codigo, nombre}  cambia el nombre (y el código si la cuenta no tiene movimientos)
// DELETE plan-cuentas.php?codigo=X           elimina una cuenta sin movimientos
declare(strict_types=1);
require_once __DIR__ . '/lib/sesion.php';
require __DIR__ . '/lib/vouchers.php';

ejecutar(function (PDO $pdo, int $empresa) {
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            return ['cuentas' => planConDetalle($pdo, $empresa)];
        case 'POST':
            $datos = cuerpoJson();
            return !empty($datos['ejemplo']) ? ['agregadas' => cargarPlanEjemplo($pdo, $empresa)] : agregarCuenta($pdo, $datos, $empresa);
        case 'PUT':
            $actual = is_string($_GET['codigo'] ?? null) ? $_GET['codigo'] : '';
            $cuenta = editarCuenta($pdo, $actual, cuerpoJson(), $empresa) ?? responder(['errores' => ['La cuenta no existe.']], 404);
            if ($cuenta['codigo'] !== $actual) {
                moverDetalle($pdo, $empresa, $actual, $cuenta['codigo']);
            }
            return $cuenta;
        case 'DELETE':
            $codigo = is_string($_GET['codigo'] ?? null) ? $_GET['codigo'] : '';
            return eliminarCuenta($pdo, $codigo, $empresa) ? ['eliminada' => $codigo] : responder(['errores' => ['La cuenta no existe.']], 404);
    }
    header('Allow: GET, POST, PUT, DELETE');
    responder(['errores' => ['Método no permitido.']], 405);
});

// Las filas importadas de iContador traen tipo, subtipo y marcas (CA, AUX, CC, CU); la columna 3 es "código nombre".
function planConDetalle(PDO $pdo, int $empresa): array
{
    empresas_schema($pdo);
    $detalle = [];
    $consulta = $pdo->prepare("SELECT datos_json FROM importacion_registros WHERE empresa_id = ? AND vista = 'plan-cuentas' ORDER BY id");
    $consulta->execute([$empresa]);
    foreach ($consulta->fetchAll(PDO::FETCH_COLUMN) as $json) {
        $celdas = array_map('strval', json_decode($json, true)['celdas'] ?? []);
        if (preg_match('/^(\S+)\s/u', trim($celdas[2] ?? ''), $m)) {
            $detalle[$m[1]] = $celdas;
        }
    }
    return array_map(function ($c) use ($detalle) {
        $fila = $detalle[$c['codigo']] ?? null;
        if ($fila) {
            // El nombre editado en el plan manda sobre el importado.
            $fila[2] = $c['codigo'] . ' ' . $c['nombre'];
        }
        return $c + ['clase' => claseCuenta($c['codigo']), 'detalle' => $fila];
    }, planCuentas($pdo, $empresa));
}

// Al cambiar el código, el detalle importado (tipo, subtipo, marcas) sigue a la cuenta.
function moverDetalle(PDO $pdo, int $empresa, string $anterior, string $nuevo): void
{
    empresas_schema($pdo);
    $consulta = $pdo->prepare("SELECT id, datos_json FROM importacion_registros WHERE empresa_id = ? AND vista = 'plan-cuentas'");
    $consulta->execute([$empresa]);
    $actualizar = $pdo->prepare('UPDATE importacion_registros SET datos_json = ? WHERE id = ?');
    foreach ($consulta->fetchAll() as $registro) {
        $datos = json_decode($registro['datos_json'], true);
        $celda = trim((string) ($datos['celdas'][2] ?? ''));
        if (is_array($datos) && preg_match('/^(\S+)(\s.*)$/su', $celda, $m) && $m[1] === $anterior) {
            $datos['celdas'][2] = $nuevo . $m[2];
            $actualizar->execute([json_encode($datos, JSON_UNESCAPED_UNICODE), $registro['id']]);
        }
    }
}
