<?php
// Prueba de Presupuestos (comparativa vs real, creación y archivado): php tools/test-presupuestos-api.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/vouchers.php';
require dirname(__DIR__) . '/api/lib/presupuestos.php';

function check(bool $ok, string $msg): void {
    if (!$ok) {
        fwrite(STDERR, "FALLA: $msg\n");
        exit(1);
    }
    echo "✓ $msg\n";
}
function rechaza(callable $f, string $fragmento = ''): bool {
    try { $f(); return false; }
    catch (PresupuestoError $e) { return $fragmento === '' || str_contains($e->getMessage(), $fragmento); }
}
function voucher(PDO $db, int $empresa, string $tipo, string $fecha, array $lineas): void {
    guardarVoucher($db, ['tipo' => $tipo, 'fecha' => $fecha, 'registro' => 'Ambos', 'glosa' => 'Prueba', 'lineas' => $lineas], null, $empresa);
}
function linea(string $cuenta, int $debe, int $haber): array { return ['cuenta' => $cuenta, 'glosa' => '', 'debe' => $debe, 'haber' => $haber]; }
function pl($cuenta, string $concepto, $monto): array { return ['cuenta_codigo' => $cuenta, 'concepto' => $concepto, 'monto_presupuestado' => $monto]; }
function lineaDe(array $comparativa, string $cuenta): array {
    return array_values(array_filter($comparativa['lineas'], fn($l) => $l['cuenta_codigo'] === $cuenta))[0];
}

// --- Funciones puras
check(Presupuestos::naturaleza('4.1.01') === 'ingreso' && Presupuestos::naturaleza('3.1.04') === 'gasto' && Presupuestos::naturaleza('1.2.02') === 'gasto', 'cuentas 4.x son ingreso; el resto se mide como gasto (Debe - Haber)');
check(Presupuestos::rangoPeriodo(2026, null) === ['2026-01-01', '2026-12-31'] && Presupuestos::rangoPeriodo(2026, 10) === ['2026-10-01', '2026-10-31'] && Presupuestos::rangoPeriodo(2028, 2) === ['2028-02-01', '2028-02-29'], 'rango del período: anual, mensual y febrero bisiesto');

// --- Datos: comprobantes de dos empresas
$db = base_prueba();
crearEsquema($db);
Presupuestos::crearEsquema($db);
Presupuestos::crearEsquema($db); // idempotente
foreach ([1, 2] as $e) cargarPlanEjemplo($db, $e);

voucher($db, 1, 'E', '2026-10-05', [linea('3.1.04', 600000, 0), linea('1.1.02', 0, 600000)]);
voucher($db, 1, 'E', '2026-10-20', [linea('3.1.05', 300000, 0), linea('1.1.02', 0, 300000)]);
voucher($db, 1, 'E', '2026-10-25', [linea('3.1.09', 50000, 0), linea('1.1.02', 0, 50000)]);
voucher($db, 1, 'E', '2026-09-30', [linea('3.1.04', 700000, 0), linea('1.1.02', 0, 700000)]);
voucher($db, 1, 'E', '2027-01-02', [linea('3.1.04', 999999, 0), linea('1.1.02', 0, 999999)]);
voucher($db, 1, 'I', '2026-10-10', [linea('1.1.02', 5000000, 0), linea('4.1.01', 0, 5000000)]);
voucher($db, 1, 'T', '2026-10-12', [linea('4.1.01', 200000, 0), linea('1.1.02', 0, 200000)]); // devolución: baja el ingreso neto
voucher($db, 2, 'E', '2026-10-05', [linea('3.1.04', 9000000, 0), linea('1.1.02', 0, 9000000)]);

$p = new Presupuestos($db, 1);
$otra = new Presupuestos($db, 2);

// --- Validaciones al crear
$ok = [pl('3.1.04', 'Arriendo', 500000)];
check(rechaza(fn() => $p->crearPresupuesto('', 2026, 10, $ok), 'nombre'), 'rechaza nombre vacío');
check(rechaza(fn() => $p->crearPresupuesto(['x'], 2026, 10, $ok), 'texto'), 'rechaza un arreglo como nombre');
check(rechaza(fn() => $p->crearPresupuesto('P', 1999, 10, $ok), 'año'), 'rechaza año fuera de rango');
check(rechaza(fn() => $p->crearPresupuesto('P', 'abc', 10, $ok), 'año'), 'rechaza año no numérico');
check(rechaza(fn() => $p->crearPresupuesto('P', 2026, 13, $ok), 'mes'), 'rechaza mes 13 (antes se mostraba el anual en silencio)');
check(rechaza(fn() => $p->crearPresupuesto('P', 2026, 10, []), 'al menos una'), 'rechaza un presupuesto sin líneas');
check(rechaza(fn() => $p->crearPresupuesto('P', 2026, 10, 'texto'), 'al menos una'), 'rechaza líneas que no son una lista');
check(rechaza(fn() => $p->crearPresupuesto('P', 2026, 10, ['x']), 'formato'), 'rechaza una línea que no es un objeto');
check(rechaza(fn() => $p->crearPresupuesto('P', 2026, 10, [pl('9.9.99', '', 1)]), 'no existe'), 'rechaza una cuenta que no está en el plan de la empresa');
check(rechaza(fn() => $p->crearPresupuesto('P', 2026, 10, [pl('3.1.04', '', 1), pl('3.1.04', '', 2)]), 'repetida'), 'rechaza cuentas repetidas');
check(rechaza(fn() => $p->crearPresupuesto('P', 2026, 10, [pl('3.1.04', '', -5)]), 'monto'), 'rechaza monto negativo');
check(rechaza(fn() => $p->crearPresupuesto('P', 2026, 10, [pl('3.1.04', '', '12.5')]), 'monto'), 'rechaza monto con decimales');
check(rechaza(fn() => $p->crearPresupuesto('P', 2026, 10, [pl('3.1.04', '', null)]), 'monto'), 'rechaza monto ausente');
check(rechaza(fn() => $p->crearPresupuesto('P', 2026, 10, [pl(['3.1.04'], '', 1)]), 'texto'), 'rechaza un arreglo como cuenta');
check(rechaza(fn() => $p->crearPresupuesto('P', 2026, 10, array_map(fn($i) => pl('3.1.04', '', 1), range(1, 201))), 'hasta 200'), 'limita las líneas');
check((int) $db->query('SELECT COUNT(*) FROM presupuestos')->fetchColumn() === 0 && (int) $db->query('SELECT COUNT(*) FROM presupuesto_lineas')->fetchColumn() === 0, 'las validaciones no dejan filas a medias');

// --- Crear y comparar (mensual)
$mensual = $p->crearPresupuesto('Presupuesto octubre', 2026, 10, [
    pl('3.1.04', 'Arriendo oficina', 500000),
    pl('3.1.05', 'Gastos generales', '400000'),
    pl('3.1.09', 'Servicios básicos', 0),
    pl('3.1.07', 'Gastos financieros', 0),
    pl('4.1.01', 'Ventas', 6000000),
]);
check($mensual > 0, 'crea un presupuesto mensual con sus líneas (monto numérico en texto aceptado)');
$c = $p->obtenerComparativa(2026, 10);
check($c['presupuesto_id'] === $mensual && $c['periodo'] === '2026-10' && count($c['lineas']) === 5, 'la comparativa del mes devuelve las líneas del presupuesto');
check(lineaDe($c, '3.1.04')['monto_real'] === 600000 && lineaDe($c, '3.1.04')['varianza'] === 100000 && lineaDe($c, '3.1.04')['varianza_pct'] === 20.0, 'gasto real = Debe - Haber del mes, sin los comprobantes de otro mes, año ni empresa');
check(lineaDe($c, '3.1.04')['cuenta_nombre'] === 'Arriendos', 'incluye el nombre de la cuenta del plan');
check(lineaDe($c, '4.1.01')['monto_real'] === 4800000 && lineaDe($c, '4.1.01')['naturaleza'] === 'ingreso', 'ingreso real = Haber - Debe (la devolución en un traspaso lo reduce)');
check(lineaDe($c, '3.1.07')['monto_real'] === 0 && lineaDe($c, '3.1.07')['varianza_pct'] === 0, 'línea sin movimientos: real cero');

$alertas = array_column($c['alertas'], 'mensaje', 'cuenta');
check($alertas['3.1.04'] === 'Gasto 20% sobre el presupuesto' && $alertas['3.1.05'] === 'Ahorro del 25%', 'alertas de gasto: sobre el presupuesto y ahorro');
check($alertas['4.1.01'] === 'Ingresos 20% bajo lo presupuestado', 'alerta de ingreso: recibir menos de lo presupuestado');
check($alertas['3.1.09'] === 'Gasto sin presupuesto asignado' && !isset($alertas['3.1.07']), 'alerta de gasto sin presupuesto (antes quedaba en 0 % sin avisar) y sin alerta si no hay movimiento');
check(array_column($c['alertas'], 'tipo', 'cuenta')['4.1.01'] === 'warning' && array_column($c['alertas'], 'tipo', 'cuenta')['3.1.05'] === 'info', 'las alertas distinguen advertencia de información');

check($c['totales']['gasto'] === ['presupuestado' => 900000, 'real' => 950000, 'varianza' => 50000, 'varianza_pct' => 5.56], 'totales de gastos por separado');
check($c['totales']['ingreso'] === ['presupuestado' => 6000000, 'real' => 4800000, 'varianza' => -1200000, 'varianza_pct' => -20.0], 'totales de ingresos por separado (no se mezclan con los gastos)');

// --- Período anual y otros períodos
$anual = $p->crearPresupuesto('Presupuesto 2026', 2026, null, [pl('3.1.04', 'Arriendo anual', 6000000)]);
$ca = $p->obtenerComparativa(2026);
check($ca['presupuesto_id'] === $anual && $ca['periodo'] === '2026' && lineaDe($ca, '3.1.04')['monto_real'] === 1300000, 'el anual suma todo el año (septiembre + octubre), sin enero del año siguiente');
check($p->obtenerComparativa(2026, '')['presupuesto_id'] === $anual, 'un mes vacío equivale a anual');
check($p->obtenerComparativa(2026, 9)['presupuesto_id'] === null && $p->obtenerComparativa(2026, 9)['lineas'] === [], 'un mes sin presupuesto devuelve líneas vacías');
check($p->obtenerComparativa(2026, 9)['totales']['gasto']['presupuestado'] === 0, 'y totales en cero');
check(rechaza(fn() => $p->obtenerComparativa(2026, 13), 'mes') && rechaza(fn() => $p->obtenerComparativa('x'), 'año'), 'período inválido al consultar');

// --- Período duplicado, archivar y volver a crear
check(rechaza(fn() => $p->crearPresupuesto('Otro octubre', 2026, 10, $ok), 'Ya hay un presupuesto activo'), 'no permite dos presupuestos activos para el mismo mes');
check(rechaza(fn() => $p->crearPresupuesto('Otro anual', 2026, null, $ok), 'Ya hay un presupuesto activo'), 'ni dos anuales');
check((int) $db->query('SELECT COUNT(*) FROM presupuestos')->fetchColumn() === 2, 'el rechazo por período duplicado no deja filas');
check($otra->obtenerComparativa(2026, 10)['presupuesto_id'] === null, 'una empresa no ve los presupuestos de otra');
$otraId = $otra->crearPresupuesto('Octubre empresa 2', 2026, 10, [pl('3.1.04', '', 1000000)]);
check(lineaDe($otra->obtenerComparativa(2026, 10), '3.1.04')['monto_real'] === 9000000, 'cada empresa compara contra sus propios comprobantes');

check(rechaza(fn() => $otra->archivarPresupuesto($mensual), 'no existe'), 'una empresa no puede archivar presupuestos de otra');
check(rechaza(fn() => $p->archivarPresupuesto(9999), 'no existe'), 'archivar un presupuesto inexistente');
check(rechaza(fn() => $p->archivarPresupuesto([$mensual, 1]), 'no es válido') && rechaza(fn() => $p->archivarPresupuesto('1abc'), 'no es válido'), 'ids mal formados se rechazan sin archivar otro presupuesto');
$p->archivarPresupuesto($mensual);
check($p->obtenerComparativa(2026, 10)['presupuesto_id'] === null, 'un presupuesto archivado deja de compararse');
check(rechaza(fn() => $p->archivarPresupuesto($mensual), 'ya está archivado'), 'no se archiva dos veces');
check((int) $db->query("SELECT COUNT(*) FROM presupuesto_lineas WHERE presupuesto_id = $mensual")->fetchColumn() === 5, 'archivar conserva las líneas');
$nuevo = $p->crearPresupuesto('Octubre corregido', 2026, 10, [pl('3.1.04', 'Arriendo', 650000)]);
check($nuevo > $mensual && $p->obtenerComparativa(2026, 10)['presupuesto_id'] === $nuevo, 'al archivar se libera el período para crear uno nuevo');

// --- Listados
$lista = $p->listarPresupuestos(2026);
check(array_column($lista, 'nombre') === ['Presupuesto 2026', 'Presupuesto octubre', 'Octubre corregido'] && $lista[0]['mes'] === null && $lista[1]['estado'] === 'archivado', 'lista del año: anual primero, luego por mes, con su estado');
check($p->listarPresupuestos(2027) === [] && rechaza(fn() => $p->listarPresupuestos(1900), 'año'), 'lista vacía otro año y año inválido');
$cuentas = $p->listarCuentasPresupuestables();
check(count($cuentas) > 10 && !array_filter($cuentas, fn($c) => !in_array($c['codigo'][0], ['3', '4'], true)) && $cuentas[0]['codigo'] < end($cuentas)['codigo'], 'cuentas presupuestables: solo gastos (3.x) e ingresos (4.x), ordenadas');

// --- Sin comprobantes ni plan de cuentas
$sinVouchers = base_prueba();
Presupuestos::crearEsquema($sinVouchers);
$sinVouchers->exec("INSERT INTO presupuestos (empresa_id, nombre, periodo, anio, mes, estado, creado_en, actualizado_en) VALUES (1, 'Sin datos', 'anual', 2026, NULL, 'activo', 'x', 'x')");
$sinVouchers->exec("INSERT INTO presupuesto_lineas (presupuesto_id, orden, cuenta_codigo, concepto, monto_presupuestado) VALUES (1, 0, '3.1.04', 'Arriendo', 1000)");
$sd = (new Presupuestos($sinVouchers, 1))->obtenerComparativa(2026);
check(lineaDe($sd, '3.1.04')['monto_real'] === 0 && lineaDe($sd, '3.1.04')['cuenta_nombre'] === '' && $sd['totales']['gasto']['presupuestado'] === 1000, 'sin tablas de comprobantes ni plan el real es cero y la comparativa funciona');
check($sd['alertas'][0]['mensaje'] === 'Ahorro del 100%', 'y se informa el ahorro completo');
check((new Presupuestos($sinVouchers, 1))->listarCuentasPresupuestables() === [] && rechaza(fn() => (new Presupuestos($sinVouchers, 1))->crearPresupuesto('P', 2027, null, $ok), 'no existe'), 'sin plan de cuentas no se pueden crear presupuestos');

// Un error real de la base de datos no se disfraza de "real = 0" (antes salía un falso "Ahorro del 100%")
$roto = base_prueba();
Presupuestos::crearEsquema($roto);
$roto->exec('CREATE TABLE vouchers (id INTEGER PRIMARY KEY, empresa_id INT, fecha TEXT)');
$roto->exec('CREATE TABLE voucher_lineas (id INTEGER PRIMARY KEY, voucher_id INT)'); // le falta la columna "cuenta"
$roto->exec("INSERT INTO presupuestos (empresa_id, nombre, periodo, anio, mes, estado, creado_en, actualizado_en) VALUES (1, 'Roto', 'anual', 2026, NULL, 'activo', 'x', 'x')");
$roto->exec("INSERT INTO presupuesto_lineas (presupuesto_id, orden, cuenta_codigo, concepto, monto_presupuestado) VALUES (1, 0, '3.1.04', 'Arriendo', 1000)");
try { (new Presupuestos($roto, 1))->obtenerComparativa(2026); $propagado = false; }
catch (PDOException) { $propagado = true; }
check($propagado, 'un fallo de SQL al calcular el real se propaga (la API responde 500) en vez de mostrarse como real = 0');

echo "Presupuestos: comparativa vs real, alertas, creación y archivado verificados ✓\n";
