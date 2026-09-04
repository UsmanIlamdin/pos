#!/usr/bin/env bash
# =============================================================
#  stop.sh — Stop the BrainCortex production stack
# =============================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DOCKER_DIR="$(dirname "$SCRIPT_DIR")"

echo "🛑  Stopping BrainCortex stack…"

ENV_FILES=(--env-file "${DOCKER_DIR}/.env")
if [[ -f "${DOCKER_DIR}/.env.lan" ]]; then
  ENV_FILES+=(--env-file "${DOCKER_DIR}/.env.lan")
fi

docker compose \
  --project-name pos \
  --file "${DOCKER_DIR}/docker-compose.yml" \
  "${ENV_FILES[@]}" \
  down

echo "✅  Stack stopped. Data volumes are preserved."
echo "    To also remove volumes (WARNING: deletes DB data): add --volumes flag"
