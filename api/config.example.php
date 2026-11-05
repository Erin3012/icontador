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
    // Llave para cifrar las claves tributarias del SII guardadas en la web: 32 bytes en base64.
    // Genera una con: php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"  (también sirve ICONTADOR_SII_CLAVE_CIFRADO)
    // 'sii_clave_cifrado' => 'cambia-por-32-bytes-en-base64',
];
