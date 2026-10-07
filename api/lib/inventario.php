<?php
declare(strict_types=1);

function inventario_schema(PDO $pdo): void
{
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $id = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY';
    $sql = "CREATE TABLE IF NOT EXISTS inventario_items (
        id $id,
        empresa_id INT NOT NULL DEFAULT 0,
        codigo VARCHAR(20) NOT NULL,
        descripcion VARCHAR(255) NOT NULL,
        categoria VARCHAR(100) NOT NULL DEFAULT '',
        cantidad INT NOT NULL DEFAULT 0,
        valor_unitario BIGINT NOT NULL DEFAULT 0,
        fecha_ingreso VARCHAR(10),
        ubicacion VARCHAR(100) NOT NULL DEFAULT '',
        observaciones TEXT,
        creado_en VARCHAR(25) NOT NULL,
        actualizado_en VARCHAR(25) NOT NULL";
    if ($mysql) {
        $sql .= ", UNIQUE KEY uk_empresa_codigo (empresa_id, codigo), KEY idx_categoria (empresa_id, categoria)";
    }
    $sql .= ")";
    $pdo->exec($sql);
}

function inventario_items(PDO $pdo, int $empresa, string $desde = '', string $hasta = '', string $categoria = ''): array
{
    $sql = 'SELECT id, codigo, descripcion, categoria, cantidad, valor_unitario, fecha_ingreso, ubicacion, observaciones FROM inventario_items WHERE empresa_id = :empresa';
    $params = [':empresa' => $empresa];

    if ($desde !== '') {
        $sql .= ' AND fecha_ingreso >= :desde';
        $params[':desde'] = $desde;
    }
    if ($hasta !== '') {
        $sql .= ' AND fecha_ingreso <= :hasta';
        $params[':hasta'] = $hasta;
    }
    if ($categoria !== '') {
        $sql .= ' AND categoria = :categoria';
        $params[':categoria'] = $categoria;
    }

    $sql .= ' ORDER BY categoria, codigo';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function inventario_agregar(PDO $pdo, int $empresa, array $item): array
{
    $sql = 'INSERT OR REPLACE INTO inventario_items (empresa_id, codigo, descripcion, categoria, cantidad, valor_unitario, fecha_ingreso, ubicacion, observaciones, creado_en, actualizado_en)
            VALUES (:empresa, :codigo, :descripcion, :categoria, :cantidad, :valor_unitario, :fecha_ingreso, :ubicacion, :observaciones, :creado_en, :actualizado_en)';

    $ahora = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':empresa' => $empresa,
        ':codigo' => $item['codigo'] ?? '',
        ':descripcion' => $item['descripcion'] ?? '',
        ':categoria' => $item['categoria'] ?? '',
        ':cantidad' => (int)($item['cantidad'] ?? 0),
        ':valor_unitario' => (int)($item['valor_unitario'] ?? 0),
        ':fecha_ingreso' => $item['fecha_ingreso'] ?? null,
        ':ubicacion' => $item['ubicacion'] ?? '',
        ':observaciones' => $item['observaciones'] ?? '',
        ':creado_en' => $ahora,
        ':actualizado_en' => $ahora,
    ]);

    return ['id' => $pdo->lastInsertId() ?: 1];
}

function inventario_eliminar(PDO $pdo, int $empresa, string $codigo): array
{
    $sql = 'DELETE FROM inventario_items WHERE empresa_id = :empresa AND codigo = :codigo';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':empresa' => $empresa, ':codigo' => $codigo]);
    return ['eliminados' => $stmt->rowCount()];
}

function libroInventario(PDO $pdo, array $filtros = [], int $empresa = 0): array
{
    // Traer items de inventario
    $desde = $filtros['desde'] ?? '';
    $hasta = $filtros['hasta'] ?? '';
    $categoria = $filtros['categoria'] ?? '';
    $items = inventario_items($pdo, $empresa, $desde, $hasta, $categoria);

    // Calcular totales por categoría
    $categorias = [];
    $total_cantidad = 0;
    $total_valor = 0;

    foreach ($items as $item) {
        $cat = $item['categoria'] ?: 'Sin categoría';
        if (!isset($categorias[$cat])) {
            $categorias[$cat] = [
                'nombre' => $cat,
                'items' => [],
                'cantidad' => 0,
                'valor_total' => 0
            ];
        }
        $valor = (int)$item['cantidad'] * (int)$item['valor_unitario'];
        $categorias[$cat]['items'][] = $item;
        $categorias[$cat]['cantidad'] += (int)$item['cantidad'];
        $categorias[$cat]['valor_total'] += $valor;

        $total_cantidad += (int)$item['cantidad'];
        $total_valor += $valor;
    }

    // Traer sumas del balance
    $sumas = sumasPorCuenta($pdo, $filtros, $empresa);
    $cuentas = [];
    $totales_balance = array_fill_keys(['debitos', 'creditos', 'deudor', 'acreedor', 'activo', 'pasivo'], 0);

    foreach (planCuentas($pdo, $empresa) as $c) {
        if (!isset($sumas[$c['codigo']])) {
            continue;
        }
        ['debe' => $debe, 'haber' => $haber] = $sumas[$c['codigo']];
        $deudor = max($debe - $haber, 0);
        $acreedor = max($haber - $debe, 0);

        $fila = [
            'codigo' => $c['codigo'],
            'nombre' => $c['nombre'],
            'debitos' => $debe,
            'creditos' => $haber,
            'deudor' => $deudor,
            'acreedor' => $acreedor
        ];
        foreach ($totales_balance as $k => $_) {
            if (isset($fila[$k])) {
                $totales_balance[$k] += $fila[$k];
            }
        }
        $cuentas[] = $fila;
    }

    return [
        'inventario' => [
            'categorias' => array_values($categorias),
            'total_cantidad' => $total_cantidad,
            'total_valor' => $total_valor
        ],
        'balance' => [
            'cuentas' => $cuentas,
            'totales' => $totales_balance
        ]
    ];
}
