<?php
require dirname(__DIR__).'/api/db.php';
$db=icontador_db();
echo json_encode($db->query('SELECT vista,COUNT(*) registros FROM importacion_registros GROUP BY vista ORDER BY vista')->fetchAll(),JSON_UNESCAPED_UNICODE);
echo "\n";
echo json_encode($db->query('SELECT vista FROM importacion_vistas ORDER BY vista')->fetchAll(PDO::FETCH_COLUMN));
