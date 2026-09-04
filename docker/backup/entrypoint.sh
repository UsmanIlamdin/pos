#!/usr/bin/env bash
# =============================================================
#  Backup service entrypoint
#  Generates a crontab from BACKUP_INTERVAL_HOURS and starts crond.
# =============================================================
set -euo pipefail

INTERVAL="${BACKUP_INTERVAL_HOURS:-12}"

echo "[entrypoint] Backup interval: every ${INTERVAL} hour(s)"

# Build cron expression (run at minute 0, every N hours)
# For every 1 hour: "0 */1 * * *", for 12: "0 */12 * * *"
CRON_EXPR="0 */${INTERVAL} * * *"

# Write crontab for root
echo "${CRON_EXPR} /scripts/backup.sh >> /var/log/backup.log 2>&1" > /etc/crontabs/root

echo "[entrypoint] Crontab set: ${CRON_EXPR}"
echo "[entrypoint] Running initial backup on startup…"

# Run one backup immediately on startup so you don't wait for first cron tick
/scripts/backup.sh || echo "[entrypoint] Initial backup failed — will retry at next scheduled time"

echo "[entrypoint] Starting crond in foreground…"
exec crond -f -l 6
