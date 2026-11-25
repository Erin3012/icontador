<?php
// Prueba de Capital Propio Tributario a partir del Activo (CPT Activo). Uso: php tools/test-ctrib-cpt.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/ctrib-cpt.php';

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
crearEsquema($pdo);
ctrib_schema($pdo);
$semilla = json_decode((string) file_get_contents(dirname(__DIR__) . '/api/lib/ctrib/enyel-2025.json'), true, 512, JSON_THROW_ON_ERROR);
ctrib_mapeo_guardar($pdo, 1, $semilla['mapeo'], 'prueba');
ctrib_balance_guardar($pdo, 1, 2026, 'itributario', $semilla['balance'], 'captura', 'prueba');
ctrib_homologacion_importar($pdo, 1, 2026, $semilla['homologacion_itributario'], 'prueba');

// Sin configuración: igual que iTributario (capital propio = total activo 20.509.426).
$c = ctrib_cpt($pdo, 1, 2026);
check(count($c['cuentas']) === 11 && $c['configuradas'] === 0 && $c['origenConfiguracion'] === 'NINGUNA', '11 cuentas de balance sin configurar');
check($c['totalActivo'] === 20509426 && $c['totalActivoDepurado'] === 20509426 && $c['capitalPropioDeterminado'] === 20509426, 'sin configurar: capital propio 20.509.426 como iTributario');
check(count($c['avisos']) === 1 && str_contains($c['avisos'][0], 'Debe configurar'), 'aviso de configurar las cuentas');
$saldos = array_column($c['cuentas'], 'saldo', 'cuenta');
check($saldos['101002'] === 17356464 && $saldos['202081'] === 11978400 && $saldos['301001'] === 1500000, 'saldos de activo, pasivo y capital');

// Configuración: pasivos exigibles y un ajuste.
falla(fn() => ctrib_cpt_guardar($pdo, 1, 2026, ['404001' => 'PASIVO'], false, 'prueba'), 'no es una cuenta de balance', 'cuenta de resultado no se configura');
falla(fn() => ctrib_cpt_guardar($pdo, 1, 2026, ['202040' => 'OTRO'], false, 'prueba'), 'Concepto no válido', 'concepto inválido');
$c = ctrib_cpt_guardar($pdo, 1, 2026, ['202040' => 'PASIVO', '202050' => 'PASIVO', '202081' => 'PASIVO', '101090' => 'AJUSTE', '101002' => ''], true, 'prueba');
check($c['origenConfiguracion'] === 'ANIO' && $c['configuradas'] === 4, '4 cuentas configuradas para el año');
check($c['subtotalPasivos'] === 71400 + 54269 + 11978400 && $c['subtotalAjustes'] === 13062, 'subtotales de pasivos exigibles y ajustes');
check($c['totalActivoDepurado'] === 20509426 - 13062 && $c['capitalPropioDeterminado'] === 20509426 - 13062 - 12104069, 'activo depurado y capital propio determinado');
check($c['diferencia'] === $c['capitalPropioDeterminado'] - $c['cptsRegimen'], 'diferencia con el CPTS del régimen (R19)');

// La configuración maestra se sugiere en otro año sin configuración propia.
ctrib_balance_guardar($pdo, 1, 2027, 'itributario', $semilla['balance'], 'captura', 'prueba');
$otro = ctrib_cpt($pdo, 1, 2027);
check($otro['origenConfiguracion'] === 'MAESTRO' && $otro['configuradas'] === 4 && str_contains($otro['avisos'][0], 'maestra'), 'la configuración maestra se sugiere en 2027');

echo "Cifrax Tributario CPT Activo: determinación como iTributario, configuración de cuentas y maestro verificados\n";
