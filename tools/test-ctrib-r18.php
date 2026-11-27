<?php
// Prueba del Recuadro 18 (Determinación del RAI, 14 D N°3) con cifras inventadas.
// Uso: php tools/test-ctrib-r18.php
declare(strict_types=1);
require dirname(__DIR__) . '/api/lib/ctrib-r18.php';

function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FALLA: $msg\n"); exit(1); } }

// CPTS 50.000.000 + REX negativo 0 + retiros 4.000.000 − REX positivo 500.000 − capital 10.000.000 − FUR 0 − sobreprecio 100.000.
$caso = ['cptPositivo' => 50000000, 'cptNegativo' => 0, 'rexNegativoAbs' => 0, 'retirosEjercicio' => 4000000,
    'subtotalRai' => 54000000, 'rexPositivoPrevio' => 500000, 'capitalAportado' => 10000000, 'saldoFur' => 0,
    'sobreprecioAcciones' => 100000, 'rai' => 43400000];
$lineas = ctrib_r18_desde_formula($caso);
$porCodigo = array_column($lineas, 'monto', 'codigo');
check($porCodigo['1720'] === 54000000, 'subtotal 1720');
check($porCodigo['1500'] === 43400000, 'RAI 1500');
check(array_column($lineas, 'codigo') === ['1703', '1719', '1492', '1704', '1720', '1493', '1494', '1725', '1727', '1500'], 'códigos en el orden del formulario');

// Con CPTS negativo y subtotal negativo, el 1720 y el 1500 quedan en cero (instrucciones del F22).
$negativo = ['cptPositivo' => 0, 'cptNegativo' => 3000000, 'rexNegativoAbs' => 0, 'retirosEjercicio' => 1000000,
    'subtotalRai' => -2000000, 'rexPositivoPrevio' => 0, 'capitalAportado' => 500000, 'saldoFur' => 0,
    'sobreprecioAcciones' => 0, 'rai' => 0];
$porCodigo = array_column(ctrib_r18_desde_formula($negativo), 'monto', 'codigo');
check($porCodigo['1719'] === 3000000 && $porCodigo['1720'] === 0 && $porCodigo['1500'] === 0, 'CPTS negativo: 1720 y 1500 en cero');

// Si el capital supera el subtotal, el RAI es cero, no negativo.
$sinRai = $caso;
$sinRai['capitalAportado'] = 90000000;
$sinRai['rai'] = 0;
check(array_column(ctrib_r18_desde_formula($sinRai), 'monto', 'codigo')['1500'] === 0, 'RAI sin renta: cero');

// Un RAI que no cuadra con sus partidas no se entrega.
$malo = $caso;
$malo['rai'] = 43400001;
try {
    ctrib_r18_desde_formula($malo);
    check(false, 'un RAI que no cuadra debió rechazarse');
} catch (CtribError $e) {
    check(str_contains($e->getMessage(), 'RAI no cuadra'), 'mensaje de RAI que no cuadra');
}

echo "Recuadro 18 (determinación del RAI): verificado\n";
