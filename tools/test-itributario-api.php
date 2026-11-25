<?php
// Prueba de la configuración de iTributario y de las solicitudes de sincronización: php tools/test-itributario-api.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/itributario.php';
function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FALLA: $msg\n"); exit(1); } }

$db = base_prueba();
itrib_schema($db);
itrib_schema($db); // idempotente

// Sin registro, la preferencia es preguntar siempre.
check(itrib_preferencia($db, 1) === 'ask', 'preferencia por defecto');

// Guardar y reemplazar la preferencia de la empresa 1 sin afectar a otras.
itrib_guardar_preferencia($db, 1, 'S');
check(itrib_preferencia($db, 1) === 'S', 'guardar S');
itrib_guardar_preferencia($db, 1, 'N');
check(itrib_preferencia($db, 1) === 'N', 'reemplazar por N');
check(itrib_preferencia($db, 2) === 'ask', 'preferencia separada por empresa');
foreach (['', 'x', 'ask ', 'Y'] as $malo) {
    try { itrib_guardar_preferencia($db, 1, $malo); check(false, "debió rechazar preferencia '$malo'"); } catch (ItribError) {}
}
check(itrib_preferencia($db, 1) === 'N', 'un rechazo no cambia la preferencia');

// Solicitud válida: queda pendiente y con el mensaje de envío no conectado.
$id = itrib_solicitar_sincronizacion($db, 1, '2026-12', 'manual', null);
check($id > 0, 'solicitud registrada');
$solicitudes = itrib_historial($db, 1);
check(count($solicitudes) === 1 && $solicitudes[0]['estado'] === 'pendiente' && $solicitudes[0]['periodo'] === '2026-12', 'solicitud pendiente');
check($solicitudes[0]['mensaje'] === ITRIB_MENSAJE_PENDIENTE, 'mensaje de envío no conectado');
check(itrib_historial($db, 2) === [], 'historial separado por empresa');

// Validaciones de período y origen.
foreach ([['2026-13', 'manual'], ['26-12', 'manual'], ['2026-12', 'otro'], ['', 'manual']] as [$periodo, $origen]) {
    try { itrib_solicitar_sincronizacion($db, 1, $periodo, $origen, null); check(false, "debió rechazar $periodo $origen"); } catch (ItribError) {}
}
check(count(itrib_historial($db, 1)) === 1, 'un rechazo no crea solicitudes');

// Historial: la más reciente primero.
$id2 = itrib_solicitar_sincronizacion($db, 1, '2026-11', 'automatico', 7);
$historial = itrib_historial($db, 1);
check($historial[0]['id'] === $id2 && $historial[1]['id'] === $id, 'orden del historial');

echo "iTributario PHP: preferencia, solicitudes, validaciones e historial verificados\n";
