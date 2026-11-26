<?php
/* API de Cifrax Tributario (balance, mapeo de dígitos y homologación al Recuadro 17). Acciones por ?accion=.
   GET  api/cifrax-tributario.php?accion=resumen&anio=2026
   GET  ?accion=mapeo                                  -> mapeo guardado y propuesta inicial
   POST ?accion=mapeo_guardar      {mapeo: {"1": "ACTIVO", ...}}
   GET  ?accion=balance&anio=2026                      -> balance guardado (8 columnas) o null
   POST ?accion=balance_desde_vouchers {anio}          -> guarda el balance con los vouchers Tributario del año comercial
   POST ?accion=balance_importar (multipart: archivo, anio) -> guarda el balance de un Excel o CSV de respaldo
   GET  ?accion=homologacion&anio=2026                 -> códigos del R17 con sus cuentas, cuentas de resultado y alertas
   POST ?accion=homologacion_asignar {anio, cuenta, codigo, motivo?} (codigo vacío = quitar)
   GET  ?accion=historial&anio=2026&entidad=homologacion */
declare(strict_types=1);
require_once __DIR__ . '/lib/sesion.php';
require __DIR__ . '/lib/ctrib-cierre.php';
require_once __DIR__ . '/lib/ctrib-cpt.php';
require_once __DIR__ . '/lib/ctrib-pdf.php';

ejecutar(function (PDO $pdo, int $empresa) {
    ctrib_schema($pdo);
    $usuario = (string) (auth_actual()['email'] ?? '');
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $accion = (string) ($_GET['accion'] ?? '');
    try {
        if ($metodo === 'GET') {
            $anio = ctrib_anio_solicitado();
            return match ($accion) {
                'resumen' => ctrib_resumen($pdo, $empresa, $anio),
                'mapeo' => ['mapeo' => ctrib_mapeo($pdo, $empresa), 'propuesto' => CTRIB_MAPEO_PROPUESTO, 'tipos' => CTRIB_TIPOS_CUENTA],
                'balance' => ctrib_balance_guardado($pdo, $empresa, $anio),
                'homologacion' => ctrib_homologacion($pdo, $empresa, $anio),
                'historial' => ctrib_historial_listar($pdo, $empresa, $anio, (string) ($_GET['entidad'] ?? '')),
                'r17' => ctrib_r17($pdo, $empresa, $anio),
                'r19' => ctrib_r19($pdo, $empresa, $anio),
                'r20' => ctrib_r20_respuesta(ctrib_r20($pdo, $empresa, $anio)),
                'r21' => ctrib_r21($pdo, $empresa, $anio),
                'dj1948' => ctrib_dj1948($pdo, $empresa, $anio),
                'r4' => ctrib_r4($pdo, $empresa, $anio),
                'r6' => ctrib_r6($pdo, $empresa, $anio),
                'cierre' => ctrib_cierre($pdo, $empresa, $anio),
                'cpt' => ctrib_cpt($pdo, $empresa, $anio),
                'ajustes' => ['ajustes' => array_values(ctrib_r17_ajustes($pdo, $empresa, $anio)), 'cuentas' => ctrib_ajuste_cuentas_disponibles($pdo, $empresa, $anio)],
                'rli' => ctrib_rli($pdo, $empresa, $anio),
                'empresa' => ctrib_empresa_ficha($pdo, $empresa),
                'retiros' => ctrib_retiros_del_anio($pdo, $empresa, $anio) + ['socios' => ctrib_socios($pdo, $empresa, $anio)],
                default => responder(['errores' => ['Acción no válida.']], 404),
            };
        }
        if ($metodo === 'POST') {
            $enviado = $_POST ?: json_decode((string) file_get_contents('php://input'), true);
            ctrib_exigir_cambio_permitido($pdo, $empresa, $accion, is_array($enviado) ? $enviado : []);
        }
        if ($metodo === 'POST' && $accion === 'mapeo_guardar') {
            $datos = cuerpoJson();
            return ['mapeo' => ctrib_mapeo_guardar($pdo, $empresa, is_array($datos['mapeo'] ?? null) ? $datos['mapeo'] : [], $usuario)];
        }
        if ($metodo === 'POST' && $accion === 'balance_desde_vouchers') {
            $datos = cuerpoJson();
            $anio = ctrib_anio_desde($datos['anio'] ?? null);
            $cuentas = ctrib_cuentas_desde_vouchers($pdo, $empresa, $anio);
            return ctrib_balance_guardar($pdo, $empresa, $anio, 'vouchers', $cuentas, '', $usuario);
        }
        if ($metodo === 'POST' && $accion === 'balance_importar') {
            $anio = ctrib_anio_desde($_POST['anio'] ?? null);
            $archivo = $_FILES['archivo'] ?? null;
            if (!$archivo || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new CtribError('Seleccione el archivo del balance (Excel .xlsx o CSV).');
            }
            $nombre = (string) $archivo['name'];
            $extension = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
            if (!in_array($extension, ['xlsx', 'csv'], true)) {
                throw new CtribError('El archivo debe ser .xlsx o .csv.');
            }
            $cuentas = ctrib_cuentas_desde_archivo($archivo['tmp_name'], $extension);
            return ctrib_balance_guardar($pdo, $empresa, $anio, $extension === 'csv' ? 'csv' : 'excel', $cuentas, $nombre, $usuario);
        }
        if ($metodo === 'POST' && $accion === 'homologacion_asignar') {
            $datos = cuerpoJson();
            $anio = ctrib_anio_desde($datos['anio'] ?? null);
            return ctrib_homologar($pdo, $empresa, $anio, trim((string) ($datos['cuenta'] ?? '')), (string) ($datos['codigo'] ?? ''), $usuario, mb_substr(trim((string) ($datos['motivo'] ?? '')), 0, 255));
        }
        if ($metodo === 'POST' && $accion === 'r17_guardar_manual') {
            $datos = cuerpoJson();
            $anio = ctrib_anio_desde($datos['anio'] ?? null);
            return ctrib_r17_guardar_manual($pdo, $empresa, $anio, (string) ($datos['codigo'] ?? ''), $datos['monto'] ?? null, $usuario);
        }
        if ($metodo === 'POST' && $accion === 'r17_finalizar') {
            $datos = cuerpoJson();
            return ctrib_r17_finalizar($pdo, $empresa, ctrib_anio_desde($datos['anio'] ?? null), $usuario);
        }
        if ($metodo === 'POST' && $accion === 'retiros_importar') {
            $anio = ctrib_anio_desde($_POST['anio'] ?? null);
            $archivo = $_FILES['archivo'] ?? null;
            if (!$archivo || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new CtribError('Seleccione el archivo de retiros (Excel .xlsx o CSV).');
            }
            $nombre = (string) $archivo['name'];
            $extension = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
            if (!in_array($extension, ['xlsx', 'csv'], true)) {
                throw new CtribError('El archivo debe ser .xlsx o .csv.');
            }
            $retiros = ctrib_retiros_desde_archivo($archivo['tmp_name'], $extension);
            return ctrib_retiros_importar($pdo, $empresa, $anio, $retiros, $nombre, $usuario);
        }
        if ($metodo === 'POST' && $accion === 'r20_guardar_celda') {
            $datos = cuerpoJson();
            $anio = ctrib_anio_desde($datos['anio'] ?? null);
            return ctrib_r20_respuesta(ctrib_r20_guardar_celda($pdo, $empresa, $anio, (string) ($datos['fila'] ?? ''), (string) ($datos['columna'] ?? ''), $datos['monto'] ?? null, $usuario));
        }
        if ($metodo === 'POST' && $accion === 'r20_finalizar') {
            $datos = cuerpoJson();
            return ctrib_r20_respuesta(ctrib_r20_finalizar($pdo, $empresa, ctrib_anio_desde($datos['anio'] ?? null), $usuario));
        }
        if ($metodo === 'POST' && $accion === 'r20_reabrir') {
            $datos = cuerpoJson();
            return ctrib_r20_respuesta(ctrib_r20_reabrir($pdo, $empresa, ctrib_anio_desde($datos['anio'] ?? null), $usuario, mb_substr(trim((string) ($datos['motivo'] ?? '')), 0, 255)));
        }
        if ($metodo === 'POST' && in_array($accion, ['r4_guardar_valor', 'r6_guardar_valor'], true)) {
            $datos = cuerpoJson();
            $anio = ctrib_anio_desde($datos['anio'] ?? null);
            $codigo = (string) ($datos['codigo'] ?? '');
            $monto = $datos['monto'] ?? null;
            return $accion === 'r4_guardar_valor'
                ? ctrib_r4_guardar_valor($pdo, $empresa, $anio, $codigo, $monto, $usuario)
                : ctrib_r6_guardar_valor($pdo, $empresa, $anio, $codigo, $monto, $usuario);
        }
        if ($metodo === 'POST' && in_array($accion, ['r4_finalizar', 'r6_finalizar', 'r4_reabrir', 'r6_reabrir'], true)) {
            $datos = cuerpoJson();
            $anio = ctrib_anio_desde($datos['anio'] ?? null);
            $motivo = mb_substr(trim((string) ($datos['motivo'] ?? '')), 0, 255);
            $recuadro = substr($accion, 0, 2) === 'r4' ? 'r4' : 'r6';
            if (str_ends_with($accion, '_finalizar')) {
                return $recuadro === 'r4' ? ctrib_r4_finalizar($pdo, $empresa, $anio, $usuario) : ctrib_r6_finalizar($pdo, $empresa, $anio, $usuario);
            }
            return $recuadro === 'r4' ? ctrib_r4_reabrir($pdo, $empresa, $anio, $usuario, $motivo) : ctrib_r6_reabrir($pdo, $empresa, $anio, $usuario, $motivo);
        }
        if ($metodo === 'POST' && $accion === 'cierre_sellar') {
            $datos = cuerpoJson();
            return ctrib_cierre_sellar($pdo, $empresa, ctrib_anio_desde($datos['anio'] ?? null), $usuario);
        }
        if ($metodo === 'POST' && $accion === 'cierre_reabrir') {
            $datos = cuerpoJson();
            return ctrib_cierre_reabrir($pdo, $empresa, ctrib_anio_desde($datos['anio'] ?? null), $usuario, mb_substr(trim((string) ($datos['motivo'] ?? '')), 0, 255));
        }
        if ($metodo === 'POST' && $accion === 'certificado_emitir') {
            $datos = cuerpoJson();
            return ctrib_certificado_emitir($pdo, $empresa, ctrib_anio_desde($datos['anio'] ?? null), (string) ($datos['rut'] ?? ''), $usuario);
        }
        if ($metodo === 'POST' && $accion === 'cpt_guardar') {
            $datos = cuerpoJson();
            return ctrib_cpt_guardar($pdo, $empresa, ctrib_anio_desde($datos['anio'] ?? null),
                is_array($datos['asignaciones'] ?? null) ? $datos['asignaciones'] : [], !empty($datos['maestro']), $usuario);
        }
        if ($metodo === 'POST' && $accion === 'r17_incentivo') {
            $datos = cuerpoJson();
            return ctrib_incentivo_guardar($pdo, $empresa, ctrib_anio_desde($datos['anio'] ?? null), !empty($datos['elegibilidad']),
                !empty($datos['limiteIngresos']), !empty($datos['limiteRentasPasivas']), $datos['monto'] ?? null, $usuario);
        }
        if ($metodo === 'POST' && $accion === 'composicion_guardar') {
            $datos = cuerpoJson();
            $anio = ctrib_anio_desde($datos['anio'] ?? null);
            ctrib_composicion_guardar($pdo, $empresa, $anio, (string) ($datos['tipo'] ?? ''), (string) ($datos['regimen'] ?? ''),
                is_array($datos['socios'] ?? null) ? $datos['socios'] : [], !empty($datos['sinRetiros']), $usuario);
            return ctrib_dj1948($pdo, $empresa, $anio);
        }
        if ($metodo === 'POST' && $accion === 'retiros_modo') {
            $datos = cuerpoJson();
            $anio = ctrib_anio_desde($datos['anio'] ?? null);
            ctrib_retiros_modo_guardar($pdo, $empresa, $anio, (string) ($datos['modo'] ?? ''), is_array($datos['socios'] ?? null) ? $datos['socios'] : [], $usuario);
            return ctrib_dj1948($pdo, $empresa, $anio);
        }
        if ($metodo === 'POST' && $accion === 'r19_guardar_manual') {
            $datos = cuerpoJson();
            $anio = ctrib_anio_desde($datos['anio'] ?? null);
            return ctrib_r19_guardar_valor($pdo, $empresa, $anio, (string) ($datos['codigo'] ?? ''), $datos['monto'] ?? null, $usuario);
        }
        if ($metodo === 'POST' && $accion === 'r19_finalizar') {
            $datos = cuerpoJson();
            return ctrib_r19_finalizar($pdo, $empresa, ctrib_anio_desde($datos['anio'] ?? null), $usuario);
        }
        if ($metodo === 'POST' && $accion === 'r19_reabrir') {
            $datos = cuerpoJson();
            return ctrib_r19_reabrir($pdo, $empresa, ctrib_anio_desde($datos['anio'] ?? null), $usuario, mb_substr(trim((string) ($datos['motivo'] ?? '')), 0, 255));
        }
        if ($metodo === 'POST' && $accion === 'r17_reabrir') {
            $datos = cuerpoJson();
            return ctrib_r17_reabrir($pdo, $empresa, ctrib_anio_desde($datos['anio'] ?? null), $usuario, mb_substr(trim((string) ($datos['motivo'] ?? '')), 0, 255));
        }
        if ($metodo === 'POST' && $accion === 'ajuste_guardar') {
            $datos = cuerpoJson();
            $anio = ctrib_anio_desde($datos['anio'] ?? null);
            ctrib_ajuste_guardar($pdo, $empresa, $anio, (string) ($datos['codigo'] ?? ''), is_array($datos['cuentas'] ?? null) ? $datos['cuentas'] : [],
                $datos['saldoInicial'] ?? null, $datos['saldoFinal'] ?? null, $usuario);
            return ctrib_r17($pdo, $empresa, $anio);
        }
        if ($metodo === 'POST' && $accion === 'rli_partida_agregar') {
            $datos = cuerpoJson();
            return ctrib_rli_partida_agregar($pdo, $empresa, ctrib_anio_desde($datos['anio'] ?? null), (string) ($datos['tipo'] ?? ''),
                (string) ($datos['glosa'] ?? ''), $datos['monto'] ?? null, $usuario);
        }
        if ($metodo === 'POST' && $accion === 'rli_partida_quitar') {
            $datos = cuerpoJson();
            return ctrib_rli_partida_quitar($pdo, $empresa, ctrib_anio_desde($datos['anio'] ?? null), (int) ($datos['id'] ?? 0), $usuario);
        }
        if ($metodo === 'POST' && $accion === 'rtre_parametro') {
            $datos = cuerpoJson();
            return ctrib_r20_respuesta(ctrib_r20_guardar_parametro($pdo, $empresa, ctrib_anio_desde($datos['anio'] ?? null),
                (string) ($datos['codigo'] ?? ''), $datos['monto'] ?? null, $usuario));
        }
        responder(['errores' => ['Acción no válida.']], 404);
    } catch (CtribError $e) {
        responder(['errores' => [$e->getMessage()]], 422);
    }
});

function ctrib_anio_desde(mixed $valor): int
{
    if (!is_numeric($valor) || (int) $valor < 2000 || (int) $valor > 2100) {
        throw new CtribError('Indique el año tributario (AAAA).');
    }
    return (int) $valor;
}

function ctrib_anio_solicitado(): int
{
    return ctrib_anio_desde($_GET['anio'] ?? null);
}

/** El RTRE completo tiene cientos de celdas: la lista 'todas' solo sirve para el snapshot y no se envía. */
function ctrib_r20_respuesta(array $r20): array
{
    unset($r20['todas']);
    return $r20;
}

function ctrib_resumen(PDO $pdo, int $empresa, int $anio): array
{
    $balance = ctrib_balance_guardado($pdo, $empresa, $anio);
    $homologacion = ctrib_homologacion($pdo, $empresa, $anio);
    return [
        'anio' => $anio,
        'anioComercial' => ctrib_anio_comercial($anio),
        'mapeo' => ctrib_mapeo($pdo, $empresa),
        'balance' => $balance ? array_diff_key($balance, ['cuentas' => true]) : null,
        'homologacion' => ['pendientes' => $homologacion['pendientes'], 'alertas' => $homologacion['alertas']],
    ];
}
