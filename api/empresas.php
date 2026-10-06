<?php
declare(strict_types=1);

require __DIR__ . '/lib/empresas.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'Método no permitido'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = icontador_db();
    empresas_schema($db);
    $query = $db->query(
        'SELECT e.id, e.origen_id, e.razon_social,
                COUNT(DISTINCT v.id) AS vistas,
                COUNT(DISTINCT r.id) AS registros
           FROM empresas e
      LEFT JOIN importacion_vistas v ON v.empresa_id = e.id
      LEFT JOIN importacion_registros r ON r.empresa_id = e.id
       GROUP BY e.id, e.origen_id, e.razon_social
       ORDER BY e.razon_social'
    );
    echo json_encode(['empresas' => $query->fetchAll()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo leer la base local.'], JSON_UNESCAPED_UNICODE);
}
