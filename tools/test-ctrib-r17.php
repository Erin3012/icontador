<?php
// Prueba del Recuadro 17 de Cifrax Tributario con la semilla ENYEL: cálculo por código, incentivo al ahorro,
// campos manuales, finalizar, desactualizado y reabrir. Uso: php tools/test-ctrib-r17.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/ctrib-r17.php';

function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FALLA: $msg\n"); exit(1); } }
function falla(callable $accion, string $contiene, string $msg): void
{
    try { $accion(); } catch (Throwable $e) {
        check(str_contains($e->getMessage(), $contiene), "$msg (mensaje: " . $e->getMessage() . ")");
        return;
    }
    check(false, "$msg (no lanzó error)");
}
function linea(array $r17, string $codigo): array
{
    foreach ($r17['lineas'] as $l) {
        if ($l['codigo'] === $codigo) {
            return $l;
        }
    }
    throw new RuntimeException("sin línea $codigo");
}

$pdo = base_prueba();
crearEsquema($pdo);
ctrib_schema($pdo);
$semilla = json_decode((string) file_get_contents(dirname(__DIR__) . '/api/lib/ctrib/enyel-2025.json'), true, 512, JSON_THROW_ON_ERROR);
$anio = 2026;
$usuario = 'prueba';
ctrib_mapeo_guardar($pdo, 1, $semilla['mapeo'], $usuario);
ctrib_balance_guardar($pdo, 1, $anio, 'itributario', $semilla['balance'], 'captura', $usuario);
ctrib_homologacion_importar($pdo, 1, $anio, $semilla['homologacion_itributario'], $usuario);

// --- Tal como estaba en iTributario (406001 en 1402) ---
$r = ctrib_r17($pdo, 1, $anio);
check(linea($r, '1400')['monto'] === 22018000, '1400 ventas exentas 22.018.000');
check(linea($r, '1402')['monto'] === 239576, '1402 con 406001 (error de iTributario) 239.576');
check(linea($r, '1409')['monto'] === 8588293 && linea($r, '1412')['monto'] === 3041538, '1409 y 1412 de los gastos');
check(linea($r, '1415')['monto'] === 2436461 && linea($r, '1424')['monto'] === 806775, '1415 arriendos y 1424 otros deducibles');
check(linea($r, '1431')['monto'] === 0, '1431 copia del 1422 (cero en ENYEL)');
check($r['totales'] === ['1410' => 22257576, '1430' => 14873067, '1729' => 7384509, '1432' => 3692255, '1440' => 3692254], 'totales iTributario: 1410, 1430, 1729, 1432 y 1440');
check($r['incentivoAhorro']['baseReinvertida'] === 7384509 && $r['incentivoAhorro']['topeMitad'] === 3692254.5, 'base reinvertida y tope 50%');
check($r['incentivoAhorro']['topeUfAplicado'] === false, 'el tope de 5.000 UF no se aplica en ENYEL');
check($r['estado'] === 'BORRADOR' && $r['puedeFinalizar'] === false && count($r['bloqueos']) === 1, 'un bloqueo: cuenta incompatible 406001');
falla(fn() => ctrib_r17_finalizar($pdo, 1, $anio, $usuario), 'incompatible', 'no se finaliza con una cuenta incompatible');

// --- Corrección 406001 → 1419 (intereses pagados) ---
ctrib_homologar($pdo, 1, $anio, '406001', '1419', $usuario, 'Corrección ENYEL: intereses bancarios pagados');
$r = ctrib_r17($pdo, 1, $anio);
check(linea($r, '1402')['monto'] === 0 && linea($r, '1419')['monto'] === 239576, '406001 pasa de 1402 a 1419');
check($r['totales']['1410'] === 22018000 && $r['totales']['1430'] === 15112643, 'ingresos 22.018.000 y egresos 15.112.643 tras la corrección');
check($r['totales']['1729'] === 6905357, 'la base imponible 1729 queda igual al resultado del balance (6.905.357)');
check($r['totales']['1432'] === 3452679 && $r['totales']['1440'] === 3452678, 'incentivo 3.452.679 (50% redondeado) y base 1440 3.452.678');
check($r['bloqueos'] === [] && $r['puedeFinalizar'] === true, 'sin bloqueos: se puede finalizar');

// --- Campos manuales ---
$r = ctrib_r17_guardar_manual($pdo, 1, $anio, '1426', 50, $usuario);
check(linea($r, '1426')['monto'] === 50 && $r['totales']['1430'] === 15112693 && $r['totales']['1729'] === 6905307, 'pérdida anterior (1426) resta de egresos');
ctrib_r17_guardar_manual($pdo, 1, $anio, '1426', 0, $usuario);
falla(fn() => ctrib_r17_guardar_manual($pdo, 1, $anio, '1400', 5, $usuario), 'no se ingresa a mano', 'código calculado o de cuentas no es manual');
falla(fn() => ctrib_r17_guardar_manual($pdo, 1, $anio, '1426', -1, $usuario), 'entero mayor', 'monto negativo no se acepta');
falla(fn() => ctrib_r17_guardar_manual($pdo, 1, $anio, '1426', 1.5, $usuario), 'entero mayor', 'monto decimal no se acepta');

// --- Finalizar, desactualizado y reabrir ---
$r = ctrib_r17_finalizar($pdo, 1, $anio, $usuario);
check($r['estado'] === 'FINALIZADO' && $r['desactualizado'] === false && $r['finalizado'] !== null, 'finalizado sin cambios');
falla(fn() => ctrib_r17_guardar_manual($pdo, 1, $anio, '1404', 10, $usuario), 'finalizado', 'un recuadro finalizado no se edita');
falla(fn() => ctrib_r17_finalizar($pdo, 1, $anio, $usuario), 'ya está finalizado', 'no se finaliza dos veces');

// Quitar una homologación después de finalizar lo deja desactualizado y con una cuenta pendiente.
ctrib_homologar($pdo, 1, $anio, '405038', '', $usuario, 'Prueba');
$r = ctrib_r17($pdo, 1, $anio);
check($r['desactualizado'] === true && $r['estado'] === 'FINALIZADO', 'cambio posterior marca desactualizado');
check(count($r['bloqueos']) === 1 && str_contains($r['bloqueos'][0], 'sin código'), 'la cuenta pendiente se informa');
ctrib_homologar($pdo, 1, $anio, '405038', '1409', $usuario, 'Prueba');
check(ctrib_r17($pdo, 1, $anio)['desactualizado'] === false, 'volver a asignar la cuenta vuelve a coincidir con el snapshot');

$r = ctrib_r17_reabrir($pdo, 1, $anio, $usuario, 'Corrección del contador');
check($r['estado'] === 'BORRADOR', 'reabrir deja el recuadro en borrador');
falla(fn() => ctrib_r17_reabrir($pdo, 1, $anio, $usuario, ''), 'no está finalizado', 'no se reabre un borrador');
$historial = ctrib_historial_listar($pdo, 1, $anio, 'recuadro');
$finalizo = array_filter($historial, fn($h) => $h['referencia'] === 'R17' && $h['despues'] === 'FINALIZADO' && $h['motivo'] === 'Recuadro finalizado');
$reabrio = array_filter($historial, fn($h) => $h['referencia'] === 'R17' && $h['despues'] === 'BORRADOR' && $h['motivo'] === 'Corrección del contador');
check(count($finalizo) === 1 && count($reabrio) === 1, 'historial registra finalizar y reabrir (los valores manuales también quedan)');

// --- Piso en cero: un código de gastos con saldo acreedor no baja de cero ---
$cuentas2027 = [
    ['codigo' => '101002', 'nombre' => 'BANCOS', 'debitos' => 1200, 'creditos' => 0],
    ['codigo' => '505002', 'nombre' => 'VENTAS EXENTAS', 'debitos' => 0, 'creditos' => 1000],
    ['codigo' => '405002', 'nombre' => 'HONORARIOS PROFESIONALES', 'debitos' => 100, 'creditos' => 300],
];
ctrib_balance_guardar($pdo, 1, 2027, 'excel', $cuentas2027, '', $usuario);
ctrib_homologar($pdo, 1, 2027, '505002', '1400', $usuario);
ctrib_homologar($pdo, 1, 2027, '405002', '1412', $usuario);
$r = ctrib_r17($pdo, 1, 2027);
check(linea($r, '1412')['monto'] === 0 && linea($r, '1412')['huboPisoEnCero'] === true, '1412 con saldo acreedor queda en cero (piso)');
check($r['totales'] === ['1410' => 1000, '1430' => 0, '1729' => 1000, '1432' => 500, '1440' => 500], 'totales con piso en cero');

echo "Cifrax Tributario R17: cálculo ENYEL (1410, 1430, 1729, 1432, 1440), corrección 406001, manuales, finalizar, desactualizado, reabrir y piso en cero verificados\n";
