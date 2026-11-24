<?php
// Prueba de RR.HH. (contratos y anexos) con SQLite en memoria: php tools/test-rrhh-api.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/rrhh.php';

function check(bool $ok, string $msg): void {
    if (!$ok) {
        fwrite(STDERR, "FALLA: $msg\n");
        exit(1);
    }
    echo "✓ $msg\n";
}

$db = base_prueba();
rrhh_schema($db);
rrhh_schema($db); // idempotente

$contrato = contrato_crear($db, 1, ['empleado_rut' => '11111111-1', 'empleado_nombre' => 'Ana Pérez', 'fecha_inicio' => '2026-01-02']);
check($contrato > 0, 'crear contrato');
check((contrato_obtener($db, $contrato, 1)['empleado_nombre'] ?? '') === 'Ana Pérez', 'obtener contrato de la empresa');
check(contrato_obtener($db, $contrato, 2) === null, 'una empresa no ve contratos de otra');

$anexo = anexo_crear($db, 1, ['contrato_id' => $contrato, 'empleado_rut' => '11111111-1', 'fecha' => '2026-03-01', 'detalle' => 'Cambio de jornada']);
check($anexo > 0, 'crear anexo');
$leido = anexo_obtener($db, $anexo, 1);
check(($leido['detalle'] ?? '') === 'Cambio de jornada' && (int)$leido['contrato_id'] === $contrato, 'obtener anexo con su contrato');
check(anexo_obtener($db, $anexo, 2) === null, 'una empresa no ve anexos de otra');
check(anexo_obtener($db, 9999, 1) === null, 'anexo inexistente devuelve null');

echo "RR.HH.: contratos y anexos verificados ✓\n";
