<?php
// Prueba del inicio de Cifrax Tributario por régimen (14 A y persona natural) con datos inventados.
// Uso: php tools/test-ctrib-inicio.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/ctrib-inicio.php';

function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FALLA: $msg\n"); exit(1); } }
function falla(callable $accion, string $contiene, string $msg): void
{
    try { $accion(); } catch (Throwable $e) {
        check(str_contains($e->getMessage(), $contiene), "$msg (mensaje: " . $e->getMessage() . ")");
        return;
    }
    check(false, "$msg (no lanzó error)");
}
function fila(array $inicio, string $nombre): array
{
    foreach ($inicio['grupos'] as $g) foreach ($g['filas'] as $f) if ($f['nombre'] === $nombre) return $f;
    throw new RuntimeException("sin fila $nombre");
}

$pdo = base_prueba();
empresas_schema($pdo);
ctrib_schema($pdo);
sii_schema($pdo);
$insertar = $pdo->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)');
$insertar->execute(['t1', 'EMPRESA PRUEBA 14 A', json_encode(['rut' => '99000011-0']), '2026-11-01']);
$e14 = (int) $pdo->lastInsertId();
$insertar->execute(['t2', 'PERSONA PRUEBA', json_encode(['rut' => '99000022-3']), '2026-11-01']);
$persona = (int) $pdo->lastInsertId();
$insertar->execute(['t3', 'EMPRESA PRUEBA PRO PYME', json_encode(['rut' => '99000033-7']), '2026-11-01']);
$pyme = (int) $pdo->lastInsertId();

// 1. Sin régimen configurado: el visor conserva su inicio actual.
check(ctrib_inicio($pdo, $e14, 2026)['propio'] === false, 'sin régimen no hay inicio propio');

// 2. Validación de la configuración: el tipo persona natural va con su régimen, y ningún otro tipo lo usa.
falla(fn() => ctrib_empresa_config_guardar($pdo, $e14, 'SPA', 'PERSONA', 'prueba'), 'Persona natural', 'un SpA no puede tener régimen de persona');
falla(fn() => ctrib_empresa_config_guardar($pdo, $e14, 'PERSONA_NATURAL', '14A', 'prueba'), 'Persona natural', 'una persona no puede tener régimen 14 A');
ctrib_empresa_config_guardar($pdo, $e14, 'SPA', '14A', 'prueba');
ctrib_empresa_config_guardar($pdo, $persona, 'PERSONA_NATURAL', 'PERSONA', 'prueba');
ctrib_empresa_config_guardar($pdo, $pyme, 'EIRL', '14D3', 'prueba');

// 3. Pro Pyme tiene su inicio por definición: filas, cierre y estados sin datos.
$pyI = ctrib_inicio($pdo, $pyme, 2026);
check($pyI['propio'] === true && $pyI['regimen'] === '14D3', 'Pro Pyme tiene inicio por definición');
check($pyI['cierre'] !== null && $pyI['cierre']['estado'] === 'PENDIENTE', 'Pro Pyme: cierre pendiente sin datos');
check(fila($pyI, 'Homologación de cuentas')['estado'] !== '', 'Pro Pyme: homologación con estado');
check(fila($pyI, 'Base imponible (RLI)')['clase'] === 'pend', 'Pro Pyme: R17 pendiente sin datos');

// 4. 14 A sin balance: el balance está pendiente y los recuadros nuevos por calcular.
$i = ctrib_inicio($pdo, $e14, 2026);
check($i['propio'] === true && $i['regimen'] === '14A', '14 A tiene inicio propio');
check(fila($i, 'Balance')['estado'] === 'PENDIENTE', 'balance sin importar: pendiente');
check(fila($i, 'Base imponible de primera categoría')['estado'] === 'POR CALCULAR', 'R12 todavía no se calcula');
check(fila($i, 'Retiro o Dividendos')['estado'] === 'PENDIENTE', 'retiros sin importar: pendiente');
check(fila($i, 'Enajenación de acciones y derechos sociales')['estado'] === 'PENDIENTE', 'R4 sin finalizar: pendiente');

// 5. Con balance importado, el balance queda completado.
$cuentas = [
    ['codigo' => '101001', 'nombre' => 'CAJA', 'debitos' => 100, 'creditos' => 0],
    ['codigo' => '201001', 'nombre' => 'PROVEEDORES', 'debitos' => 0, 'creditos' => 100],
];
ctrib_mapeo_guardar($pdo, $e14, ['1' => 'ACTIVO', '2' => 'PASIVO', '3' => 'CAPITAL', '4' => 'PERDIDA', '5' => 'GANANCIA'], 'prueba');
ctrib_balance_guardar($pdo, $e14, 2026, 'prueba', $cuentas, 'ficticio.csv', 'prueba');
check(fila(ctrib_inicio($pdo, $e14, 2026), 'Balance')['estado'] === 'COMPLETADO', 'balance importado: completado');

// 6. Retiros: sin importar, pendiente; con "sin retiros" declarado, completado.
ctrib_retiros_modo_fijar($pdo, $e14, 2026, 'sin_retiros', 'prueba');
check(fila(ctrib_inicio($pdo, $e14, 2026), 'Retiro o Dividendos')['estado'] === 'COMPLETADO', 'retiros declarados como "sin retiros": completado');

// 7. Persona natural: la consulta del F22 sale del último resultado guardado por el agente.
$p = ctrib_inicio($pdo, $persona, 2026);
check($p['propio'] === true && $p['regimen'] === 'PERSONA', 'persona natural tiene inicio propio');
check(fila($p, 'Consulta del Formulario 22 en el SII')['estado'] === 'SIN CONSULTAR', 'sin consulta del F22: sin consultar');
check(ctrib_inicio($pdo, $persona, 2026)['siguiente']['nombre'] === 'Consulta del Formulario 22 en el SII', 'persona: primero consultar el F22');
$pdo->prepare("INSERT INTO sii_consultas (empresa_id, tipo, anio, estado, datos_json, consultado_en) VALUES (?, 'f22', 2026, 'Aceptada', '{}', '2026-11-26 10:00:00')")
    ->execute([$persona]);
$f = fila(ctrib_inicio($pdo, $persona, 2026), 'Consulta del Formulario 22 en el SII');
check($f['estado'] === 'ACEPTADA' && $f['clase'] === 'ok', 'consulta guardada: se muestra su estado');
check(fila(ctrib_inicio($pdo, $persona, 2026), 'Honorarios (boletas recibidas)')['estado'] === 'POR CALCULAR', 'honorarios: por calcular hasta su etapa');

// 8. La respuesta se puede convertir a JSON (la lee el visor).
check(json_last_error() === JSON_ERROR_NONE && json_decode(json_encode(ctrib_inicio($pdo, $e14, 2026)), true) !== null, 'la respuesta es JSON válido');

// 9. Paso siguiente: en el 14 A, lo pendiente que sí se puede hacer hoy (los recuadros por calcular no cuentan).
$i = ctrib_inicio($pdo, $e14, 2026);
// Los retiros ya se declararon (paso 6): lo siguiente que se puede hacer es el R4.
check($i['siguiente'] !== null && $i['siguiente']['nombre'] === 'Enajenación de acciones y derechos sociales', 'siguiente paso del 14 A: R4');
check($i['siguiente']['ayuda'] !== '', 'el paso siguiente explica qué hacer');
check(fila($i, 'Balance')['ayuda'] !== '', 'cada fila trae su explicación');

// 10. Persona: el paso siguiente es consultar el F22; después de la consulta ya no hay nada que se pueda hacer hoy.
check(ctrib_inicio($pdo, $persona, 2026)['siguiente'] === null, 'persona con F22 consultado: no hay paso pendiente que se pueda hacer hoy');

// 11. Régimen 14 G (fundación): se guarda en la configuración; aún no tiene inicio propio.
ctrib_empresa_config_guardar($pdo, $pyme, 'OTRO', '14G', 'prueba');
check(ctrib_empresa_config($pdo, $pyme)['regimen'] === '14G' && ctrib_inicio($pdo, $pyme, 2026)['propio'] === false, '14 G se guarda y conserva la vista de fundación');

// 12. Cambio de régimen desde la pantalla: queda en el historial y ajusta el tipo.
ctrib_regimen_config_cambiar($pdo, $e14, 'PERSONA', 'prueba');
check(ctrib_empresa_config($pdo, $e14)['tipo'] === 'PERSONA_NATURAL', 'pasar a persona natural cambia también el tipo');
ctrib_regimen_config_cambiar($pdo, $e14, '14A', 'prueba');
$c = ctrib_empresa_config($pdo, $e14);
check($c['regimen'] === '14A' && $c['tipo'] === 'OTRO', 'volver a 14 A: el tipo pasa a "Otro" (ya no es persona natural)');
$hist = $pdo->query("SELECT COUNT(*) FROM ctrib_historial WHERE entidad = 'empresa'")->fetchColumn();
check((int) $hist >= 2, 'los cambios de régimen quedan en el historial');
echo "Cifrax Tributario inicio por régimen (14 A y persona natural): verificado\n";
