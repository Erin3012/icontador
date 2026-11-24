# Agente SII para Ubuntu 24.04

El agente de Ubuntu conserva el worker Node.js y Playwright. Corre Chromium sin interfaz dentro de systemd; el panel Tkinter y el EXE siguen siendo solo para Windows. La credencial central no se guarda en `.env`: durante el enrolamiento se cifra con `systemd-creds` y la clave privada local del host. systemd la entrega al worker en memoria mediante `$CREDENTIALS_DIRECTORY` al iniciar el servicio.

## Instalación en el VPS

Usa Ubuntu 24.04 LTS. Conéctate por SSH y asegúrate de tener Node.js 22 LTS, npm, systemd y acceso sudo. Playwright 1.64 requiere Node.js 20 o superior; el paquete Node.js que viene con Ubuntu 24.04 puede ser más antiguo. Para instalar Node.js 22 desde NodeSource:

```bash
curl -fsSL https://deb.nodesource.com/setup_22.x -o /tmp/nodesource_setup.sh
sudo -E bash /tmp/nodesource_setup.sh
sudo apt-get install -y nodejs
node --version
npm --version
```

Luego, desde la raíz del repositorio:

```bash
sudo bash sii-agent/ubuntu/instalar.sh
```

El instalador copia solo el worker y el lector RCV a `/opt/icontador-sii-agent`, instala Chromium y sus dependencias, crea el usuario sin acceso interactivo `icontador-sii`, y configura el servicio `icontador-sii`. No instala ni mueve el sitio web ni su base de datos. Conserva el `.env` de servicio en `/etc/icontador-sii/agent.env`, con permisos de root; ahí solo van URL y opciones, nunca claves o tokens.

Para probar solo el arranque local de Chromium, sin contactar qlc.cl ni el SII:

```bash
cd sii-agent
npm run test:linux:browser
```

## Enrolamiento del agente

1. En el hosting cPanel, el operador genera un código temporal desde CLI, fuera del directorio público:

   ```bash
   cd /home/qlccl/ops
   php sii-service.php generar
   ```

   Si ya existe un servicio activo o un código vigente, el comando exige escribir `REEMPLAZAR`; esto revoca el acceso anterior. El código se muestra solo en esa terminal y vence en 10 minutos.
2. En SSH al VPS, ejecuta:

   ```bash
   cd /opt/icontador-sii-agent/sii-agent
   npm run enrolar:linux
   ```

3. El comando pide el código sin mostrarlo y lo canjea por HTTPS. Pide sudo para cifrar el token en `/etc/credstore.encrypted/icontador-sii-agent-token`; el token no se escribe en `.env`, parámetros, registros ni archivos en claro.
4. Inicia el worker:

   ```bash
   sudo systemctl enable --now icontador-sii
   ```

El agente procesa una tarea a la vez y mantiene Chromium headless en el VPS. El usuario de qlc.cl sigue sujeto al acceso que tiene asignado a cada empresa.

## Operación

```bash
sudo systemctl status icontador-sii
sudo journalctl -u icontador-sii -f
sudo systemctl restart icontador-sii
```

Para detenerlo: `sudo systemctl stop icontador-sii`. Para retirar el enrolamiento local, detén y deshabilita el servicio y elimina la credencial cifrada. La revocación central se opera desde el hosting por SSH con `php sii-service.php revocar`, que requiere confirmación. Si el VPS se reinstala, la credencial cifrada con su clave host no se podrá descifrar en el nuevo sistema; genera un código desde CLI y vuelve a enrolar.

## Seguridad y límites

- No habilites `SII_HEADLESS=false` en un VPS sin escritorio.
- La clave tributaria viaja de qlc.cl por HTTPS como parte de una tarea y se usa en memoria; Chromium usa cookies de sesión solo en memoria.
- El token se cifra con AES-GCM mediante `systemd-creds --with-key=host`. La clave de descifrado permanece local en el VPS.
- El worker solo hace conexiones salientes HTTPS a qlc.cl y al SII; no necesita puertos entrantes abiertos para procesar la cola.
- El servicio escribe CSV temporales bajo `/var/lib/icontador-sii/descargas` y los elimina al terminar cada tarea.
