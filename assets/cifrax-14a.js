// Maqueta del régimen Semi Integrado (Art. 14 A): estructura de los recuadros, sin cálculos ni datos.
// Códigos y glosas transcritos del video tutorial de iTributario; deben validarse con el contador antes de calcular.
// El selector de régimen de la cabecera cambia solo la vista (?regimen=14A|14D3|ESP); por defecto, el configurado en la empresa.
// ESP (fundación / régimen especial, no sujeta al 14 A): recuadros 12 y 14 con lo declarado en el F22 que trajo el agente.
(function () {
    'use strict';
    var parametros = new URLSearchParams(window.location.search);
    var elegida = parametros.get('regimen') || (parametros.get('maqueta') === '14A' ? '14A' : '');
    var selector = document.getElementById('cf-regimen');
    var activa = false;
    var empresaActiva = null;
    try { empresaActiva = JSON.parse(localStorage.getItem('icontador.empresaSeleccionada') || 'null'); } catch (e) { empresaActiva = null; }
    var empresaId = parametros.get('empresa_id') || (empresaActiva && empresaActiva.id) || '';
    if (selector) selector.add(new Option('Fundación / régimen especial (no sujeta al 14 A)', 'ESP'));

    // [glosa, código, signo, origen]. Origen 'E' = se escribe a mano; otro texto = de dónde viene. Un solo elemento = sección.
    var R12 = [
        ['Resultado financiero'],
        ['Ingresos del giro percibidos o devengados', 1657, '+', 'Cuentas'], ['Rentas de fuente extranjera', 1658, '+', 'Cuentas'],
        ['Intereses percibidos o devengados', 1659, '+', 'Cuentas'], ['Otros ingresos percibidos o devengados', 1660, '+', 'Cuentas'],
        ['Costo directo de los bienes y servicios', 1661, '−', 'Cuentas'], ['Remuneraciones', 1662, '−', 'Cuentas'],
        ['Arriendos', 1140, '−', 'Cuentas'], ['Depreciación financiera del ejercicio', 1663, '−', 'Cuentas'],
        ['Intereses pagados o adeudados', 1664, '−', 'Cuentas'], ['Gastos por donaciones', 1665, '−', 'Cuentas'],
        ['Otros gastos financieros', 1666, '−', 'Cuentas'],
        ['Gastos por inversión privada en investigación y desarrollo certificados por CORFO', 1667, '−', 'Cuentas'],
        ['Gastos por inversión privada en investigación y desarrollo no certificados por CORFO', 1668, '−', 'Cuentas'],
        ['Gastos por exigencias medioambientales', 1141, '−', 'Cuentas'], ['Indemnización o compensación a clientes/usuarios', 1142, '−', 'Cuentas'],
        ['Costos y gastos necesarios para producir las rentas de fuente extranjera', 1669, '−', 'Cuentas'],
        ['Gastos por impuesto renta e impuesto diferido', 1670, '−', 'Cuentas'], ['Otros gastos deducidos de los ingresos brutos', 1671, '−', 'Cuentas'],
        ['Resultado financiero (según balance al 31.12)', 1672, '='],
        ['Ajustes al resultado financiero'],
        ['Corrección monetaria saldo deudor (art. 32 N°1 LIR)', 1673, '−', 'E'], ['Corrección monetaria saldo acreedor (art. 32 N°2 LIR)', 1674, '+', 'E'],
        ['Partidas inc. 1° no afectas al IU 40% e inc. 2° art. 21 LIR, reajustados', 1144, '+', 'E'],
        ['Depreciación financiera del ejercicio', 1675, '+', 'Cuentas'],
        ['Estimación y/o castigos de deudas incobrables (criterio financiero)', 1175, '+', 'E'],
        ['Rentas tributables no reconocidas financieramente', 1676, '+', 'E'], ['Gastos agregados por donaciones', 1677, '+', 'Cuentas'],
        ['Gastos que se agregan a la RLI según art. 33 N°1 LIR', 1678, '+', 'E'], ['Ingreso diferido por cambio de régimen', 1150, '+', 'E'],
        ['Costos y gastos asociados a ingresos no renta, generados', 1147, '+', 'E'],
        ['Proporcionalidad gastos imputados a INR y/o rentas exentas', 1148, '+', 'E'],
        ['Intereses devengados por inversiones en bonos art. 104 LIR', 1149, '+', 'E'], ['Ingresos devengados por cambio de régimen', 1151, '+', 'E'],
        ['Ajustes de precios de transferencia, art. 41 E LIR', 1991, '+', 'E'], ['Gastos adeudados por cambio de régimen', 1152, '−', 'E'],
        ['Castigo de deudas incobrables, art. 31 inc. 4° N°4 LIR', 1176, '−', 'E'], ['Depreciación tributaria del ejercicio', 1679, '−', 'E'],
        ['Gasto goodwill tributario del ejercicio', 1680, '−', 'E'], ['Impuesto específico a la actividad minera', 1681, '−', 'E'],
        ['Componente ad valorem del Royalty Minero (Ley 21.591 art. 2)', 1974, '−', 'E'],
        ['Componente del margen del Royalty Minero (Ley 21.591 art. 3 o 4)', 1975, '−', 'E'],
        ['Gastos rechazados afectos a tributación art. 21 inc. 1° LIR', 1682, '−', 'E'],
        ['Gastos rechazados afectos a tributación art. 21 inc. 3° LIR', 1683, '−', 'E'],
        ['Otras partidas', 1684, '−', 'E'], ['Rentas exentas IDPC (art. 33 N°2 LIR)', 1685, '−', 'E'],
        ['Dividendos y/o utilidades sociales percibidos o devengados (art. 33 N°2 LIR)', 1686, '−', 'E'],
        ['Dividendos y/o utilidades sociales (art. 33 N°2 LIR), ingresos no renta', 1183, '−', 'E'],
        ['Gastos aceptados por donaciones', 1687, '−', 'E'], ['Ingresos no renta, generados (art. 17 LIR)', 1688, '−', 'E'],
        ['Pérdidas de ejercicios anteriores (art. 31 N°3 LIR)', 1689, '−', 'E'],
        ['Renta líquida imponible antes de rebaja por incentivo al ahorro y/o IDPC voluntario, o pérdida tributaria', 1728, '='],
        ['Renta líquida imponible'],
        ['Incentivo al ahorro según art. 14 letra E) LIR', 1154, '−', 'E'],
        ['Base del IDPC voluntario (art. 14 A N°6 y art. 42° transitorio Ley 21.210)', 1157, '−', 'E'],
        ['Renta Líquida Imponible afecta a IDPC o pérdida tributaria', 1690, '=']
    ];
    var R13 = [
        ['CPT positivo final (recuadro N°14)', 1698, '+', 'R14'], ['CPT negativo final (recuadro N°14)', 1717, '−', 'R14'],
        ['Saldo negativo del registro REX al término del ejercicio', 1692, '+', 'RTRE'],
        ['Remesas, retiros o dividendos repartidos en el ejercicio, reajustados', 1699, '+', 'Retiros'], ['Subtotal', 1718, '='],
        ['Saldo positivo del registro REX al término del ejercicio, antes de imputaciones', 1693, '−', 'RTRE'],
        ['Capital aportado debidamente reajustado (incluye aumentos y disminuciones efectivas)', 844, '−', 'E'],
        ['Saldo FUR (cuando no haya sido considerado dentro del valor del capital aportado a la empresa)', 982, '−', 'E'],
        ['Sobreprecio obtenido en la colocación de acciones de propia emisión, debidamente reajustado', 1198, '−', 'E'],
        ['Rentas afectas a IGC o IA (RAI) del ejercicio', 1199, '=']
    ];
    var R14 = [
        ['CPT positivo inicial', 1145, '+', 'E'], ['CPT negativo inicial', 1146, '−', 'E'],
        ['Corrección monetaria del Capital Propio Tributario inicial', 1177, '+', 'Automático'],
        ['Aumentos (efectivos) de capital del ejercicio, actualizados (varios movimientos)', 893, '+', 'E'],
        ['Disminuciones (efectivas) de capital del ejercicio, actualizadas (varios movimientos)', 894, '−', 'E'],
        ['Renta líquida imponible afecta a IDPC del ejercicio', 1694, '+', 'R12'],
        ['Pérdida tributaria del ejercicio al 31 de diciembre', 1695, '−', 'R12'], ['Pérdidas de ejercicios anteriores (art. 31 N°3 LIR)', 1696, '+', 'R12'],
        ['Rentas exentas del IDPC e ingresos no renta (positivo), generados por la empresa en el ejercicio', 1178, '+', 'E'],
        ['Pérdida por rentas exentas del IDPC e ingresos no renta del ejercicio', 1179, '−', 'E'],
        ['Retiros o dividendos percibidos en el ejercicio por participaciones en otras empresas', 1180, '+', 'E'],
        ['Remesas, retiros o dividendos repartidos en el ejercicio, reajustados', 1182, '−', 'Retiros'],
        ['Partidas del inc. 1° no afectas al IU de tasa 40% y del inc. 2° del art. 21 LIR, reajustados', 1697, '−', 'R12'],
        ['Aumentos del ejercicio (por reorganizaciones)', 1186, '+', 'E'], ['Disminuciones del ejercicio (por reorganizaciones)', 1187, '−', 'E'],
        ['Ingreso diferido por cambio de régimen', 1700, '−', 'R12'],
        ['Crédito total disponible imputable contra impuestos finales (IPE), del ejercicio', 1188, '−', 'R12'],
        ['Incentivo al ahorro según art. 14 letra E) LIR', 1701, '+', 'R12'], ['Base del IDPC voluntario según art. 14 letra A) N°6 LIR', 1702, '+', 'R12'],
        ['Otras partidas a agregar', 1189, '+', 'E'], ['Otras partidas a deducir', 1190, '−', 'E'],
        ['CPT positivo final', 645, '='], ['CPT negativo final', 646, '=']
    ];

    // Grillas R15 y R16: códigos por columna. null = casilla sin código; '?' = fila que no se alcanza a ver en el video.
    var R15_COLS = ['RAI', 'DDAN', 'REX · RAP y dif. inicial art. 14 ter A)', 'REX · ISFUT/ISIF', 'REX · Otras', 'REX · Rentas exentas', 'REX · INR'];
    var R15 = [
        ['Remanente ejercicio anterior o saldo inicial (saldo positivo)', [1200, 1211, 1221, 1730, 1731, 1234, 1246]],
        ['Remanente ejercicio anterior o saldo inicial (saldo negativo)', [null, null, 1222, null, 1843, 1235, 1247]],
        ['Monto acogido al ISIF, según arts. 10 y 11 Ley N° 21.681', [1933, null, null, 1934, null, null, null]],
        ['Aumentos del ejercicio (por reorganizaciones)', [1202, 1212, 1224, 1733, 1734, 1236, 1248]],
        ['Disminuciones del ejercicio (por reorganizaciones)', [1203, 1213, 1225, 1735, 1736, 1237, 1249]],
        ['Reversos y/o disminuciones del ejercicio (propios)', [1204, 1214, 1226, 1737, 1738, 1238, 1250]],
        ['Aumentos del ejercicio (propios)', [1205, 1215, null, null, 1740, 1239, 1251]],
        ['Otros aumentos del ejercicio', [1206, 1216, 1228, 1741, 1742, 1240, 1252]],
        ['Otras disminuciones del ejercicio', [1207, 1217, 1229, 1743, 1744, 1241, 1253]],
        ['Remesas, retiros o dividendos imputados a los RTRE', [1208, 1218, 1230, 1745, 1746, 1242, 1254]],
        ['Remanente ejercicio siguiente', [1210, 1220, 1232, 1749, 1750, 1244, 1256]]
    ];
    var R16_COLS = ['Desde 2017 · no sujeto a restitución, sin devolución', 'No sujeto a restitución, con devolución', 'Sujeto a restitución, sin devolución',
        'Sujeto a restitución, con devolución', 'IPE', 'Hasta 2016 · sin devolución', 'Hasta 2016 · con devolución'];
    var R16 = [
        ['Remanente ejercicio anterior o saldo inicial (saldo positivo)', [1270, 1279, 1288, 1301, 1313, 1324, 1335]],
        ['Remanente ejercicio anterior o saldo inicial (saldo negativo)', [1821, 1822, 1289, 1302, null, null, null]],
        ['Monto imputado al ISIF, según arts. 10 y 11 Ley N° 21.681', [1936, 1937, 1938, 1939, null, 1940, 1941]],
        ['Aumentos del ejercicio (por reorganizaciones)', [1271, 1280, 1290, 1303, 1314, 1326, 1337]],
        ['Disminuciones del ejercicio (por reorganizaciones)', [1272, 1281, 1291, 1304, 1315, 1327, 1338]],
        ['IDPC e IPE — RLI o base imponible generada en el ejercicio', [null, null, 1292, 1305, 1316, null, null]],
        ['IDPC e IPE — retiros o dividendos percibidos', [1273, 1282, 1293, 1306, 1317, 1328, 1339]],
        ['Otros aumentos del ejercicio', [1274, 1283, 1294, 1307, 1318, 1329, 1340]],
        ['Otras disminuciones del ejercicio', [1275, 1284, 1295, 1308, 1319, 1330, 1341]],
        ['Asignado a remesas, retiros o dividendos efectuados en el ejercicio', [1276, 1285, 1296, 1309, 1320, 1331, 1342]],
        ['Asignado a retiros en exceso y devoluciones de capital', [1277, 1286, 1297, 1310, 1321, 1332, 1343]],
        ['Remanente ejercicio siguiente', [1278, 1287, 1312, 1300, 1323, 1334, 1345]]
    ];

    var RTRE = [
        ['1. Saldos iniciales o remanentes'], ['1.1 Saldos iniciales o remanentes de utilidades y créditos del ejercicio anterior'],
        ['1.2 Incorporación de rentas y créditos por cambio de régimen'],
        ['1.3 Incorporación de rentas y créditos por conversión, división o fusión por creación'],
        ['1.4 Reajuste VIPC de los saldos iniciales y del cambio de régimen (1.1 + 1.2)'],
        ['2. Reclasificaciones del ejercicio'], ['3. Reorganizaciones a la fecha del evento'],
        ['4. Determinación del año comercial'], ['4.1 Reverso del subtotal N°3'], ['4.2 RAI del ejercicio', 'R13'], ['4.3 DDAN del ejercicio'],
        ['4.4 REX / INR del ejercicio'], ['4.5 Créditos SAC del ejercicio'], ['5. Otras reclasificaciones'],
        ['Rentas o cantidades disponibles para ser retiradas, remesadas o distribuidas', '='], ['6. Imputación de retiros'],
        ['6.1 Retiros, remesas o dividendos del ejercicio'], ['6.2 Retiros en exceso al 31.12.2014, pendientes de imputación'],
        ['6.3 Devolución formal de capital'], ['6.4 Crédito por partidas del art. 21 (inciso 1° no afecto al 40% e inciso 2°)'],
        ['Saldo final — remanente para el ejercicio siguiente', '=']
    ];

    var VENTANAS = {
        R12: { titulo: 'Recuadro 12 — Base Imponible (14 A)', tabla: R12, chips: ['Informe RLI', 'PDF'] },
        R13: { titulo: 'Recuadro 13 — Determinación del RAI (14 A)', tabla: R13, chips: ['PDF'] },
        R14: { titulo: 'Recuadro 14 — Razonabilidad del Capital Propio Tributario (14 A)', tabla: R14, chips: ['CPT Activo', 'PDF'] },
        R15: { titulo: 'Recuadro 15 — RTRE y movimiento STUT (14 A)', cols: R15_COLS, filas: R15, chips: ['PDF'] },
        R16: { titulo: 'Recuadro 16 — Registro SAC (14 A)', cols: R16_COLS, filas: R16, chips: ['PDF'] },
        RTRE: { titulo: 'Registro Tributario de Rentas Empresariales — Régimen 14 A', chips: ['Informe PDF'] },
        HOM14: { titulo: 'Homologación de cuentas — Semi Integrado (14 A)' },
        R6: { titulo: 'Recuadro 6 — Datos Informativos (14 A)', nota: 'La versión 14 A de este recuadro no aparece en el video: falta capturarla.' },
        DJ1847: { titulo: 'DJ 1847 — Balance de 8 columnas', nota: 'El video muestra esta declaración en la lista, pero no su contenido.' },
        DJ1926: { titulo: 'DJ 1926 — Base imponible y balance', nota: 'El video muestra esta declaración en la lista, pero no su contenido.' }
    };

    function esc(s) {
        return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; });
    }
    function chip(t) { return '<span class="cv-chip">' + esc(t) + '</span>'; }
    var AVISO = '<div class="cv-maqueta-aviso">Maqueta 14 A: muestra la forma de la pantalla. No calcula, no guarda y no usa datos de la empresa.</div>';

    function tablaLineas(lineas) {
        var html = '<table class="cv-modal-tabla"><thead><tr><th>Glosa</th><th class="num">Código</th><th class="num">Monto</th><th></th><th>Origen</th></tr></thead><tbody>';
        lineas.forEach(function (l) {
            if (l.length === 1) {
                html += '<tr class="cv-seccion"><td colspan="5">▾ ' + esc(l[0]) + '</td></tr>';
                return;
            }
            var manual = l[3] === 'E';
            var origen = manual ? chip('Manual') : (l[3] ? chip(l[3]) : '');
            html += '<tr' + (l[2] === '=' ? ' class="cv-total"' : '') + '><td class="cv-glosa">' + esc(l[0]) + '</td><td class="num">' + l[1] + '</td><td class="num">' +
                (manual ? '<span class="cv-maqueta-campo">—</span>' : '—') + '</td><td>' + l[2] + '</td><td>' + origen + '</td></tr>';
        });
        return html + '</tbody></table>';
    }

    function grilla(cols, filas) {
        var html = '<div class="cv-maqueta-grilla"><table class="cv-modal-tabla"><thead><tr><th>Concepto</th>' +
            cols.map(function (c) { return '<th class="num cv-glosa">' + esc(c) + '</th>'; }).join('') + '</tr></thead><tbody>';
        filas.forEach(function (f) {
            html += '<tr><td class="cv-glosa">' + esc(f[0]) + '</td>' + f[1].map(function (c) {
                if (c === null) return '<td class="cv-maqueta-nula"></td>';
                return '<td class="num"><small>' + (c === '?' ? 'por confirmar' : c) + '</small><br>—</td>';
            }).join('') + '</tr>';
        });
        return html + '</tbody></table></div><p class="cv-texto" style="font-size:12px">«Por confirmar»: no se alcanza a ver en el video, que también corta las columnas más a la derecha.</p>';
    }

    function vistaRtre() {
        var tarjetas = ['RAI final', 'REX final', 'SAC disponible', 'Retiros del ejercicio'].map(function (t) {
            return '<div class="cv-maqueta-tarjeta"><small>' + t + '</small><strong>—</strong></div>';
        }).join('');
        var botones = ['R13', 'R15', 'R16'].map(function (r) {
            return '<button type="button" class="cv-btn-fin" data-abrir14="' + r + '" style="cursor:pointer;opacity:1;margin-right:6px">' + r + '</button>';
        }).join('');
        var columnas = ['RAI', 'DDAN', 'REX', 'SAC', 'STUT'];
        var html = '<div class="cv-maqueta-tarjetas">' + tarjetas + '<div class="cv-maqueta-tarjeta"><small>Recuadros</small>' + botones + '</div></div>' +
            '<table class="cv-modal-tabla"><thead><tr><th>Movimiento</th>' + columnas.map(function (c) { return '<th class="num">' + c + '</th>'; }).join('') + '</tr></thead><tbody>';
        RTRE.forEach(function (f) {
            var clase = /^\d\. /.test(f[0]) ? ' class="cv-seccion"' : (f[1] === '=' ? ' class="cv-total"' : '');
            html += '<tr' + clase + '><td class="cv-glosa">' + esc(f[0]) + (f[1] === 'R13' ? ' ' + chip('Viene del R13') : '') + '</td>' +
                columnas.map(function () { return '<td class="num">—</td>'; }).join('') + '</tr>';
        });
        return html + '</tbody></table>';
    }

    function vistaHomologacion() {
        var ejemplos = [['Cuenta de ingresos', 'Ganancia', '3.01.01.00', 1657], ['Cuenta de costo de ventas', 'Pérdida', '3.01.02.00', 1661],
            ['Cuenta de gastos', 'Pérdida'], ['Cuenta de pasivo', 'Pasivo']];
        return '<p class="cv-texto" style="font-size:12.5px">En 14 A cada cuenta del balance se asigna a una cuenta del plan IFRS del SII y, si es de resultado, a un código del R12.</p>' +
            '<input type="search" disabled placeholder="Buscar por código o nombre…" style="width:100%;padding:6px 8px;border:1px solid #d1d5db;border-radius:4px;font:inherit;margin-bottom:8px">' +
            '<table class="cv-modal-tabla"><thead><tr><th>Código</th><th>Nombre cuenta</th><th>Tipo</th><th class="num">Saldo balance</th><th>Configuración</th></tr></thead><tbody>' +
            ejemplos.map(function (e) {
                return '<tr><td>—</td><td>' + e[0] + ' (ejemplo)</td><td>' + chip(e[1]) + '</td><td class="num">—</td><td>' +
                    (e[2] ? chip('IFRS: ' + e[2]) + ' ' + chip('Recuadro 12: ' + e[3]) : '<em>Sin configuración</em>') + '</td></tr>';
            }).join('') + '</tbody></table>';
    }

    function abrir(clave) {
        var v = VENTANAS[clave];
        var modal = document.getElementById('cv-modal');
        if (!v || !modal) return;
        document.getElementById('cv-modal-titulo').textContent = v.titulo;
        document.getElementById('cv-modal-sub').textContent = 'AT 2026 · Semi Integrado (Art. 14 A) · maqueta';
        document.getElementById('cv-modal-acciones').innerHTML = '<span class="cv-pill cv-borr">Maqueta</span> ' +
            (v.chips || []).map(function (c) { return '<button type="button" class="cv-btn-pdf" disabled>' + c + '</button>'; }).join(' ') +
            (v.tabla || v.filas || clave === 'RTRE' ? ' <button type="button" class="cv-btn-fin" disabled>Finalizar declaración</button>' : '');
        var contenido;
        if (v.tabla) contenido = tablaLineas(v.tabla);
        else if (v.filas) contenido = grilla(v.cols, v.filas);
        else if (clave === 'RTRE') contenido = vistaRtre();
        else if (clave === 'HOM14') contenido = vistaHomologacion();
        else contenido = '<p class="cv-texto cv-mensaje-ventana">' + esc(v.nota) + '</p>';
        var destino = document.getElementById('cv-modal-cuerpo');
        destino.innerHTML = AVISO + contenido;
        destino.scrollTop = 0;
        modal.hidden = false;
        modal.querySelector('.cv-modal-x').focus();
    }

    function fila(atributo, cod, nombre, extra) {
        return '<div class="cv-fila" ' + atributo + '><span><span class="cv-cod' + (/^DJ/.test(cod) ? ' cv-cod-azul' : '') + '">' + cod + '</span>' + esc(nombre) + (extra || '') +
            '</span><span class="cv-calc">Cálculos</span><span class="cv-estado pend">MAQUETA</span></div>';
    }
    function ventana(r) { return 'data-abrir14="' + r + '"'; }
    function chipAbrir(r, texto) { return ' <span class="cv-chip" ' + ventana(r) + '>' + (texto || r) + '</span>'; }
    function activar(regimen) {
        if (activa) return;
        activa = true;
        // El inicio de 14 A, 14 G y persona natural lo pinta cifrax-inicio.js desde la API.
        // En #cv-vista-reportes se reemplazan los reportes de Pro Pyme por los del régimen activo.
        var cards = document.querySelectorAll('#cv-vista-reportes .cv-card');
        if (regimen !== '14A' && regimen !== '14G') {
            cards.forEach(function (c, i) { if (i > 0) c.hidden = true; });
            return;
        }
        var repFila = function (cod, nombre, doc, azul) {
            return '<div class="cv-rep"><span class="cv-cod' + (azul ? ' cv-cod-azul' : '') + '">' + esc(cod) + '</span>' +
                '<span class="cv-rep-nombre">' + esc(nombre) + '</span>' +
                '<span class="cv-rep-acc"><button type="button" class="cv-btn-pdf" data-pdf="' + esc(doc) + '"><i class="fa fa-file-pdf-o" aria-hidden="true"></i> Ver PDF</button></span></div>';
        };
        if (regimen === '14G') {
            if (cards[1]) cards[1].innerHTML = '<h2>Gestión General <span class="cv-count">2</span></h2>' +
                repFila('Balance', 'Balance General importado', 'BALANCE', false) +
                repFila('Homol.', 'Homologación de cuentas (cuenta → R12)', 'HOMOLOGACION', false);
            if (cards[2]) cards[2].innerHTML = '<h2>Recuadros F22 · 14 G <span class="cv-count">4</span></h2>' +
                repFila('R4', 'Enajenación de acciones y derechos sociales', 'R4', false) +
                repFila('R6', 'Datos Informativos', 'R6', false) +
                repFila('R12', 'Base Imponible (RLI)', 'R12', false) +
                repFila('R14', 'Razonabilidad del Capital Propio Tributario', 'R14', false);
            if (cards[3]) cards[3].hidden = true;
            return;
        }
        if (cards[1]) cards[1].innerHTML = '<h2>Gestión General <span class="cv-count">3</span></h2>' +
            repFila('Balance', 'Balance General importado', 'BALANCE', false) +
            repFila('Homol.', 'Homologación de cuentas (cuenta → R12)', 'HOMOLOGACION', false) +
            repFila('Retiros', 'Retiros y Dividendos por socio y mes', 'RETIROS', false);
        if (cards[2]) cards[2].innerHTML = '<h2>Recuadros F22 · 14 A <span class="cv-count">7</span></h2>' +
            repFila('R4', 'Enajenación de acciones y derechos sociales', 'R4', false) +
            repFila('R6', 'Datos Informativos', 'R6', false) +
            repFila('R12', 'Base Imponible (RLI)', 'R12', false) +
            repFila('R13', 'Determinación del RAI', 'R13', false) +
            repFila('R14', 'Razonabilidad del Capital Propio Tributario', 'R14', false) +
            repFila('R15', 'RTRE y movimiento STUT', 'R15', false) +
            repFila('R16', 'Registro SAC', 'R16', false);
        if (cards[3]) cards[3].innerHTML = '<h2>Declaraciones Juradas (DJ) · 14 A <span class="cv-count">3</span></h2>' +
            repFila('DJ 1847', 'Balance 8 columnas y clasificador Anexo N° 1', 'DJ1847', true) +
            repFila('DJ 1926', 'Base imponible de Primera Categoría', 'DJ1926', true) +
            repFila('DJ 1948', 'Retiros y Dividendos (Certificado 70)', 'DJ1948', true);
    }

    // ---------- Fundación / régimen especial: lo declarado ante el SII, tomado de la consulta guardada ----------
    var declarado = null; // {f22, ddjj}
    var especial = false;
    function anioElegido() { var s = document.getElementById('cf-anio'); return s ? Number(s.value) : 2026; }
    function consultaGuardada(tipo, anio) {
        return fetch('../api/sii.php?consulta=' + tipo + '&anio=' + anio + (empresaId ? '&empresa_id=' + encodeURIComponent(empresaId) : ''), { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .catch(function () { return null; });
    }
    function codigosDe(lineas) { return lineas.filter(function (l) { return l.length > 1; }).map(function (l) { return l[1]; }); }
    function ultima(tipo) { return declarado && declarado[tipo] ? declarado[tipo].ultima : null; }
    function codigosF22() { return ultima('f22') ? ultima('f22').datos.codigos || [] : []; }
    function otrosCodigos() {
        var usados = codigosDe(R12).concat(codigosDe(R14));
        return codigosF22().filter(function (c) { return usados.indexOf(c.codigo) < 0; });
    }
    function fuente() {
        var u = ultima('f22');
        if (!u) return '<div class="cv-maqueta-aviso">Aún no se ha consultado el F22 del AT ' + anioElegido() + '. En el panel Formulario 22 pulse Consultar.</div>';
        return '<p class="cv-texto" style="font-size:12.5px">Declarado ante el SII: folio ' + esc(u.datos.folio || '—') + ', presentado el ' + esc(u.datos.presentada || '—') +
            ' · estado ' + esc(u.estado) + ' · consultado el ' + esc(new Date(u.consultado_en).toLocaleDateString('es-CL')) + '.</p>';
    }
    function abrirEspecial(clave) {
        var modal = document.getElementById('cv-modal');
        var valores = {};
        codigosF22().forEach(function (c) { valores[c.codigo] = c.valor; });
        var titulo, tabla;
        if (clave === 'OTROS') {
            titulo = 'Otros códigos declarados en el F22';
            tabla = '<table class="cv-modal-tabla"><thead><tr><th>Glosa del SII</th><th class="num">Código</th><th class="num">Declarado</th></tr></thead><tbody>' +
                (otrosCodigos().map(function (c) {
                    return '<tr><td class="cv-glosa">' + esc(c.glosa) + '</td><td class="num">' + esc(c.codigo) + '</td><td class="num">' + esc(c.valor) + '</td></tr>';
                }).join('') || '<tr><td colspan="3">Sin otros códigos.</td></tr>') + '</tbody></table>';
        } else {
            titulo = clave === 'R12' ? 'Recuadro 12 — Base Imponible (fundación)' : 'Recuadro 14 — Capital Propio Tributario (fundación)';
            tabla = '<table class="cv-modal-tabla"><thead><tr><th>Glosa</th><th class="num">Código</th><th></th><th class="num">Declarado en el SII</th></tr></thead><tbody>' +
                (clave === 'R12' ? R12 : R14).map(function (l) {
                    if (l.length === 1) return '<tr class="cv-seccion"><td colspan="4">▾ ' + esc(l[0]) + '</td></tr>';
                    var hay = valores[l[1]] !== undefined;
                    return '<tr' + (hay ? ' style="background:#f0fdf4"' : '') + '><td class="cv-glosa">' + esc(l[0]) + '</td><td class="num">' + l[1] + '</td><td>' + l[2] +
                        '</td><td class="num">' + (hay ? '<strong>' + esc(valores[l[1]]) + '</strong>' : '—') + '</td></tr>';
                }).join('') + '</tbody></table>';
        }
        document.getElementById('cv-modal-titulo').textContent = titulo;
        document.getElementById('cv-modal-sub').textContent = 'AT ' + anioElegido() + ' · Fundación / régimen especial · solo consulta';
        document.getElementById('cv-modal-acciones').innerHTML = '<span class="cv-pill cv-disp">Declarado en el SII</span>';
        var destino = document.getElementById('cv-modal-cuerpo');
        destino.innerHTML = fuente() + tabla;
        destino.scrollTop = 0;
        modal.hidden = false;
        modal.querySelector('.cv-modal-x').focus();
    }
    function filaEspecial(atributo, cod, nombre, estado, clase) {
        return '<div class="cv-fila" ' + atributo + '><span><span class="cv-cod' + (/^DJ/.test(cod) ? ' cv-cod-azul' : '') + '">' + cod + '</span>' + esc(nombre) +
            '</span><span class="cv-calc">SII</span><span class="cv-estado ' + clase + '">' + esc(estado) + '</span></div>';
    }
    function cargarEspecial() {
        var anio = anioElegido();
        Promise.all([consultaGuardada('f22', anio), consultaGuardada('ddjj', anio)]).then(function (r) {
            if (anio !== anioElegido()) return;
            declarado = { f22: r[0], ddjj: r[1] };
            pintarEspecial();
        });
    }
    function activarEspecial() {
        if (especial) return;
        especial = true;
        activar('14G');
        var banner = document.createElement('div');
        banner.className = 'cv-maqueta-aviso';
        banner.setAttribute('data-maqueta-banner', '');
        var inicio = document.querySelector('#p-home .cv-home');
        inicio.parentNode.insertBefore(banner, inicio);
        cargarEspecial();
        var anioSelector = document.getElementById('cf-anio');
        if (anioSelector) anioSelector.addEventListener('change', cargarEspecial);
        var pasoInicio = document.querySelector('.cf-paso[data-panel="p-home"]');
        if (pasoInicio) pasoInicio.addEventListener('click', cargarEspecial);
    }

    function pintarEspecial() {
        var u = ultima('f22');
        var banner = document.querySelector('[data-maqueta-banner]');
        if (!banner) return;
        if (!u) {
            banner.innerHTML = '<strong>Fundación / régimen 14 G (AT ' + anioElegido() + ').</strong> Cifrax calcula R4, R6, R12 y R14. Aún no hay consulta del F22 en el SII para comparar.';
            return;
        }
        banner.innerHTML = '<strong>Fundación / régimen 14 G (AT ' + anioElegido() + ').</strong> F22 SII folio ' + esc(u.datos.folio || '—') +
            ' (' + esc(u.estado) + ') · <button type="button" class="cv-btn-pdf" data-esp="R12" style="padding:2px 8px">Comparar R12 SII</button> ' +
            '<button type="button" class="cv-btn-pdf" data-esp="R14" style="padding:2px 8px">Comparar R14 SII</button> ' +
            '<button type="button" class="cv-btn-pdf" data-esp="OTROS" style="padding:2px 8px">Otros códigos SII</button>';
    }

    // 14 D N°8: en Reportes, los recuadros de Pro Pyme General se reemplazan por el R22, el R23 y la DJ 1947.
    var transparente = false;
    var SOLO_PRO_PYME = ['R17', 'RLI', 'R18', 'R19', 'CPT', 'R20', 'R21', 'RTRE', 'DJ 1948'];
    var REPORTES_14D8 = [['R6', 'R22', 'Base imponible del régimen de transparencia', 'R22'], ['R22', 'R23', 'CPTS del régimen de transparencia', 'R23'],
        ['DJ 1948', 'DJ 1947', 'Base imponible asignada a los propietarios', 'DJ1947']];
    function activarTransparente() {
        if (transparente) return;
        transparente = true;
        var filas = {};
        document.querySelectorAll('#cv-vista-reportes .cv-rep').forEach(function (fila) {
            var cod = fila.querySelector('.cv-cod');
            if (cod) filas[cod.textContent.trim()] = fila;
        });
        REPORTES_14D8.forEach(function (n) {
            var despues = filas[n[0]];
            if (!despues) return;
            // Se copia una fila existente para conservar el ícono y la alineación; sin la etiqueta de estado, que es fija.
            var nueva = despues.cloneNode(true);
            var cod = nueva.querySelector('.cv-cod');
            cod.className = 'cv-cod' + (/^DJ/.test(n[1]) ? ' cv-cod-azul' : '');
            cod.textContent = n[1];
            nueva.querySelector('.cv-rep-nombre').textContent = n[2];
            var pill = nueva.querySelector('.cv-pill');
            if (pill) pill.style.visibility = 'hidden';
            var botones = nueva.querySelectorAll('.cv-btn-pdf');
            for (var k = 1; k < botones.length; k++) botones[k].remove();
            botones[0].disabled = false;
            botones[0].removeAttribute('data-informe-sii');
            botones[0].setAttribute('data-pdf', n[3]);
            despues.insertAdjacentElement('afterend', nueva);
            filas[n[1]] = nueva;
        });
        SOLO_PRO_PYME.forEach(function (c) { if (filas[c]) filas[c].style.display = 'none'; });
        document.querySelectorAll('#cv-vista-reportes .cv-card').forEach(function (tarjeta) {
            var cuenta = tarjeta.querySelector('.cv-count');
            var filasTarjeta = tarjeta.querySelectorAll('.cv-rep');
            if (cuenta && filasTarjeta.length) cuenta.textContent = Array.prototype.filter.call(filasTarjeta, function (f) { return f.style.display !== 'none'; }).length;
        });
    }

    document.addEventListener('click', function (e) {
        var x = e.target.closest ? e.target.closest('[data-esp], [data-esp-panel]') : null;
        if (!x) return;
        e.stopPropagation();
        if (x.hasAttribute('data-esp')) { abrirEspecial(x.getAttribute('data-esp')); return; }
        var paso = document.querySelector('.cf-paso[data-panel="' + x.getAttribute('data-esp-panel') + '"]');
        if (paso) paso.click();
    }, true);

    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-abrir14]') : null;
        if (!b) return;
        e.stopPropagation();
        abrir(b.getAttribute('data-abrir14'));
    }, true);
    // La vista 14 A reemplaza las tarjetas: al cambiar de régimen se recarga la página.
    function recargarCon(regimen) {
        var p = new URLSearchParams(window.location.search);
        p.delete('maqueta');
        p.set('regimen', regimen);
        window.location.search = p.toString();
    }
    function mostrar(regimen) {
        if (selector && !regimen) {
            // Sin régimen en la configuración de la empresa: no se presume 14 D N°3.
            if (!selector.querySelector('option[value=""]')) selector.add(new Option('Sin configurar', ''), 0);
            selector.value = '';
            return;
        }
        if (regimen === 'PERSONA') {
            if (selector && !selector.querySelector('option[value="PERSONA"]')) selector.add(new Option('Persona natural (honorarios)', 'PERSONA'));
            if (selector) selector.value = 'PERSONA';
            activar('PERSONA');
            return;
        }
        var vista = regimen === '14G' ? 'ESP' : regimen;
        if (selector) selector.value = vista === '14A' || vista === 'ESP' || vista === '14D8' ? vista : '14D3';
        if (vista === '14D8') activarTransparente();
        if (vista === '14A') activar('14A');
        if (vista === 'ESP') activarEspecial();
    }
    if (selector) selector.addEventListener('change', function () { recargarCon(selector.value); });
    // Vista (URL o selector) -> régimen que se guarda en la empresa, y al revés.
    var CONFIG_DE_VISTA = { 'ESP': '14G', '14D3': '14D3', '14D8': '14D8', '14A': '14A', 'PERSONA': 'PERSONA' };
    var VISTA_DE_CONFIG = { '14G': 'ESP' };
    function ofrecerGuardar(configurado) {
        var boton = document.getElementById('cf-guardar-regimen');
        var vistaConfig = VISTA_DE_CONFIG[configurado] || configurado || '';
        if (!elegida || !CONFIG_DE_VISTA[elegida] || elegida === vistaConfig) {
            if (boton) boton.remove();
            return;
        }
        if (boton || !selector) return;
        boton = document.createElement('button');
        boton.type = 'button';
        boton.id = 'cf-guardar-regimen';
        boton.className = 'cv-btn-fin';
        boton.style.cssText = 'cursor:pointer;opacity:1;margin-left:8px';
        boton.textContent = 'Guardar como régimen de la empresa';
        boton.addEventListener('click', guardarRegimen);
        selector.insertAdjacentElement('afterend', boton);
    }
    function guardarRegimen() {
        var boton = document.getElementById('cf-guardar-regimen');
        var p = new URLSearchParams(window.location.search);
        var empresaId = p.get('empresa_id');
        if (!empresaId) {
            try { var activa = JSON.parse(localStorage.getItem('icontador.empresaSeleccionada') || 'null'); empresaId = activa && activa.id; } catch (x) { empresaId = null; }
        }
        var anioSel = document.getElementById('cf-anio');
        boton.disabled = true;
        fetch('../api/cifrax-tributario.php?accion=regimen_config&empresa_id=' + encodeURIComponent(empresaId || ''), {
            method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ regimen: CONFIG_DE_VISTA[elegida], anio: anioSel ? Number(anioSel.value) : 2026 })
        }).then(function (r) {
            return r.json().then(function (j) { if (!r.ok) throw new Error(j.error || 'No se pudo guardar el régimen.'); return j; });
        }).then(function () {
            p.delete('regimen');
            window.location.search = p.toString();
        }).catch(function (err) {
            boton.disabled = false;
            boton.textContent = err.message;
        });
    }
    document.addEventListener('cv:regimen', function (e) {
        ofrecerGuardar(e.detail);
        mostrar(elegida || e.detail);
    });
    if (elegida) mostrar(elegida);
})();
