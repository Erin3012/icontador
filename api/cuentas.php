<?php
// GET cuentas.php  plan de cuentas
declare(strict_types=1);
require __DIR__ . '/lib/vouchers.php';

ejecutar(fn(PDO $pdo, int $empresa) => planCuentas($pdo, $empresa));
