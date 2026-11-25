<?php
// Prueba del Recuadro 4, Recuadro 6 y el cierre anual de Cifrax Tributario con la semilla ENYEL.
// Escenario A reproduce el R6 capturado en iTributario (antes de corregir 406001). Uso: php tools/test-ctrib-cierre.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/ctrib-cierre.php';

function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FALLA: $msg\n"); exit(1); } }
function falla(callable $accion, string $contiene, string $msg): void
{
    try { $accion(); } catch (Throwable $e) {
        check(str_contains($e->getMessage(), $contiene), "$msg (mensaje: " . $e->getMessage() . ")");
        return;
    }
    check(false, "$msg (no lanzó error)");
}
function monto_linea(array $r, string $codigo): int
{
    foreach ($r['lineas'] as $l) {
        if ($l['codigo'] === $codigo) {
            return (int) $l['monto'];
        }
    }
    throw new RuntimeException("sin línea $codigo");
}
function origen_linea(array $r, string $codigo): string
{
    foreach ($r['lineas'] as $l) {
        if ($l['codigo'] === $codigo) {
            return $l['origen'];
        }
    }
    throw new RuntimeException("sin línea $codigo");
}

function preparar(bool $corregido): PDO
{
    $pdo = base_prueba();
    crearEsquema($pdo);
    ctrib_schema($pdo);
    $semilla = json_decode((string) file_get_contents(dirname(__DIR__) . '/api/lib/ctrib/enyel-2025.json'), true, 512, JSON_THROW_ON_ERROR);
    ctrib_mapeo_guardar($pdo, 1, $semilla['mapeo'], 'prueba');
    ctrib_balance_guardar($pdo, 1, 2026, 'itributario', $semilla['balance'], 'captura', 'prueba');
    ctrib_homologacion_importar($pdo, 1, 2026, $semilla['homologacion_itributario'], 'prueba');
    if ($corregido) {
        ctrib_homologar($pdo, 1, 2026, '406001', '1419', 'prueba', 'Corrección ENYEL');
    }
    return $pdo;
}

// --- Escenario A: R6 tal como estaba en iTributario ---
$a = preparar(false);
$r6 = ctrib_r6($a, 1, 2026);
$capturado = ['122' => 20509426, '123' => 13604069, '102' => 20509426, '843' => 6905357];
$nn = [];
foreach ($r6['lineas'] as $l) {
    if ($l['monto'] !== 0) {
        $nn[$l['codigo']] = $l['monto'];
    }
}
check($nn === $capturado, 'R6 con valores distintos de cero igual a iTributario: ' . json_encode($nn));
check(origen_linea($r6, '843') === 'CALCULADO', '843 patrimonio financiero se calcula como activo menos pasivo');
falla(fn() => ctrib_r6_guardar_valor($a, 1, 2026, '122', 5, 'prueba'), 'no se ingresa a mano', 'el total de activo no se escribe a mano');
ctrib_r6_guardar_valor($a, 1, 2026, '843', 100, 'prueba');
check(monto_linea(ctrib_r6($a, 1, 2026), '843') === 100 && origen_linea(ctrib_r6($a, 1, 2026), '843') === 'MANUAL', 'sobrescribir 843 a mano');
$r6 = ctrib_r6_guardar_valor($a, 1, 2026, '843', null, 'prueba');
check(monto_linea($r6, '843') === 6905357, 'quitar la sobrescritura devuelve 6.905.357');

// R4: códigos manuales y determinación; la pérdida del año anterior se arrastra.
$r4 = ctrib_r4($a, 1, 2026);
check(array_sum(array_column($r4['lineas'], 'monto')) === 0, 'R4 sin datos: todo en cero como iTributario');
$r4 = ctrib_r4_guardar_valor($a, 1, 2026, '1809', 500, 'prueba');
check(monto_linea($r4, '1814') === 500 && monto_linea($r4, '1816') === 500, 'R4: 1814 = 1809 + 1813 y 1816 = 1814 + 1815');
ctrib_r4_guardar_valor($a, 1, 2026, '1809', -300, 'prueba');
$r4 = ctrib_r4($a, 1, 2026);
check(monto_linea($r4, '1816') === -300, 'R4: un resultado negativo queda en 1816');
$r4 = ctrib_r4_finalizar($a, 1, 2026, 'prueba');
check($r4['estado'] === 'FINALIZADO', 'R4 finalizado');
falla(fn() => ctrib_r4_guardar_valor($a, 1, 2026, '1809', 1, 'prueba'), 'finalizado', 'R4 finalizado no se edita');
$r4siguiente = ctrib_r4($a, 1, 2027);
check(monto_linea($r4siguiente, '1815') === -300 && origen_linea($r4siguiente, '1815') === 'AÑO_ANTERIOR', 'R4 del año siguiente arrastra la pérdida (1815)');
check(monto_linea($r4siguiente, '1816') === -300, '1816 del año siguiente = pérdida arrastrada');
falla(fn() => ctrib_r4_guardar_valor($a, 1, 2026, '1814', 5, 'prueba'), 'no se ingresa a mano', 'código calculado de R4 no se escribe a mano');

// Cierre sin recuadros finalizados.
$cierre = ctrib_cierre($a, 1, 2026);
check(!$cierre['completo'] && $cierre['cerrado'] === false && in_array('R17', $cierre['pendientes'], true), 'cierre: faltan recuadros');
falla(fn() => ctrib_cierre_sellar($a, 1, 2026, 'prueba'), 'Faltan recuadros', 'no se sella el cierre con recuadros pendientes');

// --- Escenario B: todo corregido y finalizado ---
$b = preparar(true);
ctrib_r4_finalizar($b, 1, 2026, 'prueba');
ctrib_r6_finalizar($b, 1, 2026, 'prueba');
falla(fn() => ctrib_r6_finalizar($b, 1, 2026, 'prueba'), 'ya está finalizado', 'R6 no se finaliza dos veces');
ctrib_incentivo_guardar($b, 1, 2026, true, true, true, null, 'prueba'); // confirmaciones del 1432
ctrib_r17_finalizar($b, 1, 2026, 'prueba');
ctrib_r19_finalizar($b, 1, 2026, 'prueba');
ctrib_r20_finalizar($b, 1, 2026, 'prueba');
$cierre = ctrib_cierre($b, 1, 2026);
check($cierre['completo'] === true && $cierre['pendientes'] === [], 'cierre: todos los recuadros finalizados (R21 sigue al RTRE)');
$estados = array_column($cierre['recuadros'], 'estado', 'codigo');
check($estados['R21'] === 'FINALIZADO', 'R21 aparece finalizado con el RTRE');
$sellado = ctrib_cierre_sellar($b, 1, 2026, 'prueba');
check($sellado['cerrado'] === true && $sellado['usuario'] === 'prueba', 'año cerrado');
falla(fn() => ctrib_cierre_sellar($b, 1, 2026, 'prueba'), 'ya está cerrado', 'no se cierra dos veces');
$reabierto = ctrib_cierre_reabrir($b, 1, 2026, 'prueba', 'Revisión del contador');
check($reabierto['cerrado'] === false, 'reabrir el cierre');
falla(fn() => ctrib_cierre_reabrir($b, 1, 2026, 'prueba', ''), 'no está cerrado', 'no se reabre un cierre abierto');

// Un recuadro reabierto después del cierre aparece como aviso.
ctrib_cierre_sellar($b, 1, 2026, 'prueba');
ctrib_r4_reabrir($b, 1, 2026, 'prueba', 'Ajuste');
$cierre = ctrib_cierre($b, 1, 2026);
check($cierre['cerrado'] === true && $cierre['pendientes'] === ['R4'] && count($cierre['avisos']) === 1, 'aviso si un recuadro se reabre con el año cerrado');

echo "Cifrax Tributario R4, R6 y cierre: R6 capturado reproducido (122, 123, 102, 843), R4 con pérdida de arrastre, cierre con recuadros pendientes, sellado y reapertura verificados\n";
