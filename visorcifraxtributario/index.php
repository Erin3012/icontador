<?php
declare(strict_types=1);
// Visor Cifrax Tributario (qlc.cl/visorcifraxtributario): la pantalla de configuración, solo con sesión iniciada.
require_once __DIR__ . '/../api/lib/auth.php';
auth_exigir_pagina();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
readfile(__DIR__ . '/../views/visorcifraxtributario.html');
