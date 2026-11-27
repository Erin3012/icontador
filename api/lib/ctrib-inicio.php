<?php
// Inicio de Cifrax Tributario por régimen. Las filas salen de api/lib/ctrib/regimenes.json y cada estado se calcula
// aquí con los datos de la empresa. Un régimen sin definición conserva el inicio que trae la pantalla.
declare(strict_types=1);

require_once __DIR__ . '/ctrib-r17.php';
require_once __DIR__ . '/ctrib-r20.php';
require_once __DIR__ . '/sii.php';
require_once __DIR__ . '/ctrib-cierre.php';

const CTRIB_MOTIVO_PENDIENTE = 'Esta parte de Cifrax Tributario todavía no está disponible.';

/** Definición de un régimen con inicio propio, o null si no la tiene. */
function ctrib_regimen_def(string $regimen): ?array
{
    $todos = json_decode((string) file_get_contents(__DIR__ . '/ctrib/regimenes.json'), true);
    return is_array($todos[$regimen] ?? null) ? $todos[$regimen] : null;
}

/** Estado (texto y clase) de una fila según la fuente declarada en el JSON. */
function ctrib_inicio_estado(PDO $pdo, int $empresa, int $anio, array $fila): array
{
    switch ($fila['fuente']) {
        case 'balance':
            $balance = ctrib_balance_guardado($pdo, $empresa, $anio);
            return !empty($balance['cuentas']) ? ['texto' => 'COMPLETADO', 'clase' => 'ok'] : ['texto' => 'PENDIENTE', 'clase' => 'pend'];
        case 'retiros':
            $r = ctrib_retiros_del_anio($pdo, $empresa, $anio);
            // La atribución total solo está completa cuando hay a quién atribuir.
            $hay = $r['modo'] === 'sin_retiros' || !empty($r['retiros'])
                || ($r['modo'] === 'totalidad' && ctrib_socios($pdo, $empresa, $anio) !== []);
            return $hay ? ['texto' => 'COMPLETADO', 'clase' => 'ok'] : ['texto' => 'PENDIENTE', 'clase' => 'pend'];
        case 'dj1948':
            $dj = ctrib_dj1948($pdo, $empresa, $anio);
            $conCertificado = $dj['socios'] !== [] && array_reduce($dj['socios'], fn($ok, $s) => $ok && !empty($s['certificado']), true);
            if ($dj['paso1']['listo'] && ($conCertificado || $dj['modo'] === 'sin_retiros')) {
                return ['texto' => 'COMPLETADO', 'clase' => 'ok'];
            }
            return $dj['paso1']['listo'] ? ['texto' => 'EN PROCESO', 'clase' => 'proceso'] : ['texto' => 'PENDIENTE', 'clase' => 'pend'];
        case 'recuadro':
            $e = ctrib_estado_recuadro($pdo, $empresa, $anio, $fila['recuadro']);
            if ($e['estado'] === 'FINALIZADO') {
                return ['texto' => 'FINALIZADO', 'clase' => 'ok'];
            }
            return $e['estado'] === 'BORRADOR' ? ['texto' => 'PENDIENTE', 'clase' => 'pend'] : ['texto' => 'EN PROCESO', 'clase' => 'proceso'];
        case 'homologacion':
            $h = ctrib_homologacion($pdo, $empresa, $anio);
            return $h['pendientes'] > 0 || $h['alertas'] > 0 ? ['texto' => 'PENDIENTE', 'clase' => 'pend'] : ['texto' => 'AL DÍA', 'clase' => 'ok'];
        case 'dj1947':
            $socios = ctrib_socios($pdo, $empresa, $anio);
            $completos = $socios !== [] && !array_filter($socios, fn($s) => $s['rut'] === '');
            return $completos && ctrib_estado_recuadro($pdo, $empresa, $anio, 'R22')['estado'] === 'FINALIZADO'
                ? ['texto' => 'COMPLETADO', 'clase' => 'ok'] : ['texto' => 'PENDIENTE', 'clase' => 'pend'];
        case 'dj1847':
        case 'dj1926':
            $clave = strtoupper($fila['fuente']);
            $e = ctrib_estado_recuadro($pdo, $empresa, $anio, $clave);
            if ($e['estado'] === 'FINALIZADO') {
                return ['texto' => 'COMPLETADO', 'clase' => 'ok'];
            }
            return ['texto' => 'PENDIENTE', 'clase' => 'pend'];
        case 'sii_consulta':
            try {
                $st = $pdo->prepare('SELECT estado FROM sii_consultas WHERE empresa_id = ? AND tipo = ? AND anio = ? ORDER BY id DESC LIMIT 1');
                $st->execute([$empresa, $fila['consulta'], $anio]);
                $estado = trim((string) $st->fetchColumn());
            } catch (PDOException $ex) {
                $estado = ''; // instalación cuyo módulo SII no guarda consultas: Inicio sigue funcionando
            }
            if ($estado === '') {
                return ['texto' => 'SIN CONSULTAR', 'clase' => 'pend'];
            }
            $clase = in_array($estado, ['Aceptada', 'Todas aceptadas'], true) ? 'ok'
                : (in_array($estado, ['En proceso', 'En revisión'], true) ? 'proceso' : 'pend');
            return ['texto' => mb_strtoupper($estado), 'clase' => $clase];
        default:
            // Todavía no calculado: pendiente hasta su etapa del plan.
            return ['texto' => 'POR CALCULAR', 'clase' => 'pend'];
    }
}

/**
 * Inicio del año para la empresa: propio = true solo si el régimen tiene definición.
 * "siguiente" es el primer paso pendiente que la persona sí puede hacer hoy (lo que aún no existe no cuenta).
 * Con $vista se muestra otro régimen sin guardarlo (vista previa del selector); en ese caso no hay cierre.
 */
function ctrib_inicio(PDO $pdo, int $empresa, int $anio, string $vista = ''): array
{
    $config = ctrib_empresa_config($pdo, $empresa);
    $regimen = in_array($vista, CTRIB_REGIMENES, true) ? $vista : $config['regimen'];
    $def = $regimen !== '' ? ctrib_regimen_def($regimen) : null;
    $previa = $def !== null && $regimen !== $config['regimen'];
    $salida = ['propio' => $def !== null, 'regimen' => $regimen, 'tipo' => $config['tipo'], 'nombre' => $def['nombre'] ?? '',
        'vistaPrevia' => $previa, 'regimenGuardado' => $config['regimen'], 'grupos' => [], 'siguiente' => null, 'cierre' => null];
    if ($def === null) {
        return $salida;
    }
    $todasLasFilas = array_merge(...array_column($def['grupos'], 'filas'));
    if (in_array('sii_consulta', array_column($todasLasFilas, 'fuente'), true)) {
        sii_schema($pdo);
    }
    $siguiente = null;
    foreach ($def['grupos'] as $grupo) {
        $filas = [];
        foreach ($grupo['filas'] as $f) {
            $estado = ctrib_inicio_estado($pdo, $empresa, $anio, $f);
            $fila = ['codigo' => $f['codigo'] ?? '', 'nombre' => $f['nombre'], 'tipo' => $f['tipo'] ?? 'Cálculos',
                'estado' => $estado['texto'], 'clase' => $estado['clase'], 'abrir' => $f['abrir'] ?? null,
                'abrir14' => $f['abrir14'] ?? null, 'etapa' => $f['etapa'] ?? null, 'ayuda' => $f['ayuda'] ?? '',
                'chips' => $f['chips'] ?? []];
            $filas[] = $fila;
            if ($siguiente === null && $estado['clase'] === 'pend' && $f['fuente'] !== 'pendiente') {
                $siguiente = ['nombre' => $fila['nombre'], 'ayuda' => $fila['ayuda'], 'motivo' => null];
            }
        }
        $salida['grupos'][] = ['slot' => $grupo['slot'], 'titulo' => $grupo['titulo'], 'filas' => $filas];
    }
    $salida['siguiente'] = $siguiente;
    if (!empty($def['cierre']) && !$previa) {
        $c = ctrib_cierre($pdo, $empresa, $anio);
        $salida['cierre'] = ['texto' => ctrib_cierre_texto($c), 'estado' => $c['cerrado'] ? 'CERRADO' : 'PENDIENTE',
            'clase' => $c['cerrado'] ? 'ok' : 'pend', 'cerrado' => (bool) $c['cerrado']];
    }
    return $salida;
}

/** Texto del cierre anual, igual que el de la pantalla: qué falta o cuándo se cerró. */
function ctrib_cierre_texto(array $c): string
{
    if ($c['cerrado']) {
        return 'Año cerrado' . ($c['cerradoEn'] ? ' el ' . substr((string) $c['cerradoEn'], 0, 10) : '') . '.';
    }
    if ($c['completo']) {
        return 'Todos los recuadros están finalizados: puede cerrar el año.';
    }
    $nombres = [];
    foreach ($c['recuadros'] as $x) {
        $nombres[$x['codigo']] = $x['codigo'] . ' — ' . $x['nombre'];
    }
    return 'Faltan recuadros por finalizar o actualizar: ' . implode(', ', array_map(fn($p) => $nombres[$p] ?? $p, $c['pendientes'])) . '.';
}

/**
 * Cambia el régimen de la empresa (queda en el historial). Persona natural va con su tipo; los demás regímenes
 * conservan el tipo de contribuyente que ya tienen, o usan "Otro" si no tienen ninguno.
 */
function ctrib_regimen_config_cambiar(PDO $pdo, int $empresa, string $regimen, string $usuario): void
{
    $actual = ctrib_empresa_config($pdo, $empresa);
    $tipo = $regimen === 'PERSONA' ? 'PERSONA_NATURAL'
        : (in_array($actual['tipo'], ['', 'PERSONA_NATURAL'], true) ? 'OTRO' : $actual['tipo']);
    ctrib_empresa_config_guardar($pdo, $empresa, $tipo, $regimen, $usuario);
}
