#!/usr/bin/env bash
# =============================================================
#  export-master-data.sh
#  Dump portable master data from the running POS stack:
#    - Items (+ taxes, quantities, kits)
#    - Custom attributes (definitions / values / item links)
#    - Customers (+ people contact fields)
#    - Employees (+ people, grants, password hashes)
#    - Suppliers (referenced by items)
#    - Company config (app_config, currency, logos, stock locations, tax setup)
#    - Upload assets (company logo, item pictures)
#
#  Usage:
#    ./scripts/export-master-data.sh
#    ./scripts/export-master-data.sh /path/to/output-dir
#
#  Output:
#    <out>/master-data-YYYYMMDD-HHMMSS/
#      manifest.txt
#      sql/*.sql
#      uploads/...
# =============================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DOCKER_DIR="$(dirname "$SCRIPT_DIR")"

if [[ -f "${DOCKER_DIR}/.env" ]]; then
  set -a
  # shellcheck disable=SC1091
  source "${DOCKER_DIR}/.env"
  set +a
fi

DB_CONTAINER="${DB_CONTAINER:-pos-db}"
APP_CONTAINER="${APP_CONTAINER:-pos-app}"
MYSQL_DATABASE="${MYSQL_DATABASE:-pos}"
MYSQL_ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-}"
TABLE_PREFIX="${TABLE_PREFIX:-ospos_}"

OUT_ROOT="${1:-${DOCKER_DIR}/backups}"
STAMP="$(date +%Y%m%d-%H%M%S)"
OUT_DIR="${OUT_ROOT%/}/master-data-${STAMP}"
SQL_DIR="${OUT_DIR}/sql"
UPLOADS_DIR="${OUT_DIR}/uploads"

die() { echo "ERROR: $*" >&2; exit 1; }

need_container() {
  docker inspect "$1" >/dev/null 2>&1 || die "Container '$1' not found. Start the stack first."
  local status
  status="$(docker inspect -f '{{.State.Running}}' "$1" 2>/dev/null || echo false)"
  [[ "$status" == "true" ]] || die "Container '$1' is not running."
}

mysql_exec() {
  if [[ -n "${MYSQL_ROOT_PASSWORD}" ]]; then
    docker exec -i "${DB_CONTAINER}" mariadb -uroot -p"${MYSQL_ROOT_PASSWORD}" "${MYSQL_DATABASE}" "$@"
  else
    docker exec -i "${DB_CONTAINER}" mariadb -u"${MYSQL_USER:-admin}" -p"${MYSQL_PASSWORD:-}" "${MYSQL_DATABASE}" "$@"
  fi
}

mysqldump_exec() {
  # Args after options are table names / dump flags
  if [[ -n "${MYSQL_ROOT_PASSWORD}" ]]; then
    docker exec "${DB_CONTAINER}" mariadb-dump -uroot -p"${MYSQL_ROOT_PASSWORD}" \
      --single-transaction --skip-triggers --no-create-info --complete-insert --replace \
      --default-character-set=utf8mb4 \
      "${MYSQL_DATABASE}" "$@"
  else
    docker exec "${DB_CONTAINER}" mariadb-dump -u"${MYSQL_USER:-admin}" -p"${MYSQL_PASSWORD:-}" \
      --single-transaction --skip-triggers --no-create-info --complete-insert --replace \
      --default-character-set=utf8mb4 \
      "${MYSQL_DATABASE}" "$@"
  fi
}

dump_table() {
  local file="$1"
  shift
  echo "  → $*"
  {
    echo "-- master-data dump: $*"
    echo "SET NAMES utf8mb4;"
    echo "SET FOREIGN_KEY_CHECKS=0;"
    # Strip MariaDB "sandbox mode" marker so dumps import cleanly on all versions.
    mysqldump_exec "$@" 2>/dev/null | sed '/sandbox mode/d'
    echo "SET FOREIGN_KEY_CHECKS=1;"
  } > "${SQL_DIR}/${file}"
}

need_container "${DB_CONTAINER}"
need_container "${APP_CONTAINER}"
[[ -n "${MYSQL_ROOT_PASSWORD}" || -n "${MYSQL_PASSWORD:-}" ]] || die "Set MYSQL_ROOT_PASSWORD or MYSQL_PASSWORD in docker/.env"

mkdir -p "${SQL_DIR}" "${UPLOADS_DIR}/item_pics" "${UPLOADS_DIR}/company"

echo "╔══════════════════════════════════════════════╗"
echo "║   Export POS master data                     ║"
echo "╚══════════════════════════════════════════════╝"
echo "Database : ${MYSQL_DATABASE} @ ${DB_CONTAINER}"
echo "Output   : ${OUT_DIR}"
echo ""

# --- Company / settings ---
dump_table "01_app_config.sql" "${TABLE_PREFIX}app_config"
dump_table "02_stock_locations.sql" "${TABLE_PREFIX}stock_locations"
dump_table "03_tax_categories.sql" "${TABLE_PREFIX}tax_categories"
dump_table "04_tax_codes.sql" "${TABLE_PREFIX}tax_codes"
dump_table "05_tax_jurisdictions.sql" "${TABLE_PREFIX}tax_jurisdictions"
dump_table "06_tax_rates.sql" "${TABLE_PREFIX}tax_rates"
dump_table "07_dinner_tables.sql" "${TABLE_PREFIX}dinner_tables"
dump_table "08_customers_packages.sql" "${TABLE_PREFIX}customers_packages"

# --- People + employees (passwords / hash_version preserved) + grants ---
dump_table "10_people.sql" "${TABLE_PREFIX}people"
dump_table "11_employees.sql" "${TABLE_PREFIX}employees"
dump_table "12_grants.sql" "${TABLE_PREFIX}grants"

# --- Customers / suppliers ---
dump_table "20_suppliers.sql" "${TABLE_PREFIX}suppliers"
dump_table "21_customers.sql" "${TABLE_PREFIX}customers"

# --- Items + attributes ---
dump_table "30_attribute_definitions.sql" "${TABLE_PREFIX}attribute_definitions"
dump_table "31_attribute_values.sql" "${TABLE_PREFIX}attribute_values"
dump_table "40_items.sql" "${TABLE_PREFIX}items"
dump_table "41_items_taxes.sql" "${TABLE_PREFIX}items_taxes"
dump_table "42_item_quantities.sql" "${TABLE_PREFIX}item_quantities"
dump_table "43_item_kits.sql" "${TABLE_PREFIX}item_kits"
dump_table "44_item_kit_items.sql" "${TABLE_PREFIX}item_kit_items"

# Item-linked attributes only (skip sale/receiving transactional links)
echo "  → ${TABLE_PREFIX}attribute_links (item_id IS NOT NULL)"
{
  echo "-- master-data dump: ${TABLE_PREFIX}attribute_links (items only)"
  echo "SET NAMES utf8mb4;"
  echo "SET FOREIGN_KEY_CHECKS=0;"
  mysqldump_exec --where="item_id IS NOT NULL" "${TABLE_PREFIX}attribute_links" 2>/dev/null | sed '/sandbox mode/d'
  echo "SET FOREIGN_KEY_CHECKS=1;"
} > "${SQL_DIR}/45_attribute_links_items.sql"

# --- Uploads: company logo(s) from app_config + item pictures ---
echo ""
echo "📦  Collecting upload assets…"

LOGO_FILE="$(
  mysql_exec -N -e "SELECT value FROM ${TABLE_PREFIX}app_config WHERE \`key\`='company_logo' LIMIT 1;" 2>/dev/null | tr -d '\r' || true
)"
if [[ -n "${LOGO_FILE}" ]]; then
  if docker exec "${APP_CONTAINER}" test -f "/app/public/uploads/${LOGO_FILE}"; then
    docker cp "${APP_CONTAINER}:/app/public/uploads/${LOGO_FILE}" "${UPLOADS_DIR}/company/${LOGO_FILE}"
    echo "  → company logo: ${LOGO_FILE}"
  else
    echo "  ⚠ company_logo '${LOGO_FILE}' referenced but file missing in container uploads"
  fi
fi

# Copy any other image-like files in uploads root (legacy logos)
while IFS= read -r f; do
  [[ -z "$f" || "$f" == "item_pics" || "$f" == ".gitignore" ]] && continue
  case "$f" in
    *.png|*.jpg|*.jpeg|*.gif|*.webp|*.svg|*.bmp)
      docker cp "${APP_CONTAINER}:/app/public/uploads/${f}" "${UPLOADS_DIR}/company/${f}" 2>/dev/null || true
      echo "  → company asset: ${f}"
      ;;
  esac
done < <(docker exec "${APP_CONTAINER}" sh -c 'ls -1 /app/public/uploads 2>/dev/null' || true)

# Item pictures directory
if docker exec "${APP_CONTAINER}" test -d /app/public/uploads/item_pics; then
  # Copy directory contents (may be empty)
  docker cp "${APP_CONTAINER}:/app/public/uploads/item_pics/." "${UPLOADS_DIR}/item_pics/" 2>/dev/null || true
  PIC_COUNT="$(find "${UPLOADS_DIR}/item_pics" -type f ! -name '.gitignore' | wc -l | tr -d ' ')"
  echo "  → item_pics: ${PIC_COUNT} file(s)"
fi

# --- Manifest ---
EMP_COUNT="$(mysql_exec -N -e "SELECT COUNT(*) FROM ${TABLE_PREFIX}employees;" 2>/dev/null | tr -d '\r')"
CUS_COUNT="$(mysql_exec -N -e "SELECT COUNT(*) FROM ${TABLE_PREFIX}customers;" 2>/dev/null | tr -d '\r')"
ITEM_COUNT="$(mysql_exec -N -e "SELECT COUNT(*) FROM ${TABLE_PREFIX}items;" 2>/dev/null | tr -d '\r')"
ATTR_DEF_COUNT="$(mysql_exec -N -e "SELECT COUNT(*) FROM ${TABLE_PREFIX}attribute_definitions;" 2>/dev/null | tr -d '\r')"

cat > "${OUT_DIR}/manifest.txt" <<EOF
POS master-data export
======================
Created:          $(date -u +%Y-%m-%dT%H:%M:%SZ)
Source database:  ${MYSQL_DATABASE}
Table prefix:     ${TABLE_PREFIX}
DB container:     ${DB_CONTAINER}
App container:    ${APP_CONTAINER}

Counts
------
employees:              ${EMP_COUNT}
customers:              ${CUS_COUNT}
items:                  ${ITEM_COUNT}
attribute_definitions:  ${ATTR_DEF_COUNT}

Contents
--------
- sql/                 Data-only dumps (--replace, no CREATE TABLE)
- uploads/company/     Company logo / root upload images
- uploads/item_pics/   Item pictures

Notes
-----
- Employee passwords are exported as stored hashes (hash_version preserved).
  After import on a new/upgraded DB, existing usernames/passwords continue to work.
- attribute_links includes ITEM links only (no sale/receiving history).
- Import expects a fresh/upgraded DB (no sales). Use --force only if you know the risk.
- Import with: ./scripts/import-master-data.sh ${OUT_DIR}
EOF

# Convenience archive
(
  cd "$(dirname "${OUT_DIR}")"
  tar -czf "master-data-${STAMP}.tar.gz" "master-data-${STAMP}"
)

echo ""
echo "✅  Export complete"
echo "    Folder : ${OUT_DIR}"
echo "    Archive: ${OUT_ROOT%/}/master-data-${STAMP}.tar.gz"
echo ""
echo "Import on the target (latest) DB with:"
echo "  ${SCRIPT_DIR}/import-master-data.sh ${OUT_DIR}"
echo "  # or: ${SCRIPT_DIR}/import-master-data.sh ${OUT_ROOT%/}/master-data-${STAMP}.tar.gz"
