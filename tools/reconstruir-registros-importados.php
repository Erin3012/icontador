<?php
declare(strict_types=1);

require dirname(__DIR__) . '/api/db.php';

$db = icontador_db();
if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
    throw new RuntimeException('Reconstrucción disponible solo para la base SQLite local.');
}

$vistas = $db->query('SELECT empresa_id, vista, datos_json FROM importacion_vistas')->fetchAll();
$delete = $db->prepare('DELETE FROM importacion_registros WHERE empresa_id = ? AND vista = ?');
$insert = $db->prepare('INSERT INTO importacion_registros(empresa_id,vista,tabla,huella,datos_json) VALUES(?,?,?,?,?)');
$total = 0;

$db->beginTransaction();
try {
    foreach ($vistas as $vista) {
        $datos = json_decode($vista['datos_json'], true, 512, JSON_THROW_ON_ERROR);
        $delete->execute([(int)$vista['empresa_id'], $vista['vista']]);
        foreach ($datos['tablas'] ?? $datos['tables'] ?? [] as $tabla) {
            $columnas = $tabla['encabezados'] ?? $tabla['columnas'] ?? [];
            $nombreTabla = (string)($tabla['id'] ?? 'tabla');
            foreach ($tabla['filas'] ?? [] as $fila) {
                $celdas = $fila['celdas'] ?? [];
                if (count($celdas) === 1 && preg_match('/Ning.n dato|No hay|Sin registros|seleccionar un periodo/i', (string)$celdas[0])) {
                    continue;
                }
                $registro = json_encode([
                    'columnas' => $columnas,
                    'origen_id' => $fila['id'] ?? null,
                    'celdas' => $celdas,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $insert->execute([(int)$vista['empresa_id'], $vista['vista'], $nombreTabla, hash('sha256', $registro), $registro]);
                $total++;
            }
        }
    }
    $db->commit();
    echo json_encode(['vistas' => count($vistas), 'registros' => $total], JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $error) {
    $db->rollBack();
    throw $error;
}
