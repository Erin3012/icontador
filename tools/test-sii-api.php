<?php
// Prueba de la cola central, cifrado por empresa, enrolamiento y aislamiento RCV.
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/sii.php';
function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FALLA: $msg\n"); exit(1); } }

$db = base_prueba();
empresas_schema($db); sii_schema($db); rcv_schema($db); hon_schema($db); sii_schema($db);
$llave = base64_encode(random_bytes(32)); putenv("ICONTADOR_SII_CLAVE_CIFRADO=$llave");
$temporal = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sii-private-' . bin2hex(random_bytes(5));
mkdir($temporal, 0700, true); mkdir($temporal . '/public', 0700, true);
file_put_contents($temporal . '/private.php', "<?php return ['sii_clave_cifrado' => '" . $llave . "'];");
file_put_contents($temporal . '/public/inside.php', "<?php return ['sii_clave_cifrado' => '" . $llave . "'];");
putenv('ICONTADOR_SII_CLAVE_CIFRADO=');
putenv('ICONTADOR_PRIVATE_CONFIG=' . $temporal . '/private.php'); $_SERVER['DOCUMENT_ROOT'] = $temporal . '/public';
check(sii_clave_cifrado_key() === base64_decode($llave, true), 'lee llave desde configuración privada fuera del docroot');
putenv('ICONTADOR_PRIVATE_CONFIG=' . $temporal . '/public/inside.php');
try { sii_clave_cifrado_key(); check(false, 'rechaza config de cifrado dentro del docroot'); } catch (SiiError) {}
putenv('ICONTADOR_PRIVATE_CONFIG='); unset($_SERVER['DOCUMENT_ROOT']); putenv("ICONTADOR_SII_CLAVE_CIFRADO=$llave");
$clavePath = $temporal . '/private.php'; @unlink($clavePath); @unlink($temporal . '/public/inside.php'); @rmdir($temporal . '/public'); @rmdir($temporal);
$db->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')
    ->execute(['t1', 'EMPRESA PRUEBA', json_encode(['rut' => '76.000.000-0']), '2026-11-01']);
$empresa = (int)$db->lastInsertId();
$db->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')
    ->execute(['t2', 'OTRA EMPRESA', json_encode(['rut' => '77.000.000-1']), '2026-11-01']);
$otraEmpresa = (int)$db->lastInsertId();
$clave = 'Clave-Prueba-123';
$compra = ['tipo' => 33, 'tipoOperacion' => 'Del Giro', 'rut' => '77000001-1', 'razon' => 'PROVEEDOR', 'folio' => '1520', 'fecha' => '03/10/2026', 'neto' => 1000000, 'iva' => 190000, 'total' => 1190000];
$venta = ['tipo' => 33, 'folio' => '401', 'neto' => 2000000, 'iva' => 380000, 'total' => 2380000];

try { sii_crear($db, $empresa, 1, '2026-10', ['compras']); check(false, 'sin clave no debe encolar'); }
catch (SiiError $e) { check(str_contains($e->getMessage(), 'Guarda la Clave Tributaria'), 'avisa que falta la clave'); }
sii_clave_guardar($db, $empresa, $clave);
check(sii_clave_guardada($db, $empresa), 'clave guardada por empresa');
$sobre = (string)$db->query('SELECT clave_cifrada FROM sii_claves WHERE empresa_id = ' . $empresa)->fetchColumn();
check(!str_contains($sobre, $clave) && !str_contains((string)base64_decode($sobre), $clave), 'clave cifrada en la base');
check(sii_clave_descifrar($db, $empresa) === $clave, 'cifrado reversible con llave maestra');

// Cola global: conserva el usuario y la empresa, y procesa solo una tarea por llamada.
$r = sii_crear($db, $empresa, 1, '2026-10', ['compras', 'ventas']);
check(count($r['creadas']) === 2, 'encola compras y ventas');
check(sii_crear($db, $empresa, 2, '2026-10', ['compras'])['ya_en_cola'] === ['compras'], 'evita duplicados entre usuarios');
foreach ([[0, '2026-10', ['compras']], [$empresa, '2026-13', ['compras']], [$empresa, '2026-10', ['otro']], [$empresa, '2026-10', []]] as [$e, $p, $l]) {
    try { sii_crear($db, $e, 1, $p, $l); check(false, 'rechaza entrada inválida'); } catch (SiiError | RcvError) {}
}
$a = sii_reclamar($db);
check(count($a) === 1 && $a[0]['rut'] === '76.000.000-0' && $a[0]['clave'] === $clave, 'entrega una tarea con RUT y clave descifrada');
$idCompras = (int)$a[0]['id'];
$b = sii_reclamar($db);
check(count($b) === 1 && $b[0]['id'] !== $idCompras, 'reclama la siguiente tarea sin repetir la anterior');
$idVentas = (int)$b[0]['id'];
sii_evento_registrar($db, $idCompras, 'autenticando', 'Clave-Prueba-123');
$logsAgente = sii_logs_agente_listar($db);
check(count($logsAgente) === 1 && $logsAgente[0]['tarea_id'] === $idCompras && $logsAgente[0]['detalle'] === '', 'el log global conserva etapas tipadas y descarta texto que podría contener secretos');
try { sii_importar($db, $idCompras, ['kind'=>'compras','period'=>'2026-09','fileName'=>'x.csv','rutEmpresa'=>'76000000-0','docs'=>[$compra]]); check(false, 'rechaza otro período'); }
catch (SiiError $e) { check(str_contains($e->getMessage(), '2026-09'), 'error de período claro'); }
check(sii_tarea($db, $idCompras)['estado'] === 'error', 'registra rechazo de período');

// Importación valida empresa/RUT y mantiene el aislamiento.
try { sii_importar($db, $idVentas, ['kind'=>'ventas','period'=>'2026-10','fileName'=>'x.csv','rutEmpresa'=>'77000000-1','docs'=>[$venta]]); check(false, 'rechaza RUT distinto'); }
catch (SiiError $e) { check(str_contains($e->getMessage(), 'RUT'), 'mensaje de RUT'); }
check(sii_tarea($db, $idVentas)['estado'] === 'error', 'RUT distinto queda en error');
sii_crear($db, $empresa, 1, '2026-10', ['ventas']);
$c = sii_reclamar($db);
$saved = sii_importar($db, (int)$c[0]['id'], ['kind'=>'ventas','period'=>'2026-10','fileName'=>'RCV_VENTA_76000000-0_202610.csv','rutEmpresa'=>'76000000-0','docs'=>[$venta]]);
check($saved === 1 && count(rcv_leer($db, '2026-10', $empresa)) === 1, 'guarda el RCV en la empresa de la tarea');
check(rcv_leer($db, '2026-10', $otraEmpresa) === [], 'no mezcla datos entre empresas');
$siiCrearResumen=sii_crear($db,$empresa,1,'2026-11',['ventas']);$tareaResumen=sii_reclamar($db)[0];
$guardadosResumen=sii_importar($db,(int)$tareaResumen['id'],['kind'=>'ventas','period'=>'2026-11','fileName'=>'RCV_RESUMEN_VENTA_76000000-0_202611.csv','rutEmpresa'=>'76000000-0','docs'=>[],'resumen'=>[['tipo'=>48,'tipoNombre'=>'Comprobantes Pago Electrónico (48)','documentos'=>187,'exento'=>0,'neto'=>1369623,'iva'=>260227,'total'=>1629850]]]);
check($guardadosResumen===187&&rcv_leer_resumenes($db,'2026-11',$empresa)[0]['resumen'][0]['documentos']===187,'importa resumen SII como conteo agregado');
check(rcv_leer($db,'2026-11',$empresa)===[]&&str_contains(sii_tarea($db,(int)$tareaResumen['id'])['mensaje'],'sin vouchers individuales'),'resumen no genera documentos ni vouchers individuales');
check(rcv_leer_resumenes($db,'2026-11',$otraEmpresa)===[],'resumen mantiene aislamiento por empresa');

// Honorarios recibidos/emitidos usan la misma cola y se guardan transaccionalmente por empresa y mes.
$rangoHonorarios = sii_crear_rango($db, $empresa, 1, '2026-12', '2026-12', [], ['recibidas', 'emitidas']);
check(count($rangoHonorarios['creadas']) === 2, 'encola ambos libros de honorarios aunque no se solicite RCV');
$duplicadoHonorarios = sii_crear_rango($db, $empresa, 2, '2026-12', '2026-12', [], ['recibidas', 'emitidas']);
check(count($duplicadoHonorarios['ya_en_cola']) === 2, 'evita duplicar honorarios pendientes');
$tareaRecibidas = sii_reclamar($db)[0];
check($tareaRecibidas['tipo'] === 'honorarios' && $tareaRecibidas['libro'] === 'recibidas', 'el agente reclama una tarea BHE con su tipo correcto');
sii_evento_registrar($db, (int)$tareaRecibidas['id'], 'abriendo_honorarios');
$boleta = ['numero'=>'9001','fecha'=>'10/12/2026','estado'=>'VIGENTE','rut'=>'11111111-1','nombre'=>'PRESTADOR','socProf'=>false,'bruto'=>100000,'retenido'=>14500,'pagado'=>85500];
$guardadasHon = sii_importar($db, (int)$tareaRecibidas['id'], ['kind'=>'recibidas','period'=>'2026-12','fileName'=>'BHE_RECIBIDAS.xls','rutEmpresa'=>'76000000-0','boletas'=>[$boleta]]);
check($guardadasHon === 1 && count(hon_leer($db, '2026-12', $empresa)[0]['boletas']) === 1, 'guarda informe de honorarios recibido');
check(sii_tarea($db,(int)$tareaRecibidas['id'])['estado'] === 'completada', 'marca completada la importación de honorarios');
$tareaEmitidas = sii_reclamar($db)[0];
check($tareaEmitidas['tipo'] === 'honorarios' && $tareaEmitidas['libro'] === 'emitidas', 'reclama también la tarea de honorarios emitidos');
$guardadasVacias = sii_importar($db, (int)$tareaEmitidas['id'], ['kind'=>'emitidas','period'=>'2026-12','fileName'=>'BHE_EMITIDAS.xls','rutEmpresa'=>'76000000-0','boletas'=>[]]);
check($guardadasVacias === 0 && sii_tarea($db,(int)$tareaEmitidas['id'])['estado'] === 'completada', 'cero boletas se completa sin error');
check(count(hon_leer($db, '2026-12', $otraEmpresa)) === 0, 'honorarios queda aislado por empresa');

// Reemplazo y eliminación de clave; fallos de cifrado no borran la anterior.
try { sii_clave_guardar($db, $empresa, str_repeat('x', 300)); check(false, 'rechaza clave demasiado larga'); } catch (SiiError) {}
check(sii_clave_descifrar($db, $empresa) === $clave, 'rechazo no borra clave anterior');
sii_clave_guardar($db, $empresa, 'Clave-Nueva');
check(sii_clave_descifrar($db, $empresa) === 'Clave-Nueva', 'reemplaza clave cifrada');
sii_clave_borrar($db, $empresa);
check(!sii_clave_guardada($db, $empresa), 'elimina clave guardada');

// Datos de empresa quedan fuera de la cola central RCV.
try { sii_crear_consulta_empresa($db, 7, '55.555.555-5', ''); check(false, 'consulta de empresa requiere clave'); }
catch (SiiError $e) { check(str_contains($e->getMessage(), 'Clave Tributaria'), 'consulta exige clave explícita'); }
check(sii_reclamar($db) === [], 'agente central no reclama consultas de datos de empresa');

// Enrolamiento central guarda solo hash, mantiene lease exclusivo y revocación inmediata.
$codigo = sii_servicio_codigo_crear($db);
check(strlen(str_replace('-', '', $codigo['codigo'])) === 20, 'código de servicio de un solo uso');
$token = sii_servicio_canjear($db, $codigo['codigo'], 'PC-PRUEBA');
check(hash('sha256', $token) === (string)$db->query('SELECT token_hash FROM sii_servicio WHERE id = 1')->fetchColumn(), 'solo persiste hash del token');
$_SERVER['HTTP_X_AGENTE_TOKEN'] = $token; sii_servicio_autorizar($db);
$w1 = '12345678-1234-4234-8234-123456789abc'; $w2 = '22345678-1234-4234-8234-123456789abc';
check(sii_servicio_heartbeat($db, $w1)['ocupado'] === false, 'primera instancia adquiere lease');
check(sii_servicio_heartbeat($db, $w2)['ocupado'] === true, 'segunda instancia queda excluida');
check(sii_servicio_estado($db, true)['conectado'], 'estado conectado por heartbeat');
try { sii_servicio_canjear($db, $codigo['codigo'], 'OTRO'); check(false, 'no permite reusar código'); } catch (SiiError) {}
sii_servicio_revocar($db);
check(!sii_servicio_estado($db, true)['activo'], 'revocar desactiva el worker');
unset($_SERVER['HTTP_X_AGENTE_TOKEN']);

echo "SII central: claves cifradas, cola global, aislamiento, lease y revocación verificados\n";
