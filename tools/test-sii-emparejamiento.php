<?php
// Prueba del emparejamiento de agentes SII (código de un solo uso -> token propio): php tools/test-sii-emparejamiento.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/sii.php';
function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FALLA: $msg\n"); exit(1); } }

$db = base_prueba();
sii_schema($db);
rcv_schema($db);

// 1. Código: formato XXXXX-XXXXX, sin guardarlo en claro.
$c = sii_codigo_crear($db, 1);
check((bool) preg_match('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/', $c['codigo']), 'formato del código: ' . $c['codigo']);
$hash = $db->query('SELECT codigo_hash FROM sii_codigos_emparejamiento')->fetchColumn();
check($hash === hash('sha256', str_replace('-', '', $c['codigo'])) && $hash !== $c['codigo'], 'el código se guarda solo como hash');

// 2. Canje: se acepta en minúsculas y sin guion; devuelve token de 48 hex; no se puede repetir.
$token = sii_codigo_canjear($db, strtolower(str_replace('-', ' ', $c['codigo'])), "PC-OFICINA\n");
check((bool) preg_match('/^[a-f0-9]{48}$/', $token), 'token de agente de 48 hex');
try { sii_codigo_canjear($db, $c['codigo'], 'otro'); check(false, 'un código usado no debe volver a canjearse'); } catch (SiiError $e) {}
$nombre = $db->query('SELECT nombre FROM sii_agentes')->fetchColumn();
check($nombre === 'PC-OFICINA', 'nombre del agente sin saltos de línea: ' . json_encode($nombre));

// 3. El token propio autoriza y el token guardado en claro no existe en la base.
check(sii_agente_por_token($db, $token) === 1, 'el token propio autoriza e identifica a su usuario');
$guardado = $db->query('SELECT token_hash FROM sii_agentes')->fetchColumn();
check($guardado !== $token && $guardado === hash('sha256', $token), 'el token se guarda solo como hash');
check(sii_agente_por_token($db, 'no-es-un-token') === null, 'token desconocido no autoriza');
check($db->query('SELECT ultimo_uso FROM sii_agentes')->fetchColumn() !== null, 'anota el último uso');

// 4. Un código nuevo invalida el anterior sin usar; un código vencido no se canjea.
$uno = sii_codigo_crear($db, 2);
$dos = sii_codigo_crear($db, 2);
try { sii_codigo_canjear($db, $uno['codigo'], 'x'); check(false, 'el código anterior debe quedar invalidado'); } catch (SiiError $e) {}
$db->prepare("UPDATE sii_codigos_emparejamiento SET expira_en = '2000-01-01 00:00:00' WHERE usuario_id = 2 AND usado_en IS NULL")->execute();
try { sii_codigo_canjear($db, $dos['codigo'], 'x'); check(false, 'un código vencido no debe canjearse'); } catch (SiiError $e) {}
try { sii_codigo_canjear($db, 'ABC', 'x'); check(false, 'un código corto no debe canjearse'); } catch (SiiError $e) {}

// 5. Revocar: solo el dueño, y el token revocado deja de autorizar. Los demás agentes siguen.
$otro = sii_codigo_crear($db, 2);
$tokenDos = sii_codigo_canjear($db, $otro['codigo'], 'PC-CASA');
$id = (int) $db->query("SELECT id FROM sii_agentes WHERE nombre = 'PC-CASA'")->fetchColumn();
try { sii_agente_revocar($db, 1, $id); check(false, 'otro usuario no puede revocar'); } catch (SiiError $e) {}
sii_agente_revocar($db, 2, $id);
check(sii_agente_por_token($db, $tokenDos) === null, 'el token revocado deja de autorizar');
check(sii_agente_por_token($db, $token) === 1, 'el otro PC sigue autorizado');
$listado = sii_agentes_listar($db, 2);
check(count($listado) === 1 && $listado[0]['revocado_en'] !== null && !array_key_exists('token_hash', $listado[0]), 'listado sin hash de token y con la revocación');
check(sii_agentes_activos($db) === 1, 'cuenta solo los agentes activos');

// 6. Cada PC recibe solo las tareas de las empresas que su usuario puede usar.
auth_schema($db);
empresas_schema($db);
$admin = auth_registrar($db, 'Admin', 'admin@prueba.cl', 'ClaveSegura123!', 'admin', 'activo');
$uno = auth_registrar($db, 'Uno', 'uno@prueba.cl', 'ClaveSegura123!', 'usuario', 'activo');
$baja = auth_registrar($db, 'Baja', 'baja@prueba.cl', 'ClaveSegura123!', 'admin', 'deshabilitado');
$insertar = $db->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)');
$insertar->execute(['a', 'ASIGNADA', json_encode(['rut' => '76000000-0']), '2026-11-27']);
$asignada = (int) $db->lastInsertId();
$insertar->execute(['b', 'AJENA', json_encode(['rut' => '99000044-0']), '2026-11-27']);
$ajena = (int) $db->lastInsertId();
empresa_asignar($db, $asignada, $uno);
sii_crear($db, $asignada, $admin, '2026-10', ['compras']);
sii_crear($db, $ajena, $admin, '2026-10', ['compras']);
$idAjena = (int) $db->query("SELECT id FROM sii_tareas WHERE empresa_id = $ajena")->fetchColumn();

check(sii_reclamar($db, 3, [], $baja) === [], 'usuario deshabilitado: nada');
$mias = sii_reclamar($db, 3, [], $uno);
check(count($mias) === 1 && $mias[0]['rut'] === '76000000-0', 'usuario: solo la empresa asignada');
try { sii_exigir_tarea_del_agente($db, $uno, $idAjena); check(false, 'no puede informar sobre una tarea ajena'); } catch (SiiError $e) {}
sii_exigir_tarea_del_agente($db, $uno, (int) $mias[0]['id']);
$resto = sii_reclamar($db, 3, [], $admin);
check(count($resto) === 1 && $resto[0]['rut'] === '99000044-0', 'administrador: las demás');

fwrite(STDOUT, "SII emparejamiento: código de un solo uso, token propio por PC, revocación y vencimiento verificados\n");
