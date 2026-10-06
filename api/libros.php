<?php
// GET libros.php?libro=diario|mayor[&desde=&hasta=&registro=Tributario|IFRS&cuenta=]
declare(strict_types=1);
require_once __DIR__ . '/lib/sesion.php';
require __DIR__ . '/lib/vouchers.php';

ejecutar(function (PDO $pdo) {
    return match ($_GET['libro'] ?? '') {
        'diario' => libroDiario($pdo, $_GET),
        'mayor' => libroMayor($pdo, $_GET),
        default => responder(['errores' => ['Indique libro=diario o libro=mayor.']], 400),
    };
});
