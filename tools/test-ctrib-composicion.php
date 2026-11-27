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
falla(fn() => ctrib_composicion_guardar($pdo, 1, $anio, 'EIRL', '14D3', [['rut' => '12.345.678-9', 'nombre' => 'X', 'participacion' => 100]], false, 'prueba'), 'dígito verificador', 'RUT con dígito verificador incorrecto');
$conRut = ctrib_composicion_guardar($pdo, 1, $anio, 'EIRL', '14D3', [['rut' => '12.345.678-5', 'nombre' => 'X', 'participacion' => 100]], false, 'prueba');
check($conRut['socios'][0]['rut'] === '12345678-5' && $conRut['avisos'] === [], 'RUT válido se guarda normalizado y sin aviso');

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
// La base del 14 D N°8 es la del R22 (percibido y pagado): también rebaja las compras de activo fijo del balance (3.139.900).
check($c['modo'] === 'totalidad' && $c['socios'][0]['retirado'] === 3765457, '14 D N°8: la titular retira la totalidad de la base del R22 (3.765.457)');

// --- Sociedad con dos socios (montos distintos por socio) ---
$c = ctrib_composicion_guardar($pdo, 1, $anio, 'SPA', '14D3', [
    ['rut' => '12345678-5', 'nombre' => 'Socio Uno', 'participacion' => 60, 'monto' => 600000],
    ['rut' => '98765432-5', 'nombre' => 'Socio Dos', 'participacion' => 40, 'monto' => 0],
], false, 'prueba');
$porRut = array_column($c['socios'], null, 'rut');
check($porRut['12345678-5']['utilidad'] === 4143214 && $porRut['98765432-5']['utilidad'] === 2762143, 'utilidad repartida 60/40');
check($porRut['12345678-5']['retirado'] === 600000 && $porRut['98765432-5']['retirado'] === 0 && $c['modo'] === 'importados', 'retiros por socio');

// --- El cuadro no borra retiros importados que el usuario no ha tocado ---
$nuevaBase = function () use ($semilla): PDO {
    $p = base_prueba();
    crearEsquema($p);
    ctrib_schema($p);
    ctrib_mapeo_guardar($p, 1, $semilla['mapeo'], 'prueba');
    ctrib_balance_guardar($p, 1, 2026, 'itributario', $semilla['balance'], 'captura', 'prueba');
    return $p;
};
$retiro = fn(string $rut, string $nombre, int $monto, string $fecha = '2025-04-10') => ['rut' => $rut, 'nombre' => $nombre, 'fecha' => $fecha, 'monto' => $monto, 'tipo' => 'Retiro'];
$socio = fn(string $rut, string $nombre, int $participacion, int $monto) => ['rut' => $rut, 'nombre' => $nombre, 'participacion' => $participacion, 'monto' => $monto];

$p = $nuevaBase();
ctrib_retiros_importar($p, 1, 2026, [$retiro('11.111.111-1', 'Socio Uno', 2000000)], 'r.csv', 'prueba');
check(ctrib_retiros_listar($p, 1, 2026)[0]['rut'] === '11111111-1', 'el RUT importado se guarda normalizado');
$c = ctrib_composicion_retiros($p, 1, 2026);
check(count(array_filter($c['avisos'], fn($a) => str_contains($a, 'no está en el cuadro'))) === 1, 'aviso de retiros fuera del cuadro');
$c = ctrib_composicion_guardar($p, 1, 2026, 'SPA', '14D3', [$socio('11.111.111-1', 'Socio Uno', 100, 0)], false, 'prueba');
check($c['totalRetirado'] === 2000000 && $c['socios'][0]['retirado'] === 2000000, 'un socio nuevo con monto cero no borra sus retiros');
check(ctrib_retiros_listar($p, 1, 2026)[0]['fecha'] === '2025-04-10', 'se conserva la fecha importada');
$c = ctrib_composicion_guardar($p, 1, 2026, 'SPA', '14D3', [$socio('11111111-1', 'Socio Uno', 100, 0)], false, 'prueba');
check($c['totalRetirado'] === 0, 'poner cero a un socio que ya estaba en el cuadro sí borra sus retiros');

$p = $nuevaBase();
ctrib_retiros_importar($p, 1, 2026, [$retiro('11111111-1', 'Socio Uno', 700000), $retiro('22222222-2', 'Socio Dos', 300000)], 'r.csv', 'prueba');
$c = ctrib_composicion_guardar($p, 1, 2026, 'SPA', '14D3', [$socio('11111111-1', 'Socio Uno', 100, 700000)], false, 'prueba');
check($c['totalRetirado'] === 1000000, 'los retiros de quien nunca estuvo en el cuadro se conservan');
ctrib_composicion_guardar($p, 1, 2026, 'SPA', '14D3', [$socio('11111111-1', 'Socio Uno', 60, 700000), $socio('22222222-2', 'Socio Dos', 40, 300000)], false, 'prueba');
$c = ctrib_composicion_guardar($p, 1, 2026, 'SPA', '14D3', [$socio('11111111-1', 'Socio Uno', 100, 700000)], false, 'prueba');
check($c['totalRetirado'] === 700000, 'quitar un socio del cuadro borra sus retiros');

$p = $nuevaBase();
ctrib_composicion_guardar($p, 1, 2026, 'EIRL', '14D3', [$socio('', 'Titular Prueba', 100, 0)], false, 'prueba');
ctrib_retiros_importar($p, 1, 2026, [$retiro('', 'titular prueba', 400000), $retiro('', 'Titular Prueba', 600000, '2025-09-10')], 'r.csv', 'prueba');
$c = ctrib_composicion_guardar($p, 1, 2026, 'EIRL', '14D3', [$socio('11111111-1', 'Titular Prueba', 100, 1000000)], false, 'prueba');
check($c['totalRetirado'] === 1000000 && count(ctrib_retiros_listar($p, 1, 2026)) === 2, 'al agregar el RUT, los retiros sin RUT se asignan y conservan sus fechas');

echo "Cifrax Tributario composición y retiros: EIRL con titular única, sociedades, sin retiros y 14 D N°8 verificados\n";
