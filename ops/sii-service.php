<?php
declare(strict_types=1);

// Operación privada del servicio SII. Instalar fuera de DOCUMENT_ROOT y ejecutar con PHP CLI.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$webRoot = getenv('ICONTADOR_WEBROOT') ?: dirname(__DIR__) . '/public_html';
$webRoot = realpath($webRoot) ?: '';
if ($webRoot === '' || !is_file($webRoot . '/api/db.php') || !is_file($webRoot . '/api/lib/sii.php')) {
    fwrite(STDERR, "No encuentro la instalación de iContador. Configura ICONTADOR_WEBROOT con la ruta de public_html.\n");
    exit(2);
}

require_once $webRoot . '/api/db.php';
require_once $webRoot . '/api/lib/sii.php';

function confirmarOperacion(string $frase): bool
{
    if (!defined('STDIN') || !stream_isatty(STDIN)) return false;
    fwrite(STDERR, "Escribe {$frase} para confirmar: ");
    $respuesta = trim((string) fgets(STDIN));
    return hash_equals($frase, $respuesta);
}

try {
    $accion = $argv[1] ?? '';
    if (!in_array($accion, ['generar', 'revocar'], true)) {
        fwrite(STDERR, "Uso: php sii-service.php generar|revocar\n");
        exit(2);
    }

    $db = icontador_db();
    sii_schema($db);
    $st = $db->query('SELECT token_hash, codigo_hash, codigo_expira_en, revocado_en FROM sii_servicio WHERE id = 1');
    $servicio = $st->fetch() ?: [];
    $activo = !empty($servicio['token_hash']) && empty($servicio['revocado_en']);
    $codigoVigente = !empty($servicio['codigo_hash']) && (string) ($servicio['codigo_expira_en'] ?? '') >= sii_ahora();

    if ($accion === 'generar') {
        if ($activo || $codigoVigente) {
            fwrite(STDERR, "Generar otro código invalidará el acceso actual o el código vigente.\n");
            if (!confirmarOperacion('REEMPLAZAR')) exit(3);
        }
        $resultado = sii_servicio_codigo_crear($db, 'Agente SII QLC VPS');
        fwrite(STDOUT, "Código temporal (vence {$resultado['expira_en']}):\n{$resultado['codigo']}\n");
        fwrite(STDOUT, "Ingresa el código en el enrolamiento SSH del servidor nuevo; no se guarda en archivos.\n");
        exit(0);
    }

    if (!$activo && !$codigoVigente) {
        fwrite(STDOUT, "No hay un servicio activo ni un código vigente.\n");
        exit(0);
    }
    fwrite(STDERR, "Esto revocará el servicio y detendrá las sincronizaciones nuevas.\n");
    if (!confirmarOperacion('REVOCAR')) exit(3);
    sii_servicio_revocar($db);
    fwrite(STDOUT, "Servicio revocado.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "No se pudo administrar el servicio SII: {$error->getMessage()}\n");
    exit(1);
}
