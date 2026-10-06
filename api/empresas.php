<?php
// GET  empresas.php                 empresas de la base (con RUT, régimen y contacto cuando existen)
// GET  empresas.php?exportar=ID     descarga la empresa con todos sus datos (archivo JSON)
// POST empresas.php {razon_social, rut, giro, regimen, telefono, email, plan_ejemplo}  crea una empresa
// POST empresas.php {formato: "icontador-empresa", ...}  importa una empresa exportada
// POST empresas.php?importar=ficha-csv  (cuerpo: el CSV "Ficha de Empresas" de iContador)  crea o actualiza empresas por RUT; solo administradores
declare(strict_types=1);
require_once __DIR__ . '/lib/sesion.php';
require __DIR__ . '/lib/empresas-csv.php';

// No usa ejecutar(): esta pantalla debe funcionar aunque la empresa elegida en el navegador ya no exista.
try {
    $db = icontador_db();
    empresas_esquema_completo($db);
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            if (isset($_GET['exportar'])) {
                $paquete = empresa_exportar($db, (int) $_GET['exportar']) ?? responder(['errores' => ['La empresa no existe.']], 404);
                $nombre = preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $paquete['empresa']['razon_social']) ?: 'empresa');
                header('Content-Disposition: attachment; filename="empresa-' . trim($nombre, '-') . '.json"');
                responder($paquete);
            }
            responder(['empresas' => empresas_listar($db)]);
        case 'POST':
            if (($_GET['importar'] ?? '') === 'ficha-csv') {
                auth_exigir_api(true);
                $csv = file_get_contents('php://input', false, null, 0, 5_000_001) ?: '';
                if ($csv === '' || strlen($csv) > 5_000_000) {
                    responder(['errores' => ['Adjunte el archivo CSV de la Ficha de Empresas (máximo 5 MB).']], 422);
                }
                responder(empresas_importar_ficha_csv($db, $csv));
            }
            $datos = cuerpoJson();
            responder(isset($datos['formato']) ? empresa_importar($db, $datos) : empresa_crear($db, $datos), 201);
    }
    header('Allow: GET, POST');
    responder(['errores' => ['Método no permitido.']], 405);
} catch (ErrorValidacion $e) {
    responder(['errores' => $e->errores], 422);
} catch (Throwable $e) {
    error_log('empresas.php: ' . $e);
    responder(['errores' => ['No se pudo leer la base de datos.']], 500);
}
