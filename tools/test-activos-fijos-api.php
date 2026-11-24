<?php
// Prueba de Activos Fijos (valuación, creación, baja y reporte): php tools/test-activos-fijos-api.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/activos_fijos.php';

function check(bool $ok, string $msg): void {
    if (!$ok) {
        fwrite(STDERR, "FALLA: $msg\n");
        exit(1);
    }
    echo "✓ $msg\n";
}
function rechaza(callable $f, string $fragmento = ''): bool {
    try { $f(); return false; }
    catch (ActivosError $e) { return $fragmento === '' || str_contains($e->getMessage(), $fragmento); }
}
function activo(array $extra = []): array {
    return $extra + ['fecha_adquisicion' => '2026-01-15', 'valor_adquisicion' => 1200000, 'valor_residual' => 0, 'vida_util_anos' => 5,
        'metodo_depreciacion' => 'lineal', 'tasa_depreciacion_acelerada' => null, 'estado' => 'vigente', 'fecha_baja' => null];
}

// --- Valuación lineal (función pura)
$v = ActivosFijos::valuar(activo(), '2026-01-31');
check($v['meses_uso'] === 1 && $v['depreciacion_acumulada'] === 20000 && $v['valor_neto'] === 1180000 && $v['depreciacion_anual'] === 240000, 'lineal: el mes de adquisición cuenta como un mes completo');
check(ActivosFijos::valuar(activo(), '2026-12-31')['depreciacion_acumulada'] === 240000, 'lineal: 12 meses = depreciación de un año');
check(ActivosFijos::valuar(activo(['fecha_adquisicion' => '2024-12-10']), '2025-01-20')['meses_uso'] === 2, 'meses reales entre diciembre y enero = 2 (antes se contaban 13 meses)');
$fin = ActivosFijos::valuar(activo(), '2040-01-01');
check($fin['depreciacion_acumulada'] === 1200000 && $fin['valor_neto'] === 0 && $fin['totalmente_depreciado'], 'la depreciación se detiene en el valor depreciable (antes superaba el valor del activo)');
$res = ActivosFijos::valuar(activo(['valor_residual' => 200000]), '2060-01-01');
check($res['valor_neto'] === 200000 && $res['depreciacion_acumulada'] === 1000000, 'el valor neto no baja del valor residual');
$antes = ActivosFijos::valuar(activo(), '2025-12-31');
check($antes['meses_uso'] === 0 && $antes['depreciacion_acumulada'] === 0 && $antes['valor_neto'] === 1200000, 'antes de la adquisición no hay depreciación');

// --- Valuación acelerada y casos límite
$ac = activo(['metodo_depreciacion' => 'acelerada', 'tasa_depreciacion_acelerada' => 2.0]);
check(ActivosFijos::valuar($ac, '2026-12-31')['depreciacion_acumulada'] === 480000 && ActivosFijos::valuar($ac, '2026-12-31')['depreciacion_anual'] === 480000, 'acelerada x2: 480.000 por año sobre 1.200.000 a 5 años');
check(ActivosFijos::valuar($ac, '2029-01-01')['valor_neto'] === 0, 'acelerada: también se detiene en el valor depreciable');
check(ActivosFijos::valuar(activo(['metodo_depreciacion' => 'acelerada']), '2026-12-31')['depreciacion_acumulada'] === 240000, 'acelerada sin factor guardado se calcula como lineal');
$dec = activo(['valor_adquisicion' => 1000000, 'metodo_depreciacion' => 'acelerada', 'tasa_depreciacion_acelerada' => '4.10', 'fecha_adquisicion' => '2026-01-01']);
check(ActivosFijos::valuar($dec, '2026-12-31')['depreciacion_acumulada'] === 820000 && ActivosFijos::valuar($dec, '2026-06-30')['depreciacion_acumulada'] === 410000, 'acelerada con factor decimal sin errores de redondeo de 1 peso (4,10 x 12 meses = 820.000)');
$f14 = activo(['valor_adquisicion' => 999999, 'vida_util_anos' => 7, 'metodo_depreciacion' => 'acelerada', 'tasa_depreciacion_acelerada' => 1.4]);
check(ActivosFijos::valuar($f14, '2026-12-31')['depreciacion_acumulada'] === intdiv(999999 * 140 * 12, 100 * 84) && ActivosFijos::valuar($f14, '2031-01-01')['depreciacion_acumulada'] === 999999, 'acelerada con factor 1,4: cálculo exacto en un año y tope al cruzar el valor depreciable');
$grande = ActivosFijos::valuar(['fecha_adquisicion' => '1900-01-01', 'valor_adquisicion' => 1000000000000, 'valor_residual' => 0, 'vida_util_anos' => 100, 'metodo_depreciacion' => 'acelerada',
    'tasa_depreciacion_acelerada' => 5.0, 'estado' => 'vigente'], '2100-12-31');
check($grande['depreciacion_acumulada'] === 1000000000000 && $grande['valor_neto'] === 0, 'valor máximo, factor máximo y 200 años no desbordan el entero');
$bajaFija = ActivosFijos::valuar(activo(['estado' => 'baja', 'fecha_baja' => '2026-06-30']), '2035-01-01');
check($bajaFija['meses_uso'] === 6 && $bajaFija['depreciacion_acumulada'] === 120000, 'un activo dado de baja queda valuado a su fecha de baja');
check(ActivosFijos::valuar(activo(['vida_util_anos' => 0]), '2026-06-01')['valor_neto'] > 0, 'vida útil 0 no produce división por cero');

// --- Base de datos
$db = base_prueba();
ActivosFijos::crearEsquema($db);
ActivosFijos::crearEsquema($db); // idempotente
$a = new ActivosFijos($db, 1);
$otra = new ActivosFijos($db, 2);
$mesActual = date('Y-m-01');
$hoy = date('Y-m-d');

check(rechaza(fn() => $a->crearActivo('', 'X', 'Vehículos', $hoy, 1000), 'código'), 'rechaza código vacío');
check(rechaza(fn() => $a->crearActivo('A1', ' ', 'Vehículos', $hoy, 1000), 'nombre'), 'rechaza nombre vacío');
check(rechaza(fn() => $a->crearActivo('A1', 'X', 'Naves', $hoy, 1000), 'categoría'), 'rechaza categoría fuera de la lista');
check(rechaza(fn() => $a->crearActivo('A1', 'X', 'Vehículos', '2026-02-30', 1000), 'fecha'), 'rechaza fecha inexistente');
check(rechaza(fn() => $a->crearActivo('A1', 'X', 'Vehículos', $hoy, 0), 'valor'), 'rechaza valor cero');
check(rechaza(fn() => $a->crearActivo('A1', 'X', 'Vehículos', $hoy, '12.5'), 'valor'), 'rechaza valor con decimales');
check(rechaza(fn() => $a->crearActivo('A1', 'X', 'Vehículos', $hoy, 1000, 0), 'vida útil'), 'rechaza vida útil cero');
check(rechaza(fn() => $a->crearActivo('A1', 'X', 'Vehículos', $hoy, 1000, 5, 'exponencial'), 'método'), 'rechaza método desconocido');
check(rechaza(fn() => $a->crearActivo('A1', 'X', 'Vehículos', $hoy, 1000, 5, 'acelerada', null), 'factor'), 'acelerada exige factor');
check(rechaza(fn() => $a->crearActivo('A1', 'X', 'Vehículos', $hoy, 1000, 5, 'acelerada', 9), 'factor'), 'acelerada rechaza factor mayor a 5');
check((int) $db->query('SELECT COUNT(*) FROM activos_fijos')->fetchColumn() === 0, 'las validaciones no dejan filas a medias');

$notebook = $a->crearActivo('NB-001', 'Notebook <b>gerencia</b>', 'Computadores y Equipos', $mesActual, 1200000, 5, 'lineal', null, 'Equipo de gerencia');
$camioneta = $a->crearActivo('VH-001', 'Camioneta', 'Vehículos', '2020-01-10', '30000000', 7, 'acelerada', '2');
check($notebook > 0 && $camioneta > $notebook, 'crea activos (valor numérico en texto aceptado)');
check((int) $db->query("SELECT COUNT(*) FROM movimientos_activos WHERE tipo_movimiento = 'adquisicion'")->fetchColumn() === 2, 'registra el movimiento de adquisición de cada activo');
check(rechaza(fn() => $a->crearActivo('NB-001', 'Otro', 'Vehículos', $hoy, 1000), 'Ya existe'), 'no permite repetir el código dentro de la empresa');
check((int) $db->query('SELECT COUNT(*) FROM movimientos_activos')->fetchColumn() === 2, 'un código repetido no deja movimientos huérfanos');
check($otra->crearActivo('NB-001', 'Notebook otra empresa', 'Computadores y Equipos', $hoy, 500000) > 0, 'otra empresa puede usar el mismo código');

// --- Listar y reporte
$lista = $a->listarActivos('vigente');
check(count($lista) === 2 && $lista[0]['codigo'] === 'NB-001' && $lista[0]['nombre'] === 'Notebook <b>gerencia</b>', 'lista los activos de la empresa, el más reciente primero');
check($lista[0]['depreciacion_acumulada'] === 20000 && $lista[0]['valor_neto'] === 1180000 && $lista[0]['depreciacion_anual'] === 240000, 'la lista incluye la valuación del mes en curso');
check($lista[1]['valor_neto'] >= 0 && $lista[1]['depreciacion_acumulada'] <= 30000000, 'activo antiguo: neto no negativo y depreciación bajo su valor');
check(count($otra->listarActivos('vigente')) === 1, 'una empresa no ve los activos de otra');
check(rechaza(fn() => $a->listarActivos('eliminado'), 'estado'), 'estado de listado inválido');

$rep = $a->obtenerReporteActivos();
check($rep['cantidad_activos'] === 2 && $rep['valor_bruto_total'] === 31200000, 'reporte: cantidad y valor bruto');
check($rep['valor_neto_total'] === $rep['valor_bruto_total'] - $rep['depreciacion_acumulada_total'], 'reporte: neto = bruto - depreciación acumulada');
check(array_keys($rep['por_categoria']) === ['Computadores y Equipos', 'Vehículos'] && $rep['por_categoria']['Vehículos']['cantidad'] === 1, 'reporte: resumen por categoría');

// --- Entradas malformadas (arreglos, ids con basura) y carrera por código
$activosAntes = (int) $db->query('SELECT COUNT(*) FROM activos_fijos')->fetchColumn();
check(rechaza(fn() => $a->crearActivo(['x'], 'N', 'Vehículos', $hoy, 1000), 'texto'), 'un arreglo como código se rechaza (antes se guardaba "Array")');
check(rechaza(fn() => $a->crearActivo('OK-1', ['y'], 'Vehículos', $hoy, 1000), 'texto'), 'un arreglo como nombre se rechaza');
check(rechaza(fn() => $a->crearActivo('OK-2', 'N', 'Vehículos', $hoy, 1000, 5, 'lineal', null, ['d']), 'texto'), 'un arreglo como descripción se rechaza');
check(rechaza(fn() => $a->crearActivo(true, 'N', 'Vehículos', $hoy, 1000), 'texto'), 'un booleano como código se rechaza');
check((int) $db->query('SELECT COUNT(*) FROM activos_fijos')->fetchColumn() === $activosAntes && (int) $db->query("SELECT COUNT(*) FROM activos_fijos WHERE codigo = 'Array'")->fetchColumn() === 0, 'las entradas rechazadas no dejan activos');
foreach ([[99, 3], '1abc', 1.9, '', 0, -1, null, true] as $malo) {
    check(rechaza(fn() => $a->darDeBaja($malo, $hoy), 'no es válido'), 'id de baja mal formado (' . json_encode($malo) . ') se rechaza sin tocar otro activo');
}
check(rechaza(fn() => $a->darDeBaja($notebook, $hoy, ['razón']), 'texto'), 'una razón de baja en arreglo se rechaza');
check(count($a->listarActivos('vigente')) === 2 && (int) $db->query("SELECT COUNT(*) FROM movimientos_activos WHERE tipo_movimiento = 'baja'")->fetchColumn() === 0, 'ninguna baja malformada afectó a un activo');

// Otro envío simultáneo ganó la carrera: el índice único lo detecta y se responde como error de dominio (no 500)
$db->exec("CREATE TRIGGER carrera_codigo BEFORE INSERT ON activos_fijos WHEN NEW.codigo = 'RACE-1' BEGIN
    INSERT INTO activos_fijos (empresa_id, codigo, nombre, categoria, fecha_adquisicion, valor_adquisicion, creado_en, actualizado_en)
    VALUES (NEW.empresa_id, 'RACE-1', 'Ganador', 'Vehículos', '2026-01-01', 1, 'x', 'x'); END");
check(rechaza(fn() => $a->crearActivo('RACE-1', 'Perdedor', 'Vehículos', $hoy, 1000), 'Ya existe'), 'si el índice único detecta el duplicado, se responde "Ya existe" y no un error de servidor');
$db->exec('DROP TRIGGER carrera_codigo');
$db->exec("DELETE FROM activos_fijos WHERE codigo = 'RACE-1'");
check(count($a->listarActivos('vigente')) === 2 && (int) $db->query('SELECT COUNT(*) FROM movimientos_activos')->fetchColumn() === 3, 'la carrera perdida no deja movimientos huérfanos');

// --- Baja
check(rechaza(fn() => $a->darDeBaja(9999, $hoy), 'no existe'), 'baja de un activo inexistente falla (antes respondía éxito)');
check(rechaza(fn() => $a->darDeBaja($notebook + 1000, $hoy), 'no existe'), 'baja con id inexistente falla');
$ajeno = (int) $db->query("SELECT id FROM activos_fijos WHERE empresa_id = 2")->fetchColumn();
check(rechaza(fn() => $a->darDeBaja($ajeno, $hoy), 'no existe'), 'una empresa no puede dar de baja activos de otra');
check(rechaza(fn() => $a->darDeBaja($camioneta, '2019-01-01'), 'anterior'), 'la fecha de baja no puede ser anterior a la adquisición');
check(rechaza(fn() => $a->darDeBaja($camioneta, '2026-13-01'), 'fecha'), 'fecha de baja inválida');
check((int) $db->query("SELECT COUNT(*) FROM movimientos_activos WHERE tipo_movimiento = 'baja'")->fetchColumn() === 0, 'las bajas rechazadas no dejan movimientos');

$baja = $a->darDeBaja($camioneta, '2023-12-31', 'Venta del vehículo');
$esperado = ActivosFijos::valuar(['fecha_adquisicion' => '2020-01-10', 'valor_adquisicion' => 30000000, 'valor_residual' => 0, 'vida_util_anos' => 7,
    'metodo_depreciacion' => 'acelerada', 'tasa_depreciacion_acelerada' => 2.0, 'estado' => 'vigente'], '2023-12-31');
check($baja['fecha_baja'] === '2023-12-31' && $baja['valor_neto'] === $esperado['valor_neto'] && $baja['depreciacion_acumulada'] === $esperado['depreciacion_acumulada'], 'la baja valora el activo a la fecha de baja');
$mov = $db->query("SELECT valor_movimiento, descripcion FROM movimientos_activos WHERE tipo_movimiento = 'baja'")->fetch(PDO::FETCH_ASSOC);
check((int) $mov['valor_movimiento'] === $baja['valor_neto'] && str_contains($mov['descripcion'], 'Venta del vehículo'), 'registra la pérdida por baja con su razón');
check(count($a->listarActivos('vigente')) === 1 && count($a->listarActivos('baja')) === 1, 'el activo dado de baja pasa al listado de bajas');
check($a->listarActivos('baja')[0]['depreciacion_acumulada'] === $esperado['depreciacion_acumulada'], 'un activo de baja conserva su depreciación a la fecha de baja');
check(rechaza(fn() => $a->darDeBaja($camioneta, $hoy), 'ya está dado de baja'), 'no se puede dar de baja dos veces');
check((int) $db->query("SELECT COUNT(*) FROM movimientos_activos WHERE tipo_movimiento = 'baja'")->fetchColumn() === 1, 'la segunda baja no duplica el movimiento');
check($a->obtenerReporteActivos()['cantidad_activos'] === 1, 'el reporte solo incluye activos vigentes');
$sinFecha = $a->darDeBaja($notebook);
check($sinFecha['fecha_baja'] === $hoy, 'sin fecha de baja se usa la de hoy');

echo "Activos Fijos: valuación, altas, bajas y reporte verificados ✓\n";
