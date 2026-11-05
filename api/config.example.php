<?php
// Copia este archivo como api/config.php (no se sube al repositorio) para usar MySQL.
// También puedes usar las variables de entorno ICONTADOR_DB_DSN, ICONTADOR_DB_USER e ICONTADOR_DB_PASS.
return [
    'dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=icontador;charset=utf8mb4',
    'user' => 'icontador',
    'password' => '',
    // Opcional: carpeta privada para los adjuntos de Sugerencias (por defecto data/adjuntos).
    // 'adjuntos_dir' => '/home/usuario/icontador-adjuntos',
    // Token secreto del agente de sincronización con el SII (sii-agent/, en el PC). Genera uno largo y aleatorio.
    // 'sii_agente_token' => 'cambia-por-un-token-largo',
    // Si mantienes un token compartido legado, asígnalo a un único usuario autenticado.
    // Es preferible emparejar cada PC desde RCV, que limita las tareas al usuario dueño del agente.
    // 'sii_agente_usuario_id' => 1,
];
