<?php
// Datos de la cuenta con sesión para la página de inicio: nombre y si es administrador.
declare(strict_types=1);
require_once __DIR__ . '/lib/sesion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    auth_json(405, ['error' => 'Método no permitido']);
}
$usuario = auth_actual();
auth_json(200, ['nombre' => $usuario['nombre'], 'email' => $usuario['email'], 'admin' => $usuario['rol'] === 'admin']);
