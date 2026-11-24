<?php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/sii.php';
function gui_check(bool $ok, string $why): void { if (!$ok) { throw new RuntimeException('FALLA: ' . $why); } }
$db=base_prueba(); empresas_schema($db); auth_schema($db); sii_schema($db); rcv_schema($db);
$db->prepare('INSERT INTO usuarios (email,nombre,clave_hash,rol,estado,creado_en) VALUES (?,?,?,?,?,?)')->execute(['operador@example.invalid','Operador prueba','x','usuario','activo',sii_ahora()]);
$user=(int)$db->lastInsertId();
$db->prepare('INSERT INTO empresas (origen_id,razon_social,datos_json,actualizado) VALUES (?,?,?,?)')->execute(['gui-test','Empresa GUI','{"rut":"76.000.000-0"}',sii_ahora()]);
$empresa=(int)$db->lastInsertId();
$codigo=sii_servicio_codigo_crear($db); $token=sii_servicio_canjear($db,$codigo['codigo'],'PC prueba');
$_SERVER['HTTP_X_AGENTE_TOKEN']=$token; sii_servicio_autorizar($db);
$worker='12345678-1234-4234-8234-123456789abc'; sii_servicio_heartbeat($db,$worker);
$ahora=sii_ahora();
$insert=$db->prepare('INSERT INTO sii_tareas (empresa_id,tipo,periodo,libro,rut,estado,mensaje,resultado_json,creado_por,creado_en,actualizado_en,reclamado_en) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
$insert->execute([$empresa,'rcv','2026-10','ventas','76.000.000-0','pendiente','Problema con RUT 76.000.000-0',null,$user,$ahora,$ahora,null]); $pending=(int)$db->lastInsertId();
$status=sii_servicio_cola($db); $json=json_encode($status,JSON_UNESCAPED_UNICODE);
gui_check(str_contains($json,'Empresa GUI') && str_contains($json,'Operador prueba'),'el estado presenta empresa y solicitante');
gui_check(!str_contains($json,'76.000.000-0') && !str_contains($json,'resultado_json') && !str_contains($json,$token),'el estado excluye RUT, resultados y token');
gui_check(str_contains($json,'[RUT oculto]'),'oculta RUT dentro de mensajes de error');
gui_check(sii_control_tarea($db,$pending,'cancelar')['estado']==='cancelada','cancela una tarea pendiente');
$insert->execute([$empresa,'rcv','2026-10','ventas','76.000.000-0','en_proceso','',null,$user,$ahora,$ahora,$ahora]); $active=(int)$db->lastInsertId();
gui_check(sii_control_tarea($db,$active,'reencolar')['estado']==='reencolando','marca la tarea activa para reencolar');
sii_finalizar_control($db,$active,'reencolar');
$st=$db->prepare('SELECT estado,reclamado_en FROM sii_tareas WHERE id=?'); $st->execute([$active]); $row=$st->fetch();
gui_check($row['estado']==='pendiente' && $row['reclamado_en']===null,'reinicio devuelve la tarea a la cola');
gui_check(sii_control_tarea($db,$active,'cancelar')['estado']==='cancelada','cancela la tarea tras reencolarla');
$insert->execute([$empresa,'rcv','2026-10','ventas','76.000.000-0','en_proceso','',null,$user,$ahora,$ahora,$ahora]); $canceling=(int)$db->lastInsertId();
sii_control_tarea($db,$canceling,'cancelar');
$doc=['tipo'=>33,'tipoOperacion'=>'Del Giro','rut'=>'77000001-1','razon'=>'Proveedor','folio'=>'41','fecha'=>'03/10/2026','neto'=>100,'iva'=>19,'total'=>119];
try { sii_importar($db,$canceling,['kind'=>'ventas','period'=>'2026-10','rutEmpresa'=>'76.000.000-0','docs'=>[$doc]]); gui_check(false,'rechaza importación después de solicitar cancelación'); }
catch (SiiError) { gui_check(rcv_leer($db,'2026-10',$empresa)===[],'cancelar no deja documentos parciales'); }
sii_finalizar_control($db,$canceling,'cancelar');
$insert->execute([$empresa,'rcv','2026-09','ventas','76.000.000-0','cancelando','',null,$user,$ahora,$ahora,date('Y-m-d H:i:s',time()-1300)]); $stale=(int)$db->lastInsertId();
sii_reclamar($db);
$st=$db->prepare('SELECT estado FROM sii_tareas WHERE id=?');$st->execute([$stale]);
gui_check($st->fetchColumn()==='cancelada','recupera cancelación huérfana por lease vencido');
echo "Agente GUI: resumen seguro, cancelar, reencolar y bloqueo de importación parcial verificados\n";
