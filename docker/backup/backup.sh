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

# ---- Timestamped filename ----
TIMESTAMP=$(date +"%Y-%m-%d_%H-%M")
FILENAME="${BACKUP_DIR}/ospos_${TIMESTAMP}.sql.gz"

echo "[backup] $(date +'%Y-%m-%d %H:%M:%S') — Starting backup → ${FILENAME}"

# ---- Wait for DB to be reachable ----
for i in $(seq 1 10); do
  if mysqladmin ping -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_USER}" -p"${DB_PASS}" --silent 2>/dev/null; then
    break
  fi
  echo "[backup] Waiting for database (attempt ${i}/10)…"
  sleep 5
done

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
find "${BACKUP_DIR}" -name "ospos_*.sql.gz" -mtime "+${KEEP_DAYS}" -delete
REMAINING=$(find "${BACKUP_DIR}" -name "ospos_*.sql.gz" | wc -l | tr -d ' ')
echo "[backup] Rotation done — ${REMAINING} backup(s) retained."
