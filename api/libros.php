<?php
// GET libros.php?libro=diario|mayor|balance|resultado[&desde=&hasta=&registro=Tributario|IFRS&cuenta=]
declare(strict_types=1);
require __DIR__ . '/lib/vouchers.php';

ejecutar(function (PDO $pdo) {
    return match ($_GET['libro'] ?? '') {
        'diario' => libroDiario($pdo, $_GET),
        'mayor' => libroMayor($pdo, $_GET),
        'balance' => balanceGeneral($pdo, $_GET),
        'resultado' => estadoResultado($pdo, $_GET),
        default => responder(['errores' => ['Indique libro=diario, mayor, balance o resultado.']], 400),
    };
});
