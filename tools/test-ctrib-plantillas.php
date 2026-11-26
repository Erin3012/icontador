<?php
// Prueba de las plantillas de importación de Cifrax Tributario (assets/plantillas/*.xlsx, mismas de iTributario):
// el importador reconoce sus encabezados y lee planillas llenas con el mismo orden de columnas.
// Uso: php tools/test-ctrib-plantillas.php
declare(strict_types=1);
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

/** Crea un .xlsx mínimo (una hoja) con las filas dadas; los enteros van como números y el resto como texto. */
function crear_xlsx(string $ruta, array $filas): void
{
    $zip = new ZipArchive();
    $zip->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $xml = '';
    foreach ($filas as $n => $fila) {
        $celdas = '';
        foreach ($fila as $i => $valor) {
            $ref = chr(65 + $i) . ($n + 1);
            $celdas .= is_int($valor)
                ? "<c r=\"$ref\"><v>$valor</v></c>"
                : "<c r=\"$ref\" t=\"inlineStr\"><is><t>" . htmlspecialchars((string) $valor) . '</t></is></c>';
        }
        $xml .= '<row r="' . ($n + 1) . '">' . $celdas . '</row>';
    }
    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $xml . '</sheetData></worksheet>');
    $zip->close();
}

$plantillas = dirname(__DIR__) . '/assets/plantillas';
check(is_file("$plantillas/balance-general-8-columnas.xlsx") && is_file("$plantillas/retiros-y-dividendos.xlsx") && is_file("$plantillas/empresas.xlsx"), 'las tres plantillas están en assets/plantillas');

// Plantillas vacías: se reconocen los encabezados y se informa que no hay datos.
falla(fn() => ctrib_cuentas_desde_archivo("$plantillas/balance-general-8-columnas.xlsx", 'xlsx'), 'no tiene cuentas', 'plantilla de balance: encabezados reconocidos, sin cuentas');
falla(fn() => ctrib_retiros_desde_archivo("$plantillas/retiros-y-dividendos.xlsx", 'xlsx'), 'no tiene retiros', 'plantilla de retiros: encabezados reconocidos, sin retiros');

$carpeta = sys_get_temp_dir() . '/ctrib-plantillas-' . bin2hex(random_bytes(3));
mkdir($carpeta, 0700, true);

// Balance con la misma estructura de la plantilla (fila de grupos y encabezados de 8 columnas).
crear_xlsx("$carpeta/balance.xlsx", [
    [''],
    ['', '', '', '', 'Saldos', '', 'Inventarios', '', 'Resultados'],
    ['Cod. Cuenta', 'Nom. Cuenta', 'Débitos', 'Créditos', 'Deudor', 'Acreedor', 'Activo', 'Pasivo', 'Pérdidas', 'Ganancias'],
    [101002, 'BANCOS', 34234307, 16877843, 17356464, 0, 17356464, 0, 0, 0],
    ['505002', 'VENTAS EXENTAS', 0, 22018000, 0, 22018000, 0, 0, 0, 22018000],
]);
$cuentas = ctrib_cuentas_desde_archivo("$carpeta/balance.xlsx", 'xlsx');
check(count($cuentas) === 2 && $cuentas[0]['codigo'] === '101002' && $cuentas[0]['debitos'] === 34234307 && $cuentas[0]['creditos'] === 16877843, 'balance llenado según la plantilla: débitos y créditos por cuenta');
check($cuentas[1]['codigo'] === '505002' && $cuentas[1]['creditos'] === 22018000, 'segunda cuenta del balance');

// Retiros con las columnas de la plantilla: RUT SOCIO, NOMBRE SOCIO, % PARTICIPACIÓN, FECHA, MONTO.
crear_xlsx("$carpeta/retiros.xlsx", [
    ['RUT SOCIO', 'NOMBRE SOCIO', '% PARTICIPACIÓN', 'FECHA', 'MONTO'],
    ['12.345.678-9', 'Socio Uno', 50, 45731, 600000],
    ['98.765.432-1', 'Socio Dos', 50, '30/09/2025', 400000],
]);
$retiros = ctrib_retiros_desde_archivo("$carpeta/retiros.xlsx", 'xlsx');
check(count($retiros) === 2, 'dos retiros leídos');
check($retiros[0]['rut'] === '12.345.678-9' && $retiros[0]['nombre'] === 'Socio Uno' && $retiros[0]['fecha'] === '2025-03-15' && $retiros[0]['monto'] === 600000, 'retiro 1: la columna de % participación no se confunde con la fecha ni el monto');
check($retiros[1]['fecha'] === '2025-09-30' && $retiros[1]['monto'] === 400000 && $retiros[1]['tipo'] === 'Retiro', 'retiro 2 con fecha en texto y tipo por defecto');

array_map('unlink', glob("$carpeta/*"));
rmdir($carpeta);
echo "Cifrax Tributario plantillas: encabezados de Balance General 8 Columnas y Retiros y Dividendos reconocidos, y planillas llenas leídas\n";
