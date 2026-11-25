<?php
// Prueba de los modos de retiros del año en Cifrax Tributario: importados, sin retiros y retiro de la totalidad
// (la base imponible completa se atribuye a los socios según su participación; sirve para 14 D N°8).
// Uso: php tools/test-ctrib-retiros-modo.php
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
function linea(array $r, string $codigo): int
{
    foreach ($r['lineas'] as $l) {
        if ($l['codigo'] === $codigo) {
            return (int) $l['monto'];
        }
    }
    throw new RuntimeException("sin línea $codigo");
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

// --- Por defecto: retiros importados (ninguno todavía) ---
check(ctrib_retiros_modo($pdo, 1, $anio) === 'importados', 'modo por defecto: retiros importados');
check(ctrib_retiros_del_anio($pdo, 1, $anio)['total'] === 0, 'sin retiros importados el total es cero');
falla(fn() => ctrib_retiros_modo_guardar($pdo, 1, $anio, 'otro', [], 'prueba'), 'no válido', 'modo inexistente');

// --- Sin retiros: borra lo importado y lo deja declarado ---
ctrib_retiros_importar($pdo, 1, $anio, [['rut' => '12345678-9', 'nombre' => 'Socio Uno', 'fecha' => '2025-03-15', 'monto' => 600000, 'tipo' => 'Retiro']], 'r.csv', 'prueba');
check(ctrib_retiros_del_anio($pdo, 1, $anio)['total'] === 600000, 'retiros importados: 600.000');
ctrib_retiros_modo_guardar($pdo, 1, $anio, 'sin_retiros', [], 'prueba');
$ef = ctrib_retiros_del_anio($pdo, 1, $anio);
check($ef['modo'] === 'sin_retiros' && $ef['total'] === 0 && $ef['retiros'] === [], 'sin retiros: total cero y sin filas');
check(ctrib_retiros_total($pdo, 1, $anio) === 0, 'sin retiros borra los retiros importados del año');
check(linea(ctrib_r19($pdo, 1, $anio), '1479') === 0, 'R19 1479 en cero sin retiros');
check(ctrib_dj1948($pdo, 1, $anio)['socios'] === [] && ctrib_dj1948($pdo, 1, $anio)['modo'] === 'sin_retiros', 'DJ 1948 sin socios y con el modo declarado');
check(count(array_filter(ctrib_historial_listar($pdo, 1, $anio, 'retiros'), fn($h) => $h['referencia'] === 'modo' && $h['despues'] === 'sin_retiros')) === 1, 'el cambio de modo queda en el historial');

// Importar de nuevo vuelve al modo de retiros importados.
ctrib_retiros_importar($pdo, 1, $anio, [['rut' => '12345678-9', 'nombre' => 'Socio Uno', 'fecha' => '2025-03-15', 'monto' => 100, 'tipo' => 'Retiro']], 'r.csv', 'prueba');
check(ctrib_retiros_modo($pdo, 1, $anio) === 'importados', 'importar retiros vuelve al modo importados');

// --- Retiro de la totalidad: validaciones de socios ---
falla(fn() => ctrib_retiros_modo_guardar($pdo, 1, $anio, 'totalidad', [], 'prueba'), 'indique los socios', 'totalidad sin socios');
falla(fn() => ctrib_retiros_modo_guardar($pdo, 1, $anio, 'totalidad', [['rut' => '1-9', 'nombre' => 'A', 'participacion' => 90]], 'prueba'), 'sumar 100', 'participación que no suma 100');
falla(fn() => ctrib_retiros_modo_guardar($pdo, 1, $anio, 'totalidad', [['rut' => '1-9', 'nombre' => 'A', 'participacion' => 0], ['rut' => '2-7', 'participacion' => 100]], 'prueba'), 'mayor que 0', 'participación cero');
falla(fn() => ctrib_retiros_modo_guardar($pdo, 1, $anio, 'totalidad', [['rut' => '1-9', 'participacion' => 50], ['rut' => '1-9', 'participacion' => 50]], 'prueba'), 'más de una vez', 'socio repetido');
falla(fn() => ctrib_retiros_modo_guardar($pdo, 1, $anio, 'totalidad', [['rut' => '', 'nombre' => 'Sin RUT', 'participacion' => 100]], 'prueba'), 'falta el RUT', 'socio sin RUT');
check(ctrib_retiros_modo($pdo, 1, $anio) === 'importados', 'un rechazo no cambia el modo');

// --- Retiro de la totalidad con dos socios (60% y 40%) ---
ctrib_retiros_modo_guardar($pdo, 1, $anio, 'totalidad', [
    ['rut' => '12345678-9', 'nombre' => 'Socio Uno', 'participacion' => 60],
    ['rut' => '98765432-1', 'nombre' => 'Socio Dos', 'participacion' => '40'],
    ['rut' => '', 'nombre' => '', 'participacion' => ''], // fila vacía del formulario: se ignora
], 'prueba');
$r17 = ctrib_r17($pdo, 1, $anio);
check($r17['modoRetiros'] === 'totalidad' && $r17['totales']['1432'] === 0, 'R17: sin incentivo al ahorro cuando se retira todo');
check($r17['totales']['1729'] === 6905357 && $r17['totales']['1440'] === 6905357, 'R17: la base imponible 1440 es la 1729 completa (6.905.357)');
$ef = ctrib_retiros_del_anio($pdo, 1, $anio);
check($ef['total'] === 6905357 && count($ef['retiros']) === 2, 'se atribuye la base imponible completa a los socios');
$porRut = array_column($ef['retiros'], 'monto', 'rut');
check($porRut['12345678-9'] === 4143214 && $porRut['98765432-1'] === 2762143, 'reparto 60/40 exacto por mayor resto (4.143.214 y 2.762.143)');
check($ef['retiros'][0]['tipo'] === 'Atribución total' && $ef['retiros'][0]['fecha'] === '2025-12-31', 'atribución al 31-12 del año comercial');
check(ctrib_retiros_total($pdo, 1, $anio) === 100, 'los retiros importados no se borran en modo totalidad (quedan sin usar)');

$r19 = ctrib_r19($pdo, 1, $anio);
check(linea($r19, '1479') === 6905357 && $r19['totales']['1545'] === 0, 'R19: 1479 con todo lo atribuido y CPTS final en cero');
$r20 = ctrib_r20($pdo, 1, $anio);
check($r20['rai'] === 6905357 && $r20['retirosImputadosRai'] === 6905357 && $r20['remanenteRai'] === 0, 'RTRE: todo el RAI se imputa a los retiros y no queda remanente');
check(linea(ctrib_r6($pdo, 1, $anio), '1193') === 0, 'R6 1193: sin retiros excedentes');

$dj = ctrib_dj1948($pdo, 1, $anio);
check($dj['modo'] === 'totalidad' && count($dj['socios']) === 2 && $dj['total'] === 6905357, 'DJ 1948: dos socios con la atribución total');
check(count($dj['sociosParticipacion']) === 2 && $dj['sociosParticipacion'][0]['participacion'] === 60.0, 'DJ 1948 devuelve los socios con su participación');

// Certificados en modo totalidad (requiere RTRE finalizado).
ctrib_r17_finalizar($pdo, 1, $anio, 'prueba');
ctrib_r19_finalizar($pdo, 1, $anio, 'prueba');
ctrib_r20_finalizar($pdo, 1, $anio, 'prueba');
check(ctrib_certificado_emitir($pdo, 1, $anio, '98765432-1', 'prueba')['numero'] === 1, 'certificado para un socio de la atribución total');
falla(fn() => ctrib_certificado_emitir($pdo, 1, $anio, '11111111-1', 'prueba'), 'no tiene retiros', 'RUT ajeno a la atribución');

echo "Cifrax Tributario modos de retiros: importados, sin retiros y retiro de la totalidad (R17, R19, RTRE, R6, DJ 1948 y certificados) verificados\n";
