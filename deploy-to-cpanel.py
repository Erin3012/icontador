#!/usr/bin/env python3
"""
Script de despliegue completo para Módulo Empleados a qlc.cl via SSH/SFTP
"""
import os
import sys
import getpass
import subprocess
from pathlib import Path
from datetime import datetime

# Configuración
PROYECTO = os.getcwd()
DEPLOY_DIR = os.path.join(PROYECTO, "deploy", "cpanel", "api")
REMOTE_HOST = "s490.v2nets.com"
REMOTE_USER = "qlccl_icontador"
REMOTE_PATH = "~/public_html/api"
DB_NAME = "qlccl_icontador"
DB_USER = "qlccl_icontador"
TIMESTAMP = datetime.now().strftime("%Y%m%d_%H%M%S")

def print_section(title):
    """Imprimir sección"""
    print()
    print("=" * 50)
    print(title)
    print("=" * 50)
    print()

def check_files():
    """Verificar que los archivos necesarios existen"""
    print("1. Verificando archivos locales...")
    files_to_check = [
        os.path.join(DEPLOY_DIR, "empleados.php"),
        os.path.join(DEPLOY_DIR, "lib", "empleados.php"),
        os.path.join(DEPLOY_DIR, "schema", "empleados-create.sql"),
    ]

    for f in files_to_check:
        if os.path.exists(f):
            print(f"   ✓ {os.path.relpath(f, PROYECTO)}")
        else:
            print(f"   ✗ Falta: {os.path.relpath(f, PROYECTO)}")
            return False
    return True

def deploy_sftp():
    """Desplegar via SFTP"""
    print()
    print("2. Desplegando a servidor via SFTP...")
    print(f"   Servidor: {REMOTE_HOST}")
    print(f"   Usuario: {REMOTE_USER}")
    print(f"   Ruta remota: {REMOTE_PATH}")
    print()

    try:
        # Crear script de SFTP
        sftp_script = f"""
cd public_html/api
mkdir -p lib
mkdir -p schema
lcd deploy/cpanel/api
put empleados.php
put lib/empleados.php lib/
put schema/empleados-create.sql schema/
ls -la
bye
"""

        # Escribir script temporal
        temp_script = "sftp_deploy.txt"
        with open(temp_script, 'w') as f:
            f.write(sftp_script)

        # Ejecutar sftp
        print(f"   Conectando a {REMOTE_USER}@{REMOTE_HOST}...")
        result = subprocess.run(
            ["sftp", "-b", temp_script, f"{REMOTE_USER}@{REMOTE_HOST}"],
            capture_output=False
        )

        os.remove(temp_script)

        if result.returncode == 0:
            print("   ✓ Archivos transferidos exitosamente")
            return True
        else:
            print("   ✗ Error en la transferencia SFTP")
            return False

    except Exception as e:
        print(f"   ✗ Error: {e}")
        return False

def show_db_setup():
    """Mostrar instrucciones para configurar la base de datos"""
    print()
    print("3. Configuración de Base de Datos")
    print()
    print("   IMPORTANTE: Ejecutar en cPanel phpMyAdmin")
    print()
    print("   Pasos:")
    print("   1. Abrir: https://cpanel.s490.v2nets.com/")
    print("   2. Ir a: phpMyAdmin")
    print("   3. Seleccionar BD: " + DB_NAME)
    print("   4. Click en tab: SQL")
    print("   5. Copiar contenido de: deploy/cpanel/api/schema/empleados-create.sql")
    print("   6. Click: Ir (ejecutar)")
    print()
    print("   O ejecutar por SSH:")
    print(f"   ssh {REMOTE_USER}@{REMOTE_HOST}")
    print(f"   mysql -u {DB_USER} -p {DB_NAME} < ~/public_html/api/schema/empleados-create.sql")
    print()

def create_summary():
    """Crear resumen de despliegue"""
    summary_file = f"DEPLOY-EMPLEADOS-{TIMESTAMP}.txt"

    summary = f"""DESPLIEGUE - MÓDULO EMPLEADOS
{datetime.now().isoformat()}

✓ COMPLETADO:
  - Archivos PHP copiados a deploy/cpanel/api/
  - Transferencia SFTP a {REMOTE_HOST}:{REMOTE_PATH}

⚠ PENDIENTE:
  - Crear tabla en BD: {DB_NAME} (phpMyAdmin o SSH)
  - Verificar en https://qlc.cl

ARCHIVOS DESPLEGADOS:
  - {REMOTE_PATH}/empleados.php
  - {REMOTE_PATH}/lib/empleados.php
  - {REMOTE_PATH}/schema/empleados-create.sql

SQL A EJECUTAR:
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

VERIFICACIÓN:
  1. https://qlc.cl → Documentos Laborales → Empleados
  2. Debería mostrar lista vacía (sin errores)
  3. Crear empleado de prueba

CONTACTO:
  - Servidor: s490.v2nets.com
  - BD: {DB_NAME}
  - Usuario: {DB_USER}
  - DocRoot: ~/public_html/
"""

    with open(summary_file, 'w', encoding='utf-8') as f:
        f.write(summary)

    print(f"   ✓ Resumen guardado: {summary_file}")
    return summary_file

def main():
    print_section("DESPLIEGUE - MÓDULO EMPLEADOS A QLC.CL")

    # Verificar archivos
    if not check_files():
        print("✗ Error: Archivos faltantes")
        return False

    print("   ✓ Todos los archivos presentes")
    print()

    # Desplegar via SFTP
    if not deploy_sftp():
        print("✗ Error en despliegue SFTP")
        return False

    # Mostrar instrucciones de BD
    show_db_setup()

    # Crear resumen
    summary = create_summary()

    print_section("ESTADO DEL DESPLIEGUE")
    print("✓ COMPLETADO - Próximos pasos:")
    print()
    print("1. Crear tabla en BD (phpMyAdmin o SSH)")
    print("2. Verificar en https://qlc.cl")
    print("3. Crear empleado de prueba")
    print()
    print(f"Resumen en: {summary}")
    print()

    return True

if __name__ == "__main__":
    success = main()
    sys.exit(0 if success else 1)
