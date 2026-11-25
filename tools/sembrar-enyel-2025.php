<?php
// Semilla de prueba de Cifrax Tributario con ENYEL (AT 2026, año comercial 2025), datos capturados de iTributario el 25-11-2026.
// Carga el mapeo de dígitos, el balance de 8 columnas y la homologación tal como estaba en iTributario (con su error 406001 → 1402).
// Idempotente: se puede ejecutar varias veces. Uso: php tools/sembrar-enyel-2025.php
declare(strict_types=1);
require dirname(__DIR__) . '/api/lib/vouchers.php';
require dirname(__DIR__) . '/api/lib/ctrib.php';

$pdo = conectar();
ctrib_schema($pdo);

$buscar = $pdo->prepare("SELECT id, razon_social FROM empresas WHERE razon_social LIKE ? ORDER BY id");
$buscar->execute(['ENYEL%']);
$empresas = $buscar->fetchAll();
if (count($empresas) !== 1) {
    fwrite(STDERR, 'Se esperaba una empresa ENYEL y se encontraron ' . count($empresas) . ".\n");
    exit(1);
}
$empresa = (int) $empresas[0]['id'];

$semilla = json_decode((string) file_get_contents(dirname(__DIR__) . '/api/lib/ctrib/enyel-2025.json'), true, 512, JSON_THROW_ON_ERROR);
$anio = (int) $semilla['anio_tributario'];
$usuario = 'semilla ENYEL (captura iTributario)';

ctrib_mapeo_guardar($pdo, $empresa, $semilla['mapeo'], $usuario);
$balance = ctrib_balance_guardar($pdo, $empresa, $anio, 'itributario', $semilla['balance'], 'captura iTributario 25-11-2026', $usuario);
$cargadas = ctrib_homologacion_importar($pdo, $empresa, $anio, $semilla['homologacion_itributario'], $usuario);

echo "ENYEL (empresa $empresa) AT $anio cargada: balance " . count($balance['cuentas']) . " cuentas, sumas iguales "
    . number_format($balance['sumasIguales']['activo'], 0, ',', '.') . ", resultado " . number_format($balance['resultado'], 0, ',', '.')
    . ", homologación $cargadas cuentas.\n";
