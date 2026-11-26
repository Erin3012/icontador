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

// --- Un valor inválido no borra el guardado ---
$d = preparar(true);
ctrib_r19_guardar_valor($d, 1, 2026, '1374', 500000, 'prueba');
falla(fn() => ctrib_r19_guardar_valor($d, 1, 2026, '1374', -5, 'prueba'), 'entero', 'R19 rechaza un negativo');
check(ctrib_valores_manuales($d, 1, 2026, 'R19')['1374'] === 500000, 'R19 conserva el valor anterior');
ctrib_r4_guardar_valor($d, 1, 2026, '1070', 700000, 'prueba');
falla(fn() => ctrib_r4_guardar_valor($d, 1, 2026, '1070', 'abc', 'prueba'), 'entero', 'R4 rechaza texto');
check(ctrib_valores_manuales($d, 1, 2026, 'R4')['1070'] === 700000, 'R4 conserva el valor anterior');
$filaLarga = null;
foreach (ctrib_rtre_catalogo() as $fila => $f) {
    foreach ($f['celdas'] as $ce) {
        if (in_array($f['naturalezaConfiguracion'], ['MANUAL', 'AUTO_CON_OVERRIDE'], true) && strlen("R20 $fila::{$ce['columna']}") > 40) {
            $filaLarga ??= [(string) $fila, $ce['columna']];
        }
    }
}
ctrib_r20_guardar_celda($d, 1, 2026, $filaLarga[0], $filaLarga[1], 123, 'prueba');
falla(fn() => ctrib_r20_guardar_celda($d, 1, 2026, $filaLarga[0], $filaLarga[1], 'abc', 'prueba'), 'entero', 'RTRE rechaza texto');
check(ctrib_rtre_celdas_manuales($d, 1, 2026)[$filaLarga[0] . '::' . $filaLarga[1]] === 123, 'RTRE conserva la celda anterior');
ctrib_registrar_historial($d, 1, 2026, 'retiros', str_repeat('a', 300), str_repeat('b', 300), '', '', 'prueba');
$h = ctrib_historial_listar($d, 1, 2026, 'retiros')[0];
check(mb_strlen($h['referencia']) === 100 && mb_strlen($h['antes']) === 255, 'el historial recorta al tamaño de las columnas');

// --- Recuadros desactualizados y año cerrado ---
$e = preparar(true);
ctrib_r4_finalizar($e, 1, 2026, 'prueba');
ctrib_r6_finalizar($e, 1, 2026, 'prueba');
ctrib_incentivo_guardar($e, 1, 2026, true, true, true, null, 'prueba');
ctrib_r17_finalizar($e, 1, 2026, 'prueba');
ctrib_r19_finalizar($e, 1, 2026, 'prueba');
ctrib_r20_finalizar($e, 1, 2026, 'prueba');
ctrib_cierre_sellar($e, 1, 2026, 'prueba');
foreach (['balance_desde_vouchers', 'balance_importar', 'homologacion_asignar', 'retiros_importar', 'composicion_guardar', 'r4_reabrir', 'accion_futura'] as $accion) {
    falla(fn() => ctrib_exigir_cambio_permitido($e, 1, $accion, ['anio' => '2026']), 'está cerrado', "año cerrado: $accion se rechaza");
}
ctrib_exigir_cambio_permitido($e, 1, 'cierre_reabrir', ['anio' => 2026]);
ctrib_exigir_cambio_permitido($e, 1, 'certificado_emitir', ['anio' => 2026]);
ctrib_exigir_cambio_permitido($e, 1, 'balance_importar', ['anio' => 2027]);
ctrib_exigir_cambio_permitido($e, 2, 'balance_importar', ['anio' => 2026]);
$mapeo = ctrib_mapeo($e, 1);
ctrib_exigir_cambio_permitido($e, 1, 'mapeo_guardar', ['mapeo' => $mapeo + ['9' => 'ACTIVO']]);
falla(fn() => ctrib_exigir_cambio_permitido($e, 1, 'mapeo_guardar', ['mapeo' => ['1' => 'PASIVO'] + $mapeo]), 'años cerrados', 'no se cambia el mapeo con un año cerrado');

// Un cambio en los datos (por fuera de la API) deja los recuadros desactualizados.
ctrib_cierre_reabrir($e, 1, 2026, 'prueba', 'Cambio');
ctrib_retiros_importar($e, 1, 2026, [['rut' => '12345678-5', 'nombre' => 'X', 'fecha' => '2025-05-05', 'monto' => 1000000, 'tipo' => 'Retiro']], 'r.csv', 'prueba');
$cierre = ctrib_cierre($e, 1, 2026);
check(!$cierre['completo'] && in_array('R19', $cierre['pendientes'], true), 'un recuadro desactualizado cuenta como pendiente');
falla(fn() => ctrib_cierre_sellar($e, 1, 2026, 'prueba'), 'actualizar', 'no se cierra con recuadros desactualizados');
falla(fn() => ctrib_certificado_emitir($e, 1, 2026, '12345678-5', 'prueba'), 'al día', 'no se certifica con el RTRE desactualizado');
check(ctrib_dj1948($e, 1, 2026)['paso1']['listo'] === false, 'DJ 1948 no está lista con el RTRE desactualizado');
check(ctrib_documento_desactualizado($e, 1, 2026, 'R19') && !ctrib_documento_desactualizado($e, 1, 2026, 'BALANCE'), 'el PDF del R19 sale como borrador');
ctrib_r20_reabrir($e, 1, 2026, 'prueba', '');
falla(fn() => ctrib_r20_finalizar($e, 1, 2026, 'prueba'), 'cambió después', 'el RTRE no se finaliza sobre un R19 desactualizado');

// --- Sin la UF del año no se finaliza un R17 con incentivo ---
$u = preparar(true);
$balance = ctrib_balance_guardado($u, 1, 2026);
ctrib_balance_guardar($u, 1, 2027, 'csv', $balance['cuentas'], '', 'prueba');
ctrib_homologacion_importar($u, 1, 2027, array_reduce(ctrib_homologacion($u, 1, 2026)['codigos'], function ($m, $c) {
    if ($c['cuentas']) { $m[$c['codigo']] = array_column($c['cuentas'], 'cuenta'); }
    return $m;
}, []), 'prueba');
ctrib_incentivo_guardar($u, 1, 2027, true, true, true, null, 'prueba');
$r17 = ctrib_r17($u, 1, 2027);
check($r17['totales']['1432'] > 0 && !$r17['puedeFinalizar'], 'sin UF y con incentivo no se puede finalizar');
falla(fn() => ctrib_r17_finalizar($u, 1, 2027, 'prueba'), 'falta la UF', 'aviso del tope de 5.000 UF');
ctrib_incentivo_guardar($u, 1, 2027, true, true, true, 0, 'prueba');
check(ctrib_r17($u, 1, 2027)['puedeFinalizar'], 'con monto 0 en el 1432 se puede finalizar');

echo "Cifrax Tributario R4, R6 y cierre: R6 capturado reproducido (122, 123, 102, 843), R4 con pérdida de arrastre, cierre con recuadros pendientes, sellado y reapertura verificados\n";
