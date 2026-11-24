'use strict';
// Control visible de consulta MiSII en la ficha de Empresas.
(function () {
  const API = '../api/sii.php';
  const METADATOS = ['fecha_constitucion','fecha_inicio_actividades','categoria_tributaria','tipo_contribuyente','subtipo_contribuyente','segmento','glosa_descriptiva'];
  const dormir = (ms) => new Promise((resolver) => setTimeout(resolver, ms));
  const campo = (form, nombre) => form?.elements?.namedItem(nombre) || null;

  function formularioEmpresa() {
    return Array.from(document.forms).find((form) => campo(form, 'razon_social') && campo(form, 'rut')) || null;
  }

  async function abrirFormulario() {
    let form = formularioEmpresa();
    if (form) return form;
    const crear = Array.from(document.querySelectorAll('a.ic-btn-action-nav')).find((a) => /Crear Empresa/i.test(a.textContent || ''));
    crear?.click();
    for (let i = 0; i < 80; i++) {
      form = formularioEmpresa();
      if (form) return form;
      await dormir(100);
    }
    return null;
  }

  function instalarEnvioFicha(form) {
    if (form.dataset.siiFichaEnvio === '1') return;
    form.dataset.siiFichaEnvio = '1';
    form.addEventListener('submit', () => {
      if (!form.dataset.fichaSii) return;
      let input = form.querySelector('input[name="ficha_sii"][data-sii-ficha]');
      if (!input) { input = document.createElement('input'); input.type = 'hidden'; input.name = 'ficha_sii'; input.dataset.siiFicha = '1'; form.append(input); }
      input.value = form.dataset.fichaSii;
    }, true);
    form.addEventListener('reset', () => { form.dataset.fichaSii = ''; });
  }

  function mostrarDatos(panel, datos) {
    const resumen = panel.querySelector('#empresas-sii-resumen-estatico');
    const lista = [
      ['Actividades', (datos.actividades || []).map(x => `${x.descripcion || ''}${x.codigo ? ` (${x.codigo})` : ''}`).filter(Boolean).join('; ')],
      ['Sucursales', (datos.sucursales || []).map(x => `${x.tipo || ''}: ${x.direccion || ''}`).filter(Boolean).join('; ')],
      ['Características tributarias', (datos.caracteristicas || []).map(x => x.nombre).filter(Boolean).join('; ')],
      ['Documentos autorizados', (datos.documentos_autorizados || []).map(x => x.tipo).filter(Boolean).join('; ')],
    ];
    resumen.replaceChildren();
    const dl = document.createElement('dl'); dl.className = 'empresas-sii-datos';
    for (const [nombre, texto] of lista) if (texto) {
      const dt = document.createElement('dt'); dt.textContent = nombre;
      const dd = document.createElement('dd'); dd.textContent = texto;
      dl.append(dt, dd);
    }
    resumen.append(dl); resumen.hidden = false;
  }

  async function consultar(panel) {
    const estado = panel.querySelector('#empresas-sii-estado-estatico');
    const claveInput = panel.querySelector('#empresas-sii-clave-estatica');
    const boton = panel.querySelector('#empresas-sii-consultar-estatico');
    let secreto = claveInput.value;
    if (!secreto) { estado.textContent = 'Ingresa la Clave Tributaria del SII.'; return; }
    boton.disabled = true; claveInput.value = ''; estado.textContent = 'Leyendo el RUT del formulario…';
    try {
      const form = await abrirFormulario();
      if (!form) throw new Error('No encontré el formulario Crear Empresa. Ábrelo y vuelve a intentar.');
      const rutControl = campo(form, 'rut');
      const rut = String(rutControl?.value || '').trim();
      if (!rut) throw new Error('Completa el RUT en el formulario Crear Empresa antes de consultar.');
      instalarEnvioFicha(form);
      estado.textContent = 'Comprobando conexión con el agente SII…';
      const servicioResp = await fetch(`${API}?servicio=1`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const servicio = await servicioResp.json();
      if (!servicioResp.ok) throw new Error(servicio.error || 'No se pudo consultar el agente SII.');
      if (!servicio.disponible) throw new Error('El agente SII está desconectado. Inténtalo cuando vuelva a estar conectado.');
      const csrfResp = await fetch(`${API}?csrf=1`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const csrf = await csrfResp.json();
      if (!csrfResp.ok) throw new Error(csrf.error || 'La sesión venció. Recarga la página.');
      const envio = await fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf.csrf, Accept: 'application/json' }, body: JSON.stringify({ tipo: 'datos_empresa', rut, clave: secreto }) });
      secreto = '';
      const tarea = await envio.json();
      if (!envio.ok) throw new Error(tarea.error || 'No se pudo iniciar la consulta.');
      estado.textContent = 'Consulta enviada. Esperando respuesta de MiSII…';
      const inicio = Date.now(); let datos = null;
      while (Date.now() - inicio < 120000) {
        await dormir(2000);
        const respuesta = await fetch(`${API}?tarea=${encodeURIComponent(tarea.id)}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const actual = await respuesta.json();
        if (!respuesta.ok) throw new Error(actual.error || 'No se pudo consultar el estado.');
        if (actual.estado === 'error') throw new Error(actual.mensaje || 'MiSII no pudo completar la consulta.');
        if (actual.estado === 'completada') { datos = actual.datos; break; }
      }
      if (!datos) throw new Error('La consulta sigue pendiente. Inténtalo más tarde.');
      for (const nombre of ['razon_social','rut','giro','regimen','telefono','email','domicilio','comuna','region', ...METADATOS]) {
        let input = campo(form, nombre);
        if (!input && METADATOS.includes(nombre)) {
          input = document.createElement('input'); input.type = 'hidden'; input.name = nombre; input.dataset.siiFicha = '1'; form.append(input);
        }
        if (input && datos[nombre]) input.value = datos[nombre];
      }
      form.dataset.fichaSii = JSON.stringify(datos);
      mostrarDatos(panel, datos);
      estado.textContent = 'Datos obtenidos y cargados en Crear Empresa. Revísalos antes de guardar.';
    } catch (error) {
      estado.textContent = error.message || 'No se pudo consultar MiSII.';
    } finally {
      secreto = ''; claveInput.value = ''; boton.disabled = false;
    }
  }

  function iniciar() {
    const panel = document.querySelector('#empresas-sii-estatico');
    if (!panel || panel.dataset.siiListo === '1') return;
    panel.dataset.siiListo = '1';
    // Si la versión actual del formulario ya trae la consulta incorporada, usa esa vista y oculta el respaldo.
    if (document.querySelector('form.empresas-crear .empresas-sii-consulta')) { panel.hidden = true; return; }
    panel.querySelector('#empresas-sii-consultar-estatico').addEventListener('click', () => consultar(panel));
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar, { once: true });
  else iniciar();
})();
