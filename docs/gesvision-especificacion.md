# Especificación de pantallas de referencia (Gesvision)

Documento de especificación funcional para que los hilos de oftaplus implementen pantallas equivalentes con HTML y CSS propios. No incluye código, estilos, imágenes ni datos de clientes.

Estado: **parcial**. Solo se documentan las pantallas vistas sin abrir formularios nuevos. Las demás quedan pendientes hasta que Eri dé un sí directo por cada una, porque abrir un formulario puede crear un borrador en su cuenta.

## 1. Barra superior

Orden de izquierda a derecha:

1. Selector de sucursal o empresa (desplegable con el nombre de la sucursal activa).
2. Botón "Descargas pendientes" (ícono de descarga con contador de pendientes).
3. Botón "Control horario" (ícono de reloj).
4. Botón "Calendario" (ícono de calendario).
5. Botón "+" con la etiqueta "New" sobre él. Abre el menú de creación (ver sección 2).
6. Botón "Configuración" (ícono de engranaje).
7. Menú de usuario: nombre del usuario y rol (por ejemplo, ADMIN).

## 2. Menú "+" (Nuevo documento)

Agrupado en tres secciones, con un ícono por opción:

- **Ventas:** Crear boleta, Crear factura completa, Crear pedido cliente, Crear presupuesto.
- **Compras:** Crear guía recibida, Crear factura recibida.
- **Almacén:** Crear modelo.

Cada opción abre el formulario de creación del tipo de documento correspondiente. Ya existe una versión equivalente en oftaplus (ver `docs/gesvision-botones-faltantes.md`).

## 3. Menú lateral

Sección "GENERAL" con:

- Campo de búsqueda con botón de lupa.
- Ingresos (ícono de gráfico de línea).
- Gastos (ícono de billete).
- Almacén (ícono de cajas).
- CRM (ícono de sobre).
- Análisis, con una etiqueta "New" en azul.

El panel se puede contraer con una flecha "<" en su encabezado.

## 4. Dashboard

### 4.1 Barra de informes

Pestañas en una fila:

- Informe activo, resaltado en negro, con ícono de estrella: **Ventas del día**.
- Pestañas no activas en borde gris: **Gráficos**, **Ventas mensuales**, **Ventas del día**.
- Botón punteado **+ Nuevo** para crear un informe.
- Botón **Vista** con ícono de máscara.
- Botón **Marcar como predeterminado** (con ícono de estrella).
- Botón **Editar dashboard** a la derecha.

Debajo, una franja informativa con ícono de información y el texto: "Nota: Las fechas y filtros pertenecen a cada informe. Para editarlos, abre el informe en el constructor."

### 4.2 Tarjetas de indicadores

Cada tarjeta muestra, de arriba hacia abajo:

1. Título en mayúsculas (por ejemplo, VENTAS DEL MES ACTUAL).
2. Rango de fechas en una etiqueta gris (formato AAAA-MM-DD - AAAA-MM-DD).
3. Monto principal en negrita, con separador de miles y dos decimales, y símbolo de moneda al final.

Tarjetas presentes: Ventas del mes actual, Ventas del día, Total cobrado día, Total cobrado mes.

## 5. Formulario de venta (Crear venta)

Se abre como ventana modal sobre el dashboard, con barra de título "Crear Venta", nombre de la empresa y botones de minimizar y cerrar.

### 5.1 Encabezado

- Número del documento (a la izquierda, con formato AAAA/NNNNNN).
- Fecha y hora de creación, con el texto "Puede editar" y un ícono de candado abierto.
- Campo **Cliente** con búsqueda desplegable y botón de persona. Valor por defecto: "VENTA DIRECTA".

### 5.2 Datos comerciales

Cuatro desplegables en una fila: **Agente**, **Tarifa dtos.**, **Motivo vis.**, **Cond. pago**.

### 5.3 Detalle de artículos

- Botón "Crear OT" a la derecha (orden de trabajo).
- Tabla con columnas: quitar (X), #, Concepto, Referencia, D, Base, PVP, Unidad, Bruto, %Dto, Neto.
- Primera fila vacía con campo "Busque aquí" para buscar el artículo.
- Descuento e IVA se pueden ingresar en euros, además de porcentaje (tooltips: "Pulse para introducir descuento en euros" y "Pulse para el IVA en euros").

### 5.4 Cobros

- Tabla con columnas Fecha, F. Pago, Importe.
- Mensaje "No hay ningún cobro" cuando está vacía.
- Enlace "Añadir nuevo cobro" y, a la derecha, "Pdt. pago" con el saldo pendiente.

### 5.5 Barra de acciones inferior

Botones en fila: **Cobros**, **Doc. Asociados**, **Stock**, **Vistos buenos**, **Observaciones**, **Óptica** (específico de un rubro; verificar si aplica a oftaplus).

Pie de la ventana:

- Indicadores de totales: **Base**, **Cuota IVA** y **Total**.
- Barra de íconos con: navegación anterior/siguiente, adjuntos, configuración, notificaciones, marcas de estado, y un ícono de cajón.
- Botón **Cerrar** y botón **Cobrar** con menú desplegable de opciones.

### 5.6 Acciones disponibles en la ventana

Controles detectados en la ventana (su ubicación exacta dentro de la barra no quedó verificada):

- Selector de plantillas para el formulario.
- Mandar una notificación enlazada al documento.
- Imprimir el documento (con casillas para elegir copia).
- Ver tarifas de venta activas.
- Abrir cajón portamonedas.

## 6. Pendientes de documentar

Cada una requiere un sí directo de Eri antes de abrirla:

- Formularios de Crear boleta, Crear pedido cliente, Crear presupuesto, Crear guía recibida, Crear factura recibida y Crear modelo.
- Pestañas de Gráficos, Ventas mensuales y el constructor de informes.
- Pantallas de Almacén, Calendario, Control horario y Descargas pendientes.
