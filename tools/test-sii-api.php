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
// Si este equipo tiene la llave en api/config.php (como indica el README del agente), "sin llave" no se puede simular.
$configLocal = is_file(dirname(__DIR__) . '/api/config.php') ? require dirname(__DIR__) . '/api/config.php' : [];
if (empty($configLocal['sii_clave_cifrado'])) {
    try { sii_clave_guardar($db, $empresa, 'otra'); check(false, 'sin llave no debe guardar'); } catch (SiiError) {}
    check(sii_clave_guardada($db, $empresa), 'sin llave no se borra la clave guardada');
}
putenv("ICONTADOR_SII_CLAVE_CIFRADO=$llave");
sii_clave_guardar($db, $empresa, '');
check(!sii_clave_guardada($db, $empresa), 'una clave vacía borra la guardada');
sii_clave_guardar($db, $empresa, $secreta);
sii_crear($db, $empresa, 1, '2026-09', ['ventas']);
$reclamadas = sii_reclamar($db, 50);
$propia = array_values(array_filter($reclamadas, fn($t) => $t['periodo'] === '2026-09' && $t['libro'] === 'ventas'));
check(count($propia) === 1 && $propia[0]['clave'] === $secreta, 'el agente recibe la clave de la empresa al reclamar');

// 11. Consultas de solo lectura (F22 y declaraciones juradas): clave, resultado, cambios y empresa.
foreach ([['otro', 2026], ['f22', 1999], ['f22', 'x']] as [$tipo, $anio]) {
    try { sii_crear_consulta($db, $empresa, 1, $tipo, $anio); check(false, "consulta inválida $tipo $anio"); } catch (SiiError) {}
}
try { sii_crear_consulta($db, $empresaSinRut, 1, 'f22', 2026); check(false, 'consulta sin RUT'); } catch (SiiError) {}
$db->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')
    ->execute(['t3', 'SIN CLAVE', json_encode(['rut' => '77.000.001-1']), '2026-11-01']);
$sinClave = (int) $db->lastInsertId();
try { sii_crear_consulta($db, $sinClave, 1, 'f22', 2026); check(false, 'consulta sin clave'); } catch (SiiError $e) { check(str_contains($e->getMessage(), 'clave'), 'pide la clave'); }
sii_reclamar($db, 50); // vacía la cola anterior
$f22 = sii_crear_consulta($db, $empresa, 1, 'f22', 2026);
check(sii_crear_consulta($db, $empresa, 1, 'f22', 2026) === ['id' => $f22['id'], 'ya_en_cola' => true], 'no duplica la consulta en cola');
check(sii_consulta_estado($db, $empresa, 'f22', 2026)['tarea']['estado'] === 'pendiente', 'estado: pendiente');
// Funcionalidad extra: un agente que no la declara no recibe F22 ni DJ, y el RCV sigue igual y va primero.
sii_crear($db, $empresa, 1, '2026-07', ['compras']);
$antiguo = sii_reclamar($db, 50);
check(array_column($antiguo, 'tipo') === ['rcv'], 'un agente sin la funcionalidad solo recibe RCV');
check(sii_tarea($db, $f22['id'])['estado'] === 'pendiente', 'la consulta de F22 queda en cola para un agente que la sepa hacer');
sii_crear($db, $empresa, 1, '2026-06', ['compras']);
$t = sii_reclamar($db, 50, ['f22', 'ddjj']);
check(array_column($t, 'tipo') === ['rcv', 'f22'], 'el RCV se entrega primero aunque se haya pedido después');
check($t[1]['periodo'] === '2026' && $t[1]['clave'] === $secreta, 'el agente recibe la consulta con el año y la clave');
try { sii_resultado_consulta($db, $f22['id'], ['codigos' => []]); check(false, 'resultado sin estado'); } catch (SiiError) {}
check(sii_tarea($db, $f22['id'])['estado'] === 'error', 'resultado incompleto deja la tarea en error');

$pedir = function (string $tipo, array $resultado) use ($db, $empresa) {
    $c = sii_crear_consulta($db, $empresa, 1, $tipo, 2026);
    sii_reclamar($db, 50, ['f22', 'ddjj']);
    return sii_resultado_consulta($db, $c['id'], $resultado);
};
$pedir('f22', ['estado' => 'En proceso', 'situacion' => 'En revisión', 'folio' => 123, 'eventos' => [['fecha' => '29/04/2026', 'descripcion' => 'Recibida']]]);
$pedir('f22', ['estado' => 'Aceptada', 'folio' => '123', 'resultado' => ['devolucion' => 5000, 'pago' => null], 'basura' => 'x',
    'codigos' => [['codigo' => 1600, 'glosa' => 'Ingresos', 'valor' => '1.000'], 'no es fila']]);
$v = sii_consulta_estado($db, $empresa, 'f22', 2026);
check($v['ultima']['estado'] === 'Aceptada' && $v['ultima']['datos']['folio'] === 123 && $v['ultima']['datos']['resultado']['devolucion'] === 5000, 'guarda el F22');
check(!isset($v['ultima']['datos']['basura']) && count($v['ultima']['datos']['codigos']) === 1, 'solo los campos esperados');
check($v['cambio']['antes'] === 'En proceso' && $v['tarea']['estado'] === 'completada', 'marca el cambio respecto de la consulta anterior');
$pedir('ddjj', ['estado' => 'Todas aceptadas', 'declaraciones' => [['formulario' => 1879, 'estado' => 'Aceptada', 'folio' => 31, 'presentada' => '11/02/2026',
    'observaciones' => [['codigo' => 'R000', 'descripcion' => 'Diferencia', 'motivo' => str_repeat('m', 2000), 'orientacion' => 'Revise', 'otro' => 'x']]]]]);
$v = sii_consulta_estado($db, $empresa, 'ddjj', 2026);
check($v['ultima']['datos']['declaraciones'][0]['formulario'] === 1879 && $v['cambio'] === null, 'guarda las declaraciones juradas');
$o = $v['ultima']['datos']['declaraciones'][0]['observaciones'][0];
check($o['codigo'] === 'R000' && strlen($o['motivo']) === 1500 && !isset($o['otro']), 'guarda el código y el motivo de la observación, acotados');
check(sii_consulta_estado($db, $sinClave, 'f22', 2026)['ultima'] === null, 'otra empresa no ve los resultados');
check(sii_consulta_estado($db, $empresa, 'f22', 2025)['ultima'] === null, 'otro año no tiene resultados');
try { sii_resultado_consulta($db, $f22['id'], ['estado' => 'Aceptada']); check(false, 'una tarea cerrada no recibe resultados'); } catch (SiiError) {}

// 12. Una clave ilegible deja en error solo su tarea: las demás se entregan.
$db->prepare('INSERT INTO sii_claves (empresa_id, clave_cifrada, actualizado_en) VALUES (?, ?, ?)')->execute([$sinClave, base64_encode(random_bytes(40)), sii_ahora()]);
$mala = sii_crear_consulta($db, $sinClave, 1, 'f22', 2026);
$buena = sii_crear_consulta($db, $empresa, 1, 'ddjj', 2025);
$t = sii_reclamar($db, 50, ['f22', 'ddjj']);
check(array_column($t, 'id') === [$buena['id']] && sii_tarea($db, $mala['id'])['estado'] === 'error', 'la clave ilegible no bloquea las demás tareas');

echo "SII: crear, reclamar, importar, fallar, reclamo vencido, token, consultas F22 y DDJJ verificados\n";
