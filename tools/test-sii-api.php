<?php
// Prueba de las tareas de sincronización con el SII (cola, reclamo del agente, importación): php tools/test-sii-api.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/sii.php';
function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FALLA: $msg\n"); exit(1); } }

$db = base_prueba();
empresas_schema($db);
sii_schema($db);
rcv_schema($db);
sii_schema($db); // idempotente
$db->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')
    ->execute(['t1', 'EMPRESA PRUEBA', json_encode(['rut' => '76.000.000-0']), '2026-11-01']);
$empresa = (int) $db->lastInsertId();
$db->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')
    ->execute(['t2', 'SIN RUT', json_encode([]), '2026-11-01']);
$empresaSinRut = (int) $db->lastInsertId();

$compra = ['tipo' => 33, 'tipoOperacion' => 'Del Giro', 'rut' => '77000001-1', 'razon' => 'PROVEEDOR', 'folio' => '1520', 'fecha' => '03/10/2026', 'neto' => 1000000, 'iva' => 190000, 'total' => 1190000];
$venta = ['tipo' => 33, 'folio' => '401', 'neto' => 2000000, 'iva' => 380000, 'total' => 2380000];

// 1. Crear: una tarea por libro, sin duplicar las que ya están en cola, y validaciones.
$r = sii_crear($db, $empresa, 1, '2026-10', ['compras', 'ventas']);
check(count($r['creadas']) === 2 && $r['ya_en_cola'] === [], 'crea compras y ventas');
$r = sii_crear($db, $empresa, 1, '2026-10', ['compras']);
check($r['creadas'] === [] && $r['ya_en_cola'] === ['compras'], 'no duplica una tarea en cola');
foreach ([[0, '2026-10', ['compras']], [$empresa, '2026-13', ['compras']], [$empresa, '2026-10', ['otro']], [$empresa, '2026-10', []], [$empresaSinRut, '2026-10', ['compras']]] as [$e, $p, $l]) {
    try { sii_crear($db, $e, 1, $p, $l); check(false, 'debió rechazar ' . json_encode([$e, $p, $l])); } catch (SiiError | RcvError) {}
}
check(count(sii_listar($db, $empresa)) === 2 && sii_listar($db, 999) === [], 'listar: solo tareas de la empresa');

// 2. El agente reclama con RUT y período; una tarea reclamada no se entrega dos veces.
$reclamadas = sii_reclamar($db);
check(count($reclamadas) === 2 && $reclamadas[0]['rut'] === '76.000.000-0' && $reclamadas[0]['periodo'] === '2026-10', 'agente reclama con RUT y período');
check(sii_reclamar($db) === [], 'no se entrega dos veces');
check(sii_listar($db, $empresa)[0]['estado'] === 'en_proceso', 'estado en_proceso');
$idCompras = (int) $reclamadas[0]['id'];
$idVentas = (int) $reclamadas[1]['id'];

// 3. Período distinto al de la tarea: rechazado y la tarea queda con error.
try {
    sii_importar($db, $idCompras, ['kind' => 'compras', 'period' => '2026-09', 'fileName' => 'x.csv', 'rutEmpresa' => '76000000-0', 'docs' => [$compra]]);
    check(false, 'debió rechazar otro período');
} catch (SiiError $e) {
    check(str_contains($e->getMessage(), '2026-09'), 'mensaje de período');
}
check(sii_tarea($db, $idCompras)['estado'] === 'error', 'tarea con período distinto queda en error');

// 4. Pedir compras de nuevo; el libro que llega no es el que pide la tarea: rechazado.
sii_crear($db, $empresa, 1, '2026-10', ['compras']);
$nueva = sii_reclamar($db);
check(count($nueva) === 1 && $nueva[0]['libro'] === 'compras', 'se vuelve a pedir compras tras un error');
$idNueva = (int) $nueva[0]['id'];
try {
    sii_importar($db, $idNueva, ['kind' => 'ventas', 'period' => '2026-10', 'fileName' => 'x.csv', 'docs' => [$venta]]);
    check(false, 'debió rechazar otro libro');
} catch (SiiError) {}
check(sii_tarea($db, $idNueva)['estado'] === 'error', 'libro distinto deja la tarea en error');

// 5. Archivo de otro RUT: lo rechaza rcv_guardar y la tarea queda con error.
sii_crear($db, $empresa, 1, '2026-10', ['compras']);
$nueva = sii_reclamar($db);
$idNueva = (int) $nueva[0]['id'];
try {
    sii_importar($db, $idNueva, ['kind' => 'compras', 'period' => '2026-10', 'fileName' => 'x.csv', 'rutEmpresa' => '99999999-9', 'docs' => [$compra]]);
    check(false, 'debió rechazar RUT de otro contribuyente');
} catch (EmpresaError $e) {
    check(str_contains($e->getMessage(), '99999999-9'), 'mensaje de RUT');
}
check(sii_tarea($db, $idNueva)['estado'] === 'error', 'RUT distinto deja la tarea en error');

// 6. El agente informa un fallo (por ejemplo, clave incorrecta).
sii_crear($db, $empresa, 1, '2026-10', ['compras']);
$nueva = sii_reclamar($db);
$idNueva = (int) $nueva[0]['id'];
sii_fallar($db, $idNueva, 'Clave tributaria incorrecta.');
check(sii_tarea($db, $idNueva)['mensaje'] === 'Clave tributaria incorrecta.', 'fallo informado por el agente');

// 7. Importación correcta: la tarea de ventas (aún reclamada) queda completada y el libro se guarda.
$guardados = sii_importar($db, $idVentas, ['kind' => 'ventas', 'period' => '2026-10', 'fileName' => 'RCV_VENTA_76000000-0_202610.csv', 'rutEmpresa' => '76000000-0', 'docs' => [$venta]]);
check($guardados === 1 && sii_tarea($db, $idVentas)['estado'] === 'completada', 'importa ventas y completa la tarea');
check(rcv_leer($db, '2026-10', $empresa)[0]['kind'] === 'ventas', 'el libro queda en rcv_documentos');
try { sii_importar($db, $idVentas, ['kind' => 'ventas', 'period' => '2026-10', 'docs' => [$venta]]); check(false, 'una tarea completada no se importa otra vez'); } catch (SiiError) {}

// 8. Reclamo vencido: una tarea reservada hace mucho vuelve a pendiente y se puede reclamar.
sii_crear($db, $empresa, 1, '2026-08', ['ventas']);
$vencida = sii_reclamar($db);
$db->prepare('UPDATE sii_tareas SET reclamado_en = ? WHERE id = ?')->execute(['2000-01-01 00:00:00', $vencida[0]['id']]);
check(count(sii_reclamar($db)) === 1, 'reclamo vencido vuelve a pendiente');

// 9. Token del agente.
putenv('ICONTADOR_SII_TOKEN=secreto-de-prueba');
check(sii_token_valido('secreto-de-prueba') && !sii_token_valido('otro') && !sii_token_valido(''), 'token válido solo si coincide');
putenv('ICONTADOR_SII_TOKEN');

// 10. Consulta de datos de empresa (sin empresa todavía): la pide un usuario, la reclama el agente y devuelve razón social y giro.
try { sii_crear_consulta_empresa($db, 5, 'x'); check(false, 'RUT inválido'); } catch (SiiError) {}
$consulta = sii_crear_consulta_empresa($db, 5, '55.555.555-5');
check(sii_crear_consulta_empresa($db, 5, '55.555.555-5')['id'] === $consulta['id'], 'no duplica la consulta en cola');
$reclamo = array_values(array_filter(sii_reclamar($db), fn($t) => $t['tipo'] === 'datos_empresa'));
check(count($reclamo) === 1 && $reclamo[0]['rut'] === '55.555.555-5' && (int) $reclamo[0]['id'] === $consulta['id'], 'agente reclama la consulta con el RUT');
check(sii_consulta_empresa($db, $consulta['id'], 5)['estado'] === 'en_proceso', 'quien la pidió ve el estado');
check(sii_consulta_empresa($db, $consulta['id'], 6) === null, 'otro usuario no ve la consulta');
try { sii_resultado_empresa($db, $consulta['id'], ['giro' => 'Venta']); check(false, 'debe exigir razón social'); } catch (SiiError) {}
check(sii_consulta_empresa($db, $consulta['id'], 5)['estado'] === 'error', 'sin razón social la consulta queda en error');
$otra = sii_crear_consulta_empresa($db, 5, '55.555.555-5');
sii_reclamar($db);
$datos = sii_resultado_empresa($db, $otra['id'], ['razon_social' => '  EMPRESA NUEVA SPA ', 'giro' => 'Venta de software']);
check($datos['razon_social'] === 'EMPRESA NUEVA SPA' && $datos['giro'] === 'Venta de software', 'devuelve razón social y giro limpios');
$visible = sii_consulta_empresa($db, $otra['id'], 5);
check($visible['estado'] === 'completada' && $visible['datos']['giro'] === 'Venta de software', 'la consulta completada entrega los datos');
try { sii_importar($db, $otra['id'], ['kind' => 'compras', 'period' => '2026-10', 'docs' => []]); check(false, 'una consulta no se importa como RCV'); } catch (SiiError) {}
check(!in_array($otra['id'], array_column(sii_listar($db, $empresa), 'id')), 'la lista de RCV no muestra consultas');

// 8. Clave tributaria cifrada por empresa: no queda en claro, se entrega solo al reclamar, y un error no borra la anterior.
$llave = base64_encode(random_bytes(32));
putenv("ICONTADOR_SII_CLAVE_CIFRADO=$llave");
$secreta = 'Clave-Prueba-123';
sii_clave_guardar($db, $empresa, $secreta);
check(sii_clave_guardada($db, $empresa), 'guarda la clave de la empresa');
$sobre = (string) $db->query('SELECT clave_cifrada FROM sii_claves WHERE empresa_id = ' . $empresa)->fetchColumn();
check(strpos($sobre, $secreta) === false && strpos((string) base64_decode($sobre), $secreta) === false, 'la clave no queda en claro en la base');
check(sii_clave_descifrar($db, $empresa) === $secreta, 'descifra la misma clave');
try { sii_clave_guardar($db, $empresa, str_repeat('x', 300)); check(false, 'rechaza una clave demasiado larga'); } catch (SiiError) {}
check(sii_clave_descifrar($db, $empresa) === $secreta, 'una clave rechazada no borra la anterior');
putenv('ICONTADOR_SII_CLAVE_CIFRADO=');
try { sii_clave_guardar($db, $empresa, 'otra'); check(false, 'sin llave no debe guardar'); } catch (SiiError) {}
check(sii_clave_guardada($db, $empresa), 'sin llave no se borra la clave guardada');
putenv("ICONTADOR_SII_CLAVE_CIFRADO=$llave");
sii_clave_guardar($db, $empresa, '');
check(!sii_clave_guardada($db, $empresa), 'una clave vacía borra la guardada');
sii_clave_guardar($db, $empresa, $secreta);
sii_crear($db, $empresa, 1, '2026-09', ['ventas']);
$reclamadas = sii_reclamar($db, 50);
$propia = array_values(array_filter($reclamadas, fn($t) => $t['periodo'] === '2026-09' && $t['libro'] === 'ventas'));
check(count($propia) === 1 && $propia[0]['clave'] === $secreta, 'el agente recibe la clave de la empresa al reclamar');

echo "SII: crear, reclamar, importar, fallar, reclamo vencido y token verificados\n";
