<?php
// Carga un balance (cuentas con débitos y créditos del año) en la contabilidad de una empresa como un voucher resumen
// al 31 de diciembre del año comercial, y lo traspasa a Cifrax Tributario (balance con origen "vouchers").
// Agrega al plan de la empresa las cuentas que falten. Es idempotente: reemplaza el voucher resumen anterior.
// Uso: php tools/cargar-balance-resumen.php <archivo.json> <texto de la razón social> <año tributario>
//   El JSON puede traer {"cuentas": [...]} o {"balance": [...]} con codigo, nombre, debitos y creditos.
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/lib/vouchers.php';
require_once dirname(__DIR__) . '/api/lib/ctrib.php';

const BALANCE_RESUMEN_GLOSA = 'Balance resumen importado';

function cargar_balance_resumen(PDO $pdo, int $empresa, int $anio, array $cuentas, string $origen, string $usuario): array
{
    $debitos = $creditos = 0;
    foreach ($cuentas as $i => $c) {
        $codigo = (string) ($c['codigo'] ?? '');
        if (!preg_match('/^[0-9][0-9.\-]{0,19}$/', $codigo)) {
            throw new CtribError('Fila ' . ($i + 1) . ": código de cuenta no válido ($codigo).");
        }
        foreach (['debitos', 'creditos'] as $k) {
            if (!is_int($c[$k] ?? null) || $c[$k] < 0) {
                throw new CtribError("Cuenta $codigo: $k debe ser un entero mayor o igual a cero.");
            }
        }
        $debitos += $c['debitos'];
        $creditos += $c['creditos'];
    }
    if ($debitos !== $creditos || $debitos === 0) {
        throw new CtribError("El balance no cuadra o está vacío: débitos $debitos y créditos $creditos.");
    }
    $ac = ctrib_anio_comercial($anio);
    $fecha = "$ac-12-31";

    $pdo->beginTransaction();
    try {
        // Plan de cuentas: solo se agregan las que falten.
        $plan = array_column(planCuentas($pdo, $empresa), 'codigo');
        $agregadas = 0;
        foreach ($cuentas as $c) {
            if (!in_array((string) $c['codigo'], $plan, true)) {
                agregarCuenta($pdo, ['codigo' => (string) $c['codigo'], 'nombre' => (string) ($c['nombre'] ?: $c['codigo'])], $empresa);
                $plan[] = (string) $c['codigo'];
                $agregadas++;
            }
        }
        // Voucher resumen anterior del mismo año: se reemplaza.
        $previos = $pdo->prepare('SELECT id FROM vouchers WHERE empresa_id = ? AND fecha = ? AND glosa LIKE ?');
        $previos->execute([$empresa, $fecha, BALANCE_RESUMEN_GLOSA . '%']);
        $borrados = 0;
        foreach ($previos->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $pdo->prepare('DELETE FROM voucher_lineas WHERE voucher_id = ?')->execute([(int) $id]);
            $pdo->prepare('DELETE FROM vouchers WHERE id = ?')->execute([(int) $id]);
            $borrados++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $lineas = [];
    foreach ($cuentas as $c) {
        if ($c['debitos'] > 0) {
            $lineas[] = ['cuenta' => (string) $c['codigo'], 'glosa' => "Débitos $ac", 'debe' => $c['debitos'], 'haber' => 0];
        }
        if ($c['creditos'] > 0) {
            $lineas[] = ['cuenta' => (string) $c['codigo'], 'glosa' => "Créditos $ac", 'debe' => 0, 'haber' => $c['creditos']];
        }
    }
    $voucher = guardarVoucher($pdo, [
        'tipo' => 'T', 'fecha' => $fecha, 'registro' => 'Ambos',
        'glosa' => BALANCE_RESUMEN_GLOSA . " $ac ($origen)", 'lineas' => $lineas,
    ], null, $empresa);

    // Traspaso a Cifrax Tributario desde los vouchers del año comercial.
    $balance = ctrib_balance_guardar($pdo, $empresa, $anio, 'vouchers', ctrib_cuentas_desde_vouchers($pdo, $empresa, $anio), '', $usuario);
    return ['voucher' => $voucher['id'], 'numero' => $voucher['numero'], 'lineas' => count($lineas), 'agregadas' => $agregadas,
        'reemplazados' => $borrados, 'balance' => $balance];
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    [$archivo, $texto, $anio] = [$argv[1] ?? '', $argv[2] ?? '', (int) ($argv[3] ?? 0)];
    if (!is_file($archivo) || $texto === '' || $anio < 2000) {
        fwrite(STDERR, "Uso: php tools/cargar-balance-resumen.php <archivo.json> <texto de la razón social> <año tributario>\n");
        exit(1);
    }
    $datos = json_decode((string) file_get_contents($archivo), true, 512, JSON_THROW_ON_ERROR);
    $cuentas = $datos['cuentas'] ?? $datos['balance'] ?? [];
    $pdo = conectar();
    ctrib_schema($pdo);
    $buscar = $pdo->prepare('SELECT id, razon_social FROM empresas WHERE razon_social LIKE ? ORDER BY id');
    $buscar->execute(['%' . $texto . '%']);
    $empresas = $buscar->fetchAll();
    if (count($empresas) !== 1) {
        fwrite(STDERR, 'Se esperaba una empresa con "' . $texto . '" y se encontraron ' . count($empresas) . ".\n");
        exit(1);
    }
    $empresa = (int) $empresas[0]['id'];
    try {
        $r = cargar_balance_resumen($pdo, $empresa, $anio, $cuentas, basename($archivo), 'carga de balance resumen');
    } catch (CtribError $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    $f = fn(int $n) => number_format($n, 0, ',', '.');
    echo "Empresa $empresa, AT $anio: voucher resumen N° {$r['numero']} (id {$r['voucher']}) con {$r['lineas']} líneas; "
        . "{$r['agregadas']} cuentas agregadas al plan; {$r['reemplazados']} voucher(s) resumen reemplazado(s).\n";
    echo 'Traspaso a Cifrax Tributario: débitos ' . $f($r['balance']['totales']['debitos']) . ', sumas iguales '
        . $f($r['balance']['sumasIguales']['activo']) . ', resultado ' . $f($r['balance']['resultado']) . ", origen {$r['balance']['origen']}.\n";
}
