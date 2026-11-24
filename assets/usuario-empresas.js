'use strict';

const busqueda = document.getElementById('buscar-empresa');
if (busqueda) {
    const filas = Array.from(document.querySelectorAll('[data-empresa]'));
    const sinCoincidencias = document.getElementById('sin-coincidencias');
    const normalizar = texto => texto.toLocaleLowerCase('es-CL').normalize('NFD').replace(/[\u0300-\u036f.]/g, '').trim();
    document.getElementById('busqueda-empresas').hidden = false;
    busqueda.addEventListener('input', () => {
        const termino = normalizar(busqueda.value);
        let coincidencia = false;
        for (const fila of filas) {
            fila.hidden = ![fila.cells[1], fila.cells[2]].some(celda => normalizar(celda.textContent).includes(termino));
            coincidencia ||= !fila.hidden;
        }
        sinCoincidencias.hidden = coincidencia;
    });
    const modoSeleccion = document.querySelector('input[name="modo"][value="seleccion"]');
    if (modoSeleccion) {
        for (const fila of filas) {
            fila.querySelector('input').addEventListener('change', () => {
                modoSeleccion.checked = true;
            });
        }
    }
}
