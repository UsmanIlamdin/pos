#!/usr/bin/env bash
# Copy gulp-injected header.php from the built image onto the host.
# Needed after `docker compose build` when ../opensourcepos/app is mounted
# over /app/app (host source has empty <!-- inject:* --> markers).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST="${ROOT}/../opensourcepos/app/Views/partial/header.php"
IMAGE="$(cd "${ROOT}" && docker compose --project-name pos images -q app 2>/dev/null | head -1)"

if [[ -z "${IMAGE}" ]]; then
  echo "No pos-app image found. Run: docker compose --project-name pos build app" >&2
  exit 1
fi

cid="$(docker create "${IMAGE}")"
trap 'docker rm -f "${cid}" >/dev/null' EXIT
docker cp "${cid}:/app/app/Views/partial/header.php" "${DEST}"
echo "Synced gulp-injected header → ${DEST}"
