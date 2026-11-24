# Propuesta de rediseño de Cifrax

## Objetivo

Modernizar los módulos con un lenguaje visual común, conservando los nombres y procesos contables que ya conocen los usuarios. La prioridad es que siempre se entienda la empresa y el período activos, cuál es el siguiente paso y qué datos están pendientes, listos o contabilizados. Esta propuesta no cambia cálculos ni reglas contables.

## Hallazgos iniciales

- El catálogo contiene módulos principales y muchas pantallas auxiliares para crear, importar, configurar y reportar. Conviene rediseñarlos por familias y compartir componentes, no tratar cada archivo como una página independiente.
- Varias páginas principales repiten la navegación heredada de Bootstrap, el texto “Para un buen uso” y estilos de color repartidos en clases antiguas. Esto hace que los encabezados y acciones se sientan distintos entre pantallas.
- RCV ya tiene una capa visual más reciente, pero carga muchas acciones y estados juntos. En móvil el flujo y las tablas compiten por espacio.
- Las vistas de contabilidad, remuneraciones y reportes contienen información densa; necesitan jerarquía y controles consistentes antes que decoración.

## Dirección visual recomendada: Libro Cifrax

Una interfaz luminosa y sobria, construida alrededor del isotipo geométrico y el wordmark real de Cifrax. La propuesta evita el lenguaje visual SaaS intercambiable: tarjetas flotantes por cada cifra, exceso de cápsulas, degradados y cuadrículas repetidas.

| Uso | Color propuesto |
|---|---|
| Navegación y estructura | Azul profundo del logo `#082B4C` |
| Acciones y enlaces | Azul Cifrax `#0B6E9F` |
| Acento del isotipo | Turquesa `#008F9B` |
| Acento de selección | Naranja `#F39800` |
| Superficie de trabajo | Marfil grisáceo `#F2F0E9` |
| Papel, formularios y tablas | Blanco cálido `#FFFDFA` |
| Líneas de libro | Gris cálido `#D9DDD9` |
| Correcto o contabilizado | Verde `#27734F` |

- Tipografía: sans-serif de sistema para datos y lectura continua; títulos de página con una serif editorial discreta. Números con cifras tabulares para comparar montos.
- Espaciado: escala de 4, 8, 12, 16, 24 y 32 px; radios discretos de 8 px.
- Botones: una acción primaria azul por pantalla; naranja para selección activa o aviso; acciones destructivas separadas y confirmadas.
- Estados: texto además de color, en etiquetas rectangulares pequeñas, con términos consistentes para `Pendiente`, `En proceso`, `Completado`, `Contabilizado`, `Revisión` y `Error`.
- Tablas: encabezados persistentes cuando sea útil, filtros agrupados, montos alineados a la derecha y desborde horizontal controlado en móvil.

## Propuesta por familia de módulos

### 1. Inicio y navegación

- Sustituir la barra superior repetida por un marco común: navegación lateral plegable en escritorio y menú lateral desplegable en móvil.
- Mantener visible el contexto de empresa y período junto al título de la vista. Cambiar empresa debe ser una acción explícita y reconocible.
- En el inicio, priorizar accesos frecuentes, períodos que requieren atención y actividad reciente. Mover los consejos de uso a Ayuda, sin ventanas emergentes que tapen el trabajo.

### 2. Empresas, usuarios y perfil

- Empresas: búsqueda y filtros arriba; filas con razón social, RUT y estado; acciones con etiquetas o ayuda accesible. Abrir el detalle/edición en un panel amplio en escritorio y pantalla completa en móvil.
- Usuarios: separar solicitudes pendientes de usuarios activos; mostrar rol y empresas asignadas con claridad; colocar aprobar, editar rol y desactivar en una zona de acciones consistente.
- Perfil y parámetros globales: agrupar por tema, explicar el alcance de cada ajuste y separar configuración personal de configuración de empresa.

### 3. Contabilidad: plan, compras, ventas y vouchers

- Plan de cuentas: árbol por clasificación con búsqueda por código/nombre y acciones de agregar/editar junto a la cuenta seleccionada.
- Compras y ventas: filtros compactos por fecha, contraparte y documento; resumen de totales sobre la tabla; importación separada de creación manual.
- Voucher: formulario por pasos cortos —cabecera, líneas, revisión— con Debe/Haber siempre visibles y diferencia destacada hasta cuadrar. En móvil, cada línea pasa a una tarjeta editable.

### 4. RCV

- Presentarlo como un flujo de período: elegir rango y libros, revisar estado de descarga, ver documentos importados y centralizar un mes.
- La lista de períodos debe ser el punto de entrada a la contabilización y mostrar por libro: descarga, cantidad de registros y estado contable. El resultado final debe decir `Contabilizado` y ofrecer consultar el voucher.
- La vista previa debe resumir totales, errores, duplicados y ajustes antes de confirmar. Los resúmenes tipo 48 deben identificarse como agregados, sin sugerir que existen documentos individuales.
- En móvil, filtros y acciones apilados; registros recientes como filas resumidas/tarjetas; tabla detallada con desplazamiento dentro de su propio contenedor.

### 5. Remuneraciones

- Convertir el menú en una secuencia de trabajo por período: parámetros, personal, variables, cálculo, revisión, liquidaciones y Previred/LRE.
- Mostrar arriba período, estado de cierre y trabajadores procesados. Distinguir visualmente datos editables, valores calculados y períodos cerrados.
- Mantener tablas para cálculos comparables; en móvil, cada trabajador debe poder revisarse sin tablas comprimidas.

### 6. Banco y tesorería

- Dividir carga de cartola, conciliación y reporte en secciones numeradas y persistentes.
- Mostrar movimientos pendientes y conciliados con filtros claros; permitir comparar monto, fecha y contraparte en una sola fila.
- En tesorería, separar ingresos, pagos programados y vencidos con saldos visibles y estados accionables.

### 7. Reportes y analítica

- Agrupar reportes por propósito: libros, estados financieros, impuestos, remuneraciones y análisis.
- Mantener filtros de período y empresa en una barra común. Mostrar primero el resultado y dejar exportar/imprimir como acciones secundarias.
- En analítica, usar gráficos solo para tendencias y comparaciones; conservar tablas detalladas para revisión contable.

### 8. Configuración y control

- Organizar parámetros por tema y alcance, con búsqueda y descripción breve.
- En control/auditoría, priorizar quién hizo qué y cuándo; usar detalle expandible para conservar la tabla escaneable.
- Distinguir las funciones administrativas según rol sin ocultar estados que ayuden al usuario a entender por qué no puede realizar una acción.

## Tres conceptos de interfaz para comparar

1. **Libro Cifrax (recomendado):** colores tomados del logo, fondo marfil, líneas finas y jerarquía tipográfica editorial. Conserva densidad de trabajo sin paneles decorativos.
2. **Consola financiera:** navegación azul profunda, superficies blancas y tablas densas. Optimiza escritorio y uso experto, pero puede sentirse pesada en móvil.
3. **Operación guiada:** superficies claras, pasos numerados y estados visibles. Facilita RCV, remuneraciones y configuración inicial; ocupa más espacio y puede alargar tareas expertas.

La recomendación es usar **Libro Cifrax** como base y aplicar **Operación guiada** solo a procesos secuenciales como RCV, liquidaciones e importaciones.

## Orden de trabajo propuesto

1. Marco común, navegación, cabecera de empresa/período, botones, campos, tablas y estados.
2. RCV como piloto: concentra problemas móviles conocidos y permite validar el patrón de período/estado/confirmación.
3. Contabilidad diaria: vouchers, compras, ventas y plan de cuentas.
4. Remuneraciones y banco/tesorería.
5. Reportes, analítica, configuración y control.

## Cómo se revisarán las propuestas

- **Professional UX Designer** como guía principal para flujos administrativos, accesibilidad, estados y diseño adaptable.
- **UI UX Pro Max** como referencia para explorar sistemas visuales, tokens y patrones por tipo de producto.
- **Impeccable** como lista de revisión para jerarquía, responsive, accesibilidad y consistencia al cerrar cada familia.

Se aplicarán como método de diseño y revisión; esta propuesta no instala dependencias ni modifica la interfaz todavía. La primera implementación debe comenzar con el marco común y el piloto RCV, comparar escritorio y móvil, y luego extender los componentes aprobados a los demás módulos.
