<?php
// GET  empresas.php                 empresas que puede ver el usuario: todas si es administrador, si no las asignadas
// GET  empresas.php?exportar=ID     descarga la empresa con todos sus datos (archivo JSON)
// POST empresas.php {razon_social, rut, giro, regimen, telefono, email, plan_ejemplo}  crea una empresa
// POST empresas.php {formato: "icontador-empresa", ...}  importa una empresa exportada
declare(strict_types=1);
require_once __DIR__ . '/lib/sesion.php';
require __DIR__ . '/lib/empresa-paquete.php';

// No usa ejecutar(): esta pantalla debe funcionar aunque la empresa elegida en el navegador ya no exista.
try {
    $db = icontador_db();
    empresas_esquema_completo($db);
    $usuario = auth_actual();
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            if (isset($_GET['exportar'])) {
                $paquete = (empresa_usuario_puede($db, $usuario, (int) $_GET['exportar']) ? empresa_exportar($db, (int) $_GET['exportar']) : null)
                    ?? responder(['errores' => ['La empresa no existe.']], 404);
                $nombre = preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $paquete['empresa']['razon_social']) ?: 'empresa');
                header('Content-Disposition: attachment; filename="empresa-' . trim($nombre, '-') . '.json"');
                responder($paquete);
            }
            $empresas = empresas_listar($db);
            if (!empresa_es_admin($usuario)) {
                $asignadas = empresas_de_usuario($db, (int) $usuario['id']);
                $empresas = array_values(array_filter($empresas, fn($e) => in_array($e['id'], $asignadas, true)));
            }
            responder(['empresas' => $empresas, 'puede_administrar' => empresa_es_admin($usuario)]);
        case 'POST':
            $datos = cuerpoJson();
            responder(isset($datos['formato']) ? empresa_importar($db, $datos, $usuario) : empresa_crear($db, $datos, $usuario), 201);
        case 'PUT':
        case 'PATCH':
            auth_exigir_api(true);
            $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) throw new ErrorValidacion(['Identificador de empresa inválido.']);
            $datos = cuerpoJson();
            if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
                responder(['empresa' => empresa_actualizar($db, (int) $id, $datos)]);
            }
            responder(['empresa' => empresa_cambiar_estado($db, (int) $id, (string) ($datos['estado'] ?? ''))]);
    }
    header('Allow: GET, POST, PUT, PATCH');
    responder(['errores' => ['Método no permitido.']], 405);
} catch (ErrorValidacion $e) {
    responder(['errores' => $e->errores], 422);
} catch (Throwable $e) {
    error_log('empresas.php: ' . $e);
    responder(['errores' => ['No se pudo leer la base de datos.']], 500);
}
