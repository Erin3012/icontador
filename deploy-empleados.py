#!/usr/bin/env python3
"""
Script de despliegue para Módulo Empleados - PR #20
Copia archivos y prepara el despliegue a cPanel
"""
import os
import shutil
import subprocess
from datetime import datetime

PROYECTO = os.path.expanduser("~/mnt/icontador")
DEPLOY_DIR = os.path.join(PROYECTO, "deploy", "cpanel")
BACKUP_DIR = os.path.join(PROYECTO, "data", "backups")
TIMESTAMP = datetime.now().strftime("%Y%m%d_%H%M%S")

def crear_directorio(ruta):
    os.makedirs(ruta, exist_ok=True)
    return ruta

def copiar_archivo(src, dst):
    """Copiar archivo, creando directorio destino si es necesario"""
    os.makedirs(os.path.dirname(dst), exist_ok=True)
    try:
        shutil.copy2(src, dst)
        return True
    except Exception as e:
        print(f"✗ Error copiando {os.path.basename(src)}: {e}")
        return False

print("=" * 50)
print("Despliegue - Módulo Empleados")
print("=" * 50)
print()

# Verificar que estamos en el directorio correcto
if not os.path.isdir(PROYECTO):
    print(f"✗ Directorio del proyecto no encontrado: {PROYECTO}")
    exit(1)

print(f"Proyecto: {PROYECTO}")
print(f"Deploy a: {DEPLOY_DIR}")
print()

# Paso 1: Crear directorios necesarios
print("1. Creando directorios...")
crear_directorio(os.path.join(DEPLOY_DIR, "api", "lib"))
crear_directorio(os.path.join(DEPLOY_DIR, "api", "schema"))
crear_directorio(BACKUP_DIR)
print("   ✓ Directorios creados")
print()

# Paso 2: Copiar archivos del módulo Empleados
print("2. Copiando archivos de Empleados...")
archivos_empleados = [
    ("api/empleados.php", f"{DEPLOY_DIR}/api/empleados.php"),
    ("api/lib/empleados.php", f"{DEPLOY_DIR}/api/lib/empleados.php"),
]

for src, dst in archivos_empleados:
    src_path = os.path.join(PROYECTO, src)
    if os.path.exists(src_path):
        if copiar_archivo(src_path, dst):
            print(f"   ✓ {src}")
    else:
        print(f"   ⚠ No encontrado: {src}")

print()

# Paso 3: Copiar otros módulos actualizados
print("3. Copiando módulos relacionados...")
otros_modulos = [
    ("api/banco.php", f"{DEPLOY_DIR}/api/banco.php"),
    ("api/honorarios.php", f"{DEPLOY_DIR}/api/honorarios.php"),
    ("api/rrhh.php", f"{DEPLOY_DIR}/api/rrhh.php"),
    ("api/sugerencias.php", f"{DEPLOY_DIR}/api/sugerencias.php"),
    ("api/usuario.php", f"{DEPLOY_DIR}/api/usuario.php"),
    ("api/lib/banco.php", f"{DEPLOY_DIR}/api/lib/banco.php"),
    ("api/lib/honorarios.php", f"{DEPLOY_DIR}/api/lib/honorarios.php"),
    ("api/lib/rrhh.php", f"{DEPLOY_DIR}/api/lib/rrhh.php"),
    ("api/lib/auth.php", f"{DEPLOY_DIR}/api/lib/auth.php"),
    ("api/lib/sesion.php", f"{DEPLOY_DIR}/api/lib/sesion.php"),
]

for src, dst in otros_modulos:
    src_path = os.path.join(PROYECTO, src)
    if os.path.exists(src_path):
        if copiar_archivo(src_path, dst):
            print(f"   ✓ {os.path.basename(src)}")

print()

# Paso 4: Crear schema SQL para despliegue
print("4. Preparando schema de empleados...")
schema_file = os.path.join(DEPLOY_DIR, "api", "schema", "empleados-create.sql")
schema_sql = """-- Tabla de Empleados
-- Ejecutar esto en cPanel phpMyAdmin en la base de datos qlccl_icontador
-- Solamente si la tabla no existe (CREATE TABLE IF NOT EXISTS es idempotente)

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
"""

try:
    with open(schema_file, 'w', encoding='utf-8') as f:
        f.write(schema_sql)
    print(f"   ✓ Schema guardado en: api/schema/empleados-create.sql")
except Exception as e:
    print(f"   ✗ Error guardando schema: {e}")

print()

# Paso 5: Crear resumen de despliegue
print("5. Generando resumen de despliegue...")
resumen_file = os.path.join(PROYECTO, f"DEPLOY-SUMMARY-{TIMESTAMP}.txt")
resumen = f"""DESPLIEGUE - MÓDULO EMPLEADOS (PR #20)
{datetime.now().isoformat()}

ESTADO: Listo para desplegar a qlc.cl

ARCHIVOS PREPARADOS EN: deploy/cpanel/
  - api/empleados.php
  - api/lib/empleados.php
  - Módulos relacionados (banco, honorarios, rrhh, etc.)

SCHEMA SQL: deploy/cpanel/api/schema/empleados-create.sql

PRÓXIMOS PASOS:
1. Hacer backup en cPanel (phpMyAdmin → Export)
2. Copiar carpeta deploy/cpanel/api/ a ~/public_html/api/ en s490.v2nets.com
3. En cPanel phpMyAdmin, ejecutar el SQL para crear tabla empleados
4. Verificar en https://qlc.cl (Ctrl+F5)

CONEXIÓN PRODUCCIÓN:
  Servidor: s490.v2nets.com (cPanel)
  Base de datos: qlccl_icontador (MySQL)
  Usuario: qlccl_icontador
  DocRoot: ~/public_html/

AUTORIZACIÓN: Eri autorizó despliegue autonomo (confío en ti)
"""

try:
    with open(resumen_file, 'w', encoding='utf-8') as f:
        f.write(resumen)
    print(f"   ✓ Resumen: {os.path.basename(resumen_file)}")
except Exception as e:
    print(f"   ✗ Error creando resumen: {e}")

print()
print("=" * 50)
print("✓ PREPARACIÓN COMPLETADA")
print("=" * 50)
print()
print("Archivos listos en:")
print(f"  {DEPLOY_DIR}/")
print()
print("Para desplegar a producción:")
print("1. Copiar deploy/cpanel/api/* a s490.v2nets.com:~/public_html/api/")
print("2. Ejecutar SQL en cPanel phpMyAdmin")
print("3. Verificar en https://qlc.cl")
