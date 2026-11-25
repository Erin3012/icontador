<?php
// Prueba de la homologación de Cifrax Tributario: validaciones, alertas, corrección 406001 (1402 → 1419) e historial.
// Uso: php tools/test-ctrib-homologacion.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/ctrib.php';

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
$anio = 2026;
$usuario = 'prueba';
ctrib_mapeo_guardar($pdo, 1, $semilla['mapeo'], $usuario);

// Sin balance no se puede homologar.
falla(fn() => ctrib_homologar($pdo, 1, $anio, '406001', '1419', $usuario), 'Aún no hay balance', 'homologar sin balance debe rechazarse');

ctrib_balance_guardar($pdo, 1, $anio, 'itributario', $semilla['balance'], 'captura', $usuario);

// Homologación tal como estaba en iTributario (incluye el error 406001 → 1402).
$cargadas = ctrib_homologacion_importar($pdo, 1, $anio, $semilla['homologacion_itributario'], $usuario);
check($cargadas === 14, "14 cuentas de resultado importadas, hubo $cargadas");

$vista = ctrib_homologacion($pdo, 1, $anio);
check($vista['pendientes'] === 0, 'todas las cuentas de resultado están asignadas');
check($vista['alertas'] === 1, 'una alerta: 406001 (gasto) asignada a 1402 (ingreso)');
$alerta = array_values(array_filter($vista['cuentasResultado'], fn($c) => $c['cuenta'] === '406001'))[0];
check($alerta['codigo'] === '1402' && $alerta['alerta'] !== null && str_contains($alerta['alerta'], 'ingresos'), 'la alerta explica la incompatibilidad');
check($alerta['saldo'] === 239576, 'saldo de 406001 (gasto) 239.576');
$codigo1402 = array_values(array_filter($vista['codigos'], fn($c) => $c['codigo'] === '1402'))[0];
check(count($codigo1402['cuentas']) === 1 && $codigo1402['seccion'] === 'INGRESOS', '1402 es de ingresos y tiene una cuenta');

// Corrección: 406001 pasa a 1419 (intereses pagados). Queda en el historial con el valor anterior.
$cambio = ctrib_homologar($pdo, 1, $anio, '406001', '1419', $usuario, 'Corrección: intereses bancarios pagados');
check($cambio['cambio'] === true && $cambio['antes'] === '1402', 'corrección 1402 → 1419');
$vista = ctrib_homologacion($pdo, 1, $anio);
check($vista['alertas'] === 0, 'la corrección elimina la alerta');
$codigo1419 = array_values(array_filter($vista['codigos'], fn($c) => $c['codigo'] === '1419'))[0];
check(count($codigo1419['cuentas']) === 1 && $codigo1419['cuentas'][0]['cuenta'] === '406001', '1419 ahora tiene 406001');
$historial = ctrib_historial_listar($pdo, 1, $anio, 'homologacion');
check(count($historial) === 2 && $historial[0]['antes'] === '1402' && $historial[0]['despues'] === '1419' && $historial[0]['motivo'] === 'Corrección: intereses bancarios pagados', 'historial de la corrección');

// Sin cambio: misma asignación no genera historial.
$igual = ctrib_homologar($pdo, 1, $anio, '406001', '1419', $usuario);
check($igual['cambio'] === false && count(ctrib_historial_listar($pdo, 1, $anio, 'homologacion')) === 2, 'misma asignación no cambia nada');

// Reglas: ingresos no van a gastos, egresos no van a ingresos, cuentas de balance no se homologan.
falla(fn() => ctrib_homologar($pdo, 1, $anio, '505002', '1409', $usuario), 'egresos', 'ingreso no puede ir a un código de egresos');
falla(fn() => ctrib_homologar($pdo, 1, $anio, '404001', '1400', $usuario), 'ingresos', 'gasto no puede ir a un código de ingresos');
falla(fn() => ctrib_homologar($pdo, 1, $anio, '101002', '1400', $usuario), 'es de balance', 'cuenta de balance no se homologa');
falla(fn() => ctrib_homologar($pdo, 1, $anio, '404001', '1430', $usuario), 'se calcula solo', 'código calculado no admite cuentas');
falla(fn() => ctrib_homologar($pdo, 1, $anio, '404001', '9999', $usuario), 'no existe', 'código inexistente');
falla(fn() => ctrib_homologar($pdo, 1, $anio, '999999', '1409', $usuario), 'no está en el balance', 'cuenta fuera del balance');

// Quitar una asignación deja la cuenta pendiente.
$quitar = ctrib_homologar($pdo, 1, $anio, '405038', '', $usuario, 'Prueba de quitar');
check($quitar['cambio'] === true && $quitar['codigo'] === '', 'quitar asignación');
check(ctrib_homologacion($pdo, 1, $anio)['pendientes'] === 1, 'una cuenta pendiente tras quitar');

// La homologación es por año: 2027 no tiene balance ni asignaciones.
check(ctrib_homologacion($pdo, 1, 2027)['cuentasResultado'] === [], 'homologación separada por año');

echo "Cifrax Tributario homologación: alertas, corrección 406001 → 1419, reglas de compatibilidad e historial verificados\n";
