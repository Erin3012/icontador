<?php
/* Documentos imprimibles de Cifrax Tributario (se guardan como PDF desde el navegador).
   GET api/cifrax-tributario-pdf.php?doc=R17&anio=2026&empresa_id=1
   doc: BALANCE, R4, R6, R17, RLI, R18, R19, CPT, R20, RTRE, R21, DJ1948 */
declare(strict_types=1);
require_once __DIR__ . '/lib/sesion.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/ctrib-pdf.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    $pdo = conectar();
    ctrib_schema($pdo);
    $empresa = empresa_actual($pdo);
    $anio = (int) ($_GET['anio'] ?? 0);
    if ($anio < 2000 || $anio > 2100) {
        throw new CtribError('Indique el año tributario (AAAA).');
    }
    echo ctrib_documento_html($pdo, $empresa, $anio, strtoupper((string) ($_GET['doc'] ?? '')));
} catch (CtribError | EmpresaError $e) {
    http_response_code(422);
    echo '<!doctype html><meta charset="utf-8"><title>Cifrax Tributario</title><p style="font-family:sans-serif">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
} catch (Throwable $e) {
    error_log('cifrax-tributario-pdf.php: ' . $e);
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>Cifrax Tributario</title><p style="font-family:sans-serif">No se pudo generar el documento.</p>';
}
