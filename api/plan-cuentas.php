<?php
// GET plan-cuentas.php?empresa_id=N  plan de cuentas importado de la empresa (filas de la vista plan-cuentas)
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
    $empresaId = filter_var($_GET['empresa_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    // Sin empresa seleccionada se usa la primera importada, como en el selector de empresas.
    $stmt = $empresaId
        ? $db->prepare('SELECT id, razon_social FROM empresas WHERE id = ?')
        : $db->prepare('SELECT id, razon_social FROM empresas ORDER BY razon_social LIMIT 1');
    $stmt->execute($empresaId ? [$empresaId] : []);
    $empresa = $stmt->fetch();
    if (!$empresa) {
        http_response_code(404);
        echo json_encode(['error' => 'No hay una empresa importada con ese identificador.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $db->prepare("SELECT datos_json FROM importacion_registros WHERE empresa_id = ? AND vista = 'plan-cuentas' ORDER BY id");
    $stmt->execute([(int)$empresa['id']]);
    $filas = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
        $registro = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $filas[] = array_map('strval', $registro['celdas'] ?? []);
    }

    echo json_encode([
        'empresa' => ['id' => (int)$empresa['id'], 'razon_social' => $empresa['razon_social']],
        'filas' => $filas,
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('iContador API: ' . $error);
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo leer el plan de cuentas de la base local.'], JSON_UNESCAPED_UNICODE);
}
