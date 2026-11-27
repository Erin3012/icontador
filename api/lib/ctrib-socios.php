<?php
// Consolidación de participaciones de socios y accionistas por RUT (Etapa 8):
// trae automáticamente retiros/dividendos y créditos desde DJ 1948 / Cert. 70 (14 D N°3 y 14 A),
// base imponible, créditos y PPM desde DJ 1947 / Cert. 69 (14 D N°8) y rentas presuntas desde DJ 1943 / Cert. 58 (Art. 34).
declare(strict_types=1);

require_once __DIR__ . '/ctrib-r20.php';
require_once __DIR__ . '/ctrib-r15-r16.php';
require_once __DIR__ . '/ctrib-r22.php';
require_once __DIR__ . '/ctrib-presunta.php';

/** Lee razón social y RUT de una empresa/persona sin depender de ctrib-pdf.php. */
function ctrib_socio_ficha_empresa(PDO $pdo, int $empresa): array
{
    try {
        $st = $pdo->prepare('SELECT razon_social, datos_json FROM empresas WHERE id = ?');
        $st->execute([$empresa]);
        $f = $st->fetch();
    } catch (Throwable) {
        return ['razon_social' => '', 'rut' => ''];
    }
    if (!$f) {
        return ['razon_social' => '', 'rut' => ''];
    }
    $datos = json_decode((string) ($f['datos_json'] ?? '{}'), true) ?: [];
    return [
        'razon_social' => (string) ($f['razon_social'] ?? ''),
        'rut' => (string) ($datos['rut'] ?? $datos['rut_empresa'] ?? ''),
    ];
}

/**
 * Extrae las rentas, créditos y PPM asignables desde una empresa (sea como socio externo por RUT o como titular 100% de una EI).
 */
function ctrib_socio_aportes_empresa(PDO $pdo, int $empId, int $anio, string $reg, string $tipo, bool $propioEI, ?string $rutObjetivo = null, ?array $l57Cache = null): ?array
{
    if (!in_array($reg, ['14D3', '14A', '14D8', 'PRESUNTA'], true)) {
        return null;
    }
    $fEmp = ctrib_socio_ficha_empresa($pdo, $empId);
    $esDividendo = !$propioEI && in_array($tipo, ['SA', 'SPA'], true);
    $aviso = null;

    if ($reg === '14D3') {
        $dj = ctrib_dj1948($pdo, $empId, $anio);
        if ($propioEI) {
            $retSocio = (int) ($dj['total'] ?? 0);
            $cert = null;
        } else {
            $socio = null;
            foreach ($dj['socios'] as $s) {
                if (ctrib_rut((string) $s['rut']) === $rutObjetivo) {
                    $socio = $s;
                    break;
                }
            }
            if (!$socio || (int) $socio['total'] <= 0) {
                return null;
            }
            $retSocio = (int) $socio['total'];
            $cert = $socio['certificado'];
        }
        $r20 = ctrib_r20($pdo, $empId, $anio);
        $baseReparto = max((int) ($r20['retirosEjercicio'] ?? 0), (int) ($dj['total'] ?? 0), $retSocio);
        $prop = $baseReparto > 0 ? $retSocio / $baseReparto : 0.0;
        $raiImputado = (int) ($r20['retirosImputadosRai'] ?? 0);
        $totalImputado = $raiImputado + (int) ($r20['retirosImputadosRex'] ?? 0) + (int) ($r20['retirosNoImputados'] ?? 0);
        $montoAfecto = $totalImputado > 0 ? (int) round($raiImputado * $prop, 0, PHP_ROUND_HALF_UP) : $retSocio;
        $cred610 = (int) round((int) ($r20['formulaSac']['creditoRetiros'] ?? 0) * $prop, 0, PHP_ROUND_HALF_UP);
        $estadoOrigen = (string) ($r20['estado'] ?? 'BORRADOR');
        if ($estadoOrigen !== 'FINALIZADO') {
            $aviso = ($propioEI ? 'El RTRE (R20) propio' : "{$fEmp['razon_social']} (14 D N°3) tiene su RTRE (R20)") . ' en borrador: los créditos podrían variar.';
        }
        return [
            'empresaId' => $empId, 'rutEmpresa' => (string) $fEmp['rut'], 'razonSocial' => (string) $fEmp['razon_social'],
            'regimen' => '14D3', 'fuente' => $propioEI ? 'Negocio propio (R20 / Línea 1 a)' : 'DJ 1948 / Cert. 70',
            'certificado' => $cert, 'estadoOrigen' => $estadoOrigen,
            'retiro104' => $esDividendo ? 0 : $montoAfecto, 'dividendo105' => $esDividendo ? $montoAfecto : 0,
            'rentaPresunta108' => 0, 'baseAtribuida1632' => 0,
            'incremento159' => $cred610, 'debito1035' => 0,
            'creditoSinDev612' => 0, 'credito33bis1638' => 0, 'creditoConDev610' => $cred610, 'ppm1645' => 0,
            'aviso' => $aviso,
        ];
    }

    if ($reg === '14A') {
        $dj = ctrib_dj1948($pdo, $empId, $anio);
        if ($propioEI) {
            $retSocio = (int) ($dj['total'] ?? 0);
            $cert = null;
        } else {
            $socio = null;
            foreach ($dj['socios'] as $s) {
                if (ctrib_rut((string) $s['rut']) === $rutObjetivo) {
                    $socio = $s;
                    break;
                }
            }
            if (!$socio || (int) $socio['total'] <= 0) {
                return null;
            }
            $retSocio = (int) $socio['total'];
            $cert = $socio['certificado'];
        }
        $r15 = ctrib_r15($pdo, $empId, $anio);
        $cel = $r15['celdas']['6.1'] ?? [];
        $totalRet = max((int) ($dj['total'] ?? 0), $retSocio);
        $prop = $totalRet > 0 ? $retSocio / $totalRet : 0.0;

        $raiDdan = abs((int) ($cel['RAI']['monto'] ?? 0)) + abs((int) ($cel['DDAN']['monto'] ?? 0));
        $rexImp = 0;
        foreach (CTRIB_14A_REX_ORDEN as $colRex) {
            $rexImp += abs((int) ($cel[$colRex]['monto'] ?? 0));
        }
        $montoAfecto = ($raiDdan + $rexImp) > 0
            ? (int) round($raiDdan * $prop, 0, PHP_ROUND_HALF_UP)
            : $retSocio;

        $sinRestSinDev = (int) round((abs((int) ($cel['SAC_NO_REST_SIN_DEV']['monto'] ?? 0)) + abs((int) ($cel['SAC_2016_SIN_DEV']['monto'] ?? 0))) * $prop, 0, PHP_ROUND_HALF_UP);
        $sinRestConDev = (int) round((abs((int) ($cel['SAC_NO_REST_CON_DEV']['monto'] ?? 0)) + abs((int) ($cel['SAC_2016_CON_DEV']['monto'] ?? 0))) * $prop, 0, PHP_ROUND_HALF_UP);
        $conRestSinDev = (int) round(abs((int) ($cel['SAC_REST_SIN_DEV']['monto'] ?? 0)) * $prop, 0, PHP_ROUND_HALF_UP);
        $conRestConDev = (int) round(abs((int) ($cel['SAC_REST_CON_DEV']['monto'] ?? 0)) * $prop, 0, PHP_ROUND_HALF_UP);

        $cred612 = $sinRestSinDev + $conRestSinDev;
        $cred610 = $sinRestConDev + $conRestConDev;
        $inc159 = $cred612 + $cred610;
        $deb1035 = (int) round(($conRestSinDev + $conRestConDev) * 0.35, 0, PHP_ROUND_HALF_UP);
        $estadoOrigen = (string) ($r15['estado'] ?? 'BORRADOR');
        if ($estadoOrigen !== 'FINALIZADO') {
            $aviso = ($propioEI ? 'El RTRE (R15) propio' : "{$fEmp['razon_social']} (14 A) tiene su RTRE (R15)") . ' en borrador: los créditos podrían variar.';
        }
        return [
            'empresaId' => $empId, 'rutEmpresa' => (string) $fEmp['rut'], 'razonSocial' => (string) $fEmp['razon_social'],
            'regimen' => '14A', 'fuente' => $propioEI ? 'Negocio propio (R15 / Línea 1 a)' : 'DJ 1948 / Cert. 70',
            'certificado' => $cert, 'estadoOrigen' => $estadoOrigen,
            'retiro104' => $esDividendo ? 0 : $montoAfecto, 'dividendo105' => $esDividendo ? $montoAfecto : 0,
            'rentaPresunta108' => 0, 'baseAtribuida1632' => 0,
            'incremento159' => $inc159, 'debito1035' => $deb1035,
            'creditoSinDev612' => $cred612, 'credito33bis1638' => 0, 'creditoConDev610' => $cred610, 'ppm1645' => 0,
            'aviso' => $aviso,
        ];
    }

    if ($reg === '14D8') {
        $r22 = ctrib_r22($pdo, $empId, $anio);
        $m22 = array_column($r22['lineas'], 'monto', 'codigo');
        $ppmEmp = ctrib_valores_manuales($pdo, $empId, $anio, 'F22')['1904'] ?? 0;
        if ($propioEI) {
            $base1632 = max(0, (int) ($m22['1630'] ?? 0));
            $cred33bis = (int) ($m22['1609'] ?? 0);
            $cred610 = (int) ($m22['1605'] ?? 0);
            $ppmSocio = (int) $ppmEmp;
        } else {
            $propFilas = $r22['propietarios']['filas'] ?? [];
            $idx = null;
            foreach ($propFilas as $i => $pf) {
                if (ctrib_rut((string) ($pf['rut'] ?? '')) === $rutObjetivo) {
                    $idx = $i;
                    break;
                }
            }
            if ($idx === null) {
                return null;
            }
            $pf = $propFilas[$idx];
            $base1632 = (int) ($pf['baseImponible'] ?? 0);
            $cred33bis = (int) ($pf['credito33bis'] ?? 0);
            $cred610 = (int) ($pf['creditoIdpc'] ?? 0);
            $ppmSocio = 0;
            if ($ppmEmp > 0) {
                $repPpm = ctrib_repartir((int) $ppmEmp, array_column($propFilas, 'participacion'));
                $ppmSocio = (int) ($repPpm[$idx] ?? 0);
            }
        }
        $estadoOrigen = (string) ($r22['estado'] ?? 'BORRADOR');
        if ($estadoOrigen !== 'FINALIZADO') {
            $aviso = ($propioEI ? 'El R22 propio' : "{$fEmp['razon_social']} (14 D N°8) tiene su R22") . ' en borrador: la base atribuida y créditos podrían variar.';
        }
        return [
            'empresaId' => $empId, 'rutEmpresa' => (string) $fEmp['rut'], 'razonSocial' => (string) $fEmp['razon_social'],
            'regimen' => '14D8', 'fuente' => $propioEI ? 'Negocio propio (R22 / Línea 6 a)' : 'DJ 1947 / Cert. 69',
            'certificado' => null, 'estadoOrigen' => $estadoOrigen,
            'retiro104' => 0, 'dividendo105' => 0, 'rentaPresunta108' => 0, 'baseAtribuida1632' => $base1632,
            'incremento159' => 0, 'debito1035' => 0,
            'creditoSinDev612' => 0, 'credito33bis1638' => $cred33bis, 'creditoConDev610' => $cred610, 'ppm1645' => $ppmSocio,
            'aviso' => $aviso,
        ];
    }

    // PRESUNTA
    $l57 = $l57Cache ?? ctrib_l57($pdo, $empId, $anio);
    if ($propioEI) {
        $renta108 = (int) ($l57['totales']['187'] ?? 0);
        $cred610 = (int) ($l57['totales']['189'] ?? 0);
    } else {
        $pf = null;
        foreach ($l57['propietarios']['filas'] ?? [] as $row) {
            if (ctrib_rut((string) ($row['rut'] ?? '')) === $rutObjetivo) {
                $pf = $row;
                break;
            }
        }
        if (!$pf) {
            return null;
        }
        $renta108 = (int) ($pf['rentaPresunta'] ?? 0);
        $cred610 = (int) ($pf['creditoIdpc'] ?? 0);
    }
    $estadoOrigen = (string) ($l57['estado'] ?? 'BORRADOR');
    if ($estadoOrigen !== 'FINALIZADO') {
        $aviso = ($propioEI ? 'La L57 propia' : "{$fEmp['razon_social']} (Renta Presunta) tiene su L57") . ' en borrador: la renta asignada podría variar.';
    }
    return [
        'empresaId' => $empId, 'rutEmpresa' => (string) $fEmp['rut'], 'razonSocial' => (string) $fEmp['razon_social'],
        'regimen' => 'PRESUNTA', 'fuente' => $propioEI ? 'Negocio propio (L57 / Línea 4 a)' : 'DJ 1943 / Cert. 58',
        'certificado' => null, 'estadoOrigen' => $estadoOrigen,
        'retiro104' => 0, 'dividendo105' => 0, 'rentaPresunta108' => $renta108, 'baseAtribuida1632' => 0,
        'incremento159' => 0, 'debito1035' => 0,
        'creditoSinDev612' => 0, 'credito33bis1638' => 0, 'creditoConDev610' => $cred610, 'ppm1645' => 0,
        'aviso' => $aviso,
    ];
}

/** Aportes del negocio propio de un Empresario Individual (EI) para unirlos con sus líneas personales del F22. */
function ctrib_ei_negocio(PDO $pdo, int $empresa, int $anio, string $regimen, ?array $l57Cache = null): ?array
{
    $fila = ctrib_socio_aportes_empresa($pdo, $empresa, $anio, $regimen, 'EI', true, null, $l57Cache);
    if ($fila === null) {
        return null;
    }
    $aviso = $fila['aviso'];
    unset($fila['aviso']);
    return ['fila' => $fila, 'aviso' => $aviso];
}

/**
 * Busca todas las empresas donde participa el RUT del contribuyente en el año tributario
 * y consolida las líneas personales del F22 (104, 105, 108, 1632, 159, 1035, 612, 1638, 610, 1645).
 * Si $empresasPermitidas es un array de IDs, limita la búsqueda a esas empresas (aislamiento por usuario).
 */
function ctrib_socio_participaciones(PDO $pdo, int $empresa, int $anio, ?array $empresasPermitidas = null): array
{
    $totales = [
        '104' => 0, '105' => 0, '108' => 0, '1632' => 0, '159' => 0,
        '1035' => 0, '612' => 0, '1638' => 0, '610' => 0, '1645' => 0,
    ];
    $ficha = ctrib_socio_ficha_empresa($pdo, $empresa);
    $rutObjetivo = ctrib_rut((string) ($ficha['rut'] ?? ''));
    if ($rutObjetivo === '') {
        return [
            'rut' => '',
            'filas' => [],
            'totales' => $totales,
            'avisos' => ['Ingrese el RUT del contribuyente en la ficha de la empresa/persona para consolidar automáticamente sus participaciones en sociedades.'],
        ];
    }

    // Recolecta IDs candidatos desde ctrib_socios y ctrib_retiros del año (normalizando RUT en PHP).
    $candidatas = [];
    foreach (['ctrib_socios', 'ctrib_retiros'] as $tabla) {
        $st = $pdo->prepare("SELECT DISTINCT empresa_id, rut FROM $tabla WHERE anio = ? AND empresa_id != ?");
        $st->execute([$anio, $empresa]);
        foreach ($st->fetchAll() as $row) {
            if (ctrib_rut((string) $row['rut']) === $rutObjetivo) {
                $id = (int) $row['empresa_id'];
                if ($empresasPermitidas === null || in_array($id, $empresasPermitidas, true)) {
                    $candidatas[$id] = true;
                }
            }
        }
    }

    $filas = [];
    $avisos = [];
    foreach (array_keys($candidatas) as $empId) {
        $cfg = ctrib_empresa_config($pdo, $empId);
        $fila = ctrib_socio_aportes_empresa($pdo, $empId, $anio, $cfg['regimen'], $cfg['tipo'], false, $rutObjetivo);
        if ($fila === null) {
            continue;
        }
        if ($fila['aviso'] !== null) {
            $avisos[] = $fila['aviso'];
        }
        unset($fila['aviso']);
        $filas[] = $fila;
        $totales['104']  += $fila['retiro104'];
        $totales['105']  += $fila['dividendo105'];
        $totales['108']  += $fila['rentaPresunta108'];
        $totales['1632'] += $fila['baseAtribuida1632'];
        $totales['159']  += $fila['incremento159'];
        $totales['1035'] += $fila['debito1035'];
        $totales['612']  += $fila['creditoSinDev612'];
        $totales['1638'] += $fila['credito33bis1638'];
        $totales['610']  += $fila['creditoConDev610'];
        $totales['1645'] += $fila['ppm1645'];
    }

    return [
        'rut' => $rutObjetivo,
        'filas' => $filas,
        'totales' => $totales,
        'avisos' => $avisos,
    ];
}
