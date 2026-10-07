#!/bin/bash
# Script de despliegue para Módulo Empleados a qlc.cl
# Transfiere archivos via SFTP y ejecuta SQL en la base de datos

set -e  # Exit on error

PROYECTO="."
DEPLOY_DIR="deploy/cpanel"
REMOTE_HOST="s490.v2nets.com"
REMOTE_USER="qlccl_icontador"
REMOTE_PATH="~/public_html/api"
DB_NAME="qlccl_icontador"
DB_USER="qlccl_icontador"

echo "========================================"
echo "Despliegue - Módulo Empleados a qlc.cl"
echo "========================================"
echo ""
echo "Servidor: $REMOTE_HOST"
echo "Usuario: $REMOTE_USER"
echo "Ruta remota: $REMOTE_PATH"
echo "Base de datos: $DB_NAME"
echo ""

# Paso 1: Verificar archivos locales
echo "1. Verificando archivos locales..."
if [ ! -f "$DEPLOY_DIR/api/empleados.php" ]; then
    echo "✗ Error: $DEPLOY_DIR/api/empleados.php no encontrado"
    exit 1
fi
if [ ! -f "$DEPLOY_DIR/api/lib/empleados.php" ]; then
    echo "✗ Error: $DEPLOY_DIR/api/lib/empleados.php no encontrado"
    exit 1
fi
if [ ! -f "$DEPLOY_DIR/api/schema/empleados-create.sql" ]; then
    echo "✗ Error: $DEPLOY_DIR/api/schema/empleados-create.sql no encontrado"
    exit 1
fi
echo "   ✓ Archivos verificados"
echo ""

# Paso 2: Transferir archivos via SFTP
echo "2. Conectando a $REMOTE_HOST y transfiriendo archivos..."
echo "   (Se solicitará contraseña SSH para $REMOTE_USER@$REMOTE_HOST)"
echo ""

sftp -b - "$REMOTE_USER@$REMOTE_HOST" << EOF
cd public_html/api
lcd $DEPLOY_DIR/api
put empleados.php
put -r lib/
put -r schema/
bye
EOF

echo ""
echo "   ✓ Archivos transferidos"
echo ""

# Paso 3: Crear tabla en la base de datos (manual en cPanel)
echo "3. Instrucciones para crear la tabla en la base de datos:"
echo ""
echo "   OPCIÓN 1 - Usar phpMyAdmin en cPanel:"
echo "   1. Ir a cPanel → phpMyAdmin"
echo "   2. Seleccionar base de datos: $DB_NAME"
echo "   3. Click en 'SQL'"
echo "   4. Copiar el contenido de: deploy/cpanel/api/schema/empleados-create.sql"
echo "   5. Ejecutar"
echo ""
echo "   OPCIÓN 2 - Usar MySQL desde SSH:"
echo "   ssh $REMOTE_USER@$REMOTE_HOST"
echo "   mysql -u $DB_USER -p $DB_NAME < public_html/api/schema/empleados-create.sql"
echo ""

# Paso 4: Instrucciones de verificación
echo "4. Verificación:"
echo ""
echo "   - Abrir navegador: https://qlc.cl"
echo "   - Ir a: Documentos Laborales → Empleados"
echo "   - Debería mostrar lista vacía de empleados"
echo ""

echo "========================================"
echo "✓ DESPLIEGUE COMPLETADO"
echo "========================================"
echo ""
echo "Estado:"
echo "  ✓ Archivos transferidos a $REMOTE_HOST"
echo "  ⚠ Tabla de BD: Crear manualmente en cPanel phpMyAdmin"
echo "  → Verificar en https://qlc.cl/Documentos%20Laborales/Empleados"
echo ""
