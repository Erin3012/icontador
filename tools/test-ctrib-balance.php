<?php
// Prueba del balance de Cifrax Tributario: mapeo de dígitos, balance desde vouchers, importación Excel/CSV y la semilla ENYEL.
// Uso: php tools/test-ctrib-balance.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/ctrib.php';

function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FALLA: $msg\n"); exit(1); } }
function falla(callable $accion, string $contiene, string $msg): void
{
    try { $accion(); } catch (Throwable $e) {
        check(str_contains($e->getMessage(), $contiene), "$msg (mensaje: " . $e->getMessage() . ")");
        return;
    }
    check(false, "$msg (no lanzó error)");
}

$mapeoEnyel = ['1' => 'ACTIVO', '2' => 'PASIVO', '3' => 'CAPITAL', '4' => 'PERDIDA', '5' => 'GANANCIA'];

$pdo = base_prueba();
crearEsquema($pdo);
ctrib_schema($pdo);
ctrib_schema($pdo); // idempotente

// --- Mapeo de dígitos ---
check(ctrib_mapeo($pdo, 1) === [], 'sin mapeo al inicio');
falla(fn() => ctrib_mapeo_guardar($pdo, 1, []), 'al menos un dígito', 'mapeo vacío debe rechazarse');
falla(fn() => ctrib_mapeo_guardar($pdo, 1, ['0' => 'ACTIVO']), 'dígito 0', 'dígito 0 no válido');
falla(fn() => ctrib_mapeo_guardar($pdo, 1, ['1' => 'CAJA']), 'Tipo de cuenta', 'tipo no válido');
ctrib_mapeo_guardar($pdo, 1, $mapeoEnyel, 'prueba');
check(ctrib_mapeo($pdo, 1) === ['1' => 'ACTIVO', '2' => 'PASIVO', '3' => 'CAPITAL', '4' => 'PERDIDA', '5' => 'GANANCIA'], 'mapeo guardado');
check(ctrib_mapeo($pdo, 2) === [], 'mapeo separado por empresa');
$historialMapeo = ctrib_historial_listar($pdo, 1, 0, 'mapeo');
check(count($historialMapeo) === 5, 'historial de mapeo: un registro por dígito nuevo (5), hubo ' . count($historialMapeo));

// --- Balance de la semilla ENYEL (captura de iTributario) ---
$semilla = json_decode((string) file_get_contents(dirname(__DIR__) . '/api/lib/ctrib/enyel-2025.json'), true, 512, JSON_THROW_ON_ERROR);
$enyel = ctrib_armar_balance($semilla['balance'], $mapeoEnyel);
check($enyel['totales']['debitos'] === 96772918 && $enyel['totales']['creditos'] === 96772918, 'débitos y créditos ENYEL 96.772.918');
check($enyel['totales']['activo'] === 20509426, 'activo ENYEL 20.509.426');
check($enyel['totales']['pasivo'] === 13604069, 'pasivo ENYEL (incluye capital) 13.604.069');
check($enyel['totales']['perdida'] === 15112643, 'pérdidas del ejercicio ENYEL 15.112.643');
check($enyel['totales']['ganancia'] === 22018000, 'ganancias ENYEL 22.018.000');
check($enyel['resultado'] === 6905357, 'resultado ENYEL 6.905.357 (utilidad, la columna de pérdidas la cuadra)');
check($enyel['sumasIguales']['perdida'] === 22018000 && $enyel['sumasIguales']['ganancia'] === 22018000, 'sumas iguales de resultado');
check($enyel['sumasIguales']['activo'] === 20509426 && $enyel['sumasIguales']['pasivo'] === 20509426, 'sumas iguales de inventario 20.509.426');
check($enyel['cuadra'] === true, 'balance ENYEL cuadra');

// --- Balance desde vouchers del año comercial (AT 2026 = 2025) ---
crearEsquema($pdo);
foreach (['101001' => 'Caja', '101002' => 'Bancos', '505002' => 'Ventas exentas', '404001' => 'Costo de ventas', '301001' => 'Capital'] as $codigo => $nombre) {
    agregarCuenta($pdo, ['codigo' => $codigo, 'nombre' => $nombre], 1);
}
$voucher = fn(string $fecha, array $lineas, string $registro = 'Tributario') => guardarVoucher($pdo, [
    'tipo' => 'I', 'fecha' => $fecha, 'registro' => $registro, 'glosa' => 'prueba',
    'lineas' => $lineas,
], null, 1);
$voucher('2025-03-10', [['cuenta' => '101001', 'debe' => 1000000, 'haber' => 0], ['cuenta' => '505002', 'debe' => 0, 'haber' => 1000000]]);
$voucher('2025-06-01', [['cuenta' => '404001', 'debe' => 300000, 'haber' => 0], ['cuenta' => '101002', 'debe' => 0, 'haber' => 300000]]);
$voucher('2025-07-01', [['cuenta' => '101002', 'debe' => 500000, 'haber' => 0], ['cuenta' => '301001', 'debe' => 0, 'haber' => 500000]]);
// Fuera del período o de otro registro: no debe contarse.
$voucher('2024-12-31', [['cuenta' => '101001', 'debe' => 99, 'haber' => 0], ['cuenta' => '505002', 'debe' => 0, 'haber' => 99]]);
$voucher('2025-05-05', [['cuenta' => '101001', 'debe' => 7, 'haber' => 0], ['cuenta' => '505002', 'debe' => 0, 'haber' => 7]], 'IFRS');

$cuentasVouchers = ctrib_cuentas_desde_vouchers($pdo, 1, 2026);
$balanceVouchers = ctrib_armar_balance($cuentasVouchers, $mapeoEnyel);
check(count($cuentasVouchers) === 5, 'cuentas desde vouchers (101001, 101002, 301001, 404001, 505002): ' . count($cuentasVouchers));
check($balanceVouchers['totales']['debitos'] === 1000000 + 300000 + 500000 && $balanceVouchers['cuadra'], 'balance desde vouchers cuadra y excluye otro año y registro IFRS');

// Sin mapeo de algún dígito, el balance no se genera.
falla(fn() => ctrib_armar_balance($cuentasVouchers, ['1' => 'ACTIVO']), 'Falta asignar', 'dígitos sin tipo deben bloquear el balance');

$guardado = ctrib_balance_guardar($pdo, 1, 2026, 'vouchers', $cuentasVouchers, '', 'prueba');
check($guardado['origen'] === 'vouchers' && $guardado['anioComercial'] === 2025, 'snapshot desde vouchers');
check(ctrib_balance_guardado($pdo, 1, 2026)['totales']['debitos'] === 1800000, 'snapshot leído de la base');
check(ctrib_balance_guardado($pdo, 1, 2027) === null, 'sin balance para otro año');

// Un balance que no cuadra no se guarda y deja el anterior.
falla(fn() => ctrib_balance_guardar($pdo, 1, 2026, 'excel', [['codigo' => '101001', 'nombre' => 'Caja', 'debitos' => 10, 'creditos' => 5]], '', 'prueba'), 'no cuadra', 'balance descuadrado debe rechazarse');
check(ctrib_balance_guardado($pdo, 1, 2026)['origen'] === 'vouchers', 'el balance anterior se conserva');

// --- Importación Excel (.xlsx) y CSV ---
check(ctrib_monto_texto('5.315.000') === 5315000 && ctrib_monto_texto('') === 0 && ctrib_monto_texto('-12') === -12, 'montos en texto');
$carpeta = sys_get_temp_dir() . '/ctrib-prueba-' . bin2hex(random_bytes(3));
mkdir($carpeta, 0700, true);

$filasXlsx = [
    ['BALANCE GENERAL 8 COLUMNAS', '', '', ''],
    ['Cod. Cuenta', 'Nom. Cuenta', 'Débitos', 'Créditos'],
    [101001, 'Caja', 1000000, 1000000],
    ['505002', 'Ventas exentas', 0, 1000000],
];
$xlsx = $carpeta . '/balance.xlsx';
$zip = new ZipArchive();
check($zip->open($xlsx, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'crear xlsx de prueba');
$letras = fn(int $i) => chr(65 + $i);
$filasXml = '';
foreach ($filasXlsx as $n => $fila) {
    $celdas = '';
    foreach ($fila as $i => $valor) {
        $ref = $letras($i) . ($n + 1);
        if (is_int($valor)) {
            $celdas .= "<c r=\"$ref\"><v>$valor</v></c>";
        } else {
            $celdas .= "<c r=\"$ref\" t=\"inlineStr\"><is><t>" . htmlspecialchars((string) $valor) . "</t></is></c>";
        }
    }
    $filasXml .= '<row r="' . ($n + 1) . '">' . $celdas . '</row>';
}
$zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $filasXml . '</sheetData></worksheet>');
$zip->close();
$desdeXlsx = ctrib_cuentas_desde_archivo($xlsx, 'xlsx');
check(count($desdeXlsx) === 2 && $desdeXlsx[0]['codigo'] === '101001' && $desdeXlsx[1]['creditos'] === 1000000, 'lectura de xlsx con encabezado tras título y código numérico');

$csv = $carpeta . '/balance.csv';
file_put_contents($csv, "\xEF\xBB\xBFCod. Cuenta;Nom. Cuenta;Débitos;Créditos\n101001;Caja;5.315.000;5.315.000\n505002;Ventas exentas;0;22.018.000\n");
$desdeCsv = ctrib_cuentas_desde_archivo($csv, 'csv');
check(count($desdeCsv) === 2 && $desdeCsv[0]['debitos'] === 5315000 && $desdeCsv[1]['creditos'] === 22018000, 'lectura de CSV con BOM, separador ; y montos con puntos');

$duplicado = $carpeta . '/duplicado.csv';
file_put_contents($duplicado, "Cod. Cuenta;Nom. Cuenta;Débitos;Créditos\n101001;Caja;1;1\n101001;Caja;1;1\n");
falla(fn() => ctrib_cuentas_desde_archivo($duplicado, 'csv'), 'más de una vez', 'cuenta repetida debe rechazarse');
$sinEncabezado = $carpeta . '/sin.csv';
file_put_contents($sinEncabezado, "a;b;c\n1;2;3\n");
falla(fn() => ctrib_cuentas_desde_archivo($sinEncabezado, 'csv'), 'encabezados', 'sin encabezados debe rechazarse');
array_map('unlink', glob($carpeta . '/*'));
rmdir($carpeta);

echo "Cifrax Tributario balance: mapeo, balance ENYEL (sumas iguales 96.772.918, resultado 6.905.357), vouchers, Excel/CSV e historial verificados\n";
