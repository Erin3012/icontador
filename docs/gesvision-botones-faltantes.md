# Botones de Gesvision vs. oftaplus / icontador

Inventario de controles visibles en la interfaz de referencia (solo nombres y ubicación, sin código ni datos de clientes) y su estado en esta app.

Estado: **Existe** = hay equivalente funcional. **Parcial** = hay algo parecido pero no el mismo control. **Falta** = no hay equivalente.

## Barra superior

| Botón / control | Estado | Equivalente en la app |
|---|---|---|
| Nuevo documento (+) | Existe | Desplegable en `views/ventas.html` y `views/compras.html` (commit 59b256c) |
| Descargas pendientes | Falta | Sin bandeja de descargas |
| Calendario | Falta | Sin calendario |
| Control horario | Falta | Sin registro de horario |
| Configuración (engranaje) | Parcial | Parámetros en `views/parametros*.html` |
| Selector de empresa/sucursal | Parcial | Selector de empresa existente en `views/panel.html` |
| Menú de usuario | Existe | Salir / perfil en `views/panel.html` |

## Menú lateral

| Sección | Estado | Equivalente en la app |
|---|---|---|
| Ingresos | Parcial | Ventas (`views/ventas.html`) |
| Gastos | Parcial | Compras (`views/compras.html`) |
| Almacén | Falta | Sin módulo de inventario/almacén |
| CRM | Existe | `views/crm.html` |
| Análisis | Parcial | `views/analitica*.html` |
| Búsqueda en el menú | Falta | Sin buscador en el menú lateral |

## Dashboard

| Control | Estado | Equivalente en la app |
|---|---|---|
| Pestañas de informes (Ventas del día, Gráficos, Ventas mensuales) | Parcial | `views/dashboard-ejecutivo.html` con tarjetas fijas |
| + Nuevo informe | Falta | Sin informes configurables |
| Marcar como predeterminado | Falta | Sin dashboard predeterminado |
| Editar dashboard | Falta | Sin edición de tarjetas |
| Selector de fechas por tarjeta | Falta | Sin filtro de fechas por tarjeta |

## Formulario de venta (Crear venta)

| Control | Estado | Equivalente en la app |
|---|---|---|
| Cobros / Añadir nuevo cobro | Falta | Sin registro de cobros en la venta |
| Cobrar | Falta | Sin acción de cobro |
| Doc. Asociados | Falta | Sin vínculo con documentos asociados |
| Stock | Falta | Sin control de stock |
| Vistos buenos | Falta | Sin flujo de aprobación |
| Observaciones | Falta | Sin campo de observaciones en venta |
| Crear OT (orden de trabajo) | Falta | Sin órdenes de trabajo |
| Imprimir documento | Parcial | Impresión de libros en `views/reportes-*.html` |
| Selector de plantillas | Falta | Sin plantillas de documento |
| Mandar notificación del documento | Falta | Sin notificación ligada al documento |
| Ver tarifas de venta activas | Falta | Sin tarifas de venta |
| Abrir cajón portamonedas | Falta | Sin integración con cajón de efectivo |
| Descuento en euros / IVA en euros | Falta | Solo descuento en porcentaje (`%Dto`) |

## Módulos sin equivalente

- Almacén (stock, modelos, guías)
- Notificaciones y descargas pendientes
- Calendario y control horario
- Órdenes de trabajo
- Tarifas de venta
- Cobros de venta
