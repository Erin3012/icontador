// Inicio de Cifrax Tributario para los regímenes con inicio propio (14 A y persona natural).
// Las filas y sus estados vienen de la API (accion=inicio). Con otros regímenes no hace nada:
// queda el inicio de Pro Pyme que trae la pantalla.
(function () {
    'use strict';

    var contenedor = document.getElementById('cv-vista-tareas');
    if (!contenedor) return;

    var empresaActiva = null;
    try { empresaActiva = JSON.parse(localStorage.getItem('icontador.empresaSeleccionada') || 'null'); } catch (e) { empresaActiva = null; }
    var empresaId = new URLSearchParams(window.location.search).get('empresa_id') || (empresaActiva && empresaActiva.id) || '';

    var ICONOS = { gestion: 'fa-sitemap', f22: 'fa-th', dj: 'fa-gavel' };
    var IDS = { gestion: 'cv-card-gestion', f22: 'cv-card-f22', dj: 'cv-card-dj' };
    var ORDEN = { gestion: 0, f22: 1, dj: 2 };

    function anioActual() {
        var s = document.getElementById('cf-anio');
        return (s && s.value) || '2026';
    }
    function esc(t) {
        return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function fila(f) {
        var abrir = f.abrir ? ' data-abrir="' + esc(f.abrir) + '"' : (f.abrir14 ? ' data-abrir14="' + esc(f.abrir14) + '"' : '');
        var etapa = f.etapa ? ' title="Etapa ' + f.etapa + ' del plan de Cifrax Tributario"' : '';
        var cod = f.codigo ? '<span class="cv-cod' + (/^DJ/.test(f.codigo) ? ' cv-cod-azul' : '') + '">' + esc(f.codigo) + '</span>' : '';
        var tipo = f.tipo === 'Cálculos' ? '<span class="cv-calc">Cálculos</span>'
            : '<span class="cv-tipo ' + (f.tipo === 'Finanzas' ? 'cv-tipo-fin' : 'cv-tipo-config') + '">' + esc(f.tipo) + '</span>';
        var ayuda = f.ayuda ? '<small style="display:block;font-size:12px;color:#5b6678;font-weight:400;margin-top:2px">' + esc(f.ayuda) + '</small>' : '';
        var chips = (f.chips || []).map(function (c) {
            return ' <span class="cv-chip" data-abrir="' + esc(c.abrir) + '"' + (c.dialogo ? ' data-dialogo="' + esc(c.dialogo) + '"' : '') +
                (c.titulo ? ' title="' + esc(c.titulo) + '"' : '') + '>' + esc(c.texto) + '</span>';
        }).join('');
        return '<div class="cv-fila"' + abrir + etapa + '><span>' + cod + esc(f.nombre) + chips + ayuda + '</span>' + tipo +
            '<span class="cv-estado ' + esc(f.clase) + '">' + esc(f.estado) + '</span></div>';
    }
    function tarjeta(slot, grupo) {
        return '<div class="cv-card" id="' + IDS[slot] + '"><h2><i class="fa ' + ICONOS[slot] + '" aria-hidden="true"></i> ' + esc(grupo.titulo) +
            ' <span class="cv-count">' + grupo.filas.length + '</span></h2>' +
            '<div class="cv-cabecera-fila"><span>Nombre del proceso</span><span>Tipo</span><span>Estado</span></div>' +
            grupo.filas.map(fila).join('') + '</div>';
    }
    // Columna "Contenido de esta página": muestra solo los grupos del régimen y su número de procesos.
    function navegacion(grupos) {
        var por = {};
        grupos.forEach(function (g) { por[g.slot] = g; });
        Object.keys(IDS).forEach(function (slot) {
            var boton = document.querySelector('[data-ver="' + IDS[slot] + '"]');
            if (!boton) return;
            var g = por[slot];
            // style.display: la clase del botón sobrescribe [hidden] (ver memoria del visor).
            boton.style.display = g ? '' : 'none';
            if (!g) return;
            var n = g.filas.length;
            var pie = boton.querySelector('small');
            if (pie) pie.textContent = n + (n === 1 ? ' proceso' : ' procesos');
            var titulo = boton.querySelector('strong');
            if (titulo && slot === 'f22') titulo.lastChild.textContent = ' ' + g.titulo.split(' · ')[0];
        });
    }
    function resumenPendientes(grupos) {
        var n = 0;
        grupos.forEach(function (g) { g.filas.forEach(function (f) { if (f.clase === 'pend') n++; }); });
        var aviso = document.getElementById('cv-aviso-inicio');
        if (aviso) {
            aviso.innerHTML = '<i class="fa fa-lightbulb-o" aria-hidden="true"></i> ' +
                (n === 1 ? 'Queda 1 proceso pendiente para este período.' : n > 1 ? 'Quedan ' + n + ' procesos pendientes para este período.' : 'Todo al día: no hay procesos pendientes.');
        }
    }
    function siguienteHtml(s) {
        var cuerpo = s
            ? '<p class="cv-texto"><strong>' + esc(s.nombre) + '</strong></p>' + (s.ayuda ? '<p class="cv-texto">' + esc(s.ayuda) + '</p>' : '')
            : '<p class="cv-texto">No hay pasos pendientes que se puedan hacer hoy en este régimen.</p>';
        return '<div class="cv-card cv-siguiente"><h2><i class="fa fa-flag-checkered" aria-hidden="true"></i> Siguiente paso</h2>' + cuerpo + '</div>';
    }
    function cierreHtml(c) {
        return '<div class="cv-card cv-cierre"><div><strong>Cierre Anual Definitivo</strong><p class="cv-texto" data-cierre-texto>' + esc(c.texto) +
            '</p></div><button type="button" class="cv-btn-fin" style="cursor:pointer;opacity:1" data-abrir="CIERRE">Ver cierre anual</button></div>';
    }
    function pintar(datos) {
        if (!datos || !datos.propio) {
            window.cvInicioPropio = false;
            return;
        }
        var grupos = datos.grupos.slice().sort(function (a, b) { return ORDEN[a.slot] - ORDEN[b.slot]; });
        var previa = datos.vistaPrevia ? '<div class="cv-card" data-vista-previa><p class="cv-texto"><strong>Vista previa de ' + esc(datos.nombre) + '.</strong> ' +
            (datos.regimenGuardado ? 'La empresa tiene guardado otro régimen' : 'La empresa no tiene régimen guardado') +
            ': para fijar este, use «Guardar como régimen de la empresa».</p></div>' : '';
        contenedor.innerHTML = previa + siguienteHtml(datos.siguiente) + (datos.cierre ? cierreHtml(datos.cierre) : '') +
            grupos.map(function (g) { return tarjeta(g.slot, g); }).join('');
        navegacion(grupos);
        resumenPendientes(grupos);
        window.cvInicioPropio = true;
    }
    function cargar() {
        if (!empresaId) return;
        // El selector de régimen cambia la vista (?regimen=): el inicio muestra ese régimen aunque no esté guardado.
        var vista = new URLSearchParams(window.location.search).get('regimen') || '';
        var anioPedido = anioActual();
        var url = '../api/cifrax-tributario.php?accion=inicio&anio=' + encodeURIComponent(anioPedido) +
            '&empresa_id=' + encodeURIComponent(empresaId) + (vista ? '&regimen=' + encodeURIComponent(vista === 'ESP' ? '14G' : vista) : '');
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) { if (anioPedido === anioActual()) pintar(d); }) // se ignora la respuesta de un año ya cambiado
            .catch(function () { window.cvInicioPropio = false; });
    }

    cargar();
    var anio = document.getElementById('cf-anio');
    if (anio) anio.addEventListener('change', cargar);
    // Al cerrar una ventana de recuadro cambian los estados: se vuelven a leer.
    var modal = document.getElementById('cv-modal');
    if (modal) {
        new MutationObserver(function () { if (modal.hidden && window.cvInicioPropio) cargar(); })
            .observe(modal, { attributes: true, attributeFilter: ['hidden'] });
    }
})();
