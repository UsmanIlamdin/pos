#!/bin/bash
# =============================================================
#  BrainCortex — MariaDB init (runs only when db_data is empty)
# =============================================================
set -euo pipefail

mysql -u root -p"${MYSQL_ROOT_PASSWORD}" <<-EOSQL
	ALTER DATABASE \`${MYSQL_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
	GRANT ALL PRIVILEGES ON \`${MYSQL_DATABASE}\`.* TO '${MYSQL_USER}'@'%';
	FLUSH PRIVILEGES;
EOSQL

echo "[init] Database \`${MYSQL_DATABASE}\` ready for user \`${MYSQL_USER}\`"
