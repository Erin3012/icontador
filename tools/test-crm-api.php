<?php
// Prueba del CRM (contactos, interacciones, oportunidades, tareas y dashboard): php tools/test-crm-api.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/crm.php';

function check(bool $ok, string $msg): void {
    if (!$ok) {
        fwrite(STDERR, "FALLA: $msg\n");
        exit(1);
    }
    echo "✓ $msg\n";
}
function rechaza(callable $f, string $fragmento = ''): bool {
    try { $f(); return false; }
    catch (CrmError $e) { return $fragmento === '' || str_contains($e->getMessage(), $fragmento); }
}
function en(int $dias): string { return date('Y-m-d', strtotime(($dias >= 0 ? '+' : '') . "$dias days")); }

$db = base_prueba();
CRM::crearEsquema($db);
CRM::crearEsquema($db); // idempotente
$c = new CRM($db, 1);
$otra = new CRM($db, 2);

// --- Contactos
check(rechaza(fn() => $c->crearContacto(''), 'nombre'), 'rechaza nombre vacío');
check(rechaza(fn() => $c->crearContacto(['x']), 'texto'), 'rechaza un arreglo como nombre');
check(rechaza(fn() => $c->crearContacto('Ana', 'amigo'), 'tipo'), 'rechaza tipo de contacto desconocido');
check(rechaza(fn() => $c->crearContacto('Ana', 'cliente', 'no-es-correo'), 'correo'), 'rechaza correo inválido');
check(rechaza(fn() => $c->crearContacto('Ana', 'cliente', null, 'abc'), 'teléfono'), 'rechaza teléfono con letras');
check(rechaza(fn() => $c->crearContacto(str_repeat('x', 256)), 'nombre'), 'rechaza nombre demasiado largo');
check((int) $db->query('SELECT COUNT(*) FROM crm_contactos')->fetchColumn() === 0, 'los datos inválidos no dejan contactos');

$ana = $c->crearContacto('Ana <b>Pérez</b>', 'cliente', 'ana@ejemplo.cl', '+56 9 1234 5678', 'Ana SpA');
$beto = $c->crearContacto('Beto', 'prospecto');
$carla = $c->crearContacto('Carla', 'cliente', '', '', '');
$ajeno = $otra->crearContacto('Contacto de otra empresa', 'cliente');
check($ana > 0 && $beto > $ana && $carla > $beto, 'crea contactos (campos opcionales vacíos aceptados)');
$todos = $c->listarContactos();
check(count($todos) === 3 && $todos[0]['nombre'] === 'Carla' && $todos[0]['email'] === null, 'lista todos los contactos de la empresa (el más reciente primero cuando nadie tiene interacciones)');
check(count($c->listarContactos('cliente')) === 2 && count($c->listarContactos('prospecto')) === 1 && count($c->listarContactos('todos')) === 3, 'filtra por tipo');
check(count($otra->listarContactos()) === 1, 'una empresa no ve los contactos de otra');
check(rechaza(fn() => $c->listarContactos('extraterrestre'), 'tipo'), 'tipo de listado inválido');
check(rechaza(fn() => $c->listarContactos('cliente', 'borrado'), 'estado'), 'estado de listado inválido');

// --- Interacciones (aislamiento: antes se podía registrar con un contacto de otra empresa)
check(rechaza(fn() => $c->registrarInteraccion($ajeno, 'llamada', 'Hola'), 'no existe'), 'no se puede registrar una interacción con un contacto de otra empresa');
check(rechaza(fn() => $c->registrarInteraccion(9999, 'llamada', 'Hola'), 'no existe'), 'contacto inexistente');
check(rechaza(fn() => $c->registrarInteraccion([$ana, 1], 'llamada', 'Hola'), 'no es válido'), 'un id de contacto en arreglo se rechaza (antes (int) lo resolvía a 1)');
check(rechaza(fn() => $c->registrarInteraccion("{$ana}abc", 'llamada', 'Hola'), 'no es válido'), 'un id de contacto con basura se rechaza');
check(rechaza(fn() => $c->registrarInteraccion($ana, 'telepatia', 'Hola'), 'tipo'), 'tipo de interacción inválido');
check(rechaza(fn() => $c->registrarInteraccion($ana, 'llamada', ''), 'descripción'), 'la descripción es obligatoria');
check(rechaza(fn() => $c->registrarInteraccion($ana, 'llamada', 'Hola', null, 'mucho'), 'duración'), 'duración inválida');
check(rechaza(fn() => $c->registrarInteraccion($ana, 'llamada', 'Hola', null, 5000), 'duración'), 'duración mayor a un día');
check((int) $db->query('SELECT COUNT(*) FROM crm_interacciones')->fetchColumn() === 0, 'las interacciones rechazadas no dejan filas');
$i1 = $c->registrarInteraccion($ana, 'llamada', 'Primera llamada', 'Interesada', 15);
$i2 = $c->registrarInteraccion($ana, 'email', 'Envío de propuesta');
check($i1 > 0 && $i2 > $i1, 'registra interacciones');
$ultima = $db->query("SELECT ultima_interaccion FROM crm_contactos WHERE id = $ana")->fetchColumn();
check($ultima === date('Y-m-d'), 'actualiza la última interacción del contacto con la fecha de hoy');
check($c->listarContactos()[0]['id'] === $ana, 'los contactos con interacción reciente van primero y los que no tienen, al final');
$hist = $c->listarInteracciones($ana);
check(count($hist) === 2 && $hist[0]['descripcion'] === 'Envío de propuesta' && $hist[1]['duracion_minutos'] === 15 && $hist[1]['resultado'] === 'Interesada', 'historial de interacciones, la más reciente primero');
check(rechaza(fn() => $c->listarInteracciones($ajeno), 'no existe'), 'no se ve el historial de un contacto de otra empresa');

// --- Oportunidades
check(rechaza(fn() => $c->crearOportunidad($ajeno, 'Venta', 1000), 'no existe'), 'no se puede crear una oportunidad con un contacto de otra empresa');
check(rechaza(fn() => $c->crearOportunidad($ana, '', 1000), 'nombre'), 'el nombre de la oportunidad es obligatorio');
check(rechaza(fn() => $c->crearOportunidad($ana, 'Venta', -5), 'monto'), 'monto negativo');
check(rechaza(fn() => $c->crearOportunidad($ana, 'Venta', '12.5'), 'monto'), 'monto con decimales');
check(rechaza(fn() => $c->crearOportunidad($ana, 'Venta', 1000, '2026-02-30'), 'fecha'), 'fecha de cierre inexistente');
check(rechaza(fn() => $c->crearOportunidad($ana, 'Venta', 1000, en(5), 'ganada'), 'etapa'), 'etapa desconocida');
check((int) $db->query('SELECT COUNT(*) FROM crm_oportunidades')->fetchColumn() === 0, 'las oportunidades rechazadas no dejan filas');
$o1 = $c->crearOportunidad($ana, 'Software contable', 1000000, en(20), 'propuesta');
$o2 = $c->crearOportunidad($beto, 'Consultoría', '500000', en(10));
$otra->crearOportunidad($ajeno, 'Oportunidad ajena', 9000000);
$abiertas = $c->obtenerOportunidadesAbiertas();
check(count($abiertas) === 2 && $abiertas[0]['nombre'] === 'Consultoría' && $abiertas[1]['nombre'] === 'Software contable', 'lista oportunidades abiertas, la que cierra antes primero');
check($abiertas[1]['probabilidad'] === 50 && $abiertas[1]['valor_esperado'] === 500000 && $abiertas[0]['probabilidad'] === 10 && $abiertas[0]['valor_esperado'] === 50000, 'la probabilidad sale de la etapa y el valor esperado es monto x probabilidad');
check($abiertas[1]['contacto_nombre'] === 'Ana <b>Pérez</b>', 'incluye el nombre del contacto');
check(count($otra->obtenerOportunidadesAbiertas()) === 1, 'una empresa no ve las oportunidades de otra');

$c->cambiarEtapa($o2, 'negociacion');
$despues = array_values(array_filter($c->obtenerOportunidadesAbiertas(), fn($o) => $o['id'] === $o2))[0];
check($despues['etapa'] === 'negociacion' && $despues['probabilidad'] === 75 && $despues['valor_esperado'] === 375000, 'cambiar la etapa actualiza la probabilidad');
check(rechaza(fn() => $c->cambiarEtapa($o2, 'inventada'), 'etapa'), 'etapa inválida al cambiar');
check(rechaza(fn() => $otra->cambiarEtapa($o2, 'cierre'), 'no existe'), 'una empresa no puede cambiar la etapa de oportunidades de otra');
check(rechaza(fn() => $c->cerrarOportunidad($o1, 'empate'), 'ganada o perdida'), 'resultado de cierre inválido');
$c->cerrarOportunidad($o1, 'ganada', 'Firmó contrato');
$cerrada = $db->query("SELECT estado, probabilidad, fecha_cierre_real, motivo_cierre FROM crm_oportunidades WHERE id = $o1")->fetch(PDO::FETCH_ASSOC);
check($cerrada === ['estado' => 'ganada', 'probabilidad' => 100, 'fecha_cierre_real' => date('Y-m-d'), 'motivo_cierre' => 'Firmó contrato'], 'cerrar como ganada registra estado, fecha y motivo');
check(count($c->obtenerOportunidadesAbiertas()) === 1, 'una oportunidad cerrada deja de estar abierta');
check(rechaza(fn() => $c->cerrarOportunidad($o1, 'perdida'), 'cerrada') && rechaza(fn() => $c->cambiarEtapa($o1, 'cierre'), 'cerrada'), 'una oportunidad cerrada no se puede cerrar ni mover de nuevo');
check(rechaza(fn() => $otra->cerrarOportunidad($o2, 'perdida'), 'no existe'), 'una empresa no puede cerrar oportunidades de otra');
check(rechaza(fn() => $c->cerrarOportunidad([$o2, 1], 'perdida'), 'no es válida'), 'id de oportunidad en arreglo se rechaza');

// Etapa en arreglo/objeto/número: error de dominio, no TypeError (antes isset() sobre un arreglo daba 500)
foreach ([['x'], [], 1.5, 7, true, null] as $mala) {
    check(rechaza(fn() => $c->cambiarEtapa($o2, $mala), 'etapa') && rechaza(fn() => $c->crearOportunidad($ana, 'Venta', 1000, en(5), $mala), 'etapa'), 'etapa mal formada (' . json_encode($mala) . ') se rechaza como error de dominio');
}
$c->cambiarEtapa($o2, 'negociacion'); // misma etapa otra vez: no falla
check($db->query("SELECT etapa FROM crm_oportunidades WHERE id = $o2")->fetchColumn() === 'negociacion', 'reenviar la misma etapa a una oportunidad abierta no es un error y la deja igual');
// Un monto heredado enorme (sin validar en el esquema anterior) no tumba el listado
$db->exec("INSERT INTO crm_oportunidades (contacto_id, empresa_id, nombre, monto_estimado, etapa, probabilidad, fecha_cierre_estimada, creado_en, actualizado_en)
    VALUES ($ana, 1, 'Heredada', 9000000000000000000, 'propuesta', 50, '" . en(40) . "', 'x', 'x')");
$conHeredada = $c->obtenerOportunidadesAbiertas();
check(count($conHeredada) === 2 && end($conHeredada)['nombre'] === 'Heredada' && end($conHeredada)['valor_esperado'] > 0, 'una fila heredada con monto enorme no rompe el listado de oportunidades');
check($c->obtenerDashboard()['oportunidades_abiertas'] === 2, 'ni el dashboard: cuenta también la oportunidad heredada');
$db->exec("DELETE FROM crm_oportunidades WHERE nombre = 'Heredada'");

// --- Tareas
check(rechaza(fn() => $c->crearTarea($ajeno, 'Llamar', en(1)), 'no existe'), 'no se puede crear una tarea para un contacto de otra empresa');
check(rechaza(fn() => $c->crearTarea($ana, '', en(1)), 'descripción'), 'la descripción de la tarea es obligatoria');
check(rechaza(fn() => $c->crearTarea($ana, 'Llamar', ''), 'fecha'), 'la fecha de vencimiento es obligatoria');
check(rechaza(fn() => $c->crearTarea($ana, 'Llamar', en(1), 'urgente'), 'prioridad'), 'prioridad inválida');
check(rechaza(fn() => $c->crearTarea($ana, 'Llamar', en(1), 'alta', 'brujeria'), 'tipo'), 'tipo de tarea inválido');
$t1 = $c->crearTarea($ana, 'Enviar cotización', en(3), 'baja');
$t2 = $c->crearTarea($beto, 'Llamar a Beto', en(3), 'alta', 'llamada');
$t3 = $c->crearTarea($ana, 'Renovar contrato', en(-2), 'media');
$otra->crearTarea($ajeno, 'Tarea ajena', en(1));
$tareas = $c->obtenerTareasPendientes();
check(array_column($tareas, 'id') === [$t3, $t2, $t1], 'tareas ordenadas por vencimiento y, a igual fecha, alta antes que baja (antes se ordenaba alfabéticamente)');
check($tareas[0]['vencida'] === true && $tareas[1]['vencida'] === false && $tareas[0]['contacto_nombre'] === 'Ana <b>Pérez</b>', 'marca las tareas vencidas e incluye el contacto');
check(count($otra->obtenerTareasPendientes()) === 1, 'una empresa no ve las tareas de otra');
check(rechaza(fn() => $otra->completarTarea($t1), 'no existe'), 'una empresa no puede completar tareas de otra');
$c->completarTarea($t1);
check(count($c->obtenerTareasPendientes()) === 2, 'una tarea completada deja de estar pendiente');
check(rechaza(fn() => $c->completarTarea($t1), 'pendiente') && rechaza(fn() => $c->completarTarea('1abc'), 'no es válida') && rechaza(fn() => $c->completarTarea(9999), 'no existe'), 'no se completa dos veces; ids inválidos o inexistentes se rechazan');

// --- Dashboard
$d = $c->obtenerDashboard();
check($d['clientes_totales'] === 2 && $d['oportunidades_abiertas'] === 1 && $d['valor_esperado'] === 375000, 'dashboard: clientes, oportunidades abiertas y valor esperado');
check($d['tareas_pendientes'] === 2 && $d['tareas_vencidas'] === 1 && $d['interacciones_hoy'] === 2, 'dashboard: tareas pendientes y vencidas e interacciones de hoy');
check(count($d['oportunidades']) === 1 && count($d['tareas']) === 2, 'dashboard: incluye los listados');
$vacio = (new CRM($db, 99))->obtenerDashboard();
check($vacio['clientes_totales'] === 0 && $vacio['valor_esperado'] === 0 && $vacio['tareas'] === [] && $vacio['oportunidades'] === [], 'empresa sin datos: dashboard en cero');

echo "CRM: contactos, interacciones, oportunidades, tareas y aislamiento entre empresas verificados ✓\n";
