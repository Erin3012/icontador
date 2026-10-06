<?php
// GET    plan-cuentas.php                     plan de cuentas de la empresa, con el detalle importado de iContador cuando existe
// POST   plan-cuentas.php {"ejemplo": true}   agrega las cuentas del plan de ejemplo que falten
// POST   plan-cuentas.php {codigo, nombre}    agrega una cuenta
// DELETE plan-cuentas.php?codigo=X           elimina una cuenta sin movimientos
declare(strict_types=1);
require __DIR__ . '/lib/vouchers.php';

ejecutar(function (PDO $pdo, int $empresa) {
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            return ['cuentas' => planConDetalle($pdo, $empresa)];
        case 'POST':
            $datos = cuerpoJson();
            return !empty($datos['ejemplo']) ? ['agregadas' => cargarPlanEjemplo($pdo, $empresa)] : agregarCuenta($pdo, $datos, $empresa);
        case 'DELETE':
            $codigo = is_string($_GET['codigo'] ?? null) ? $_GET['codigo'] : '';
            return eliminarCuenta($pdo, $codigo, $empresa) ? ['eliminada' => $codigo] : responder(['errores' => ['La cuenta no existe.']], 404);
    }
    header('Allow: GET, POST, DELETE');
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
    return array_map(fn($c) => $c + ['clase' => claseCuenta($c['codigo']), 'detalle' => $detalle[$c['codigo']] ?? null], planCuentas($pdo, $empresa));
}
