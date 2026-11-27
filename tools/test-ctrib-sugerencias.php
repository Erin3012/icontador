<?php
// Prueba de las sugerencias de homologación desde el plan de cuentas IFRS tributario del SII y de la consulta de ese plan.
// Uso: php tools/test-ctrib-sugerencias.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/ctrib-pdf.php';
require dirname(__DIR__) . '/api/lib/ctrib-sugerencias.php';

function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FALLA: $msg\n"); exit(1); } }

$pdo = base_prueba();
empresas_schema($pdo);
crearEsquema($pdo);
ctrib_schema($pdo);
$pdo->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')
    ->execute(['1', 'ENYEL PRUEBA', json_encode(['rut' => '76000000-0']), '2026-11-26']);
$e = (int) $pdo->lastInsertId();
$semilla = json_decode((string) file_get_contents(dirname(__DIR__) . '/api/lib/ctrib/enyel-2025.json'), true, 512, JSON_THROW_ON_ERROR);
ctrib_mapeo_guardar($pdo, $e, $semilla['mapeo'], 'prueba');
ctrib_balance_guardar($pdo, $e, 2026, 'itributario', $semilla['balance'], 'captura', 'prueba');
ctrib_homologacion_importar($pdo, $e, 2026, $semilla['homologacion_itributario'], 'prueba');
ctrib_homologar($pdo, $e, 2026, '406001', '1419', 'prueba', 'Corrección');

// Las 14 cuentas de resultado de ENYEL: la sugerencia coincide con la homologación del contador.
$h = ctrib_homologacion_con_sugerencias($pdo, $e, 2026);
check(count($h['cuentasResultado']) === 14, '14 cuentas de resultado');
foreach ($h['cuentasResultado'] as $c) {
    check($c['sugerencia']['codigo'] === $c['codigo'], "sugerencia de {$c['cuenta']} {$c['nombre']}: {$c['sugerencia']['codigo']} (homologada en {$c['codigo']})");
    check(ctrib_compatible($c['tipo'], $c['sugerencia']['codigo']) === null, "sugerencia compatible para {$c['cuenta']}");
    check(isset(ctrib_plan_sii_lineas()[$c['sugerencia']['planSii']['codigo']]), "línea del plan SII válida para {$c['cuenta']}");
}
check($h['sugerenciasAplicables'] === 0 && $h['pendientes'] === 0, 'sin cuentas pendientes no hay sugerencias por aplicar');

// Reglas, parecido con el plan SII y respaldo por tipo.
$s = ctrib_sugerencia('DEPRECIACIÓN DEL EJERCICIO', 'PERDIDA');
check($s['codigo'] === null && $s['planSii']['codigo'] === '3.03.01.00', 'depreciación: sin código (Pro Pyme no la deduce)');
check(ctrib_sugerencia('Sueldos y salarios', 'PERDIDA')['codigo'] === '1411', 'sueldos → 1411');
check(ctrib_sugerencia('MULTAS E INTERESES SII', 'PERDIDA')['codigo'] === '1422', 'multas fiscales → 1422');
check(ctrib_sugerencia('INTERESES GANADOS', 'GANANCIA')['codigo'] === '1402', 'intereses ganados → 1402');
check(ctrib_sugerencia('Utilidad venta de acciones', 'GANANCIA')['codigo'] === '1403', 'mayor valor en acciones → 1403');
$s = ctrib_sugerencia('OTROS EGRESOS FUERA DE EXPLOTACIÓN', 'PERDIDA');
check($s['confianza'] === 'media' && $s['planSii']['codigo'] === '3.05.10.00' && $s['codigo'] === '1424', 'parecido con la línea del plan SII');
check(ctrib_sugerencia('CUENTA XYZ', 'GANANCIA')['codigo'] === '1400' && ctrib_sugerencia('CUENTA XYZ', 'PERDIDA')['codigo'] === '1424', 'sin regla: por tipo de cuenta');
check(ctrib_sugerencia('Intereses', 'GANANCIA')['codigo'] === '1402' && ctrib_sugerencia('Intereses', 'PERDIDA')['codigo'] === '1419', 'el mismo nombre se sugiere según el tipo');

// Aplicar: solo a las cuentas sin código, sin tocar las asignadas, y con historial.
ctrib_homologar($pdo, $e, 2026, '405029', '', 'p', 'Prueba');
ctrib_homologar($pdo, $e, 2026, '505002', '', 'p', 'Prueba');
ctrib_homologar($pdo, $e, 2026, '405010', '1409', 'p', 'Decisión del usuario');
$h = ctrib_homologacion_con_sugerencias($pdo, $e, 2026);
check($h['pendientes'] === 2 && $h['sugerenciasAplicables'] === 2, 'dos cuentas sin código con sugerencia');
$r = ctrib_homologacion_aplicar_sugerencias($pdo, $e, 2026, 'p');
$asig = ctrib_asignaciones($pdo, $e, 2026);
check($r['aplicadas'] === 2 && $r['pendientes'] === 0 && $asig['405029'] === '1415' && $asig['505002'] === '1400', 'se aplican las dos sugerencias');
check($asig['405010'] === '1409', 'una asignación hecha por el usuario no se cambia');
$motivos = array_column(ctrib_historial_listar($pdo, $e, 2026, 'homologacion'), 'motivo');
check(count(array_keys($motivos, 'Sugerencia aplicada (plan SII)', true)) === 2, 'historial de las sugerencias aplicadas');
check(ctrib_homologacion_aplicar_sugerencias($pdo, $e, 2026, 'p')['aplicadas'] === 0, 'aplicar otra vez no cambia nada');

// Plan de cuentas del SII para la consulta.
$p = ctrib_plan_sii();
check(count($p['cuentas']) === 409, 'plan SII con 409 líneas');
check($p['grupos'] === ['ACTIVO' => 69, 'PASIVO' => 33, 'PATRIMONIO' => 15, 'RESULTADO' => 52, 'AJUSTE_TRIBUTARIO' => 240], 'grupos del plan SII');
$por = array_column($p['cuentas'], null, 'codigo');
check($por['1.00.00.00']['nivel'] === 1 && $por['1.01.00.00']['nivel'] === 2 && $por['1.01.01.00']['nivel'] === 3 && $por['5.01.01.01']['nivel'] === 4, 'niveles según los segmentos del código');
check($por['3.01.02.00']['codigoR17'] === '1409' && $por['3.01.00.00']['codigoR17'] === null && $por['1.01.01.00']['codigoR17'] === null, 'código R17 solo en líneas RESULTADO');
check($por['3.05.01.00']['codigoR17'] === '1403 (ingreso) / 1423 (gasto)', 'código R17 según el tipo de cuenta');

echo "Cifrax Tributario sugerencias de homologación (14 de 14 en ENYEL, reglas, parecido, por tipo, aplicar) y plan de cuentas SII verificados\n";
