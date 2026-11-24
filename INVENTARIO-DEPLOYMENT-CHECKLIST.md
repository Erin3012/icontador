# Despliegue Módulo Libro de Inventario y Balance - QLC.cl

**Estado:** ✅ **LISTO PARA DESPLEGAR**

**Fecha:** 2026-10-08  
**Branch:** `claude/project-thread-inventario`  
**Commit base:** `d26ad06` (Merge: 8 Features Complete)  
**Objetivo:** Módulo Inventario funcionando en https://qlc.cl

---

## ✅ COMPLETADO

### 1. Desarrollo Local
- ✅ api/inventario.php - Endpoint REST CRUD
- ✅ api/lib/inventario.php - Funciones de base de datos
- ✅ Validación de datos (código, descripción, cantidad, etc.)
- ✅ Soporte para SQLite y MySQL

### 2. Archivos de Despliegue
- ✅ api/inventario.php (CRUD operations)
- ✅ api/lib/inventario.php (Database functions)
- ✅ api/schema/inventario.mysql.sql (Schema)
- ✅ tools/test-inventario-api.php (API tests)

### 3. Integración
- ✅ Registro en api/libros.php para endpoint 'inventario'
- ✅ Integración con balance general
- ✅ Soporte para export PDF/EXCEL/CSV
- ✅ Registro en assets/contabilidad.js

### 4. Control de Versiones
- ✅ Commit en branch: `claude/project-thread-inventario`
- ✅ Push a GitHub

---

## 📋 PASOS DE DESPLIEGUE

### PASO 1: Transferir Archivos a Servidor (SSH)

```bash
# Desde local, git archive para crear tarball
git archive HEAD --prefix=icontador/ -o icontador-inventario.tar.gz

# Transferir y extraer en server
scp -P 52990 -i C:\Users\local1\.ssh\oftaplus_cpanel_ed25519 \
  icontador-inventario.tar.gz qlccl@s490.v2nets.com:~/backups-icontador/

ssh -p 52990 -i C:\Users\local1\.ssh\oftaplus_cpanel_ed25519 qlccl@s490.v2nets.com << 'EOF'
cd ~/backups-icontador
tar xzf icontador-inventario.tar.gz
cp -a icontador/* ~/public_html/
EOF
```

### PASO 2: Crear Tabla en Base de Datos MySQL

**Opción A - SSH directo:**
```bash
ssh -p 52990 -i C:\Users\local1\.ssh\oftaplus_cpanel_ed25519 qlccl@s490.v2nets.com << 'EOF'
cd ~/public_html
php -r '
$config = require "api/config.php";
$pdo = new PDO($config["dsn"], $config["user"], $config["password"]);
require "api/lib/inventario.php";
inventario_schema($pdo);
echo "✅ Schema created\n";
'
EOF
```

**SQL a ejecutar:**
```sql
CREATE TABLE IF NOT EXISTS inventario_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL DEFAULT 0,
  codigo VARCHAR(20) NOT NULL,
  descripcion VARCHAR(255) NOT NULL,
  categoria VARCHAR(100) NOT NULL DEFAULT '',
  cantidad INT NOT NULL DEFAULT 0,
  valor_unitario BIGINT NOT NULL DEFAULT 0,
  fecha_ingreso VARCHAR(10),
  ubicacion VARCHAR(100) NOT NULL DEFAULT '',
  observaciones TEXT,
  creado_en VARCHAR(25) NOT NULL,
  actualizado_en VARCHAR(25) NOT NULL,
  UNIQUE KEY uk_empresa_codigo (empresa_id, codigo),
  KEY idx_categoria (empresa_id, categoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### PASO 3: Verificar en Servidor

```bash
ssh -p 52990 -i C:\Users\local1\.ssh\oftaplus_cpanel_ed25519 qlccl@s490.v2nets.com << 'EOF'
echo "=== Verificar archivos ==="
ls -la ~/public_html/api/inventario.php
ls -la ~/public_html/api/lib/inventario.php

echo ""
echo "=== Verificar tabla ==="
php -r '
$config = require "api/config.php";
$pdo = new PDO($config["dsn"], $config["user"], $config["password"]);
$result = $pdo->query("SHOW TABLES LIKE '"'"'inventario_items'"'"'")->fetch();
if ($result) {
  echo "✅ Table inventario_items exists\n";
} else {
  echo "❌ Table NOT FOUND\n";
}
'

echo ""
echo "=== Verificar API ==="
curl -s -H "X-Empresa-Id: 1" http://localhost/api/inventario.php | head -c 100
EOF
```

### PASO 4: Verificar en Navegador

1. Abrir: https://qlc.cl
2. Ingresar sesión
3. Navegar: Reportes → Libro de Inventario y Balance
4. Ver interface del módulo

**URL API:**
- GET: `https://qlc.cl/api/inventario.php` (lista de items)
- POST: `https://qlc.cl/api/inventario.php` (crear/actualizar)
- DELETE: `https://qlc.cl/api/inventario.php?codigo=...` (eliminar)

---

## 🔧 API REST - Documentación Técnica

### Endpoints

#### Listar Items
```
GET /api/inventario.php
Response: {"items": [...]}
```

#### Crear/Actualizar Item
```
POST /api/inventario.php
Body: {"codigo": "INV-001", "descripcion": "Laptop", "categoria": "Equipos", "cantidad": 5, "valor_unitario": 1500000, ...}
Response: {"id": 1, "success": true}
```

#### Eliminar Item
```
DELETE /api/inventario.php?codigo=INV-001
Response: {"success": true}
```

### Campos Disponibles
- **codigo** (requerido): Código único del item
- **descripcion** (requerido): Descripción del item
- **categoria**: Categoría (Equipos, Mobiliario, etc.)
- **cantidad**: Cantidad en stock
- **valor_unitario**: Valor unitario (entero, centavos incluidos)
- **fecha_ingreso**: Fecha de ingreso (YYYY-MM-DD)
- **ubicacion**: Ubicación física
- **observaciones**: Notas adicionales

---

## 📞 Información de Conexión

| Parámetro | Valor |
|-----------|-------|
| Servidor | s490.v2nets.com |
| Usuario SSH | qlccl |
| Base de datos | qlccl_icontador |
| DocRoot | ~/public_html/ |
| URL Sitio | https://qlc.cl |
| URL API | https://qlc.cl/api/inventario.php |

---

## ⚠️ Rollback/Recuperación

Si hay problemas:

1. **Backup antes de cambios:** Respaldar tabla actual
2. **Revertir archivos:** Restaurar versión anterior desde repositorio
3. **Restaurar BD:** Usar backup de tabla

```bash
# Respaldar tabla actual
mysqldump -u qlccl_icontador -p qlccl_icontador inventario_items > inventario_items_backup.sql
```

---

## 📝 Notas

- ✅ Módulo soporta SQLite (local) y MySQL (producción)
- ✅ Integración con api/libros.php para reportes combinados
- ✅ Índices optimizados para búsquedas por categoría
- ✅ Valores en centavos (entero BIGINT)
- ✅ Clave única: (empresa_id, codigo)
- ✅ Integración con balance general

---

**Autorizado por:** Eri  
**Commit base:** d26ad06  
**Branch:** claude/project-thread-inventario  
**Estado:** 🟢 **LISTO PARA PRODUCCIÓN**
