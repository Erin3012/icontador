<?php
// Prueba del cuadro de composición y retiros del año (Cifrax Tributario): EIRL con un titular, sociedades,
// sin retiros y 14 D N°8 (retiro de la totalidad). Uso: php tools/test-ctrib-composicion.php
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

$pdo = base_prueba();
crearEsquema($pdo);
ctrib_schema($pdo);
$semilla = json_decode((string) file_get_contents(dirname(__DIR__) . '/api/lib/ctrib/enyel-2025.json'), true, 512, JSON_THROW_ON_ERROR);
ctrib_mapeo_guardar($pdo, 1, $semilla['mapeo'], 'prueba');
ctrib_balance_guardar($pdo, 1, 2026, 'itributario', $semilla['balance'], 'captura', 'prueba');
ctrib_homologacion_importar($pdo, 1, 2026, $semilla['homologacion_itributario'], 'prueba');
ctrib_homologar($pdo, 1, 2026, '406001', '1419', 'prueba', 'Corrección ENYEL');
$anio = 2026;
$angela = ['rut' => '', 'nombre' => 'ANGELA SOBARZO RAMÍREZ', 'participacion' => 100];

// --- Validaciones ---
falla(fn() => ctrib_composicion_guardar($pdo, 1, $anio, 'XX', '14D3', [$angela], false, 'prueba'), 'tipo de contribuyente', 'tipo inválido');
falla(fn() => ctrib_composicion_guardar($pdo, 1, $anio, 'EIRL', '14Z', [$angela], false, 'prueba'), 'régimen', 'régimen inválido');
falla(fn() => ctrib_composicion_guardar($pdo, 1, $anio, 'EIRL', '14D3', [], false, 'prueba'), 'al menos un socio', 'sin socios');
falla(fn() => ctrib_composicion_guardar($pdo, 1, $anio, 'EIRL', '14D3', [['rut' => '1-9', 'nombre' => 'A', 'participacion' => 50], ['rut' => '2-7', 'nombre' => 'B', 'participacion' => 50]], false, 'prueba'), 'un solo titular', 'EIRL con dos titulares');
falla(fn() => ctrib_composicion_guardar($pdo, 1, $anio, 'SPA', '14D3', [['rut' => '1-9', 'nombre' => 'A', 'participacion' => 70]], false, 'prueba'), 'sumar 100', 'participación incompleta');
falla(fn() => ctrib_composicion_guardar($pdo, 1, $anio, 'SPA', '14D3', [['rut' => '', 'nombre' => 'A', 'participacion' => 50], ['rut' => '', 'nombre' => 'B', 'participacion' => 50]], false, 'prueba'), 'sin RUT', 'dos socios sin RUT');
falla(fn() => ctrib_composicion_guardar($pdo, 1, $anio, 'EIRL', '14D3', [$angela + ['monto' => -5]], false, 'prueba'), 'monto retirado', 'monto negativo');

// --- ENYEL: EIRL, 14 D N°3, titular Angela con el 100%, sin RUT todavía ---
$c = ctrib_composicion_guardar($pdo, 1, $anio, 'EIRL', '14D3', [$angela + ['monto' => 0]], false, 'prueba');
check($c['tipo'] === 'EIRL' && $c['regimen'] === '14D3' && count($c['socios']) === 1, 'ENYEL: EIRL 14 D N°3 con una titular');
check($c['socios'][0]['utilidad'] === 6905357 && $c['socios'][0]['retirado'] === 0, 'a la titular le corresponde toda la utilidad (6.905.357) y aún no retira');
check($c['modo'] === 'importados' && $c['totalRetirado'] === 0, 'sin monto: retiros pendientes (no se declara "sin retiros" solo)');
check(count($c['avisos']) === 1 && str_contains($c['avisos'][0], 'RUT'), 'aviso: falta el RUT de la titular');

// Retiro de la titular en el año: se atribuye completo a ella.
$c = ctrib_composicion_guardar($pdo, 1, $anio, 'EIRL', '14D3', [$angela + ['monto' => '1500000']], false, 'prueba');
check($c['socios'][0]['retirado'] === 1500000 && $c['totalRetirado'] === 1500000, 'retiro de 1.500.000 atribuido completo a la titular');
$r19 = ctrib_r19($pdo, 1, $anio);
check(array_column($r19['lineas'], 'monto', 'codigo')['1479'] === 1500000, 'R19 1479 con el retiro de la titular');
check(ctrib_dj1948($pdo, 1, $anio)['socios'][0]['total'] === 1500000, 'DJ 1948 con la titular y su retiro');

// Guardar otra vez con el mismo monto no toca los retiros.
$historialAntes = count(ctrib_historial_listar($pdo, 1, $anio, 'retiros'));
ctrib_composicion_guardar($pdo, 1, $anio, 'EIRL', '14D3', [$angela + ['monto' => 1500000]], false, 'prueba');
check(count(ctrib_historial_listar($pdo, 1, $anio, 'retiros')) === $historialAntes, 'mismo monto: los retiros se conservan sin cambios');

// Retiros importados con varias fechas se conservan si el total por socio coincide.
ctrib_retiros_importar($pdo, 1, $anio, [
    ['rut' => '', 'nombre' => 'ANGELA SOBARZO RAMÍREZ', 'fecha' => '2025-04-10', 'monto' => 500000, 'tipo' => 'Retiro'],
    ['rut' => '', 'nombre' => 'ANGELA SOBARZO RAMÍREZ', 'fecha' => '2025-10-10', 'monto' => 1000000, 'tipo' => 'Retiro'],
], 'retiros.csv', 'prueba');
ctrib_composicion_guardar($pdo, 1, $anio, 'EIRL', '14D3', [$angela + ['monto' => 1500000]], false, 'prueba');
check(count(ctrib_retiros_listar($pdo, 1, $anio)) === 2, 'retiros importados (dos fechas) conservados cuando el total coincide');

// --- Sin retiros ---
$c = ctrib_composicion_guardar($pdo, 1, $anio, 'EIRL', '14D3', [$angela], true, 'prueba');
check($c['modo'] === 'sin_retiros' && $c['totalRetirado'] === 0 && ctrib_retiros_total($pdo, 1, $anio) === 0, 'no hubo retiros: modo sin retiros y retiros borrados');

// --- 14 D N°8: se retira la totalidad ---
$c = ctrib_composicion_guardar($pdo, 1, $anio, 'EIRL', '14D8', [$angela], false, 'prueba');
check($c['modo'] === 'totalidad' && $c['socios'][0]['retirado'] === 6905357, '14 D N°8: la titular retira la totalidad de la base (6.905.357)');

// --- Sociedad con dos socios (montos distintos por socio) ---
$c = ctrib_composicion_guardar($pdo, 1, $anio, 'SPA', '14D3', [
    ['rut' => '12345678-9', 'nombre' => 'Socio Uno', 'participacion' => 60, 'monto' => 600000],
    ['rut' => '98765432-1', 'nombre' => 'Socio Dos', 'participacion' => 40, 'monto' => 0],
], false, 'prueba');
$porRut = array_column($c['socios'], null, 'rut');
check($porRut['12345678-9']['utilidad'] === 4143214 && $porRut['98765432-1']['utilidad'] === 2762143, 'utilidad repartida 60/40');
check($porRut['12345678-9']['retirado'] === 600000 && $porRut['98765432-1']['retirado'] === 0 && $c['modo'] === 'importados', 'retiros por socio');

echo "Cifrax Tributario composición y retiros: EIRL con titular única, sociedades, sin retiros y 14 D N°8 verificados\n";
