#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "Ejecuta este instalador como root: sudo bash sii-agent/ubuntu/instalar.sh" >&2
  exit 1
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DEST=/opt/icontador-sii-agent
STATE=/var/lib/icontador-sii
CONFIG=/etc/icontador-sii

command -v node >/dev/null || { echo 'Falta Node.js. Instala Node.js 22 LTS antes de continuar.' >&2; exit 1; }
command -v npm >/dev/null || { echo 'Falta npm.' >&2; exit 1; }
command -v systemd-creds >/dev/null || { echo 'Falta systemd-creds; se requiere systemd 250 o superior.' >&2; exit 1; }
node -e 'if (Number(process.versions.node.split(".")[0]) < 20) process.exit(1)' || { echo 'Playwright requiere Node.js 20 o superior; se recomienda Node.js 22 LTS.' >&2; exit 1; }

if ! id icontador-sii >/dev/null 2>&1; then
  useradd --system --home-dir "$STATE" --create-home --shell /usr/sbin/nologin icontador-sii
fi
install -d -o icontador-sii -g icontador-sii -m 0750 "$STATE" "$STATE/descargas" "$STATE/ms-playwright"
install -d -o root -g root -m 0750 "$CONFIG"
install -d -o root -g root -m 0700 /etc/credstore.encrypted

install -d -o root -g root -m 0755 "$DEST/sii-agent/src" "$DEST/assets"
cp -a "$ROOT/sii-agent/src/." "$DEST/sii-agent/src/"
install -m 0644 "$ROOT/sii-agent/package.json" "$DEST/sii-agent/package.json"
install -m 0644 "$ROOT/sii-agent/package-lock.json" "$DEST/sii-agent/package-lock.json"
install -m 0644 "$ROOT/assets/rcv-import.js" "$DEST/assets/rcv-import.js"
install -m 0644 "$ROOT/assets/honorarios-import.js" "$DEST/assets/honorarios-import.js"
chown -R root:root "$DEST"

if [[ ! -f "$CONFIG/agent.env" ]]; then
  cat > "$CONFIG/agent.env" <<'EOF'
SII_URL_SERVIDOR=https://qlc.cl
SII_HEADLESS=true
SII_INTERVALO_SEG=15
SII_CARPETA=/var/lib/icontador-sii/descargas
SII_CONSERVAR=false
EOF
  chmod 0600 "$CONFIG/agent.env"
fi

cd "$DEST/sii-agent"
npm ci --omit=dev
npx playwright install-deps chromium
runuser -u icontador-sii -- env HOME="$STATE" PLAYWRIGHT_BROWSERS_PATH="$STATE/ms-playwright" npm exec -- playwright install chromium
chown -R icontador-sii:icontador-sii "$STATE"

if [[ ! -f /var/lib/systemd/credential.secret ]]; then
  systemd-creds setup
fi
install -m 0644 "$ROOT/sii-agent/ubuntu/icontador-sii.service" /etc/systemd/system/icontador-sii.service
systemctl daemon-reload

echo 'Instalación lista. Configura el código de servicio mediante: cd /opt/icontador-sii-agent/sii-agent && npm run enrolar:linux'
echo 'Luego inicia: sudo systemctl enable --now icontador-sii'
