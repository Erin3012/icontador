<?php
// GET    banco.php?plantilla=1                                  plantilla CSV vacía (Fecha; Descripción; Monto; Referencia)
// GET    banco.php?vista=extractos[&estado=&desde=&hasta=&q=&monto=]   movimientos de la cartola
// GET    banco.php?vista=comprobantes[&desde=&hasta=&q=&monto=]         vouchers sin conciliar
// GET    banco.php?vista=reporte[&desde=&hasta=]                         saldo inicial, movimientos y saldo final
// POST   banco.php?accion=importar      {csv}                  guarda los movimientos del CSV completado
// POST   banco.php?accion=conciliar&id=N {comprobante: id|null} marca el movimiento como conciliado
// POST   banco.php?accion=desconciliar&id=N                    lo vuelve a dejar pendiente
// DELETE banco.php?id=N                                        elimina un movimiento pendiente
declare(strict_types=1);
require_once __DIR__ . '/lib/sesion.php';
require __DIR__ . '/lib/banco.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['plantilla'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="plantilla-cartola-banco.csv"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo banco_plantilla();
    exit;
}

ejecutar(function (PDO $pdo, int $empresa) {
    banco_schema($pdo);
    $id = isset($_GET['id']) ? (int) $_GET['id'] : null;
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            return match ($_GET['vista'] ?? 'extractos') {
                'extractos' => ['extractos' => banco_listar($pdo, $_GET, $empresa)],
                'comprobantes' => ['comprobantes' => banco_comprobantes_pendientes($pdo, $_GET, $empresa)],
                'reporte' => banco_reporte($pdo, $_GET, $empresa),
                default => responder(['errores' => ['Vista desconocida.']], 400),
            };
        case 'POST':
            $accion = $_GET['accion'] ?? '';
            if ($accion === 'importar') {
                $csv = cuerpoJson()['csv'] ?? null;
                if (!is_string($csv) || $csv === '') {
                    throw new ErrorValidacion(['Seleccione el archivo CSV con la cartola.']);
                }
                if (strlen($csv) > 5 * 1024 * 1024) {
                    throw new ErrorValidacion(['El archivo supera los 5 MB.']);
                }
                return banco_importar($pdo, $csv, $empresa);
            }
            if (!$id) {
                return responder(['errores' => ['Falta el id del movimiento.']], 400);
            }
            if ($accion === 'conciliar') {
                $comprobante = cuerpoJson()['comprobante'] ?? null;
                if ($comprobante !== null && (!is_int($comprobante) || $comprobante < 1)) {
                    throw new ErrorValidacion(['Voucher inválido.']);
                }
                return banco_conciliar($pdo, $id, $comprobante, $empresa);
            }
            if ($accion === 'desconciliar') {
                return banco_desconciliar($pdo, $id, $empresa);
            }
            return responder(['errores' => ['Acción desconocida.']], 400);
        case 'DELETE':
            return $id && banco_eliminar($pdo, $id, $empresa) ? ['eliminado' => $id] : responder(['errores' => ['El movimiento no existe.']], 404);
    }
    header('Allow: GET, POST, DELETE');
    responder(['errores' => ['Método no permitido.']], 405);
});
