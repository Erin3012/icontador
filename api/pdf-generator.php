<?php
/**
 * Generador de PDF para Documentos de RR.HH.
 * Soporta: contrato, anexo, permiso, feriado, finiquito
 * Uso: /api/pdf-generator.php?tipo=contrato&id=123&action=html|pdf
 */

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/rrhh.php';

$tipo = $_GET['tipo'] ?? 'contrato';
$id = (int)($_GET['id'] ?? 0);
$action = $_GET['action'] ?? 'html';

$pdo = icontador_db();
if (!$pdo) die('No database');

// Generar documento según tipo
switch ($tipo) {
    case 'contrato':
        if ($id) {
            $contrato = contrato_obtener($id);
            echo generar_contrato_html($contrato);
        } else {
            echo '<p>ID de contrato requerido</p>';
        }
        break;
    case 'anexo':
        if ($id) {
            $anexo = anexo_obtener($id);
            echo generar_anexo_html($anexo);
        } else {
            echo '<p>ID de anexo requerido</p>';
        }
        break;
    case 'permiso':
        if ($id) {
            $permiso = permiso_obtener($id);
            echo generar_permiso_html($permiso);
        } else {
            echo '<p>ID de permiso requerido</p>';
        }
        break;
    case 'feriado':
        if ($id) {
            $feriado = feriado_obtener($id);
            echo generar_feriado_html($feriado);
        } else {
            echo '<p>ID de feriado requerido</p>';
        }
        break;
    case 'comprobante':
        if ($id) {
            $comprobante = comprobante_obtener($id);
            echo generar_comprobante_html($comprobante);
        } else {
            echo '<p>ID de comprobante requerido</p>';
        }
        break;
    case 'finiquito':
        if ($id) {
            $finiquito = finiquito_obtener($id);
            echo generar_finiquito_html($finiquito);
        } else {
            echo '<p>ID de finiquito requerido</p>';
        }
        break;
    default:
        echo '<p>Tipo de documento no válido</p>';
}

function generar_contrato_html($contrato) {
    if (!$contrato) return '<p>Contrato no encontrado</p>';

    $empresa_nombre = $contrato['empresa_nombre'] ?? 'NOMBRE EMPRESA';
    $empresa_rut = $contrato['empresa_rut'] ?? 'RUT EMPRESA';
    $empresa_email = $contrato['empresa_email'] ?? 'email@empresa.cl';
    $empresa_representante = $contrato['empresa_representante'] ?? 'REPRESENTANTE';
    $empresa_representante_rut = $contrato['empresa_representante_rut'] ?? 'RUT REPRESENTANTE';

    $emp_nombre = $contrato['empleado_nombre'] ?? '';
    $emp_rut = $contrato['empleado_rut'] ?? '';
    $emp_fecha_nac = $contrato['empleado_fecha_nac'] ?? '01/01/1990';
    $emp_nacionalidad = $contrato['empleado_nacionalidad'] ?? 'CHILENA';
    $emp_domicilio = $contrato['empleado_domicilio'] ?? '';
    $emp_telefono = $contrato['empleado_telefono'] ?? '';
    $emp_email = $contrato['empleado_email'] ?? '';

    $cargo = $contrato['cargo'] ?? '';
    $lugar_prestacion = $contrato['lugar_prestacion'] ?? '';
    $jornada_tipo = $contrato['jornada_tipo'] ?? 'completa';
    $jornada_horas = $contrato['jornada_horas'] ?? 44;
    $sueldo_base = number_format($contrato['sueldo_base'] ?? 0, 0, ',', '.');
    $fecha_inicio = $contrato['fecha_inicio'] ?? date('d/m/Y');
    $beneficios = $contrato['beneficios'] ?? '';
    $tipo_contrato = $contrato['tipo_contrato'] ?? 'indefinido';

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Contrato de Trabajo</title>
<style>
body { font-family: Arial, sans-serif; max-width: 900px; margin: 0 auto; padding: 20px; font-size: 12px; line-height: 1.5; }
h1 { text-align: center; font-size: 16px; margin: 20px 0; }
p { margin: 8px 0; text-align: justify; }
.clausula { margin: 15px 0; }
.clausula-titulo { font-weight: bold; margin-top: 12px; }
.firma-linea { border-top: 1px solid #000; display: inline-block; width: 200px; }
.firmas { display: flex; justify-content: space-around; margin-top: 40px; text-align: center; }
.firma-bloque { width: 40%; }
@media print { body { padding: 0; } }
</style>
</head>
<body>
<h1>CONTRATO DE TRABAJO</h1>

<p>En Santiago Centro el $fecha_inicio entre la Empresa $empresa_nombre, RUT $empresa_rut, correo electrónico $empresa_email, representada por don(a) $empresa_representante RUT $empresa_representante_rut y don(a) $emp_rut, con domicilio en $emp_domicilio, en adelante "el empleador" y don(a) $emp_nombre, de nacionalidad $emp_nacionalidad, cedula de identidad Nº $emp_rut, correo electrónico $emp_email, domiciliado en $emp_domicilio, de profesión u oficio $cargo, se ha convenido el siguiente contrato de trabajo.</p>

<div class="clausula">
    <div class="clausula-titulo">PRIMERO:</div>
    <p>El trabajador se compromete y obliga a ejecutar el trabajo de $cargo que se le encomienda.</p>
</div>

<div class="clausula">
    <div class="clausula-titulo">SEGUNDO:</div>
    <p>Los servicios se prestarán en $lugar_prestacion, sin perjuicio de la facultad del empleador de alterar, por causa justificada, la naturaleza de los servicios, o el sitio o recinto en que ellos han de prestarse.</p>
</div>

<div class="clausula">
    <div class="clausula-titulo">TERCERO:</div>
    <p>La jornada de trabajo será: $jornada_tipo, con $jornada_horas horas semanales.</p>
</div>

<div class="clausula">
    <div class="clausula-titulo">CUARTO:</div>
    <p>El empleador se compromete a remunerar los servicios del trabajador con un sueldo base mensual de \$$sueldo_base que será liquidado y pagado por períodos vencidos.</p>
</div>

<div class="clausula">
    <div class="clausula-titulo">QUINTO:</div>
    <p>El empleador se compromete a pagar al trabajador los siguientes beneficios: $beneficios. Las remuneraciones se pagarán MENSUALMENTE.</p>
</div>

<div class="clausula">
    <div class="clausula-titulo">SEXTO:</div>
    <p>El trabajador se compromete a cumplir las instrucciones que le sean impartidas por su jefe inmediato o por la gerencia de la empresa, y acatar el Reglamento Interno de Orden, Higiene y Seguridad.</p>
</div>

<div class="clausula">
    <div class="clausula-titulo">SEPTIMO:</div>
    <p>El presente contrato será: $tipo_contrato y sólo podrá ponérsele término en conformidad a la legislación vigente.</p>
</div>

<div class="clausula">
    <div class="clausula-titulo">OCTAVO:</div>
    <p>Se deja constancia que el trabajador ingresó al servicio del empleador el $fecha_inicio.</p>
</div>

<div class="firmas">
    <div class="firma-bloque">
        <div class="firma-linea"></div>
        <p>Firma Empleador<br>Rut: $empresa_rut</p>
    </div>
    <div class="firma-bloque">
        <div class="firma-linea"></div>
        <p>Firma Trabajador<br>Rut: $emp_rut</p>
    </div>
</div>

<script>
if (location.hash === '#print') { setTimeout(() => window.print(), 100); }
</script>
</body>
</html>
HTML;
}

function generar_anexo_html($anexo) {
    if (!$anexo) return '<p>Anexo no encontrado</p>';
    $emp_nombre = $anexo['empleado_nombre'] ?? '';
    $emp_rut = $anexo['empleado_rut'] ?? '';
    $detalle = $anexo['detalle'] ?? '';
    $fecha = $anexo['fecha'] ?? date('d/m/Y');

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Anexo al Contrato</title>
<style>
body { font-family: Arial, sans-serif; max-width: 900px; margin: 0 auto; padding: 20px; font-size: 12px; }
h1 { text-align: center; }
</style>
</head>
<body>
<h1>ANEXO AL CONTRATO DE TRABAJO</h1>
<p><strong>Trabajador:</strong> $emp_nombre</p>
<p><strong>Fecha:</strong> $fecha</p>
<p><strong>Detalle del Anexo:</strong></p>
<p>$detalle</p>
</body>
</html>
HTML;
}

function generar_permiso_html($permiso) {
    if (!$permiso) return '<p>Permiso no encontrado</p>';
    $rut = $permiso['empleado_rut'] ?? '';
    $nombre = $permiso['empleado_nombre'] ?? '';
    $servicio = $permiso['servicio'] ?? '';
    $desde = date('d/m/Y', strtotime($permiso['fecha_desde'] ?? 'now'));
    $hasta = date('d/m/Y', strtotime($permiso['fecha_hasta'] ?? 'now'));
    $dias = $permiso['dias'] ?? 0;

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Permiso sin Goce de Sueldo</title>
<style>
body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; font-size: 13px; }
h1 { text-align: center; }
.form-field { margin: 15px 0; }
label { font-weight: bold; }
</style>
</head>
<body>
<h1>SOLICITUD DE PERMISO SIN GOCE DE REMUNERACIONES</h1>

<div class="form-field">
    <label>RUT:</label> $rut
</div>

<div class="form-field">
    <label>Nombre:</label> $nombre
</div>

<div class="form-field">
    <label>Servicio:</label> $servicio
</div>

<div class="form-field">
    <label>Solicito me conceda</label> $dias <label>día(s) de Permiso sin goce de Remuneraciones.</label>
</div>

<div class="form-field">
    <label>Desde:</label> $desde &nbsp;&nbsp;&nbsp;&nbsp; <label>Hasta:</label> $hasta
</div>

<p style="margin-top: 40px;">Saluda Atentamente a Usted,</p>

<table style="width: 100%; margin-top: 40px;">
<tr>
    <td style="text-align: center; width: 33%;">
        <div style="border-top: 1px solid #000; height: 30px;"></div>
        <p>V° B° JEFE DIRECTO</p>
    </td>
    <td style="text-align: center; width: 33%;">
        <div style="border-top: 1px solid #000; height: 30px;"></div>
        <p>FIRMA SOLICITANTE</p>
    </td>
    <td style="text-align: center; width: 33%;">
        <div style="border-top: 1px solid #000; height: 30px;"></div>
        <p>V° B° JEFE SUPERIOR</p>
    </td>
</tr>
</table>

<p style="margin-top: 20px; text-align: right;">Santiago, _______________________________</p>
</body>
</html>
HTML;
}

function generar_feriado_html($feriado) {
    if (!$feriado) return '<p>Feriado no encontrado</p>';
    $rut = $feriado['empleado_rut'] ?? '';
    $nombre = $feriado['empleado_nombre'] ?? '';
    $servicio = $feriado['servicio'] ?? '';
    $desde = date('d/m/Y', strtotime($feriado['fecha_desde'] ?? 'now'));
    $hasta = date('d/m/Y', strtotime($feriado['fecha_hasta'] ?? 'now'));
    $dias = $feriado['dias'] ?? 0;
    $anio = $feriado['anio_feriado'] ?? date('Y');

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Feriado Legal</title>
<style>
body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; font-size: 13px; }
h1 { text-align: center; }
.form-field { margin: 15px 0; }
</style>
</head>
<body>
<h1>SOLICITUD DE FERIADO LEGAL</h1>

<div class="form-field">
    <label><strong>RUT:</strong></label> $rut
</div>

<div class="form-field">
    <label><strong>Nombre:</strong></label> $nombre
</div>

<div class="form-field">
    <label><strong>Servicio:</strong></label> $servicio
</div>

<div class="form-field">
    <p>Solicito me conceda <strong>$dias</strong> día(s) de Feriado Legal.</p>
</div>

<div class="form-field">
    <label><strong>Desde:</strong></label> $desde &nbsp;&nbsp;&nbsp;&nbsp; <label><strong>Hasta:</strong></label> $hasta
</div>

<div class="form-field">
    <label><strong>Correspondiente al año:</strong></label> $anio
</div>

<p style="margin-top: 40px;">Saluda Atentamente a Usted,</p>

<table style="width: 100%; margin-top: 40px;">
<tr>
    <td style="text-align: center; width: 33%;">
        <div style="border-top: 1px solid #000; height: 30px;"></div>
        <p>V° B° JEFE DIRECTO</p>
    </td>
    <td style="text-align: center; width: 33%;">
        <div style="border-top: 1px solid #000; height: 30px;"></div>
        <p>FIRMA SOLICITANTE</p>
    </td>
    <td style="text-align: center; width: 33%;">
        <div style="border-top: 1px solid #000; height: 30px;"></div>
        <p>V° B° JEFE SUPERIOR</p>
    </td>
</tr>
</table>

<p style="margin-top: 20px; text-align: right;">Santiago, _______________________________</p>
</body>
</html>
HTML;
}

function generar_comprobante_html($comprobante) {
    if (!$comprobante) return '<p>Comprobante no encontrado</p>';
    $nombre = $comprobante['empleado_nombre'] ?? '';
    $rut = $comprobante['empleado_rut'] ?? '';
    $lugar = $comprobante['lugar'] ?? 'Concepción';
    $desde = date('d/m/Y', strtotime($comprobante['fecha_desde'] ?? 'now'));
    $hasta = date('d/m/Y', strtotime($comprobante['fecha_hasta'] ?? 'now'));
    $dias = $comprobante['dias_usados'] ?? 0;
    $valor_diario = number_format($comprobante['valor_diario'] ?? 0, 0, ',', '.');
    $total = number_format($comprobante['total'] ?? 0, 0, ',', '.');

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Comprobante de Feriado</title>
<style>
body { font-family: Arial, sans-serif; max-width: 900px; margin: 0 auto; padding: 20px; font-size: 13px; }
table { width: 100%; border-collapse: collapse; margin: 20px 0; }
td, th { border: 1px solid #000; padding: 8px; text-align: left; }
th { background-color: #f0f0f0; font-weight: bold; }
.total-row { font-weight: bold; }
</style>
</head>
<body>
<h1 style="text-align: center;">COMPROBANTE DE FERIADO LEGAL</h1>

<p>En cumplimiento a las disposiciones legales vigentes se deja constancia que a contar de las fechas que se indican, el trabajador:</p>

<p><strong>DON(A): </strong> $nombre &nbsp;&nbsp;&nbsp;&nbsp; <strong>R.U.T:</strong> $rut</p>

<p><strong>Hará uso de su Feriado Legal con remuneración íntegra de acuerdo al siguiente detalle:</strong></p>

<table>
<tr>
    <th>Período</th>
    <th>Desde</th>
    <th>Hasta</th>
    <th>Lugar</th>
    <th>Días</th>
    <th>Valor Diario</th>
    <th>Valor Total</th>
</tr>
<tr>
    <td>Año</td>
    <td>$desde</td>
    <td>$hasta</td>
    <td>$lugar</td>
    <td style="text-align: center;">$dias</td>
    <td style="text-align: right;">\$$valor_diario</td>
    <td style="text-align: right;" class="total-row">\$$total</td>
</tr>
</table>

</body>
</html>
HTML;
}

function generar_finiquito_html($finiquito) {
    if (!$finiquito) return '<p>Finiquito no encontrado</p>';

    $emp_nombre = $finiquito['empleado_nombre'] ?? '';
    $emp_rut = $finiquito['empleado_rut'] ?? '';
    $cargo = $finiquito['cargo'] ?? '';
    $desde = date('d/m/Y', strtotime($finiquito['fecha_inicio'] ?? 'now'));
    $hasta = date('d/m/Y', strtotime($finiquito['fecha_termino'] ?? 'now'));
    $lugar = $finiquito['lugar_prestacion'] ?? '';
    $causal = $finiquito['causal_termino'] ?? '';

    $sueldo = number_format($finiquito['sueldo_liquido'] ?? 0, 0, ',', '.');
    $vacaciones = number_format($finiquito['vacaciones_proporcional'] ?? 0, 0, ',', '.');
    $feriado = number_format($finiquito['feriado_proporcional'] ?? 0, 0, ',', '.');
    $ind_aviso = number_format($finiquito['indemnizacion_aviso'] ?? 0, 0, ',', '.');
    $ind_anos = number_format($finiquito['indemnizacion_años'] ?? 0, 0, ',', '.');
    $total_haberes = number_format($finiquito['total_haberes'] ?? 0, 0, ',', '.');
    $desc_prev = number_format($finiquito['descuentos_prev'] ?? 0, 0, ',', '.');
    $retencion = number_format($finiquito['retencion_pension_alimenticia'] ?? 0, 0, ',', '.');
    $liquido = number_format($finiquito['liquido_pagado'] ?? 0, 0, ',', '.');

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Finiquito</title>
<style>
body { font-family: Arial, sans-serif; max-width: 900px; margin: 0 auto; padding: 20px; font-size: 12px; line-height: 1.5; }
h1 { text-align: center; }
table { width: 100%; border-collapse: collapse; margin: 15px 0; }
td { padding: 6px; border-bottom: 1px solid #ddd; }
.label { font-weight: bold; width: 40%; }
.valor { text-align: right; width: 20%; }
.total-row { border-top: 2px solid #000; font-weight: bold; }
.firmas { display: flex; justify-content: space-around; margin-top: 50px; text-align: center; }
.firma-bloque { width: 30%; }
</style>
</head>
<body>
<h1>FINIQUITO DE TRABAJO</h1>

<p><strong>Trabajador:</strong> $emp_nombre &nbsp;&nbsp;&nbsp;&nbsp; <strong>RUT:</strong> $emp_rut</p>
<p><strong>Cargo:</strong> $cargo &nbsp;&nbsp;&nbsp;&nbsp; <strong>Lugar:</strong> $lugar</p>
<p><strong>Período:</strong> Del $desde al $hasta</p>
<p><strong>Causal de Terminación:</strong> $causal</p>

<h3>LIQUIDACIÓN</h3>
<table>
<tr>
    <td class="label">Sueldo Líquido:</td>
    <td class="valor">\$$sueldo</td>
</tr>
<tr>
    <td class="label">Vacaciones Proporcional:</td>
    <td class="valor">\$$vacaciones</td>
</tr>
<tr>
    <td class="label">Feriado Proporcional:</td>
    <td class="valor">\$$feriado</td>
</tr>
<tr>
    <td class="label">Indemnización Aviso Previo:</td>
    <td class="valor">\$$ind_aviso</td>
</tr>
<tr>
    <td class="label">Indemnización Años de Servicio:</td>
    <td class="valor">\$$ind_anos</td>
</tr>
<tr class="total-row">
    <td class="label">TOTAL HABERES:</td>
    <td class="valor">\$$total_haberes</td>
</tr>
<tr>
    <td class="label">Descuentos Previsionales:</td>
    <td class="valor">\$$desc_prev</td>
</tr>
<tr>
    <td class="label">Retención Pensión Alimenticia:</td>
    <td class="valor">\$$retencion</td>
</tr>
<tr class="total-row">
    <td class="label">LÍQUIDO A PAGAR:</td>
    <td class="valor">\$$liquido</td>
</tr>
</table>

<p style="margin-top: 30px; text-align: justify;">El trabajador declara haber recibido conforme al presente finiquito y renuncia a todas las acciones que pudieran emanar del contrato que lo vinculó con la empresa.</p>

<div class="firmas">
    <div class="firma-bloque">
        <div style="border-top: 1px solid #000; height: 30px;"></div>
        <p>Firma Trabajador</p>
    </div>
    <div class="firma-bloque">
        <div style="border-top: 1px solid #000; height: 30px;"></div>
        <p>Firma Empleador</p>
    </div>
    <div class="firma-bloque">
        <div style="border-top: 1px solid #000; height: 30px;"></div>
        <p>Ministro de Fe</p>
    </div>
</div>

</body>
</html>
HTML;
}

function comprobante_obtener($id) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM comprobantes_feriado WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function anexo_obtener($id) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM anexos_contrato WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
