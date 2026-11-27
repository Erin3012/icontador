<?php
// Reglas que protegen el cálculo: sugerencias en bloque, cambio de régimen, años cerrados, falta del balance anterior
// y estados del SII en Inicio. Uso: php tools/test-ctrib-correcciones.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/ctrib-pdf.php';
require dirname(__DIR__) . '/api/lib/ctrib-inicio.php';
require dirname(__DIR__) . '/api/lib/ctrib-sugerencias.php';

function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FALLA: $msg\n"); exit(1); } }
function falla(callable $accion, string $contiene, string $msg): void
{
    try { $accion(); } catch (Throwable $e) {
        check(str_contains($e->getMessage(), $contiene), "$msg (mensaje: " . $e->getMessage() . ")");
        return;
    }
    check(false, "$msg (no lanzó error)");
}

$pdo = base_prueba();
empresas_schema($pdo);
crearEsquema($pdo);
ctrib_schema($pdo);
sii_schema($pdo);
$nueva = function (string $nombre) use ($pdo): int {
    $pdo->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')
        ->execute([$nombre, $nombre, json_encode(['rut' => '99000044-0']), '2026-11-27']);
    return (int) $pdo->lastInsertId();
};
$cuenta = fn(string $codigo, string $nombre, int $debe, int $haber) => ['codigo' => $codigo, 'nombre' => $nombre, 'debitos' => $debe, 'creditos' => $haber];
$mapeo = ['1' => 'ACTIVO', '2' => 'PASIVO', '3' => 'CAPITAL', '4' => 'PERDIDA', '5' => 'GANANCIA'];
$balance = [
    $cuenta('101001', 'CAJA', 7000000, 0),
    $cuenta('102001', 'EQUIPOS', 2000000, 0),
    $cuenta('103001', 'MERCADERIAS', 4000000, 0),
    $cuenta('301001', 'CAPITAL', 0, 3000000),
    $cuenta('401001', 'GASTO PARTICULAR SOCIO', 500000, 0),
    $cuenta('401002', 'ARRIENDOS', 1500000, 0),
    $cuenta('501001', 'VENTAS', 0, 12000000),
];

// 1. Sugerencias en bloque: la de confianza baja queda para revisarla una a una.
$e = $nueva('SUGERENCIAS');
ctrib_mapeo_guardar($pdo, $e, $mapeo, 'p');
ctrib_balance_guardar($pdo, $e, 2026, 'prueba', $balance, 'f.csv', 'p');
$h = ctrib_homologacion_con_sugerencias($pdo, $e, 2026);
$confianzas = array_column(array_map(fn($c) => [$c['cuenta'], $c['sugerencia']['confianza']], $h['cuentasResultado']), 1, 0);
check($confianzas['401001'] === 'baja', 'gasto sin regla: confianza baja');
check($h['sugerenciasAplicables'] === 2, 'solo se ofrecen en bloque las de confianza alta o media');
ctrib_homologacion_aplicar_sugerencias($pdo, $e, 2026, 'p');
check(!isset(ctrib_asignaciones($pdo, $e, 2026)['401001']) && ctrib_homologacion($pdo, $e, 2026)['pendientes'] === 1, 'la de confianza baja sigue pendiente');

// 2. Cambio de régimen: el modo de retiros acompaña al régimen.
$r = $nueva('REGIMEN');
ctrib_empresa_config_guardar($pdo, $r, 'SPA', '14D3', 'p');
check(ctrib_retiros_modo($pdo, $r, 2026) === 'importados', '14 D N°3: retiros importados');
ctrib_retiros_modo_fijar($pdo, $r, 2025, 'sin_retiros', 'p');
ctrib_retiros_modo_fijar($pdo, $r, 2026, 'importados', 'p');
ctrib_regimen_config_cambiar($pdo, $r, '14D8', 'p');
check(ctrib_retiros_modo($pdo, $r, 2026) === 'totalidad' && ctrib_retiros_modo($pdo, $r, 2024) === 'totalidad', 'al pasar a 14 D N°8 se atribuye la totalidad');
check(ctrib_retiros_modo($pdo, $r, 2025) === 'sin_retiros', 'la declaración sin retiros se conserva');
ctrib_retiros_modo_fijar($pdo, $r, 2026, 'totalidad', 'p');
ctrib_regimen_config_cambiar($pdo, $r, '14D3', 'p');
check(ctrib_retiros_modo($pdo, $r, 2026) === 'importados', 'al salir del 14 D N°8 vuelven los retiros importados');
ctrib_retiros_modo_fijar($pdo, $r, 2026, 'totalidad', 'p');
ctrib_regimen_config_cambiar($pdo, $r, '14A', 'p');
check(ctrib_retiros_modo($pdo, $r, 2026) === 'totalidad', 'entre otros regímenes se respeta la elección');

$t = $nueva('SIN SOCIOS');
ctrib_empresa_config_guardar($pdo, $t, 'SPA', '14D8', 'p');
$estadoRetiros = function () use ($pdo, $t): string {
    foreach (ctrib_inicio($pdo, $t, 2026)['grupos'] as $g) foreach ($g['filas'] as $f) if ($f['nombre'] === 'Propietarios y retiros') return $f['estado'];
    return '';
};
check($estadoRetiros() === 'PENDIENTE', '14 D N°8 sin propietarios: pendiente');
ctrib_retiros_modo_guardar($pdo, $t, 2026, 'totalidad', [['rut' => '11111111-1', 'nombre' => 'DUEÑO', 'participacion' => 100]], 'p');
check($estadoRetiros() === 'COMPLETADO', '14 D N°8 con propietarios: completado');

// 3. Con un año cerrado no se cambia el régimen desde otro año.
$pdo->prepare("INSERT INTO ctrib_recuadros (empresa_id, anio, recuadro, estado, finalizado, usuario) VALUES (?, 2025, 'CIERRE', 'FINALIZADO', '2026-01-01', 'p')")
    ->execute([$r]);
falla(fn() => ctrib_exigir_cambio_permitido($pdo, $r, 'regimen_config', ['regimen' => '14D8', 'anio' => 2026]), 'régimen', 'selector: régimen bloqueado');
falla(fn() => ctrib_exigir_cambio_permitido($pdo, $r, 'composicion_guardar', ['regimen' => '14D3', 'anio' => 2026]), 'régimen', 'composición: régimen bloqueado');
ctrib_exigir_cambio_permitido($pdo, $r, 'composicion_guardar', ['regimen' => '14A', 'anio' => 2026]); // mismo régimen: permitido

// 4. Sin balance del año anterior no se finaliza con saldos supuestos.
$s = $nueva('SIN ANTERIOR');
ctrib_mapeo_guardar($pdo, $s, $mapeo, 'p');
ctrib_balance_guardar($pdo, $s, 2026, 'prueba', $balance, 'f.csv', 'p');
foreach (['501001' => '1400', '401001' => '1422', '401002' => '1415'] as $c => $codigo) ctrib_homologar($pdo, $s, 2026, (string) $c, $codigo, 'p');
ctrib_existencias_guardar($pdo, $s, 2026, ['103001'], null, null, 'p');
check(str_contains(implode(' ', ctrib_r17($pdo, $s, 2026)['bloqueos']), 'existencia inicial'), 'R17: pide la existencia inicial');
ctrib_empresa_config_guardar($pdo, $s, 'SPA', '14D8', 'p');
$r22 = ctrib_r22($pdo, $s, 2026);
$texto = implode(' ', $r22['bloqueos']);
check(str_contains($texto, 'existencia inicial') && str_contains($texto, '1618') && $r22['confirmar1618'] === 2000000, 'R22: pide existencia inicial y confirmar el 1618');
falla(fn() => ctrib_r22_finalizar($pdo, $s, 2026, 'p'), '1618', 'R22 no se finaliza sin confirmar');
ctrib_existencias_guardar($pdo, $s, 2026, ['103001'], 0, null, 'p');
$r22 = ctrib_r22_guardar_valor($pdo, $s, 2026, '1618', 2000000, 'p');
check($r22['bloqueos'] === [] && $r22['confirmar1618'] === null, 'confirmados los datos, se puede finalizar');
// Con el balance anterior importado no hace falta confirmar nada.
$c = $nueva('CON ANTERIOR');
ctrib_mapeo_guardar($pdo, $c, $mapeo, 'p');
ctrib_balance_guardar($pdo, $c, 2025, 'prueba', $balance, 'f.csv', 'p');
ctrib_balance_guardar($pdo, $c, 2026, 'prueba', $balance, 'f.csv', 'p');
ctrib_existencias_guardar($pdo, $c, 2026, ['103001'], null, null, 'p');
check(ctrib_existencias_bloqueo($pdo, $c, 2026) === null && ctrib_r22($pdo, $c, 2026)['confirmar1618'] === null, 'con balance anterior no hay bloqueo');

// 5. Inicio: solo un resultado favorable del SII queda en verde.
$p = $nueva('PERSONA');
ctrib_empresa_config_guardar($pdo, $p, 'PERSONA_NATURAL', 'PERSONA', 'p');
$estadoF22 = function (string $estado) use ($pdo, $p): string {
    $pdo->prepare("INSERT INTO sii_consultas (empresa_id, tipo, anio, estado, datos_json, consultado_en) VALUES (?, 'f22', 2026, ?, '{}', '2026-11-27 10:00:00')")
        ->execute([$p, $estado]);
    foreach (ctrib_inicio($pdo, $p, 2026)['grupos'] as $g) foreach ($g['filas'] as $f) if ($f['estado'] === mb_strtoupper($estado)) return $f['clase'];
    return '';
};
foreach (['Aceptada' => 'ok', 'En proceso' => 'proceso', 'Con inconsistencia' => 'pend', 'Devolución retenida' => 'pend', 'Otro' => 'pend'] as $estado => $clase) {
    check($estadoF22($estado) === $clase, "F22 «{$estado}» debe quedar como $clase");
}

// Sin la tabla de consultas del SII el Inicio no falla.
$pdo->exec('DROP TABLE sii_consultas');
$estados = [];
foreach (ctrib_inicio($pdo, $p, 2026)['grupos'] as $g) foreach ($g['filas'] as $f) $estados[] = $f['estado'];
check(in_array('SIN CONSULTAR', $estados, true), 'sin tabla de consultas: sin consultar');

fwrite(STDOUT, "Cifrax Tributario: sugerencias, cambio de régimen, años cerrados, balance anterior y estados del SII verificados\n");
