<?php
// Prueba de enrolamiento de un solo servicio central: php tools/test-sii-emparejamiento.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/sii.php';
function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FALLA: $msg\n"); exit(1); } }

$db = base_prueba(); sii_schema($db); rcv_schema($db);
$codigo = sii_servicio_codigo_crear($db);
check((bool)preg_match('/^[A-Z2-9]{5}(-[A-Z2-9]{5}){3}$/', $codigo['codigo']), 'formato del código de servicio');
$guardado = (string)$db->query('SELECT codigo_hash FROM sii_servicio WHERE id = 1')->fetchColumn();
check($guardado === hash('sha256', str_replace('-', '', $codigo['codigo'])), 'solo persiste hash del código');
$token = sii_servicio_canjear($db, strtolower(str_replace('-', ' ', $codigo['codigo'])), "PC-CENTRAL\n");
check((bool)preg_match('/^[a-f0-9]{64}$/', $token), 'token técnico de 64 caracteres hex');
$fila = $db->query('SELECT token_hash, nombre FROM sii_servicio WHERE id = 1')->fetch();
check($fila['token_hash'] === hash('sha256', $token) && $fila['nombre'] === 'PC-CENTRAL', 'guarda hash y limpia el nombre');
try { sii_servicio_canjear($db, $codigo['codigo'], 'OTRO'); check(false, 'un código usado no debe canjearse otra vez'); } catch (SiiError) {}

$_SERVER['HTTP_X_AGENTE_TOKEN'] = $token; sii_servicio_autorizar($db);
$worker1 = '12345678-1234-4234-8234-123456789abc';
$worker2 = '22345678-1234-4234-8234-123456789abc';
check(sii_servicio_heartbeat($db, $worker1)['ocupado'] === false, 'una instancia toma el lease');
check(sii_servicio_heartbeat($db, $worker2)['ocupado'] === true, 'la segunda instancia queda esperando');
check(sii_servicio_estado($db, true)['conectado'], 'el heartbeat refleja conexión');
sii_servicio_liberar($db, $worker1);
check(sii_servicio_heartbeat($db, $worker2)['ocupado'] === false, 'el lease queda libre al cerrar');

// Enrolamiento nuevo invalida los tokens individuales y reemplaza el token global previo.
$db->prepare('INSERT INTO sii_agentes (usuario_id, nombre, token_hash, creado_en) VALUES (1, ?, ?, ?)')
    ->execute(['LEGADO', hash('sha256', 'legacy-token'), sii_ahora()]);
$nuevoCodigo = sii_servicio_codigo_crear($db);
check($db->query('SELECT revocado_en FROM sii_agentes')->fetchColumn() !== null, 'revoca tokens individuales anteriores');
check((string)$db->query('SELECT token_hash FROM sii_servicio WHERE id = 1')->fetchColumn() === '', 'invalida el token del servicio anterior');
$nuevoToken = sii_servicio_canjear($db, $nuevoCodigo['codigo'], 'PC-CENTRAL');
$_SERVER['HTTP_X_AGENTE_TOKEN'] = $nuevoToken; sii_servicio_autorizar($db);

$db->exec("UPDATE sii_servicio SET codigo_hash = '" . hash('sha256', 'A') . "', codigo_expira_en = '2000-01-01 00:00:00'");
try { sii_servicio_canjear($db, 'A', 'PC'); check(false, 'código vencido no se canjea'); } catch (SiiError) {}
sii_servicio_revocar($db);
check(!sii_servicio_estado($db, true)['activo'], 'revoca el servicio global');
unset($_SERVER['HTTP_X_AGENTE_TOKEN']);

echo "SII servicio central: código único, token hash, lease, revocación y renovación verificados\n";
