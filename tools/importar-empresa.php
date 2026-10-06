<?php
declare(strict_types=1);
require dirname(__DIR__) . '/api/lib/vouchers.php';
$payload=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$db=icontador_db();
if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite')throw new RuntimeException('Importador preparado para la base SQLite local.');
$db->exec('PRAGMA foreign_keys=ON');
empresas_schema($db);
crearEsquema($db);
$db->beginTransaction();
try{
 $e=$payload['empresa'];$now=gmdate('c');
 $stmt=$db->prepare('INSERT INTO empresas(origen_id,razon_social,datos_json,actualizado) VALUES(?,?,?,?) ON CONFLICT(origen_id) DO UPDATE SET razon_social=excluded.razon_social,datos_json=excluded.datos_json,actualizado=excluded.actualizado');
 $stmt->execute([(string)$e['origen_id'],$e['razon_social'],json_encode($e,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$now]);
 $stmt=$db->prepare('SELECT id FROM empresas WHERE origen_id=?');$stmt->execute([(string)$e['origen_id']]);$eid=(int)$stmt->fetchColumn();
 $view=$payload['vista'];$data=$payload['datos'];
 $stmt=$db->prepare('INSERT INTO importacion_vistas(empresa_id,vista,datos_json,actualizado) VALUES(?,?,?,?) ON CONFLICT(empresa_id,vista) DO UPDATE SET datos_json=excluded.datos_json,actualizado=excluded.actualizado');
 $stmt->execute([$eid,$view,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$now]);
 $db->prepare('DELETE FROM importacion_registros WHERE empresa_id=? AND vista=?')->execute([$eid,$view]);
 $stmt=$db->prepare('INSERT INTO importacion_registros(empresa_id,vista,tabla,huella,datos_json) VALUES(?,?,?,?,?) ON CONFLICT(empresa_id,vista,tabla,huella) DO NOTHING');
 $count=0;$accounts=0;
 foreach($data['tablas']??$data['tables']??[] as $table){foreach($table['filas']??[] as $row){if(count($row['celdas']??[])===1&&preg_match('/Ning.n dato|No hay|Sin registros|seleccionar un periodo/i',$row['celdas'][0]))continue;
  $columnas=$table['encabezados']??$table['columnas']??[];
  $tabla=(string)($table['id']??'tabla');
  $json=json_encode(['columnas'=>$columnas,'origen_id'=>$row['id']??null,'celdas'=>$row['celdas']??[]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$stmt->execute([$eid,$view,$tabla,hash('sha256',$json),$json]);$count+=$stmt->rowCount();
  if($view==='plan-cuentas'&&isset($row['celdas'][2])&&preg_match('/^(\d+)\s+(.+)$/u',trim($row['celdas'][2]),$m)){
   $a=$db->prepare('INSERT INTO cuentas(empresa_id,codigo,nombre) VALUES(?,?,?) ON CONFLICT(empresa_id,codigo) DO UPDATE SET nombre=excluded.nombre');$a->execute([$eid,$m[1],$m[2]]);$accounts++;
  }
 }}
 $db->commit();echo json_encode(['empresa_id'=>$eid,'vista'=>$view,'nuevos_registros'=>$count,'cuentas'=>$accounts]);
}catch(Throwable $ex){$db->rollBack();throw $ex;}
