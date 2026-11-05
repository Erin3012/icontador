# Inspección visible del acceso al SII (Playwright)

Fecha de inspección: 2026-11-03. Se usó Google Chrome en modo headless con un contexto nuevo y vacío. No se reutilizaron perfiles/cookies, no se ingresaron credenciales, no se enviaron formularios y no se inspeccionaron llamadas de red, almacenamiento del navegador ni rutas privadas.

## Resultado observado

### Portal público

URL: <https://homer.sii.cl/>

- Portal público “SII | Servicio de Impuestos Internos”.
- Enlaces visibles: “Ingresar a Mi Sii”, “Servicios online”, “Contáctanos” y “Ayudas”.
- Acceso al RCV publicado dentro de Servicios online como “Registro de compras y ventas”.

### Acceso al RCV

URL solicitada: <https://www4.sii.cl/consdcvinternetui/#/index>

Al abrirla sin sesión, el SII redirigió a su pantalla de autenticación en `zeusr.sii.cl`, mostrando:

- Identificación de Contribuyentes.
- Campo visible de RUT (placeholder de ejemplo `Ej: 123456789`).
- Campo de tipo contraseña “Clave Tributaria”.
- Botón “INGRESAR”.
- Alternativa “Ingresar con Certificado Digital”.
- Enlaces “Solicitar Clave” y “Recuperar Clave”.

Playwright confirmó que la interfaz requiere autenticación antes de mostrar el Registro de Compras y Ventas. No se probó el botón de ingreso. La consulta no permite documentar todavía las vistas post-autenticación, filtros de período, detalles, estados de descargas diferidas ni archivos exportados.

## Procedimiento para repetir

Desde la raíz del proyecto:

```powershell
node .\sii-agent\tools\inspect-sii-ui.cjs
```

El script usa una instancia de Chrome con contexto temporal y solo resume elementos visibles. No acepta parámetros de contraseña ni persiste el contexto. Si Playwright/Chrome no está instalado, instalar las dependencias de `sii-agent` primero.

## Límites y siguiente paso

Esta inspección documenta la navegación pública, no el canal técnico autorizado. El titular informó el 5 de noviembre de 2026 que recibió autorización del SII; falta incorporar al repositorio la especificación técnica y el alcance para adaptar y habilitar la integración. Hasta entonces, el agente mantiene bloqueadas las consultas automáticas y se usa la exportación oficial/manual del RCV.

- [Términos de uso del sitio web SII](https://www.sii.cl/sobre_el_sii/terminos_sitio_web.html)
- [Registro de Compras y Ventas: página de servicios online](https://www.sii.cl/servicios_online/1039-3256.html)
- [Pregunta frecuente sobre descargas diferidas del RCV](https://www.sii.cl/preguntas_frecuentes/factura_electronica/001_003_7006.htm)

## Artefacto

El recolector de inspección está en `tools/inspect-sii-ui.cjs`. Su salida es solo para documentación técnica; no contiene cookies, credenciales, contenido de respuesta ni datos de una empresa.
