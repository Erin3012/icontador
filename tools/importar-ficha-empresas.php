<?php
// Carga la "Ficha de Empresas" de iContador (CSV) en la base configurada (SQLite local o MySQL del sitio):
//   php tools/importar-ficha-empresas.php "ruta/Ficha Empresas.csv" [--simular]
// Con --simular muestra lo que haría sin guardar nada.
// Crea las empresas nuevas y actualiza la ficha de las que ya existen (por RUT); no toca sus vouchers ni plan de cuentas.
declare(strict_types=1);
require dirname(__DIR__) . '/api/lib/empresas-csv.php';

$simular = in_array('--simular', $argv, true);
$ruta = array_values(array_diff(array_slice($argv, 1), ['--simular']))[0] ?? '';
if ($ruta === '' || !is_readable($ruta)) {
    fwrite(STDERR, "Uso: php tools/importar-ficha-empresas.php archivo.csv [--simular]\n");
    exit(1);
}
try {
    $db = icontador_db();
    empresas_esquema_completo($db);
    $r = empresas_importar_ficha_csv($db, file_get_contents($ruta), $simular);
} catch (ErrorValidacion $e) {
    fwrite(STDERR, implode("\n", $e->errores) . "\n");
    exit(1);
}
echo $simular ? "SIMULACIÓN: no se guardó nada.\n" : '';
echo "Empresas creadas: {$r['creadas']}\nEmpresas actualizadas: {$r['actualizadas']}\n";
foreach ($r['empresas_actualizadas'] as $nombre) {
    echo "  actualizada: $nombre\n";
}
foreach ($r['errores'] as $error) {
    echo "Omitida: $error\n";
}
echo 'Total en la base: ' . count(empresas_listar($db)) . "\n";
