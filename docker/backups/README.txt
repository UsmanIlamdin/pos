This folder stores automatic database backups.

Backups are created by the `pos-backup` container.
- Format: ospos_YYYY-MM-DD_HH-MM.sql.gz
- Interval: configured via BACKUP_INTERVAL_HOURS in .env (default: 12 hours)
- Retention: configured via BACKUP_KEEP_DAYS in .env (default: 7 days)

To restore a backup:
  gunzip -c ospos_2024-01-01_00-00.sql.gz | docker exec -i pos-db \
    mariadb -u admin -pYOUR_PASSWORD ospos

To trigger a manual backup immediately:
  docker exec pos-backup /scripts/backup.sh
