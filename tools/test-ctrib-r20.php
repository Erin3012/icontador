<?php
// Prueba del RTRE (R20), Registro SAC (R21) y DJ 1948 de Cifrax Tributario con la semilla ENYEL.
// Escenario A reproduce las celdas que iTributario capturó (AT 2026, antes de corregir 406001).
// Uso: php tools/test-ctrib-r20.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/ctrib-r20.php';

function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FALLA: $msg\n"); exit(1); } }
function falla(callable $accion, string $contiene, string $msg): void
{
    try { $accion(); } catch (Throwable $e) {
        check(str_contains($e->getMessage(), $contiene), "$msg (mensaje: " . $e->getMessage() . ")");
        return;
    }
    check(false, "$msg (no lanzó error)");
}
function celdas_no_nulas(array $r20): array
{
    $salida = [];
    foreach ($r20['celdas'] as $c) {
        $salida[$c['fila'] . '::' . $c['columna']] = $c['monto'];
    }
    return $salida;
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

$SAC = 'SAC_NO_RESTITUCION_DESDE_2020_CON_DEVOLUCION';

// --- Escenario A: ENYEL tal como estaba en iTributario ---
$a = preparar(false);
$r = ctrib_r20($a, 1, 2026);
$capturadas = ["4.2::RAI" => 7384509, "4.5::$SAC" => 461532, "SUBTOTAL_5::RAI" => 7384509, "SUBTOTAL_5::$SAC" => 461532,
    "REMANENTE_FINAL::RAI" => 7384509, "REMANENTE_FINAL::$SAC" => 461532];
$nn = celdas_no_nulas($r);
check(count($nn) === count($capturadas), 'celdas no nulas: ' . count($nn) . ' (iTributario tiene ' . count($capturadas) . ')');
foreach ($capturadas as $clave => $valor) {
    check(($nn[$clave] ?? null) === $valor, "celda $clave = $valor como en iTributario (hay " . ($nn[$clave] ?? 'nada') . ')');
}
check($r['rai'] === 7384509 && $r['idpc'] === 461532, 'RAI 7.384.509 e IDPC 461.532 como iTributario');
check($r['formulaRai']['subtotalRai'] === 7384509 && $r['formulaSac']['tasaIdpc'] === 12.5, 'fórmula del RAI y tasa Pro Pyme 12,5%');
check(in_array('Saldos iniciales (1.1): sin RTRE del año anterior finalizado, quedan en cero.', $r['pendientesDeCalculo'], true), 'saldos iniciales marcados como pendientes');
falla(fn() => ctrib_r20_finalizar($a, 1, 2026, 'prueba'), 'Recuadro 19', 'no se finaliza el RTRE sin R19 finalizado');
falla(fn() => ctrib_certificado_emitir($a, 1, 2026, '12345678-9', 'prueba'), 'debe estar finalizado', 'no se emiten certificados sin RTRE finalizado');

// Celda manual: 1.2 RAI = 100 entra al subtotal 3 y el reverso (4.1) lo cancela; el remanente no cambia.
ctrib_r20_guardar_celda($a, 1, 2026, '1.2', 'RAI', 100, 'prueba');
$r = ctrib_r20($a, 1, 2026);
$nn = celdas_no_nulas($r);
check(($nn['1.2::RAI'] ?? null) === 100 && ($nn['4.1::RAI'] ?? null) === -100, 'reverso del subtotal 3: 4.1 = −100');
check(($nn['REMANENTE_FINAL::RAI'] ?? null) === 7384509, 'el reverso deja el remanente RAI en 7.384.509');
falla(fn() => ctrib_r20_guardar_celda($a, 1, 2026, '1.2', 'RAI', -5, 'prueba'), 'solo admite valores positivos', 'celda SOLO_POSITIVO no acepta negativos');
falla(fn() => ctrib_r20_guardar_celda($a, 1, 2026, '4.2', 'RAI', 5, 'prueba'), 'se calcula sola', 'fila automática no se escribe a mano');
falla(fn() => ctrib_r20_guardar_celda($a, 1, 2026, '1.2', 'ZZZ', 5, 'prueba'), 'no aplica', 'columna que no corresponde a la fila');
falla(fn() => ctrib_r20_guardar_celda($a, 1, 2026, '9.9', 'RAI', 5, 'prueba'), 'no existe', 'fila inexistente');
ctrib_r20_guardar_celda($a, 1, 2026, '1.2', 'RAI', null, 'prueba');
check(!isset(celdas_no_nulas(ctrib_r20($a, 1, 2026))['1.2::RAI']), 'quitar la celda manual la borra');

// --- Escenario B: corregido, con retiros de dos socios ---
$b = preparar(true);
ctrib_retiros_importar($b, 1, 2026, [
    ['rut' => '12345678-9', 'nombre' => 'Socio Uno', 'fecha' => '2025-03-15', 'monto' => 600000, 'tipo' => 'Retiro'],
    ['rut' => '98765432-1', 'nombre' => 'Socio Dos', 'fecha' => '2025-09-30', 'monto' => 400000, 'tipo' => 'Dividendo'],
], 'retiros.csv', 'prueba');
$r = ctrib_r20($b, 1, 2026);
$esperadas = ["4.2::RAI" => 6905357, "4.5::$SAC" => 494085, "6.1::RAI" => 1000000, "SUBTOTAL_5::RAI" => 6905357,
    "SUBTOTAL_5::$SAC" => 494085, "REMANENTE_FINAL::RAI" => 5905357, "REMANENTE_FINAL::$SAC" => 494085];
$nn = celdas_no_nulas($r);
check(count($nn) === count($esperadas), 'escenario con retiros: ' . count($nn) . ' celdas no nulas');
foreach ($esperadas as $clave => $valor) {
    check(($nn[$clave] ?? null) === $valor, "con retiros, $clave = $valor (hay " . ($nn[$clave] ?? 'nada') . ')');
}
check($r['rai'] === 6905357 && $r['idpc'] === 494085 && $r['retirosImputadosRai'] === 1000000, 'RAI 6.905.357, IDPC 494.085, retiros imputados al RAI');
check(!in_array(true, array_map(fn($p) => str_contains($p, 'exceden'), $r['pendientesDeCalculo']), true), 'sin retiros que excedan el RAI');

// Finalizar en orden: R17, R19 y luego RTRE.
ctrib_incentivo_guardar($b, 1, 2026, true, true, true, null, 'prueba'); // confirmaciones del 1432
ctrib_r17_finalizar($b, 1, 2026, 'prueba');
falla(fn() => ctrib_r20_finalizar($b, 1, 2026, 'prueba'), 'Recuadro 19', 'el RTRE exige el R19 finalizado');
ctrib_r19_finalizar($b, 1, 2026, 'prueba');
$r = ctrib_r20_finalizar($b, 1, 2026, 'prueba');
check($r['estado'] === 'FINALIZADO', 'RTRE finalizado');
falla(fn() => ctrib_r20_guardar_celda($b, 1, 2026, '1.2', 'RAI', 5, 'prueba'), 'finalizado', 'RTRE finalizado no se edita');
falla(fn() => ctrib_r20_finalizar($b, 1, 2026, 'prueba'), 'ya está finalizado', 'no se finaliza dos veces');

// DJ 1948 y certificados
$dj = ctrib_dj1948($b, 1, 2026);
check($dj['paso1']['listo'] === true && count($dj['socios']) === 2, 'DJ 1948: paso 1 listo y dos socios');
check($dj['socios'][0]['total'] === 600000 && $dj['socios'][0]['certificado'] === null, 'socio con retiro de 600.000 sin certificado');
$c1 = ctrib_certificado_emitir($b, 1, 2026, '12345678-9', 'prueba');
check($c1['numero'] === 1 && $c1['nuevo'] === true, 'primer certificado N° 1');
$c1otra = ctrib_certificado_emitir($b, 1, 2026, '12345678-9', 'prueba');
check($c1otra['numero'] === 1 && $c1otra['nuevo'] === false, 'reemitir conserva el número');
$c2 = ctrib_certificado_emitir($b, 1, 2026, '98765432-1', 'prueba');
check($c2['numero'] === 2, 'segundo socio recibe el N° 2');
falla(fn() => ctrib_certificado_emitir($b, 1, 2026, '11111111-1', 'prueba'), 'no tiene retiros', 'RUT sin retiros no recibe certificado');

// R21: 1513 = IDPC del ejercicio y 1564 = remanente SAC.
$r21 = ctrib_r21($b, 1, 2026);
$lineas21 = array_column($r21['lineas'], 'monto', 'codigo');
check($lineas21['1513'] === 494085 && $lineas21['1564'] === 494085, 'R21: 1513 y 1564 = 494.085');

// --- Escenario C: el año siguiente toma el remanente finalizado ---
$siguiente = ctrib_r20($b, 1, 2027);
$nn = celdas_no_nulas($siguiente);
check(($nn['1.1::RAI'] ?? null) === 5905357 && ($nn['1.1::' . $SAC] ?? null) === 494085, '1.1 del año siguiente = remanente finalizado (RAI 5.905.357, SAC 494.085)');
// El CPTS de 2027 arrastra el 1545 de 2026 (código 1445 del R19), así que el RAI se reconstruye con ese saldo:
// el reverso del 4.1 evita contarlo dos veces y el remanente RAI queda igual que el de 2026.
check($siguiente['remanenteSac'] === 494085 && $siguiente['remanenteRai'] === 5905357, 'el año siguiente arrastra el SAC (494.085) y el RAI del CPTS (5.905.357) sin duplicarlo');

// Reabrir
$reabierto = ctrib_r20_reabrir($b, 1, 2026, 'prueba', 'Ajuste de retiros');
check($reabierto['estado'] === 'BORRADOR', 'reabrir deja el RTRE en borrador');

echo "Cifrax Tributario RTRE: celdas de iTributario reproducidas (RAI 7.384.509, SAC 461.532), reverso del subtotal, retiros, R21, DJ 1948 con certificados y remanente del año siguiente verificados\n";
