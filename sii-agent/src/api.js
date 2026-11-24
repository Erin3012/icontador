'use strict';
// Cliente de qlc.cl (api/sii-agente.php). Se autentica con el token técnico del servicio, no con una sesión.

class ErrorServidor extends Error {
  constructor(mensaje, estado) {
    super(mensaje);
    this.name = 'ErrorServidor';
    this.estado = estado;
  }
}

function servidorBase(url) {
  let parsed;
  try { parsed = new URL(String(url)); }
  catch { throw new Error('SII_URL_SERVIDOR no es una URL válida.'); }
  const loopback = ['localhost', '127.0.0.1', '::1'].includes(parsed.hostname.toLowerCase());
  if (parsed.protocol !== 'https:' && !(parsed.protocol === 'http:' && loopback)) {
    throw new Error('El agente solo acepta HTTPS, salvo un servidor local de desarrollo.');
  }
  return parsed.origin.replace(/\/+$/, '');
}

function crearServidor({ url, token, workerId, fetchImpl = globalThis.fetch }) {
  const base = servidorBase(url) + '/api/sii-agente.php';

  async function pedir(metodo, accion, cuerpo) {
    const res = await fetchImpl(`${base}?accion=${accion}`, {
      method: metodo,
      redirect: 'error',
      headers: { 'X-Agente-Token': token, 'X-Agente-Worker': workerId || '', ...(cuerpo ? { 'Content-Type': 'application/json' } : {}) },
      body: cuerpo ? JSON.stringify(cuerpo) : undefined,
    });
    const datos = await res.json().catch(() => ({}));
    if (!res.ok) throw new ErrorServidor(datos.error || `Error ${res.status} del servidor.`, res.status);
    return datos;
  }

  return {
    heartbeat: async () => pedir('POST', 'heartbeat', { worker_id: workerId }),
    pendientes: async () => (await pedir('GET', 'pendientes')).tareas,
    estado: async () => pedir('GET', 'estado'),
    controlar: (tareaId, accion) => pedir('POST', 'control', { tarea_id: tareaId, accion }),
    finalizarControl: (tareaId, accion) => pedir('POST', 'finalizar_control', { tarea_id: tareaId, accion }),
    evento: (tareaId, etapa, detalle = '') => pedir('POST', 'evento', { tarea_id: tareaId, etapa, detalle: String(detalle) }),
    captura: (tareaId, jpeg) => pedir('POST', 'captura', { tarea_id: tareaId, jpeg: Buffer.from(jpeg).toString('base64') }),
    importar: (tareaId, datos) => pedir('POST', 'importar', { tarea_id: tareaId, ...datos }),
    resultadoEmpresa: (tareaId, datos) => pedir('POST', 'resultado_empresa', { tarea_id: tareaId, datos }),
    fallar: (tareaId, mensaje) => pedir('POST', 'error', { tarea_id: tareaId, mensaje: String(mensaje).slice(0, 500) }),
    liberar: async () => pedir('POST', 'liberar', { worker_id: workerId }),
  };
}

/**
 * Enrolamiento único: canjea el código de servicio de un administrador por el token central.
 */
async function emparejarAgente({ url, codigo, nombre, fetchImpl = globalThis.fetch }) {
  const base = servidorBase(url) + '/api/sii-agente.php';
  const res = await fetchImpl(`${base}?accion=canjear_servicio`, {
    method: 'POST',
    redirect: 'error',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ codigo: String(codigo).trim(), nombre: String(nombre || '').slice(0, 80) }),
  });
  const datos = await res.json().catch(() => ({}));
  if (!res.ok || typeof datos.token !== 'string' || !/^[a-f0-9]{64}$/i.test(datos.token)) {
    throw new ErrorServidor(datos.error || `No se pudo enrolar el agente central (error ${res.status}). Genera un código nuevo en qlc.cl.`, res.status);
  }
  return datos.token;
}

module.exports = { crearServidor, emparejarAgente, ErrorServidor };
