'use strict';

(() => {
    const pantalla = window.matchMedia('(max-width: 767px)');
    const menus = new Map();
    let siguienteId = 0;

    function registrarMenu(control, menu, nombre) {
        if (!control || !menu) return;
        menu.id ||= 'cifrax-movil-menu-' + ++siguienteId;
        menu.classList.add('cifrax-movil-menu');
        control.setAttribute('aria-label', nombre);
        control.setAttribute('aria-controls', menu.id);
        menus.set(control, menu);
    }

    function mostrarMenu(control, menu, visible) {
        control.setAttribute('aria-expanded', String(visible));
        menu.classList.toggle('abierto', visible);
        if (visible) menu.removeAttribute('hidden');
    }

    registrarMenu(document.querySelector('.navbar-inverse > .navbar-header .navbar-toggle'),
        document.getElementById('menu-principal'), 'Menú principal');

    document.addEventListener('DOMContentLoaded', () => {
        const cabecera = document.querySelector('.navbar-inverse > .navbar-header');
        if (!cabecera || cabecera.querySelector('.cifrax-marca')) return;
        const enlace = document.createElement('a');
        enlace.className = 'cifrax-marca cifrax-marca-movil';
        enlace.href = 'inicio-cuenta.html';
        const logo = document.createElement('img');
        logo.src = '/assets/cifrax-logo-blanco.png';
        logo.alt = 'Cifrax, inicio';
        enlace.append(logo);
        cabecera.prepend(enlace);
    }, {once: true});

    document.querySelectorAll('.ic-has-dropdown').forEach(grupo => {
        const control = grupo.querySelector(':scope > .ic-trigger-link');
        const lista = grupo.querySelector(':scope > .ic-dropdown-list');
        registrarMenu(control, lista, 'Módulos');
    });

    document.querySelectorAll('.barr-actions-container').forEach(barra => {
        registrarMenu(barra.querySelector('.ic-act-mobile-btn'), barra.querySelector(':scope > .ic-actions-list'), 'Acciones del módulo');
    });

    document.querySelectorAll('.ic-act-has-dropdown').forEach(grupo => {
        const enlace = grupo.querySelector(':scope > a');
        const lista = grupo.querySelector(':scope > ul');
        if (!enlace || !lista) return;
        const nombre = enlace.textContent.trim().replace(/\s+/g, ' ');
        if (enlace.getAttribute('href') === '#') {
            registrarMenu(enlace, lista, nombre);
        } else {
            const boton = document.createElement('button');
            boton.type = 'button';
            boton.textContent = '▾';
            enlace.after(boton);
            grupo.classList.add('cifrax-movil-submenu');
            registrarMenu(boton, lista, 'Opciones de ' + nombre);
        }
    });

    function prepararMenus() {
        for (const [control, menu] of menus) {
            if (pantalla.matches) {
                mostrarMenu(control, menu, false);
                if (control.matches('a[href="#"]')) control.setAttribute('role', 'button');
            } else {
                menu.classList.remove('abierto');
                if (control.matches('a[href="#"]')) control.removeAttribute('role');
                control.removeAttribute('aria-expanded');
            }
        }
    }

    document.addEventListener('click', evento => {
        if (!pantalla.matches) return;
        const control = evento.target.closest('a, button');
        const menu = menus.get(control);
        if (!menu) return;
        evento.preventDefault();
        evento.stopImmediatePropagation();
        const abrir = !menu.classList.contains('abierto');
        if (abrir) {
            for (const [otroControl, otroMenu] of menus) {
                if (otroMenu !== menu && !otroMenu.contains(control)) mostrarMenu(otroControl, otroMenu, false);
            }
        }
        mostrarMenu(control, menu, abrir);
    }, true);

    document.addEventListener('keydown', evento => {
        if (!pantalla.matches) return;
        if (evento.key === ' ' && evento.target.matches('a[role="button"]') && menus.has(evento.target)) {
            evento.preventDefault();
            evento.target.click();
        }
        if (evento.key === 'Escape') {
            const abiertos = [...menus].filter(([control, menu]) =>
                menu.classList.contains('abierto') && (menu.contains(evento.target) || control === evento.target));
            const ultimo = abiertos.at(-1);
            if (ultimo) {
                evento.preventDefault();
                mostrarMenu(...ultimo, false);
                ultimo[0].focus();
            }
        }
    });

    document.querySelectorAll('form[id*="filtro" i], form:has(> .flex-align-center)').forEach(formulario => {
        if (formulario.querySelector(':scope > [class*="col-"]')) formulario.classList.add('cifrax-movil-filtros');
    });
    document.querySelectorAll('.bloq_220px:has(.icontador-modulos-dropdown)').forEach(titulo => {
        const barra = titulo.parentElement.parentElement;
        if (barra.querySelector('.barr-actions-container, .navbar-inverse-submenu')) barra.classList.add('cifrax-movil-barra');
    });

    const selectorTabla = 'table.dataTable, table.display, table.conta-tabla, table.table, .table-container table, .vouchers-table, .ban-tabla, .dl-tabla';
    function adaptarTabla(tabla) {
        if (!tabla.isConnected || tabla.parentElement?.classList.contains('cifrax-movil-tabla') ||
            tabla.closest('#conta-impresion, .footer_msj, .dl-hoja')) return;
        const fila = tabla.tHead?.rows[0];
        if (!fila || fila.cells.length < 4) return;
        const area = document.createElement('div');
        area.className = 'cifrax-movil-tabla';
        area.setAttribute('role', 'region');
        area.setAttribute('aria-label', tabla.caption?.textContent.trim() || 'Tabla con desplazamiento horizontal');
        area.tabIndex = 0;
        tabla.before(area);
        area.append(tabla);
    }

    let observador;
    function prepararTablas() {
        if (!pantalla.matches) {
            observador?.disconnect();
            observador = undefined;
            document.querySelectorAll('.cifrax-movil-tabla').forEach(area => area.replaceWith(...area.childNodes));
            return;
        }
        document.querySelectorAll(selectorTabla).forEach(adaptarTabla);
        if (observador) return;
        observador = new MutationObserver(cambios => {
            for (const cambio of cambios) {
                for (const nodo of cambio.addedNodes) {
                    if (!(nodo instanceof Element)) continue;
                    if (nodo.matches(selectorTabla)) adaptarTabla(nodo);
                    nodo.querySelectorAll(selectorTabla).forEach(adaptarTabla);
                }
            }
        });
        observador.observe(document.body, {childList: true, subtree: true});
    }

    function ajustarPantalla() {
        prepararMenus();
        prepararTablas();
    }

    document.documentElement.classList.add('cifrax-movil-listo');
    ajustarPantalla();
    pantalla.addEventListener('change', ajustarPantalla);
})();
