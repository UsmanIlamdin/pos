#!/usr/bin/env bash
# =============================================================
#  start.sh — Start the BrainCortex production stack
#  Detects LAN IP so other Wi‑Fi devices can open the app.
# =============================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DOCKER_DIR="$(dirname "$SCRIPT_DIR")"

echo "╔══════════════════════════════════════════════╗"
echo "║   BrainCortex — Production Stack Start     ║"
echo "╚══════════════════════════════════════════════╝"
echo ""

# Ensure backups folder exists on host
mkdir -p "${DOCKER_DIR}/backups"

# Ensure traefik log dir exists
mkdir -p "${DOCKER_DIR}/traefik/logs"

# CI4 FileHandler logs → host opensourcepos/writable/logs (bind-mounted)
mkdir -p "${DOCKER_DIR}/../opensourcepos/writable/logs"

chmod +x "${SCRIPT_DIR}/detect-lan-ip.sh" "${SCRIPT_DIR}/generate-certs.sh"

echo "📡  Detecting LAN / Wi‑Fi IP…"
LAN_IP="$("${SCRIPT_DIR}/detect-lan-ip.sh")"
echo "     LAN IP: ${LAN_IP}"
echo ""

# Generate (or refresh) local TLS certs for Traefik
"${SCRIPT_DIR}/generate-certs.sh"

echo "📦  Building and starting services…"
docker compose \
  --project-name pos \
  --file "${DOCKER_DIR}/docker-compose.yml" \
  --env-file "${DOCKER_DIR}/.env" \
  --env-file "${DOCKER_DIR}/.env.lan" \
  up -d --build --remove-orphans

# Keep host header.php in sync with gulp hashes from the new image
chmod +x "${SCRIPT_DIR}/sync-app-header.sh"
"${SCRIPT_DIR}/sync-app-header.sh" || echo "⚠️  Could not sync header.php (UI CSS/JS may be stale until you run ./scripts/sync-app-header.sh)"

echo ""
echo "✅  Stack started!"
echo ""

# Read display values from .env
APP_DOMAIN=$(grep -E '^APP_DOMAIN=' "${DOCKER_DIR}/.env" | cut -d= -f2 | tr -d '"' | tr -d "'" | xargs)
APP_PORT=$(grep -E '^APP_PORT=' "${DOCKER_DIR}/.env" | cut -d= -f2 | tr -d '"' | tr -d "'" | xargs)
APP_HTTPS_PORT=$(grep -E '^APP_HTTPS_PORT=' "${DOCKER_DIR}/.env" | cut -d= -f2 | tr -d '"' | tr -d "'" | xargs)
DASHBOARD_PORT=$(grep -E '^TRAEFIK_DASHBOARD_PORT=' "${DOCKER_DIR}/.env" | cut -d= -f2 | tr -d '"' | tr -d "'" | xargs)

APP_DOMAIN="${APP_DOMAIN:-localhost}"
APP_PORT="${APP_PORT:-80}"
APP_HTTPS_PORT="${APP_HTTPS_PORT:-443}"
DASHBOARD_PORT="${DASHBOARD_PORT:-8080}"

HTTPS_SUFFIX=""
HTTP_SUFFIX=""
if [[ "${APP_HTTPS_PORT}" != "443" ]]; then
  HTTPS_SUFFIX=":${APP_HTTPS_PORT}"
fi
if [[ "${APP_PORT}" != "80" ]]; then
  HTTP_SUFFIX=":${APP_PORT}"
fi

echo "  This Mac:"
echo "    🌐  https://${APP_DOMAIN}${HTTPS_SUFFIX}"
echo "    ↪️   http://${APP_DOMAIN}${HTTP_SUFFIX} → HTTPS"
echo ""
echo "  Other phones / PCs on the same Wi‑Fi:"
echo "    📱  https://${LAN_IP}${HTTPS_SUFFIX}"
echo "    📱  https://${APP_DOMAIN}${HTTPS_SUFFIX}"
echo ""
echo "  To use the hostname on another device, add this hosts entry"
echo "  (pointing at this Mac's Wi‑Fi IP):"
echo "    ${LAN_IP}  ${APP_DOMAIN}"
echo ""
echo "  📊  Traefik Dashboard: http://localhost:${DASHBOARD_PORT}"
echo "  💾  Backups folder:   ${DOCKER_DIR}/backups/"
echo ""
echo "  Default login: admin / pointofsale"
echo ""
echo "  To view logs:   docker compose --project-name pos logs -f"
echo "  To stop stack:  $(dirname "$0")/stop.sh"
