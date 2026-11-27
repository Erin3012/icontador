<?php
// Verificación integral de compatibilidad MySQL en todas las fases de Cifrax Tributario (Etapas 1 a 10)
// y módulos de apoyo (Notificaciones, CRM, Inventario). Uso: php tools/test-ctrib-mysql-esquema.php
declare(strict_types=1);
require __DIR__ . '/base-prueba.php';
require dirname(__DIR__) . '/api/lib/ctrib-pdf.php';
require dirname(__DIR__) . '/api/lib/ctrib-inicio.php';
require dirname(__DIR__) . '/api/lib/notificaciones.php';
require dirname(__DIR__) . '/api/lib/crm.php';
require dirname(__DIR__) . '/api/lib/inventario.php';

function check(bool $ok, string $msg): void
{
    if (!$ok) {
        fwrite(STDERR, "FALLA: $msg\n");
        exit(1);
    }
}

// 1. Paridad exacta entre api/schema/ctrib.mysql.sql y api/lib/ctrib.php::ctrib_schema (22 tablas)
$sqlMysql = file_get_contents(dirname(__DIR__) . '/api/schema/ctrib.mysql.sql');
$phpCtrib = file_get_contents(dirname(__DIR__) . '/api/lib/ctrib.php');

$extraerTablas = function (string $texto): array {
    $tablas = [];
    preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s*\((.*?)\)\s*(?:\$motor|ENGINE\s*=)/si', $texto, $m, PREG_SET_ORDER);
    foreach ($m as $coinc) {
        $nombre = $coinc[1];
        $lineas = [];
        foreach (preg_split('/\R/', $coinc[2]) ?: [] as $l) {
            $limpia = trim(rtrim(trim($l), ','));
            if ($limpia === '' || str_starts_with($limpia, '--') || str_starts_with($limpia, '//')) {
                continue;
            }
            $limpia = str_replace(['$id', '$snapshot'], ['INT AUTO_INCREMENT PRIMARY KEY', 'MEDIUMTEXT NULL'], $limpia);
            $limpia = preg_replace('/\s+/', ' ', $limpia) ?? $limpia;
            $lineas[] = $limpia;
        }
        $tablas[$nombre] = $lineas;
    }
    return $tablas;
};

$tablasSql = $extraerTablas($sqlMysql);
$tablasPhp = $extraerTablas($phpCtrib);

check(count($tablasPhp) === 22, 'ctrib.php define las 22 tablas de las fases 1 a 10');
check(array_keys($tablasSql) !== [], 'ctrib.mysql.sql tiene tablas válidas');
foreach ($tablasPhp as $tabla => $colsPhp) {
    check(isset($tablasSql[$tabla]), "ctrib.mysql.sql debe incluir la tabla $tabla");
    check($tablasSql[$tabla] === $colsPhp, "ctrib.mysql.sql y ctrib.php coinciden exactamente en columnas/claves de $tabla");
}
foreach (array_keys($tablasSql) as $tabla) {
    check(isset($tablasPhp[$tabla]), "ctrib.mysql.sql no tiene tablas huérfanas ($tabla)");
}

// 2. Anchos MySQL suficientes para constantes de todas las etapas (incluyendo PERSONA_NATURAL y R15/R20/L57/F22/R1)
check(str_contains(implode("\n", $tablasSql['ctrib_empresa_config']), "tipo VARCHAR(20)"), 'ctrib_empresa_config.tipo permite PERSONA_NATURAL (15+ chars)');
foreach (CTRIB_TIPOS_CONTRIBUYENTE as $t) {
    check(strlen($t) <= 20, "Tipo $t cabe en VARCHAR(20)");
}
foreach (CTRIB_REGIMENES as $r) {
    check(strlen($r) <= 12, "Régimen $r cabe en VARCHAR(12)");
}
foreach (CTRIB_MODOS_RETIROS as $modo) {
    check(strlen($modo) <= 12, "Modo retiros $modo cabe en VARCHAR(12)");
}
foreach (CTRIB_14A_RTRE_FILAS as $f) {
    check(strlen('A:' . $f['fila']) <= 12, "Fila R15 A:{$f['fila']} cabe en VARCHAR(12)");
}
foreach (ctrib_14a_todas_columnas() as $col) {
    check(strlen($col) <= 60, "Columna R15/R16 $col cabe en VARCHAR(60)");
}
foreach (CTRIB_R21_COLUMNAS as [, $colsRtre]) {
    foreach ($colsRtre as $col) {
        check(strlen($col) <= 60, "Columna R20/R21 $col cabe en VARCHAR(60)");
    }
}
check(str_contains(implode("\n", $tablasSql['ctrib_recuadros']), 'snapshot MEDIUMTEXT NULL'), 'ctrib_recuadros.snapshot usa MEDIUMTEXT en MySQL');

// 3. Prueba funcional sobre base_prueba() (SQLite y MySQL si ICONTADOR_TEST_MYSQL está activo)
$pdo = base_prueba();
empresas_schema($pdo);
crearEsquema($pdo);
ctrib_schema($pdo);
sii_schema($pdo);

if (es_mysql($pdo)) {
    // Ejecuta también el archivo .mysql.sql y valida anchos reales en information_schema
    $pdo->exec($sqlMysql);
    $tipoLen = (int) $pdo->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ctrib_empresa_config' AND COLUMN_NAME = 'tipo'")->fetchColumn();
    check($tipoLen >= 20, 'En MySQL real, ctrib_empresa_config.tipo >= 20');
    $snapType = strtolower((string) $pdo->query("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ctrib_recuadros' AND COLUMN_NAME = 'snapshot'")->fetchColumn());
    check($snapType === 'mediumtext', 'En MySQL real, ctrib_recuadros.snapshot es mediumtext');

    // Simula tabla antigua MySQL con columnas estrechas y comprueba auto-migración en ctrib_schema()
    $pdo->exec("ALTER TABLE ctrib_empresa_config MODIFY tipo VARCHAR(12) NOT NULL DEFAULT ''");
    $pdo->exec("INSERT INTO ctrib_empresa_config (empresa_id, tipo, regimen, usuario, actualizado) VALUES (9999, 'PERSONA_NATU', 'PERSONA', 'mig', '2026-01-01')");
    $pdo->exec('ALTER TABLE ctrib_recuadros MODIFY snapshot TEXT NULL');
    ctrib_schema($pdo);
    $recuperado = $pdo->query('SELECT tipo FROM ctrib_empresa_config WHERE empresa_id = 9999')->fetchColumn();
    check($recuperado === 'PERSONA_NATURAL', 'ctrib_schema recupera PERSONA_NATU -> PERSONA_NATURAL al migrar');
}

// Verifica guardado/lectura real de PERSONA_NATURAL + DJ 1847 (ctrib_ifrs_cuentas) + valores
$pdo->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')
    ->execute(['mysql-pn', 'PERSONA PRUEBA', json_encode(['rut' => '11111111-1']), '2026-11-27']);
$ePn = (int) $pdo->lastInsertId();
ctrib_empresa_config_guardar($pdo, $ePn, 'PERSONA_NATURAL', 'PERSONA', 'prueba');
check(ctrib_empresa_config($pdo, $ePn)['tipo'] === 'PERSONA_NATURAL', 'Guarda y lee tipo PERSONA_NATURAL completo');

$pdo->prepare('INSERT INTO empresas (origen_id, razon_social, datos_json, actualizado) VALUES (?, ?, ?, ?)')
    ->execute(['mysql-14a', 'EMPRESA 14A PRUEBA', json_encode(['rut' => '76111222-3']), '2026-11-27']);
$e14a = (int) $pdo->lastInsertId();
ctrib_empresa_config_guardar($pdo, $e14a, 'SPA', '14A', 'prueba');
ctrib_mapeo_guardar($pdo, $e14a, ['1' => 'ACTIVO', '3' => 'CAPITAL', '5' => 'GANANCIA'], 'prueba');
ctrib_balance_guardar($pdo, $e14a, 2026, 'excel', [
    ['codigo' => '101001', 'nombre' => 'CAJA', 'debitos' => 1000000, 'creditos' => 0],
    ['codigo' => '501001', 'nombre' => 'VENTAS', 'debitos' => 0, 'creditos' => 1000000],
], str_repeat('balance_', 40) . '.xlsx', str_repeat('u', 150));
$codIfrs = (string) (array_keys(ctrib_ifrs_catalogo(false))[1] ?? array_key_first(ctrib_ifrs_catalogo(false)));
$dj = ctrib_dj1847_guardar_cuenta($pdo, $e14a, 2026, '101001', $codIfrs, 'prueba');
check(array_column($dj['cuentas'], 'codigoIfrs', 'codigo')['101001'] === $codIfrs, 'Persiste y lee ctrib_ifrs_cuentas');

// 4. Notificaciones, CRM e Inventario compatibles con SQLite y MySQL PDO
Notificaciones::crearEsquema($pdo);
$notif = new Notificaciones($pdo, $e14a);
check($notif->crearNotificacion(1, 'alerta', 'Prueba', 'Mensaje') > 0, 'Crea notificación');
check(count($notif->obtenerNotificacionesPendientes(1)) === 1, 'Lee notificaciones pendientes sin TypeError de PDO::query');
check($notif->actualizarPreferencias(1, ['notificaciones_email' => true, 'resumen_diario' => true]), 'Inserta preferencias de notificación');
check($notif->actualizarPreferencias(1, ['notificaciones_email' => false, 'resumen_diario' => false]), 'Actualiza preferencias existentes (upsert)');
$prefs = $notif->obtenerPreferencias(1);
check((int) $prefs['notificaciones_email'] === 0, 'Lee preferencias actualizadas');

CRM::crearEsquema($pdo);
$crm = new CRM($pdo, $e14a);
$cid = $crm->crearContacto('Cliente Prueba', 'cliente', 'cliente@ejemplo.cl');
check($cid > 0, 'Crea contacto CRM');
$crm->registrarInteraccion($cid, 'llamada', 'Seguimiento');
$dash = $crm->obtenerDashboard();
check((int) ($dash['clientes_totales'] ?? 0) === 1 && (int) ($dash['interacciones_hoy'] ?? 0) === 1, 'Dashboard CRM compatible SQLite/MySQL');
check(count($crm->listarContactos()) === 1, 'Lista contactos sin sintaxis NULLS LAST exclusiva de SQLite/PG');

inventario_schema($pdo);
$resInv = inventario_agregar($pdo, $e14a, ['codigo' => 'INV-M1', 'descripcion' => 'Equipo', 'fecha_ingreso' => '']);
check((int) ($resInv['id'] ?? 0) > 0, 'Inventario admite fecha_ingreso vacía convirtiéndola a NULL');

echo "test-ctrib-mysql-esquema OK\n";
