<?php
declare(strict_types=1);
// Cifrax Tributario (qlc.cl/cifraxtributario): empresa y configuracion de sincronizacion, solo con sesion iniciada.
require_once __DIR__ . '/../api/lib/auth.php';
auth_exigir_pagina();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
readfile(__DIR__ . '/../views/cifraxtributario.html');
