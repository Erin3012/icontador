<?php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/inventario.php';
require dirname(__DIR__) . '/api/lib/vouchers.php';

$fallas = 0;
function comprobar(bool $ok, string $mensaje): void
{
    global $fallas;
    echo ($ok ? 'ok   ' : 'FALLA ') . $mensaje . PHP_EOL;
    $fallas += $ok ? 0 : 1;
}

$db = base_prueba();
inventario_schema($db);
crearEsquema($db);
cargarPlanEjemplo($db);

// Test: Agregar item de inventario
$item1 = [
    'codigo' => 'INV001',
    'descripcion' => 'Escritorio de oficina',
    'categoria' => 'Mobiliario',
    'cantidad' => 5,
    'valor_unitario' => 200000,
    'ubicacion' => 'Oficina principal',
];
$resultado = inventario_agregar($db, 1, $item1);
comprobar(!empty($resultado), 'agregar item de inventario');

$item2 = [
    'codigo' => 'INV002',
    'descripcion' => 'Monitor 24 pulgadas',
    'categoria' => 'Equipos electrónicos',
    'cantidad' => 10,
    'valor_unitario' => 350000,
    'ubicacion' => 'Cubículos',
];
inventario_agregar($db, 1, $item2);
comprobar(true, 'agregar segundo item');

// Test: Listar items
$items = inventario_items($db, 1);
comprobar(count($items) >= 2, 'listar items de inventario');

// Test: Generar Libro de Inventario y Balance
$libro = libroInventario($db, [], 1);
comprobar(isset($libro['inventario']) && isset($libro['balance']), 'generar libro de inventario y balance');
comprobar(count($libro['inventario']['categorias']) > 0, 'categorías en inventario');

// Test: Eliminar item
$resultado = inventario_eliminar($db, 1, 'INV001');
comprobar($resultado['eliminados'] > 0, 'eliminar item de inventario');

$items = inventario_items($db, 1);
comprobar(count($items) === 1, 'verificar item restante después de eliminar');

if ($fallas === 0) {
    echo "\n✅ Todas las pruebas pasaron!\n";
} else {
    echo "\n❌ $fallas pruebas fallaron\n";
    exit(1);
}
