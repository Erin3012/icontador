'use strict';
// Convierte las respuestas que cargan las pantallas del SII (consulta del F22 y de declaraciones juradas)
// en los datos que guarda Cifrax. Sin navegador: se prueba con respuestas de ejemplo.

const ENTIDADES = { amp: '&', lt: '<', gt: '>', quot: '"', nbsp: ' ', aacute: 'á', eacute: 'é', iacute: 'í', oacute: 'ó', uacute: 'ú', ntilde: 'ñ',
  Aacute: 'Á', Eacute: 'É', Iacute: 'Í', Oacute: 'Ó', Uacute: 'Ú', Ntilde: 'Ñ', uuml: 'ü', deg: '°', ordm: 'º' };

// Datos del F22 que no son cifras de la declaración: identificación y contacto (nombre, RUT, dirección, teléfono,
// correo), cuenta bancaria para la devolución (301, 306, 780), software, representante y marcas internas.
// Desde 8000 son datos técnicos (direcciones IP, certificado).
const CODIGOS_INTERNOS = new Set([1, 2, 3, 5, 6, 8, 9, 10, 11, 12, 48, 53, 55, 102, 301, 306, 312, 780, 903, 3056, 3057, 3061]);

function limpiar(texto) {
  return String(texto ?? '')
    .replace(/&#(\d+);/g, (_m, n) => String.fromCodePoint(Number(n)))
    .replace(/&([a-zA-Z]+);/g, (m, n) => ENTIDADES[n] ?? m)
    .replace(/\s+/g, ' ')
    .trim();
}

function numero(valor) {
  const solo = String(valor ?? '').replace(/\./g, '').trim();
  return /^-?\d+$/.test(solo) ? Number(solo) : null;
}

function lista(respuesta) {
  return Array.isArray(respuesta?.data) ? respuesta.data : [];
}

/** "2026-03-29T22:08:15" -> "29/03/2026 22:08". Otros formatos quedan como vienen. */
function fechaHora(texto) {
  const m = /^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2}))?/.exec(String(texto ?? ''));
  if (!m) return limpiar(texto);
  const fecha = `${m[3]}/${m[2]}/${m[1]}`;
  return m[4] && `${m[4]}:${m[5]}` !== '00:00' ? `${fecha} ${m[4]}:${m[5]}` : fecha;
}

/** Estado del F22 a partir de la frase de "Situación Renta Actual" del SII. */
function estadoF22(situacion) {
  const t = limpiar(situacion).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  if (/devolucion[^.]*(retenid|retuvo)|(retenid|retuvo)[^.]*devolucion/.test(t)) return 'Devolución retenida';
  if (/observad|inconsistenc|(?<!no )presenta diferencias|no ha sido aceptad|rechazad|impugnad/.test(t)) return 'Con inconsistencia';
  if (/aceptad|autoriz.*devolucion/.test(t)) return 'Aceptada';
  if (/proceso|revision|recibida/.test(t)) return 'En proceso';
  return 'Otro';
}

function parsearF22(anio, r) {
  const folios = lista(r.consultaFolios);
  const vigente = folios.find((f) => String(f.vgte) === '1') || folios[0] || null;
  const situacion = (r.buscaDeclVgte?.data?.glosas || []).map((g) => limpiar(g.descripcion)).filter(Boolean).join(' ');
  const eventos = lista(r.buscaEventos)
    .filter((e) => !vigente || e.folio == null || String(e.folio) === String(vigente.folio))
    .map((e) => ({ fecha: limpiar(e.fechaEvento), descripcion: limpiar(e.nombre) }));
  const observaciones = lista(r.situacionObservacion)
    .map((o) => limpiar(o.descripcion || o.glosa || o.nombre))
    .filter(Boolean);
  const codigos = lista(r.f22Completo)
    .map((c) => ({ codigo: Number(c.codigo), glosa: limpiar(c.glosa), valor: limpiar(c.valor) }))
    .filter((c) => Number.isInteger(c.codigo) && c.codigo < 8000 && !CODIGOS_INTERNOS.has(c.codigo) && c.valor !== '');
  const valor = (codigo) => numero(codigos.find((c) => c.codigo === codigo)?.valor);
  const estado = !vigente ? 'Sin declaración' : estadoF22(situacion || eventos.at(-1)?.descripcion);
  return {
    tipo: 'f22', anio, estado, situacion,
    folio: vigente ? numero(vigente.folio) : null,
    presentada: vigente ? limpiar(vigente.fecIng) : '',
    eventos, observaciones,
    resultado: { devolucion: valor(87), pago: valor(94) || valor(91) },
    codigos,
  };
}

const DJ_OBSERVADA = /observad|rechazad|inconsist/i;

/** Observaciones de una declaración (servicio getDjObservacion): código, descripción, motivo y orientación del SII. */
function parsearObservaciones(respuesta) {
  const filas = Array.isArray(respuesta) ? respuesta : lista(respuesta);
  return filas
    .map((o) => ({ codigo: limpiar(o.observacion), descripcion: limpiar(o.descripcion), motivo: limpiar(o.glosaObservacion), orientacion: limpiar(o.orientacion) }))
    .filter((o) => o.codigo || o.descripcion || o.motivo);
}

/** Declaraciones observadas del detalle anual, con la llave que pide el servicio de observaciones. */
function declaracionesObservadas(detalle) {
  return lista(detalle).flatMap((item) => (Array.isArray(item.result) ? item.result : [])
    .filter((d) => DJ_OBSERVADA.test(d.estado || ''))
    .map((d) => ({ dj: Number(item.dj), key: d.key })));
}

function parsearDdjj(anio, detalle, observaciones = {}) {
  const declaraciones = [];
  for (const item of lista(detalle)) {
    for (const d of Array.isArray(item.result) ? item.result : []) {
      declaraciones.push({
        formulario: Number(item.dj), nombre: limpiar(item.titulo), estado: limpiar(d.estado), folio: numero(d.folio),
        presentada: fechaHora(d.fecha), tipo: limpiar(d.tipoDeclaracion), medio: limpiar(d.medioPresentacion), casos: numero(d.casos),
        observaciones: parsearObservaciones(observaciones[d.key] || []),
      });
    }
  }
  let estado = 'En revisión';
  const vigentes = declaraciones.filter((d) => !/anulad/i.test(d.estado));
  if (!declaraciones.length) estado = 'Sin DJ presentadas';
  else if (declaraciones.some((d) => DJ_OBSERVADA.test(d.estado))) estado = 'Con observadas';
  else if (vigentes.length && vigentes.every((d) => /aceptad/i.test(d.estado))) estado = 'Todas aceptadas';
  return { tipo: 'ddjj', anio, estado, declaraciones };
}

module.exports = { parsearF22, parsearDdjj, parsearObservaciones, declaracionesObservadas, estadoF22, limpiar, fechaHora };
