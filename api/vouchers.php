<?php
// GET    vouchers.php[?desde=&hasta=&numero=]  lista de vouchers
// GET    vouchers.php?id=N                    un voucher
// POST   vouchers.php                         crea (JSON con tipo, fecha, registro, glosa, lineas)
// PUT    vouchers.php?id=N                    reemplaza
// DELETE vouchers.php?id=N                    elimina
declare(strict_types=1);
require_once __DIR__ . '/lib/sesion.php';
require __DIR__ . '/lib/vouchers.php';

ejecutar(function (PDO $pdo, int $empresa) {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : null;
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            if ($id === null) {
                return listarVouchers($pdo, $_GET, $empresa);
            }
            return obtenerVoucher($pdo, $id, $empresa) ?? responder(['errores' => ['El voucher no existe.']], 404);
        case 'POST':
            return guardarVoucher($pdo, cuerpoJson(), null, $empresa);
        case 'PUT':
            return $id ? guardarVoucher($pdo, cuerpoJson(), $id, $empresa) : responder(['errores' => ['Falta el id del voucher.']], 400);
        case 'DELETE':
            return $id && eliminarVoucher($pdo, $id, $empresa) ? ['eliminado' => $id] : responder(['errores' => ['El voucher no existe.']], 404);
    }
    header('Allow: GET, POST, PUT, DELETE');
    responder(['errores' => ['Método no permitido.']], 405);
});
