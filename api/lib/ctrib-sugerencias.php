<?php
// Sugerencias para la homologación del Recuadro 17 desde el plan de cuentas IFRS tributario del SII, y la pantalla de
// consulta de ese plan. La equivalencia plan SII → código R17 y las reglas por nombre están en ctrib/sugerencias-homologacion.json.
// Solo sugiere: cada asignación la confirma el usuario.
declare(strict_types=1);

require_once __DIR__ . '/ctrib.php';

const CTRIB_PALABRAS_VACIAS = ['de', 'del', 'la', 'las', 'los', 'el', 'y', 'e', 'en', 'por', 'con', 'para', 'a', 'al',
    'otros', 'otras', 'gastos', 'gasto', 'ingresos', 'ingreso', 'resultado', 'cuenta', 'cuentas'];

function ctrib_sugerencias_datos(): array
{
    static $datos = null;
    if ($datos === null) {
        $datos = json_decode((string) file_get_contents(__DIR__ . '/ctrib/sugerencias-homologacion.json'), true, 512, JSON_THROW_ON_ERROR);
    }
    return $datos;
}

/** Plan de cuentas IFRS tributario del SII (409 líneas, igual al de iTributario), indexado por código. */
function ctrib_plan_sii_lineas(): array
{
    static $lineas = null;
    if ($lineas === null) {
        $lineas = [];
        foreach (json_decode((string) file_get_contents(__DIR__ . '/ctrib/clasificador-ifrs.json'), true, 512, JSON_THROW_ON_ERROR) as $l) {
            $lineas[(string) $l['codigo']] = $l;
        }
    }
    return $lineas;
}

/** Minúsculas, sin tildes ni signos, para comparar nombres de cuentas. */
function ctrib_normalizar_nombre(string $texto): string
{
    $texto = strtr(mb_strtolower($texto, 'UTF-8'), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $texto));
}

/** Código del R17 de una línea del plan SII para el tipo de cuenta, solo si es compatible (ingreso con INGRESOS, gasto con EGRESOS). */
function ctrib_codigo_equivalente(string $planSii, string $tipo): ?string
{
    $eq = ctrib_sugerencias_datos()['equivalencias'][$planSii] ?? null;
    $codigo = is_array($eq) ? ($eq[$tipo] ?? null) : $eq;
    return $codigo !== null && ctrib_compatible($tipo, (string) $codigo) === null ? (string) $codigo : null;
}

/**
 * Sugerencia para una cuenta de resultado: por regla del nombre (confianza alta), por parecido con las líneas RESULTADO
 * del plan SII (media) o por el tipo de cuenta (baja). El código sugerido siempre es compatible con el tipo, o null cuando
 * Pro Pyme no lo considera (por ejemplo, la depreciación) y lo decide el usuario.
 */
function ctrib_sugerencia(string $nombre, string $tipo): array
{
    $plan = ctrib_plan_sii_lineas();
    $normal = ctrib_normalizar_nombre($nombre);
    $armar = function (string $planSii, ?string $codigo, string $confianza, string $motivo) use ($plan): array {
        return [
            'planSii' => ['codigo' => $planSii, 'descripcion' => $plan[$planSii]['descripcion'] ?? ''],
            'codigo' => $codigo, 'glosa' => $codigo !== null ? (string) (ctrib_catalogo_r17()[$codigo]['glosa'] ?? '') : null,
            'confianza' => $confianza, 'motivo' => $motivo,
        ];
    };
    foreach (ctrib_sugerencias_datos()['reglas'] as $regla) {
        if (($regla['tipo'] ?? null) !== null && $regla['tipo'] !== $tipo) {
            continue;
        }
        if (!preg_match('~' . $regla['patron'] . '~u', $normal)) {
            continue;
        }
        $codigo = array_key_exists('codigo', $regla) ? $regla['codigo'] : ctrib_codigo_equivalente($regla['planSii'], $tipo);
        if ($codigo !== null && ctrib_compatible($tipo, (string) $codigo) !== null) {
            $codigo = null;
        }
        return $armar($regla['planSii'], $codigo === null ? null : (string) $codigo, 'alta', $regla['motivo']);
    }
    $raices = fn(string $texto) => array_unique(array_map(fn($p) => substr($p, 0, 6),
        array_filter(explode(' ', $texto), fn($p) => strlen($p) >= 4 && !in_array($p, CTRIB_PALABRAS_VACIAS, true))));
    $propias = $raices($normal);
    $mejor = null;
    $puntaje = 0;
    foreach ($plan as $codigoPlan => $l) {
        if ($l['grupo'] !== 'RESULTADO' || $l['esEncabezado'] || ctrib_codigo_equivalente((string) $codigoPlan, $tipo) === null) {
            continue;
        }
        $comunes = count(array_intersect($propias, $raices(ctrib_normalizar_nombre($l['descripcion']))));
        if ($comunes > $puntaje) {
            $puntaje = $comunes;
            $mejor = (string) $codigoPlan;
        }
    }
    if ($mejor !== null) {
        return $armar($mejor, ctrib_codigo_equivalente($mejor, $tipo), 'media', 'parecido con «' . $plan[$mejor]['descripcion'] . '» del plan SII');
    }
    return $tipo === 'GANANCIA'
        ? $armar('3.01.01.00', '1400', 'baja', 'ingreso sin regla: ingresos del giro')
        : $armar('3.01.03.00', '1424', 'baja', 'gasto sin regla: otros gastos deducibles');
}

/** Traduce una sugerencia del R17 al código equivalente del R12 para empresas del régimen 14 A. */
function ctrib_sugerencia_para_14a(array $s, string $nombre, string $tipo): array
{
    $mapa = [
        '1400' => '1657', '1401' => '1658', '1402' => '1659', '1403' => '1660', '1587' => '1657', '1588' => '1660',
        '1409' => '1661', '1410' => '1661', '1411' => '1662', '1412' => '1671', '1413' => '1671', '1414' => '1671',
        '1415' => '1140', '1416' => '1664', '1417' => '1671', '1418' => '1666', '1419' => '1671', '1420' => '1671',
        '1421' => '1671', '1422' => '1671', '1423' => '1671', '1424' => '1671',
    ];
    $normal = ctrib_normalizar_nombre($nombre);
    $cod = $s['codigo'] !== null ? ($mapa[$s['codigo']] ?? null) : null;
    if ($tipo === 'PERDIDA') {
        if (preg_match('/depreciac/u', $normal)) $cod = '1663';
        elseif (preg_match('/donacion/u', $normal)) $cod = '1665';
        elseif (preg_match('/impuesto.*renta|impuesto diferido/u', $normal)) $cod = '1670';
        elseif ($cod === null) $cod = '1671';
    } elseif ($tipo === 'GANANCIA' && $cod === null) {
        $cod = '1660';
    }
    $r12 = ctrib_catalogo_r12();
    return [
        'planSii' => $s['planSii'],
        'codigo' => $cod,
        'glosa' => $cod !== null ? (string) ($r12[$cod]['glosa'] ?? '') : null,
        'confianza' => $s['confianza'],
        'motivo' => $s['motivo'],
    ];
}

/** Se asigna en bloque solo con confianza alta o media: la baja es un relleno genérico que se revisa una a una. */
function ctrib_sugerencia_masiva(array $cuenta): bool
{
    return $cuenta['codigo'] === null && $cuenta['sugerencia']['codigo'] !== null && $cuenta['sugerencia']['confianza'] !== 'baja';
}

/** Homologación del año con la sugerencia de cada cuenta de resultado. */
function ctrib_homologacion_con_sugerencias(PDO $pdo, int $empresa, int $anio): array
{
    $esR12 = in_array(ctrib_empresa_config($pdo, $empresa)['regimen'], ['14A', '14G'], true);
    $h = ctrib_homologacion($pdo, $empresa, $anio);
    $aplicables = 0;
    foreach ($h['cuentasResultado'] as $i => $c) {
        $s = ctrib_sugerencia((string) $c['nombre'], (string) $c['tipo']);
        if ($esR12) {
            $s = ctrib_sugerencia_para_14a($s, (string) $c['nombre'], (string) $c['tipo']);
        }
        $h['cuentasResultado'][$i]['sugerencia'] = $s;
        if (ctrib_sugerencia_masiva($h['cuentasResultado'][$i])) {
            $aplicables++;
        }
    }
    $h['sugerenciasAplicables'] = $aplicables;
    return $h;
}

/** Asigna las sugerencias de confianza alta o media a las cuentas sin código (nunca cambia una asignación hecha). Queda en el historial. */
function ctrib_homologacion_aplicar_sugerencias(PDO $pdo, int $empresa, int $anio, string $usuario): array
{
    $h = ctrib_homologacion_con_sugerencias($pdo, $empresa, $anio);
    $aplicadas = 0;
    $pdo->beginTransaction();
    try {
        foreach ($h['cuentasResultado'] as $c) {
            if (ctrib_sugerencia_masiva($c)) {
                ctrib_homologar($pdo, $empresa, $anio, (string) $c['cuenta'], $c['sugerencia']['codigo'], $usuario, 'Sugerencia aplicada (plan SII)');
                $aplicadas++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['aplicadas' => $aplicadas] + ctrib_homologacion_con_sugerencias($pdo, $empresa, $anio);
}

/** Plan de cuentas del SII para consultar: nivel según los segmentos del código y, en RESULTADO, el código del R17 equivalente. */
function ctrib_plan_sii(): array
{
    $cuentas = [];
    $grupos = [];
    foreach (ctrib_plan_sii_lineas() as $codigo => $l) {
        $codigo = (string) $codigo;
        $nivel = 1 + count(array_filter(array_slice(explode('.', $codigo), 1), fn($s) => $s !== '00'));
        $r17 = null;
        if ($l['grupo'] === 'RESULTADO' && !$l['esEncabezado']) {
            $eq = ctrib_sugerencias_datos()['equivalencias'][$codigo] ?? null;
            if (is_array($eq)) {
                $partes = [];
                foreach (['GANANCIA' => 'ingreso', 'PERDIDA' => 'gasto'] as $tipo => $texto) {
                    if (($eq[$tipo] ?? null) !== null) {
                        $partes[] = $eq[$tipo] . " ($texto)";
                    }
                }
                $r17 = $partes ? implode(' / ', $partes) : null;
            } else {
                $r17 = $eq;
            }
        }
        $cuentas[] = ['codigo' => $codigo, 'descripcion' => $l['descripcion'], 'grupo' => $l['grupo'], 'nivel' => $nivel,
            'esEncabezado' => (bool) $l['esEncabezado'], 'excepcionTipoCapital' => (bool) $l['excepcionTipoCapital'], 'codigoR17' => $r17];
        $grupos[$l['grupo']] = ($grupos[$l['grupo']] ?? 0) + 1;
    }
    return ['cuentas' => $cuentas, 'grupos' => $grupos];
}
