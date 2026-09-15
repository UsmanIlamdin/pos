#!/usr/bin/env bash
# =============================================================
#  BrainCortex — Database Backup Script
#  Runs mysqldump, compresses, and rotates old backups.
# =============================================================
set -euo pipefail

# ---- Config (from environment) ----
DB_HOST="${MYSQL_HOST:-db}"
DB_PORT="${MYSQL_PORT:-3306}"
DB_NAME="${MYSQL_DATABASE}"
DB_USER="${MYSQL_USER}"
DB_PASS="${MYSQL_PASSWORD}"
BACKUP_DIR="${BACKUP_DIR:-/backups}"
KEEP_DAYS="${BACKUP_KEEP_DAYS:-7}"
BACKUP_INTERVAL_HOURS="${BACKUP_INTERVAL_HOURS:-12}"

BACKUP_INTERVAL_MINUTES=$((BACKUP_INTERVAL_HOURS * 60))

# ---- Ensure backup directory exists ----
mkdir -p "${BACKUP_DIR}"

echo "[backup] $(date +'%Y-%m-%d %H:%M:%S') — Checking backup interval..."
echo "[backup] Backup interval: ${BACKUP_INTERVAL_HOURS} hour(s)"

# ---- Check for a recent backup ----
RECENT_BACKUP=$(find "${BACKUP_DIR}" \
  -maxdepth 1 \
  -type f \
  -name "ospos_*.sql.gz" \
  -mmin "-${BACKUP_INTERVAL_MINUTES}" \
  -print -quit)

if [[ -n "${RECENT_BACKUP}" ]]; then
    echo "[backup] Recent backup already exists:"
    echo "[backup] ${RECENT_BACKUP}"
    echo "[backup] Skipping backup."

    # Still rotate old backups.
    echo "[backup] Removing backups older than ${KEEP_DAYS} days…"

    find "${BACKUP_DIR}" \
      -maxdepth 1 \
      -type f \
      -name "ospos_*.sql.gz" \
      -mtime "+${KEEP_DAYS}" \
      -delete

    REMAINING=$(find "${BACKUP_DIR}" \
      -maxdepth 1 \
      -type f \
      -name "ospos_*.sql.gz" \
      | wc -l \
      | tr -d ' ')

    echo "[backup] Rotation done — ${REMAINING} backup(s) retained."

    exit 0
fi

echo "[backup] No recent backup found."
echo "[backup] Creating new backup..."

# ---- Timestamped filename ----
TIMESTAMP=$(date +"%Y-%m-%d_%H-%M-%S")
FILENAME="${BACKUP_DIR}/ospos_${TIMESTAMP}.sql.gz"

echo "[backup] Starting backup → ${FILENAME}"

# ---- Wait for DB to be reachable ----
DB_READY=false

for i in $(seq 1 10); do
    if mysqladmin ping \
        -h "${DB_HOST}" \
        -P "${DB_PORT}" \
        -u "${DB_USER}" \
        -p"${DB_PASS}" \
        --silent 2>/dev/null; then

        DB_READY=true
        break
    fi

    echo "[backup] Waiting for database (attempt ${i}/10)…"
    sleep 5
done

if [[ "${DB_READY}" != "true" ]]; then
    echo "[backup] ERROR: Database was not reachable."
    exit 1
fi

# ---- Dump ----
mysqldump \
  -h "${DB_HOST}" \
  -P "${DB_PORT}" \
  -u "${DB_USER}" \
  -p"${DB_PASS}" \
  --single-transaction \
  --routines \
  --triggers \
  "${DB_NAME}" | gzip -9 > "${FILENAME}"

echo "[backup] Backup completed: $(du -sh "${FILENAME}" | cut -f1) compressed"

# ---- Rotate old backups ----
echo "[backup] Removing backups older than ${KEEP_DAYS} days…"

find "${BACKUP_DIR}" \
  -maxdepth 1 \
  -type f \
  -name "ospos_*.sql.gz" \
  -mtime "+${KEEP_DAYS}" \
  -delete

REMAINING=$(find "${BACKUP_DIR}" \
  -maxdepth 1 \
  -type f \
  -name "ospos_*.sql.gz" \
  | wc -l \
  | tr -d ' ')

echo "[backup] Rotation done — ${REMAINING} backup(s) retained."