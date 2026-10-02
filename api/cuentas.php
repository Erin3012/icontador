<?php
// GET cuentas.php  plan de cuentas
declare(strict_types=1);
require __DIR__ . '/lib.php';

ejecutar(fn(PDO $pdo) => planCuentas($pdo));
