<?php
// Prueba del Recuadro 19 y de los retiros de Cifrax Tributario con la semilla ENYEL.
// Uso: php tools/test-ctrib-r19.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/ctrib-r19.php';

function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FALLA: $msg\n"); exit(1); } }
function falla(callable $accion, string $contiene, string $msg): void
{
    try { $accion(); } catch (Throwable $e) {
        check(str_contains($e->getMessage(), $contiene), "$msg (mensaje: " . $e->getMessage() . ")");
        return;
    }
    check(false, "$msg (no lanzó error)");
}
function linea(array $recuadro, string $codigo): array
{
    foreach ($recuadro['lineas'] as $l) {
        if ($l['codigo'] === $codigo) {
            return $l;
        }
    }
    throw new RuntimeException("sin línea $codigo");
}

// Fechas: texto, dd/mm/aaaa y número de serie de Excel.
check(ctrib_fecha_texto('15/03/2025') === '2025-03-15' && ctrib_fecha_texto('2025-09-30') === '2025-09-30', 'fechas en texto');
check(ctrib_fecha_texto(45658) === '2025-01-01', 'fecha de serie de Excel 45658 = 2025-01-01');
check(ctrib_fecha_texto('31/02/2025') === null && ctrib_fecha_texto('abc') === null, 'fechas inválidas');

$pdo = base_prueba();
crearEsquema($pdo);
ctrib_schema($pdo);
$semilla = json_decode((string) file_get_contents(dirname(__DIR__) . '/api/lib/ctrib/enyel-2025.json'), true, 512, JSON_THROW_ON_ERROR);
$anio = 2026;
$usuario = 'prueba';
ctrib_mapeo_guardar($pdo, 1, $semilla['mapeo'], $usuario);
ctrib_balance_guardar($pdo, 1, $anio, 'itributario', $semilla['balance'], 'captura', $usuario);
ctrib_homologacion_importar($pdo, 1, $anio, $semilla['homologacion_itributario'], $usuario);
ctrib_homologar($pdo, 1, $anio, '406001', '1419', $usuario, 'Corrección ENYEL');

// --- Sin retiros: R19 calculado desde el R17 corregido ---
$r19 = ctrib_r19($pdo, 1, $anio);
check(linea($r19, '1705')['monto'] === 3452678 && linea($r19, '1710')['monto'] === 3452679, '1705 = 1440 (3.452.678) y 1710 = 1432 (3.452.679)');
check($r19['totales'] === ['1545' => 6905357, '1546' => 0], 'CPTS positivo final 6.905.357 (igual a la utilidad del balance), negativo 0');
check(linea($r19, '1445')['origen'] === 'SIN_ANTERIOR_FINALIZADO' && linea($r19, '1445')['monto'] === 0, 'sin R19 del año anterior: 1445 queda en cero y manual');
check(count($r19['bloqueos']) === 1 && str_contains($r19['bloqueos'][0], 'Recuadro 17'), 'R19 exige R17 finalizado');
falla(fn() => ctrib_r19_finalizar($pdo, 1, $anio, $usuario), 'Recuadro 17', 'no se finaliza el R19 con el R17 en borrador');

// --- Retiros: importación ---
$csv = sys_get_temp_dir() . '/ctrib-retiros-' . bin2hex(random_bytes(3)) . '.csv';
file_put_contents($csv, "\xEF\xBB\xBFRUT;Nombre socio;Fecha;Monto;Tipo\n12.345.678-9;Socio Uno;15/03/2025;600.000;Retiro\n98.765.432-1;Socio Dos;2025-09-30;400000;Dividendo\n");
$retiros = ctrib_retiros_desde_archivo($csv, 'csv');
unlink($csv);
check(count($retiros) === 2 && $retiros[1]['tipo'] === 'Dividendo' && $retiros[0]['monto'] === 600000, 'lectura del CSV de retiros');
falla(fn() => ctrib_retiros_importar($pdo, 1, $anio, [['rut' => '1-9', 'nombre' => 'X', 'fecha' => '2026-03-01', 'monto' => 10, 'tipo' => 'Retiro']], 'x.csv', $usuario), 'fuera del año comercial', 'retiro fuera del año comercial');
falla(fn() => ctrib_retiros_desde_archivo("data:text/csv,sin+encabezados", 'csv'), 'encabezados', 'sin encabezados de retiros');
$importado = ctrib_retiros_importar($pdo, 1, $anio, $retiros, 'retiros.csv', $usuario);
check($importado === ['cantidad' => 2, 'total' => 1000000], 'retiros importados: 2 por 1.000.000');

// Los retiros bajan el CPTS final y suben la base que toma el tope del incentivo.
$r19 = ctrib_r19($pdo, 1, $anio);
check(linea($r19, '1479')['monto'] === 1000000, '1479 = retiros del año');
check($r19['totales']['1545'] === 5905357, 'CPTS positivo final baja por los retiros: 5.905.357');
$r17 = ctrib_r17($pdo, 1, $anio);
check($r17['totales']['1432'] === 2952679 && $r17['totales']['1440'] === 3952678, 'con retiros, el incentivo 1432 usa la base menos retiros (2.952.679)');

// --- Finalizar R17 y luego R19 ---
ctrib_incentivo_guardar($pdo, 1, $anio, true, true, true, null, $usuario); // confirmaciones del 1432
ctrib_r17_finalizar($pdo, 1, $anio, $usuario);
$r19 = ctrib_r19_finalizar($pdo, 1, $anio, $usuario);
check($r19['estado'] === 'FINALIZADO', 'R19 finalizado con R17 finalizado');
falla(fn() => ctrib_r19_guardar_valor($pdo, 1, $anio, '1374', 100, $usuario), 'finalizado', 'R19 finalizado no se edita');
falla(fn() => ctrib_r19_finalizar($pdo, 1, $anio, $usuario), 'ya está finalizado', 'no se finaliza dos veces');

// --- Año siguiente: 1445 toma el 1545 finalizado del año anterior, y se puede sobrescribir ---
$siguiente = ctrib_r19($pdo, 1, 2027);
check(linea($siguiente, '1445')['monto'] === 5905357 && linea($siguiente, '1445')['origen'] === 'AÑO_ANTERIOR', '1445 de 2027 = 1545 finalizado de 2026');
check(linea($siguiente, '1446')['monto'] === 0, '1446 de 2027 = 1546 finalizado de 2026 (cero)');
$sobrescrito = ctrib_r19_guardar_valor($pdo, 1, 2027, '1445', 100, $usuario);
check(linea($sobrescrito, '1445')['monto'] === 100 && linea($sobrescrito, '1445')['origen'] === 'MANUAL', 'sobrescritura manual de 1445');
check($sobrescrito['totales']['1545'] === 100, 'la sobrescritura entra al CPTS final');
$quitado = ctrib_r19_guardar_valor($pdo, 1, 2027, '1445', null, $usuario);
check(linea($quitado, '1445')['monto'] === 5905357, 'quitar la sobrescritura vuelve al valor del año anterior');
falla(fn() => ctrib_r19_guardar_valor($pdo, 1, 2027, '1705', 5, $usuario), 'no se ingresa a mano', 'código automático no se sobrescribe');

// --- Cambio de retiros después de finalizar: queda desactualizado ---
ctrib_retiros_importar($pdo, 1, $anio, [['rut' => '12345678-9', 'nombre' => 'Socio Uno', 'fecha' => '2025-03-15', 'monto' => 500000, 'tipo' => 'Retiro']], 'retiros2.csv', $usuario);
$r19 = ctrib_r19($pdo, 1, $anio);
check($r19['desactualizado'] === true && $r19['estado'] === 'FINALIZADO', 'R19 desactualizado tras cambiar retiros');
check(ctrib_r17($pdo, 1, $anio)['desactualizado'] === true, 'R17 también queda desactualizado (su 1432 depende de los retiros)');

// --- Reabrir ---
$reabierto = ctrib_r19_reabrir($pdo, 1, $anio, $usuario, 'Ajuste de retiros');
check($reabierto['estado'] === 'BORRADOR', 'reabrir deja el R19 en borrador');
falla(fn() => ctrib_r19_reabrir($pdo, 1, $anio, $usuario, ''), 'no está finalizado', 'no se reabre un borrador');
$historial = ctrib_historial_listar($pdo, 1, $anio, 'recuadro');
check(count(array_filter($historial, fn($h) => $h['referencia'] === 'R19' && $h['motivo'] === 'Ajuste de retiros')) === 1, 'historial de reapertura del R19');

echo "Cifrax Tributario R19 y retiros: automáticos desde el R17, retiros, año anterior con sobrescritura, CPTS final, finalizar, desactualizado y reabrir verificados\n";
