// Consultas SII del visor (Formulario 22 y declaraciones juradas) de la empresa abierta. La consulta la ejecuta el
// agente del PC (sii-agent/) a través de api/sii.php; aquí solo se pide, se muestra y se vuelve a mirar mientras corre.
(function () {
    'use strict';
    var empresaActiva = null;
    try { empresaActiva = JSON.parse(localStorage.getItem('icontador.empresaSeleccionada') || 'null'); } catch (e) { empresaActiva = null; }
    var empresaId = new URLSearchParams(window.location.search).get('empresa_id') || (empresaActiva && empresaActiva.id) || '';
    var empresa = { razon: empresaId ? 'Empresa ' + empresaId : 'Sin empresa seleccionada', rut: '' };
    var estados = {};   // tipo -> respuesta de api/sii.php?consulta=
    var timers = {};
    var abierta = false; // detalle de las DJ desplegado
    var modal = document.getElementById('cf-modal-historial');
    if (!modal) return;

    function esc(s) {
        return String(s === null || s === undefined ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }
    function pesos(n) { return '$' + Number(n || 0).toLocaleString('es-CL'); }
    function fechaHora(iso) {
        if (!iso) return '—';
        var d = new Date(iso);
        return isNaN(d) ? iso : d.toLocaleDateString('es-CL') + ' ' + d.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit', hour12: false });
    }
    function anioDe(tipo) { return Number(document.getElementById(tipo + '-anio').value); }
    function url(base, extra) {
        var p = extra ? [extra] : [];
        if (empresaId) p.push('empresa_id=' + encodeURIComponent(empresaId));
        return base + (p.length ? '?' + p.join('&') : '');
    }
    function pedir(direccion, cuerpo) {
        var opciones = { credentials: 'same-origin', headers: {} };
        if (cuerpo) {
            opciones.method = 'POST';
            opciones.headers['Content-Type'] = 'application/json';
            opciones.body = JSON.stringify(cuerpo);
        }
        return fetch(direccion, opciones).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (d) {
                if (!r.ok) throw new Error(d.error || (d.errores || []).join(' ') || ('Error ' + r.status));
                return d;
            });
        });
    }
    function nota(tipo, texto, error) {
        var n = document.querySelector('[data-sii-nota="' + tipo + '"]');
        n.textContent = texto || '';
        n.classList.toggle('cf-sii-error', !!error);
    }

    function enCurso(d) { return !!(d && d.tarea && (d.tarea.estado === 'pendiente' || d.tarea.estado === 'en_proceso')); }
    function fallo(d) {
        if (!d || !d.tarea || d.tarea.estado !== 'error') return null;
        if (d.ultima && new Date(d.ultima.consultado_en) > new Date(d.tarea.actualizado_en)) return null;
        return d.tarea.mensaje || 'La consulta falló.';
    }

    function chips(tipo, d) {
        var estado = d && d.ultima ? d.ultima.estado : null;
        var observaciones = d && d.ultima && d.ultima.datos ? (d.ultima.datos.observaciones || []) : [];
        var lista = tipo === 'f22'
            ? [['Con inconsistencia', estado === 'Con inconsistencia' || observaciones.length > 0], ['Devolución retenida', estado === 'Devolución retenida'],
                ['Con cambios', !!(d && d.cambio)], ['Error de acceso', !!fallo(d)], ['Aceptadas', estado === 'Aceptada']]
            : [['Con observadas', estado === 'Con observadas'], ['Con cambios', !!(d && d.cambio)], ['Error de acceso', !!fallo(d)],
                ['Sin consultar', !!d && !d.ultima], ['Todas aceptadas', estado === 'Todas aceptadas']];
        document.querySelector('[data-sii-chips="' + tipo + '"]').innerHTML = lista.map(function (c) {
            return '<div class="cf-chip"><strong>' + (c[1] ? 1 : 0) + '</strong><span>' + c[0] + '</span></div>';
        }).join('');
    }

    function detalleResultado(tipo, d) {
        var u = d.ultima;
        if (tipo === 'f22') {
            var r = u.datos.resultado || {};
            if (u.datos.estado === 'Sin declaración') return '';
            var obs = (u.datos.observaciones || []).length;
            var cuantas = obs ? '<br><small class="cf-pendiente">' + obs + ' observación(es)</small>' : '';
            if (r.devolucion > 0) return ' ' + (u.datos.estado === 'Devolución retenida' ? 'de ' : 'con devolución ') + pesos(r.devolucion) + cuantas;
            if (r.pago > 0) return ' con pago ' + pesos(r.pago) + cuantas;
            return ' sin pago ni devolución' + cuantas;
        }
        var dj = u.datos.declaraciones || [];
        var observadas = dj.filter(function (x) { return /observad|rechazad|inconsist/i.test(x.estado); }).length;
        return '<br><small>' + dj.length + ' presentada(s)' + (observadas ? ' · ' + observadas + ' observada(s)' : '') + '</small>';
    }

    function celdaEstado(tipo, d) {
        if (!d) return '<span class="cf-estado">Cargando…</span>';
        var html = '';
        if (!d.clave_guardada) {
            html = '<span class="cf-pendiente cf-estado">Sin clave</span><br><small>Guarde la clave del SII en Cifrax (RCV → Clave tributaria del SII).</small>';
        } else if (enCurso(d)) {
            var detenido = d.tarea.estado === 'pendiente' && Date.now() - new Date(d.tarea.creado_en) > 3 * 60 * 1000;
            html = '<span class="cf-pendiente cf-estado">' + (d.tarea.estado === 'pendiente' ? 'En cola' : 'Consultando') + '</span>' +
                (detenido ? '<br><small class="cf-sii-error">El agente no responde: revise que esté corriendo y actualizado en su PC.</small>'
                    : '<br><small>El agente de su PC la ejecuta en uno o dos minutos.</small>');
        } else if (fallo(d)) {
            html = '<span class="cf-pendiente cf-estado">Error de acceso</span><br><small class="cf-sii-error">' + esc(fallo(d)) + '</small>';
        } else if (!d.ultima) {
            return '<span class="cf-estado">Sin consultar</span>';
        }
        if (d.ultima) {
            var bien = d.ultima.estado === 'Aceptada' || d.ultima.estado === 'Todas aceptadas';
            html += (html ? '<br><small>Última respuesta:</small> ' : '') + '<span class="cf-estado ' + (bien ? 'cf-listo' : 'cf-pendiente') + '">' + esc(d.ultima.estado) + '</span>' +
                detalleResultado(tipo, d);
            if (d.cambio) html += '<br><small class="cf-pendiente">Cambió el ' + esc(fechaHora(d.cambio.fecha)) + ': antes ' + esc(d.cambio.antes) + '</small>';
        }
        return html;
    }

    function botonConsultar(tipo, d) {
        var no = !d || !d.clave_guardada || enCurso(d);
        return '<button type="button" class="cf-boton principal" data-sii-consultar="' + tipo + '"' + (no ? ' disabled' : '') + '>' +
            (d && d.ultima ? 'Reconsultar' : 'Consultar') + '</button>';
    }

    function tablaDeclaraciones(d) {
        var filas = (d.ultima.datos.declaraciones || []).map(function (x, i) {
            var bien = /aceptad/i.test(x.estado);
            var obs = x.observaciones || [];
            var ver = obs.length
                ? '<button type="button" class="cf-boton" data-sii-observaciones="' + i + '">Ver observaciones</button><br><small class="cf-pendiente">' +
                    esc(obs.map(function (o) { return o.codigo; }).join(', ')) + '</small>'
                : (bien ? '' : '<small>Reconsulte para traer el motivo.</small>');
            return '<tr><td><strong>DJ ' + esc(x.formulario) + '</strong><br><small>' + esc(x.nombre) + '</small></td>' +
                '<td><span class="cf-estado ' + (bien ? 'cf-listo' : 'cf-pendiente') + '">' + esc(x.estado) + '</span></td>' +
                '<td>' + esc(x.folio) + '</td><td>' + esc(x.presentada) + '</td><td>' + esc(x.tipo) + '</td><td>' + esc(x.medio) + '</td><td>' + ver + '</td></tr>';
        }).join('') || '<tr><td colspan="7">Sin declaraciones presentadas.</td></tr>';
        return '<table class="cf-tabla cf-tabla-interna"><thead><tr><th>Formulario</th><th>Estado</th><th>Folio</th><th>Presentada</th><th>Tipo</th><th>Medio</th><th>Observaciones</th></tr></thead><tbody>' +
            filas + '</tbody></table>';
    }

    function verObservaciones(indice) {
        var d = estados.ddjj;
        var x = d && d.ultima ? (d.ultima.datos.declaraciones || [])[indice] : null;
        if (!x) return;
        var campo = function (titulo, texto) { return texto ? '<p><strong>' + titulo + '</strong><br>' + esc(texto) + '</p>' : ''; };
        modal.querySelector('.cf-modal-caja').innerHTML =
            '<div class="cf-modal-cab"><div><strong id="cf-modal-titulo">DJ ' + esc(x.formulario) + ' — ' + esc(empresa.razon) + '</strong><div class="cf-nota">' +
            'Año tributario ' + esc(d.anio) + ' · Folio ' + esc(x.folio) + ' · ' + esc(x.estado) + '</div></div>' +
            '<button type="button" class="cf-boton" data-sii-cerrar="1" aria-label="Cerrar">×</button></div>' +
            (x.observaciones || []).map(function (o) {
                return '<h3>Observación ' + esc(o.codigo) + '</h3>' + campo('Descripción', o.descripcion) + campo('Motivo', o.motivo) + campo('Orientación del SII', o.orientacion);
            }).join('') +
            '<div class="cf-acciones"><button type="button" class="cf-boton" data-sii-cerrar="1">Cerrar</button></div>';
        modal.hidden = false;
        modal.querySelector('[data-sii-cerrar]').focus();
    }

    function pintar(tipo) {
        var d = estados[tipo];
        chips(tipo, d);
        var base = '<td>' + esc(empresa.razon) + '</td><td style="white-space:nowrap">' + esc(empresa.rut) + '</td><td>' + celdaEstado(tipo, d) + '</td><td>' +
            esc(d && d.ultima ? fechaHora(d.ultima.consultado_en) : '—') + '</td>';
        var html;
        if (tipo === 'f22') {
            html = '<tr>' + base + '<td class="cf-acciones-celda">' +
                (d && d.ultima ? '<button type="button" class="cf-boton" data-sii-historial="1">Ver historial</button> ' : '') + botonConsultar(tipo, d) + '</td></tr>';
        } else {
            var hay = !!(d && d.ultima);
            html = '<tr><td>' + (hay ? '<button type="button" class="cf-boton cf-expandir" data-sii-expandir="1" aria-expanded="' + abierta + '">' + (abierta ? '▾' : '▸') + '</button>' : '') +
                '</td>' + base + '<td>' + botonConsultar(tipo, d) + '</td></tr>' +
                (hay && abierta ? '<tr><td colspan="6">' + tablaDeclaraciones(d) + '</td></tr>' : '');
        }
        document.querySelector('[data-sii-filas="' + tipo + '"]').innerHTML = html;
    }

    function cargar(tipo) {
        clearTimeout(timers[tipo]);
        var anio = anioDe(tipo);
        return pedir(url('../api/sii.php', 'consulta=' + tipo + '&anio=' + anio)).then(function (d) {
            if (anio !== anioDe(tipo)) return; // el usuario cambió de año mientras llegaba
            estados[tipo] = d;
            pintar(tipo);
            nota(tipo, d.ultima ? 'Datos del SII guardados en Cifrax. Solo consulta: nada se envía ni se modifica.' : '');
            if (enCurso(d)) timers[tipo] = setTimeout(function () { cargar(tipo); }, 5000);
        }).catch(function (e) {
            estados[tipo] = null;
            pintar(tipo);
            nota(tipo, e.message, true);
        });
    }

    function verHistorial() {
        var d = estados.f22;
        if (!d || !d.ultima) return;
        var x = d.ultima.datos;
        var eventos = (x.eventos || []).map(function (e) { return '<tr><td>' + esc(e.fecha) + '</td><td>' + esc(e.descripcion) + '</td></tr>'; }).join('') ||
            '<tr><td colspan="2">Sin eventos.</td></tr>';
        var observaciones = (x.observaciones || []).length
            ? '<h3>Observaciones</h3><ul>' + x.observaciones.map(function (o) { return '<li>' + esc(o) + '</li>'; }).join('') + '</ul>' : '';
        var codigos = (x.codigos || []).map(function (c) {
            return '<tr><td>' + esc(c.codigo) + '</td><td>' + esc(c.glosa) + '</td><td style="text-align:right">' + esc(c.valor) + '</td></tr>';
        }).join('');
        modal.querySelector('.cf-modal-caja').innerHTML =
            '<div class="cf-modal-cab"><div><strong id="cf-modal-titulo">' + esc(empresa.razon) + '</strong><div class="cf-nota">' + esc(empresa.rut) + ' · Año tributario ' + esc(d.anio) + '</div></div>' +
            '<button type="button" class="cf-boton" data-sii-cerrar="1" aria-label="Cerrar">×</button></div>' +
            '<p><span class="cf-estado ' + (d.ultima.estado === 'Aceptada' ? 'cf-listo' : 'cf-pendiente') + '">' + esc(d.ultima.estado) + '</span>' + detalleResultado('f22', d) + '</p>' +
            (x.situacion ? '<p>' + esc(x.situacion) + '</p>' : '') +
            '<div class="cf-cifras cf-cifras-2"><div class="cf-cifra"><span>Folio</span><strong>' + esc(x.folio || '—') + '</strong></div>' +
            '<div class="cf-cifra"><span>Presentada</span><strong>' + esc(x.presentada || '—') + '</strong></div>' +
            '<div class="cf-cifra"><span>Consultado</span><strong>' + esc(fechaHora(d.ultima.consultado_en)) + '</strong></div></div>' +
            observaciones + '<h3>Historial del SII</h3><table class="cf-tabla"><thead><tr><th>Fecha</th><th>Evento</th></tr></thead><tbody>' + eventos + '</tbody></table>' +
            (codigos ? '<div data-sii-codigos hidden><h3>Formulario 22 declarado</h3><table class="cf-tabla"><thead><tr><th>Código</th><th>Glosa</th><th>Valor</th></tr></thead><tbody>' + codigos + '</tbody></table></div>' : '') +
            '<div class="cf-acciones">' + (codigos ? '<button type="button" class="cf-boton principal" data-sii-ver-f22="1">Ver F22</button>' : '') +
            '<button type="button" class="cf-boton" data-sii-cerrar="1">Cerrar</button></div>';
        modal.hidden = false;
        modal.querySelector('[data-sii-cerrar]').focus();
    }

    // La ventana se cierra solo con la X, el botón Cerrar o Esc; un clic fuera no la cierra.
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) modal.hidden = true; });

    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-sii-consultar], [data-sii-historial], [data-sii-expandir], [data-sii-cerrar], [data-sii-ver-f22], [data-sii-observaciones]') : null;
        if (!b || b.disabled) return;
        if (b.hasAttribute('data-sii-cerrar')) { modal.hidden = true; return; }
        if (b.hasAttribute('data-sii-observaciones')) { verObservaciones(Number(b.getAttribute('data-sii-observaciones'))); return; }
        if (b.hasAttribute('data-sii-historial')) { verHistorial(); return; }
        if (b.hasAttribute('data-sii-ver-f22')) {
            var bloque = modal.querySelector('[data-sii-codigos]');
            bloque.hidden = !bloque.hidden;
            b.textContent = bloque.hidden ? 'Ver F22' : 'Ocultar F22';
            return;
        }
        if (b.hasAttribute('data-sii-expandir')) { abierta = !abierta; pintar('ddjj'); return; }
        var tipo = b.getAttribute('data-sii-consultar');
        b.disabled = true;
        pedir(url('../api/sii.php'), { tipo: 'consulta', consulta: tipo, anio: anioDe(tipo) })
            .then(function () { return cargar(tipo); })
            .catch(function (err) { b.disabled = false; nota(tipo, err.message, true); });
    });

    ['f22', 'ddjj'].forEach(function (tipo) {
        document.getElementById(tipo + '-anio').addEventListener('change', function () { estados[tipo] = null; pintar(tipo); cargar(tipo); });
        var paso = document.querySelector('.cf-paso[data-panel="p-' + tipo + '"]');
        if (paso) paso.addEventListener('click', function () { cargar(tipo); });
    });

    pedir(url('../api/cifrax-tributario.php', 'accion=empresa&anio=2026'))
        .then(function (f) {
            if (f && f.razon_social) empresa = { razon: f.razon_social, rut: f.rut || '' };
        })
        .catch(function () { /* se muestra el número de empresa */ })
        .then(function () { cargar('f22'); cargar('ddjj'); });
})();
