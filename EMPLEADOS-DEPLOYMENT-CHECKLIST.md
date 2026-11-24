# Despliegue Módulo Empleados - QLC.cl

**Estado:** ✅ **LISTO PARA DESPLEGAR**

**Fecha:** 2026-10-07  
**Branch:** `claude/project-thread-kgwsfp`  
**Objetivo:** Módulo Empleados funcionando en https://qlc.cl

---

## ✅ COMPLETADO

### 1. Desarrollo Local
- ✅ api/empleados.php - Endpoint REST CRUD
- ✅ api/lib/empleados.php - Funciones de base de datos
- ✅ Validación de datos (RUT, nombre, etc.)
- ✅ Soporte para SQLite y MySQL

### 2. Archivos de Despliegue
- ✅ deploy/cpanel/api/empleados.php
- ✅ deploy/cpanel/api/lib/empleados.php
- ✅ deploy/cpanel/api/schema/empleados-create.sql
- ✅ deploy/cpanel/api/db.php (conexión)

### 3. Scripts de Despliegue
- ✅ deploy-to-cpanel.py (Python SFTP)
- ✅ deploy-empleados-ssh.sh (Bash SSH)

### 4. Control de Versiones
- ✅ Commit a branch: `claude/project-thread-kgwsfp`
- ✅ Push a GitHub

---

## 📋 PASOS DE DESPLIEGUE

### PASO 1: Transferir Archivos a Servidor (SFTP)

```bash
# Windows/Mac/Linux con Python:
python3 deploy-to-cpanel.py

# O manual con sftp:
sftp qlccl_icontador@s490.v2nets.com
> cd public_html/api
> put deploy/cpanel/api/empleados.php
> put deploy/cpanel/api/lib/empleados.php
> quit
```

**Servidor:** s490.v2nets.com  
**Usuario:** qlccl_icontador  
**Ruta remota:** ~/public_html/api/

### PASO 2: Crear Tabla en Base de Datos MySQL

**Opción A - phpMyAdmin en cPanel (Recomendado):**
1. Abrir: https://cpanel.s490.v2nets.com/
2. Ingresar con credenciales cPanel
3. Ir a: phpMyAdmin
4. Seleccionar BD: `qlccl_icontador`
5. Tab: SQL
6. Copiar contenido de: `deploy/cpanel/api/schema/empleados-create.sql`
7. Click: Ir (ejecutar)

**Opción B - SSH directo:**
```bash
ssh qlccl_icontador@s490.v2nets.com
mysql -u qlccl_icontador -p qlccl_icontador < ~/public_html/api/schema/empleados-create.sql
```

**SQL a ejecutar:**
```sql
CREATE TABLE IF NOT EXISTS empleados (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL DEFAULT 0,
  rut VARCHAR(20) NOT NULL,
  nombre VARCHAR(255) NOT NULL,
  email VARCHAR(255),
  telefono VARCHAR(20),
  cargo VARCHAR(255),
  fecha_ingreso DATE,
  direccion VARCHAR(255),
  banco_cuenta VARCHAR(50),
  tipo_contrato VARCHAR(50),
  estado VARCHAR(50) NOT NULL DEFAULT 'activo',
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_rut_empresa (empresa_id, rut),
  KEY idx_empleados_empresa (empresa_id),
  KEY idx_empleados_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### PASO 3: Verificar en Navegador

1. Abrir: https://qlc.cl
2. Navegar: Documentos Laborales → Empleados
3. Debería mostrar: Lista vacía sin errores
4. Crear empleado de prueba

**URL API:**
- GET: `https://qlc.cl/api/empleados.php?empresa_id=1`
- POST: `https://qlc.cl/api/empleados.php?empresa_id=1`

---

## 🔧 API REST - Documentación Técnica

### Endpoints

#### Listar Empleados
```
GET /api/empleados.php?empresa_id=1
Response: {"empleados": [...]}
```

#### Obtener Empleado
```
GET /api/empleados.php?id=5
Response: {"empleado": {...}}
```

#### Crear Empleado
```
POST /api/empleados.php?empresa_id=1
Body: {"rut": "12345678-9", "nombre": "Juan", ...}
Response: {"id": 1}
```

#### Actualizar Empleado
```
PUT /api/empleados.php?id=5
Body: {"nombre": "Juan Actualizado", ...}
Response: {"success": true}
```

#### Eliminar Empleado
```
DELETE /api/empleados.php?id=5
Response: {"success": true}
```

### Campos Disponibles
- **rut** (requerido): RUT del empleado
- **nombre** (requerido): Nombre completo
- **email**: Email de contacto
- **telefono**: Teléfono de contacto
- **cargo**: Posición laboral
- **fecha_ingreso**: Fecha de ingreso (YYYY-MM-DD)
- **direccion**: Dirección
- **banco_cuenta**: Número de cuenta bancaria
- **tipo_contrato**: Tipo de contrato
- **estado**: Estado (activo/inactivo)

---

## 📞 Información de Conexión

| Parámetro | Valor |
|-----------|-------|
| Servidor | s490.v2nets.com |
| Usuario SSH | qlccl_icontador |
| Base de datos | qlccl_icontador |
| Usuario DB | qlccl_icontador |
| DocRoot | ~/public_html/ |
| URL Sitio | https://qlc.cl |
| URL API | https://qlc.cl/api/empleados.php |

---

## ⚠️ Rollback/Recuperación

Si hay problemas:

1. **Backup antes de cambios:** Crear backup en phpMyAdmin
2. **Revertir archivos:** Copiar versión anterior desde repositorio
3. **Restaurar BD:** phpMyAdmin → Import

Archivo de schema backup:
```bash
# Respaldar tabla actual
mysqldump -u qlccl_icontador -p qlccl_icontador empleados > empleados_backup.sql
```

---

## 📝 Notas

- ✅ Módulo soporta SQLite (local) y MySQL (producción)
- ✅ Validación de datos en servidor (no confíar en cliente)
- ✅ Índices optimizados para búsquedas frecuentes
- ✅ Timestamps automáticos (creado_en, actualizado_en)
- ✅ Clave única: (empresa_id, rut)

---

**Autorizado por:** Eri  
**Commits:** b835a9a  
**Estado:** 🟢 **LISTO PARA PRODUCCIÓN**
