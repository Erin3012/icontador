<?php
// Prueba de la persistencia de boletas de honorarios y de las columnas nuevas del RCV: php tools/test-honorarios-api.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/honorarios.php';
require dirname(__DIR__) . '/api/lib/rcv.php';
function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FALLA: $msg\n"); exit(1); } }

$db = base_prueba();
empresas_schema($db);
hon_schema($db);
hon_schema($db); // idempotente
rcv_schema($db);
$db->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')->execute(['p1', 'EMPRESA', json_encode(['rut' => '76.000.000-0']), '2026-10-06']);
$empresa = (int)$db->lastInsertId();

$vigente = ['numero' => '58', 'fecha' => '30/09/2026', 'estado' => 'VIGENTE', 'rut' => '11111111-1', 'nombre' => 'PERSONA UNO', 'socProf' => false, 'bruto' => 943953, 'retenido' => 143953, 'pagado' => 800000];
$anulada = ['numero' => '59', 'fecha' => '30/09/2026', 'estado' => 'ANULADA', 'fechaAnulacion' => '30/09/2026', 'rut' => '22222222-2', 'nombre' => 'PERSONA DOS', 'socProf' => true, 'bruto' => 100000, 'retenido' => 15250, 'pagado' => 84750];
$datos = ['kind' => 'recibidas', 'period' => '2026-09', 'fileName' => 'informeMensualREC.xls', 'rutEmpresa' => '76000000-0', 'boletas' => [$vigente, $anulada]];
check(hon_guardar($db, $datos, $empresa) === 2, 'guardar boletas');
check(hon_guardar($db, $datos, $empresa) === 2, 'reimportar reemplaza');
check(hon_guardar($db, ['kind' => 'recibidas', 'period' => '2026-08', 'boletas' => [$vigente]], $empresa) === 1, 'otro período');
$libros = hon_leer($db, '2026-09', $empresa);
check(count($libros) === 1 && count($libros[0]['boletas']) === 2 && $libros[0]['fileName'] === 'informeMensualREC.xls', 'leer período');
check($libros[0]['boletas'][1]['socProf'] === true && $libros[0]['boletas'][0]['bruto'] === 943953 && $libros[0]['boletas'][1]['fechaAnulacion'] === '30/09/2026', 'campos');
check($libros[0]['totales'] === ['boletas' => 2, 'anuladas' => 1, 'bruto' => 943953, 'retenido' => 143953, 'pagado' => 800000], 'las anuladas no suman');
check(hon_periodos($db, $empresa) === [['period' => '2026-09', 'recibidas' => 2, 'emitidas' => 0], ['period' => '2026-08', 'recibidas' => 1, 'emitidas' => 0]], 'períodos');
check(hon_leer($db, '2026-09', 0) === [], 'datos separados por empresa');
$libro = hon_libro($db, 'recibidas', '2026-09-01', '2026-09-30', $empresa);
check(count($libro['boletas']) === 2 && $libro['totales']['retenido'] === 143953 && $libro['boletas'][0]['periodo'] === '2026-09', 'Libro de Honorarios');
check(hon_libro($db, 'recibidas', null, null, $empresa)['totales']['bruto'] === 943953 * 2, 'Libro de Honorarios sin fechas');
// El archivo de otro contribuyente no se guarda en esta empresa.
try { hon_guardar($db, ['rutEmpresa' => '77777777-7'] + $datos, $empresa); check(false, 'debió rechazar RUT ajeno'); } catch (EmpresaError) {}
foreach ([['kind' => 'otro'] + $datos, ['period' => '2026-13'] + $datos, ['boletas' => [['numero' => '', 'bruto' => 1]]] + $datos, ['boletas' => [['numero' => '1', 'bruto' => 'mucho']]] + $datos] as $malo) {
    try { hon_guardar($db, $malo, $empresa); check(false, 'debió rechazar ' . json_encode($malo)); } catch (HonorariosError) {}
}
check(count(hon_leer($db, '2026-09', $empresa)[0]['boletas']) === 2, 'un rechazo no borra lo guardado');
foreach ([['01-09-2026', null], ['2026-09-30', '2026-09-01']] as [$d, $h]) {
    try { hon_libro($db, 'recibidas', $d, $h, $empresa); check(false, "debió rechazar $d $h"); } catch (HonorariosError) {}
}
check(hon_borrar($db, '2026-09', $empresa) === 2 && count(hon_periodos($db, $empresa)) === 1, 'borrar');

// RCV: columnas del formato completo del SII, archivos que no son el registro y RUT de la empresa.
$compra = ['tipo' => 33, 'folio' => '100', 'neto' => 38666, 'ivaNoRec' => 7347, 'ivaNoRecCodigo' => '9', 'otros' => 3642, 'otroImpCodigo' => '35', 'otroImpTasa' => '0', 'impSinCredito' => 3642, 'total' => 49655, 'fechaRecepcion' => '25/09/2026 12:23:03'];
$activo = ['tipo' => 33, 'folio' => '200', 'neto' => 100000, 'iva' => 19000, 'netoActivoFijo' => 100000, 'ivaActivoFijo' => 19000, 'total' => 119000];
check(rcv_guardar($db, ['kind' => 'compras', 'period' => '2026-09', 'fileName' => 'RCV_COMPRA_REGISTRO_76000000-0_202609.csv', 'rutEmpresa' => '76000000-0', 'docs' => [$compra, $activo]], $empresa) === 2, 'RCV con columnas nuevas');
$doc = rcv_leer($db, '2026-09', $empresa)[0]['docs'][0];
check($doc['ivaNoRecCodigo'] === '9' && $doc['otroImpCodigo'] === '35' && $doc['impSinCredito'] === 3642 && $doc['fechaRecepcion'] === '25/09/2026 12:23:03', 'RCV lee columnas nuevas');
check(rcv_libro($db, 'compras', null, null, null, $empresa)['totales']['ivaActivoFijo'] === 19000, 'Libro de Compras suma IVA activo fijo');
try { rcv_guardar($db, ['kind' => 'compras', 'period' => '2026-09', 'fileName' => 'RCV_COMPRA_PENDIENTE_76000000-0_202609.csv', 'docs' => [$compra]], $empresa); check(false, 'debió rechazar pendientes'); } catch (RcvError) {}
try { rcv_guardar($db, ['kind' => 'ventas', 'period' => '2026-09', 'rutEmpresa' => '77777777-7', 'docs' => []], $empresa); check(false, 'debió rechazar RCV de otro RUT'); } catch (EmpresaError) {}
check(count(rcv_leer($db, '2026-09', $empresa)[0]['docs']) === 2, 'los rechazos no borran el RCV');
echo "Honorarios PHP: guardar, reemplazar, leer, libro, RUT y validación verificados; RCV con columnas nuevas verificado\n";
