<?php
// GET libros.php?libro=diario|mayor|balance|resultado[&desde=&hasta=&registro=Tributario|IFRS&cuenta=]
declare(strict_types=1);
require_once __DIR__ . '/lib/sesion.php';
require __DIR__ . '/lib/vouchers.php';

ejecutar(function (PDO $pdo, int $empresa) {
    return match ($_GET['libro'] ?? '') {
        'diario' => libroDiario($pdo, $_GET, $empresa),
        'mayor' => libroMayor($pdo, $_GET, $empresa),
        'balance' => balanceGeneral($pdo, $_GET, $empresa),
        'resultado' => estadoResultado($pdo, $_GET, $empresa),
        default => responder(['errores' => ['Indique libro=diario, mayor, balance o resultado.']], 400),
    };
});
