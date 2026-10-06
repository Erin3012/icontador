<?php
// Incluir al inicio de cada api/*.php: sin una sesión aprobada responde 401 en JSON y no ejecuta el endpoint.
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

auth_exigir_api();
