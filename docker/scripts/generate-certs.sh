#!/usr/bin/env bash
# =============================================================
#  generate-certs.sh — Create local TLS certs for Traefik
#  Includes APP_DOMAIN + LAN IP so phones on Wi‑Fi can connect.
#  Regenerates automatically when domain or LAN IP changes.
# =============================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DOCKER_DIR="$(dirname "$SCRIPT_DIR")"
ENV_FILE="${DOCKER_DIR}/.env"
LAN_ENV_FILE="${DOCKER_DIR}/.env.lan"
CERT_DIR="${DOCKER_DIR}/traefik/certs"

if [[ ! -f "${ENV_FILE}" ]]; then
  echo "Missing ${ENV_FILE}. Create .env first." >&2
  exit 1
fi

# shellcheck disable=SC1090
source "${ENV_FILE}"
if [[ -f "${LAN_ENV_FILE}" ]]; then
  # shellcheck disable=SC1090
  source "${LAN_ENV_FILE}"
fi

APP_DOMAIN="${APP_DOMAIN:-localhost}"
LAN_IP="${LAN_IP:-127.0.0.1}"
CERT_FILE="${CERT_DIR}/cert.pem"
KEY_FILE="${CERT_DIR}/key.pem"
META_FILE="${CERT_DIR}/.cert-meta"
EXPECTED_META="${APP_DOMAIN}|${LAN_IP}"

mkdir -p "${CERT_DIR}"

if [[ -f "${CERT_FILE}" && -f "${KEY_FILE}" && -f "${META_FILE}" ]]; then
  CURRENT_META="$(cat "${META_FILE}")"
  if [[ "${CURRENT_META}" == "${EXPECTED_META}" ]]; then
    echo "TLS certificates already exist for ${APP_DOMAIN} (+ ${LAN_IP})."
    exit 0
  fi
  echo "Domain or LAN IP changed — regenerating TLS certificates…"
  rm -f "${CERT_FILE}" "${KEY_FILE}" "${META_FILE}"
fi

echo "Generating self-signed TLS certificate for ${APP_DOMAIN} and ${LAN_IP} …"

OPENSSL_CONFIG="$(mktemp)"
trap 'rm -f "${OPENSSL_CONFIG}"' EXIT

cat > "${OPENSSL_CONFIG}" <<EOF
[req]
default_bits = 2048
prompt = no
default_md = sha256
distinguished_name = dn
req_extensions = req_ext
x509_extensions = req_ext

[dn]
CN = ${APP_DOMAIN}
O = BrainCortex Local
C = US

[req_ext]
subjectAltName = @alt_names

[alt_names]
DNS.1 = ${APP_DOMAIN}
DNS.2 = localhost
IP.1 = ${LAN_IP}
IP.2 = 127.0.0.1
EOF

openssl req -x509 -nodes -days 825 -newkey rsa:2048 \
  -keyout "${KEY_FILE}" \
  -out "${CERT_FILE}" \
  -config "${OPENSSL_CONFIG}"

chmod 644 "${CERT_FILE}"
chmod 600 "${KEY_FILE}"
printf '%s\n' "${EXPECTED_META}" > "${META_FILE}"

echo "Created:"
echo "  ${CERT_FILE}"
echo "  ${KEY_FILE}"
echo ""
echo "Browsers will warn about the self-signed certificate."
echo "On phones, accept the warning once, or use the LAN IP URL."
