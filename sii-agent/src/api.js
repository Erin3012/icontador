'use strict';
// Cliente del servidor de Cifrax (api/sii-agente.php). Se autentica con el token secreto, no con una sesión.

class ErrorServidor extends Error {
  constructor(mensaje, estado) {
    super(mensaje);
    this.name = 'ErrorServidor';
    this.estado = estado;
  }
}

function crearServidor({ url, token, fetchImpl = globalThis.fetch }) {
  const base = String(url).replace(/\/+$/, '') + '/api/sii-agente.php';

  async function pedir(metodo, accion, cuerpo) {
    const res = await fetchImpl(`${base}?accion=${accion}`, {
      method: metodo,
      headers: { 'X-Agente-Token': token, ...(cuerpo ? { 'Content-Type': 'application/json' } : {}) },
      body: cuerpo ? JSON.stringify(cuerpo) : undefined,
    });
    const datos = await res.json().catch(() => ({}));
    if (!res.ok) throw new ErrorServidor(datos.error || `Error ${res.status} del servidor.`, res.status);
    return datos;
  }

  return {
    pendientes: async () => (await pedir('GET', 'pendientes')).tareas,
    importar: (tareaId, datos) => pedir('POST', 'importar', { tarea_id: tareaId, ...datos }),
    fallar: (tareaId, mensaje) => pedir('POST', 'error', { tarea_id: tareaId, mensaje: String(mensaje).slice(0, 500) }),
    resultadoEmpresa: (tareaId, datos) => pedir('POST', 'resultado_empresa', { tarea_id: tareaId, ...datos }),
  };
}

module.exports = { crearServidor, ErrorServidor };
