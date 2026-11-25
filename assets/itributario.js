'use strict';
// Pantalla iTributario: preferencia de sincronización y solicitudes de envío del balance.
// La API es api/itributario.php. El envío real a iTributario todavía no está conectado (ver la respuesta de la API).
(function () {
  var API = '../api/itributario.php';
  var aviso = document.getElementById('itrib-aviso');
  var selPeriodo = document.getElementById('itrib-periodo');
  var btnSincronizar = document.getElementById('itrib-sincronizar');
  var btnGuardar = document.getElementById('itrib-guardar');
  var tbody = document.getElementById('itrib-historial');

  // Empresa de la pantalla: se toma de ?empresa_id= (la misma que usan las demás APIs).
  function empresaId() {
    return new URLSearchParams(window.location.search).get('empresa_id') || '';
  }

  function url(accion) {
    var partes = [];
    if (accion) partes.push('accion=' + encodeURIComponent(accion));
    if (empresaId()) partes.push('empresa_id=' + encodeURIComponent(empresaId()));
    return partes.length ? API + '?' + partes.join('&') : API;
  }

  function pedir(metodo, accion, cuerpo) {
    var opciones = { method: metodo, credentials: 'same-origin', headers: {} };
    if (cuerpo) {
      opciones.headers['Content-Type'] = 'application/json';
      opciones.body = JSON.stringify(cuerpo);
    }
    return fetch(url(accion), opciones).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (datos) {
        if (!res.ok) throw new Error(datos.error || ('Error ' + res.status + ' del servidor.'));
        return datos;
      });
    });
  }

  function mostrarAviso(texto, tipo) {
    aviso.textContent = texto;
    aviso.className = 'itrib-aviso ' + (tipo || '');
    aviso.hidden = false;
  }

  // Períodos: año tributario actual y los tres anteriores (el balance se referencia al 31 de diciembre).
  function cargarPeriodos() {
    var anio = new Date().getFullYear();
    for (var a = anio; a >= anio - 3; a--) {
      var op = document.createElement('option');
      op.value = a + '-12';
      op.textContent = String(a);
      selPeriodo.appendChild(op);
    }
  }

  function marcarPreferencia(valor) {
    var radio = document.querySelector('input[name="itrib-pref"][value="' + valor + '"]');
    if (radio) radio.checked = true;
  }

  function pintarHistorial(filas) {
    tbody.textContent = '';
    if (!filas || !filas.length) {
      var vacio = document.createElement('tr');
      var celda = document.createElement('td');
      celda.colSpan = 5;
      celda.textContent = 'Aún no hay sincronizaciones.';
      vacio.appendChild(celda);
      tbody.appendChild(vacio);
      return;
    }
    filas.forEach(function (f) {
      var tr = document.createElement('tr');
      [f.creado_en, f.periodo, f.origen, f.estado, f.mensaje].forEach(function (texto) {
        var td = document.createElement('td');
        td.textContent = texto || '';
        tr.appendChild(td);
      });
      tbody.appendChild(tr);
    });
  }

  function cargar() {
    pedir('GET', '').then(function (datos) {
      marcarPreferencia(datos.auto_sync || 'ask');
      pintarHistorial(datos.historial);
    }).catch(function (e) {
      mostrarAviso(e.message, 'error');
    });
  }

  btnGuardar.addEventListener('click', function () {
    var elegida = document.querySelector('input[name="itrib-pref"]:checked');
    if (!elegida) return mostrarAviso('Elija una opción de sincronización automática.', 'error');
    btnGuardar.disabled = true;
    pedir('POST', 'preferencia', { auto_sync: elegida.value }).then(function () {
      mostrarAviso('Preferencia guardada.', 'exito');
    }).catch(function (e) {
      mostrarAviso(e.message, 'error');
    }).finally(function () { btnGuardar.disabled = false; });
  });

  btnSincronizar.addEventListener('click', function () {
    var periodo = selPeriodo.value;
    if (!window.confirm('¿Enviar el balance del período ' + periodo.slice(0, 4) + ' a iTributario?')) return;
    btnSincronizar.disabled = true;
    pedir('POST', 'sincronizar', { periodo: periodo, origen: 'manual' }).then(function (datos) {
      mostrarAviso(datos.mensaje || 'Solicitud registrada.', datos.exito === 1 ? 'exito' : 'pendiente');
      return pedir('GET', '');
    }).then(function (datos) {
      pintarHistorial(datos.historial);
    }).catch(function (e) {
      mostrarAviso(e.message, 'error');
    }).finally(function () { btnSincronizar.disabled = false; });
  });

  // "Ir a Cifrax Tributario" abre el visor (pasos 1 a 7) en una segunda ventana.
  var btnIr = document.getElementById('itrib-ir');
  btnIr.addEventListener('click', function () {
    // Se abre en otra pestana y lleva la empresa de esta pantalla.
    var id = empresaId();
    window.open('/visorcifraxtributario/' + (id ? '?empresa_id=' + encodeURIComponent(id) : ''), '_blank');
  });

  cargarPeriodos();
  cargar();
})();
