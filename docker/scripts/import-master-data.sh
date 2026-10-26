#!/usr/bin/env bash
# =============================================================
#  import-master-data.sh
#  Import a master-data export into the running POS database
#  (intended for a fresh/upgraded schema after migrations).
#
#  Restores:
#    - Items, attributes, kits, quantities, item taxes
#    - Customers (+ people emails/phones/addresses)
#    - Employees (+ password hashes / hash_version + grants)
#    - Suppliers (needed by items)
#    - Company app_config (currency, logos, settings)
#    - Stock locations, tax setup, dinner tables, customer packages
#    - Upload assets (company logo, item pictures)
#
#  Usage:
#    ./scripts/import-master-data.sh /path/to/master-data-YYYYMMDD-HHMMSS
#    ./scripts/import-master-data.sh /path/to/master-data-YYYYMMDD-HHMMSS.tar.gz
#    ./scripts/import-master-data.sh /path/to/dir --dry-run
#
#  Safety:
#    - Intended for a FRESH/upgraded DB (no sales/receivings), or same-ID restore.
#    - Aborts if transactional rows exist unless --force is passed.
#    - Creates a full DB backup under docker/backups/ before replacing data
#      unless --skip-backup is passed.
#    - Clears dependent rows (inventory, giftcards, customer account, …)
#      before replacing master tables so FKs do not block the wipe.
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
BACKUP_ROOT="${DOCKER_DIR}/backups"

SOURCE=""
DRY_RUN=0
SKIP_BACKUP=0
YES=0
FORCE=0

die() { echo "ERROR: $*" >&2; exit 1; }
info() { echo "$*"; }

usage() {
  cat <<EOF
Usage: $0 <master-data-dir|archive.tar.gz> [options]

Options:
  --dry-run       Show what would be imported; do not write
  --skip-backup   Do not take a full DB backup before replace
  --yes           Do not prompt for confirmation
  --force         Allow import even if sales/receivings/inventory exist
                  (may leave orphan transactional rows if IDs differ)
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dry-run) DRY_RUN=1; shift ;;
    --skip-backup) SKIP_BACKUP=1; shift ;;
    --yes|-y) YES=1; shift ;;
    --force) FORCE=1; shift ;;
    -h|--help) usage; exit 0 ;;
    -*)
      die "Unknown option: $1"
      ;;
    *)
      if [[ -z "${SOURCE}" ]]; then
        SOURCE="$1"
      else
        die "Unexpected argument: $1"
      fi
      shift
      ;;
  esac
done

[[ -n "${SOURCE}" ]] || { usage; exit 1; }

need_container() {
  docker inspect "$1" >/dev/null 2>&1 || die "Container '$1' not found. Start the stack first."
  local status
  status="$(docker inspect -f '{{.State.Running}}' "$1" 2>/dev/null || echo false)"
  [[ "$status" == "true" ]] || die "Container '$1' is not running."
}

mysql_exec() {
  # With args: pass to mariadb (e.g. -N -e "SQL"). Without args: SQL on stdin.
  if [[ -n "${MYSQL_ROOT_PASSWORD}" ]]; then
    if [[ $# -gt 0 ]]; then
      docker exec "${DB_CONTAINER}" mariadb -uroot -p"${MYSQL_ROOT_PASSWORD}" "${MYSQL_DATABASE}" "$@"
    else
      docker exec -i "${DB_CONTAINER}" mariadb -uroot -p"${MYSQL_ROOT_PASSWORD}" "${MYSQL_DATABASE}"
    fi
  else
    if [[ $# -gt 0 ]]; then
      docker exec "${DB_CONTAINER}" mariadb -u"${MYSQL_USER:-admin}" -p"${MYSQL_PASSWORD:-}" "${MYSQL_DATABASE}" "$@"
    else
      docker exec -i "${DB_CONTAINER}" mariadb -u"${MYSQL_USER:-admin}" -p"${MYSQL_PASSWORD:-}" "${MYSQL_DATABASE}"
    fi
  fi
}

mysql_scalar() {
  mysql_exec -N -e "$1" 2>/dev/null | tr -d '\r' | tail -1
}

resolve_source_dir() {
  local src="$1"
  if [[ -d "${src}" ]]; then
    echo "${src}"
    return
  fi
  if [[ -f "${src}" && "${src}" == *.tar.gz ]]; then
    local tmp
    tmp="$(mktemp -d "${TMPDIR:-/tmp}/master-data-import.XXXXXX")"
    tar -xzf "${src}" -C "${tmp}"
    local inner
    inner="$(find "${tmp}" -maxdepth 1 -type d -name 'master-data-*' | head -1)"
    [[ -n "${inner}" ]] || die "Archive did not contain a master-data-* directory"
    echo "${inner}"
    return
  fi
  die "Source not found: ${src}"
}

need_container "${DB_CONTAINER}"
need_container "${APP_CONTAINER}"
[[ -n "${MYSQL_ROOT_PASSWORD}" || -n "${MYSQL_PASSWORD:-}" ]] || die "Set MYSQL_ROOT_PASSWORD or MYSQL_PASSWORD in docker/.env"

WORK_DIR="$(resolve_source_dir "${SOURCE}")"
SQL_DIR="${WORK_DIR}/sql"
UPLOADS_DIR="${WORK_DIR}/uploads"

[[ -d "${SQL_DIR}" ]] || die "Missing sql/ in ${WORK_DIR}"
[[ -f "${WORK_DIR}/manifest.txt" ]] && info "Manifest:"$'\n'"$(cat "${WORK_DIR}/manifest.txt")"$'\n'

SQL_FILES=(
  "01_app_config.sql"
  "02_stock_locations.sql"
  "03_tax_categories.sql"
  "04_tax_codes.sql"
  "05_tax_jurisdictions.sql"
  "06_tax_rates.sql"
  "07_dinner_tables.sql"
  "08_customers_packages.sql"
  "10_people.sql"
  "11_employees.sql"
  "12_grants.sql"
  "20_suppliers.sql"
  "21_customers.sql"
  "30_attribute_definitions.sql"
  "31_attribute_values.sql"
  "40_items.sql"
  "41_items_taxes.sql"
  "42_item_quantities.sql"
  "43_item_kits.sql"
  "44_item_kit_items.sql"
  "45_attribute_links_items.sql"
)

echo "╔══════════════════════════════════════════════╗"
echo "║   Import POS master data                     ║"
echo "╚══════════════════════════════════════════════╝"
echo "Source   : ${WORK_DIR}"
echo "Database : ${MYSQL_DATABASE} @ ${DB_CONTAINER}"
echo "Mode     : $([ "${DRY_RUN}" -eq 1 ] && echo DRY-RUN || echo WRITE)"
echo ""

MISSING=0
for f in "${SQL_FILES[@]}"; do
  if [[ ! -f "${SQL_DIR}/${f}" ]]; then
    echo "  ⚠ missing ${f} (will skip)"
    MISSING=1
  else
    echo "  ✓ ${f}"
  fi
done
[[ "${MISSING}" -eq 0 ]] || info "(Some optional files missing — import continues with what exists.)"
echo ""

# --- Preflight: transactional data that FKs into master tables ---
SALES_CNT="$(mysql_scalar "SELECT COUNT(*) FROM ${TABLE_PREFIX}sales;" || echo 0)"
RECV_CNT="$(mysql_scalar "SELECT COUNT(*) FROM ${TABLE_PREFIX}receivings;" || echo 0)"
INV_CNT="$(mysql_scalar "SELECT COUNT(*) FROM ${TABLE_PREFIX}inventory;" || echo 0)"
SI_CNT="$(mysql_scalar "SELECT COUNT(*) FROM ${TABLE_PREFIX}sales_items;" || echo 0)"
info "Preflight transactional counts: sales=${SALES_CNT} receivings=${RECV_CNT} inventory=${INV_CNT} sales_items=${SI_CNT}"

if [[ "${FORCE}" -ne 1 ]]; then
  if [[ "${SALES_CNT:-0}" -gt 0 || "${RECV_CNT:-0}" -gt 0 || "${INV_CNT:-0}" -gt 0 || "${SI_CNT:-0}" -gt 0 ]]; then
    die "Target DB still has transactional rows (sales/receivings/inventory).
Import is designed for a fresh/upgraded empty schema, or a restore that
keeps the same person_id/item_id values.

Re-run with --force only if you understand orphan FK risk, or purge
transactional data first (see docker/PURGE-TRANSACTIONAL-DATA.md)."
  fi
else
  info "⚠  --force: continuing despite transactional rows (orphan FK risk if IDs differ)."
fi

if [[ "${DRY_RUN}" -eq 1 ]]; then
  info "Dry-run only. No changes made."
  exit 0
fi

if [[ "${YES}" -ne 1 ]]; then
  echo "This will REPLACE master data in '${MYSQL_DATABASE}' (items, customers,"
  echo "employees/grants, attributes, app_config, related setup tables)."
  echo "Dependent inventory / customer-account / giftcard rows are cleared first."
  echo "modules/permissions/migrations are kept; grants are re-applied from the dump."
  read -r -p "Continue? [y/N] " ans
  [[ "${ans}" == "y" || "${ans}" == "Y" ]] || die "Aborted."
fi

# --- Full DB backup first ---
if [[ "${SKIP_BACKUP}" -ne 1 ]]; then
  mkdir -p "${BACKUP_ROOT}"
  BAK="${BACKUP_ROOT}/pos-before-master-import-$(date +%Y%m%d-%H%M%S).sql"
  info "💾  Full DB backup → ${BAK}"
  if [[ -n "${MYSQL_ROOT_PASSWORD}" ]]; then
    docker exec "${DB_CONTAINER}" mariadb-dump -uroot -p"${MYSQL_ROOT_PASSWORD}" \
      --single-transaction --routines --triggers "${MYSQL_DATABASE}" > "${BAK}"
  else
    docker exec "${DB_CONTAINER}" mariadb-dump -u"${MYSQL_USER:-admin}" -p"${MYSQL_PASSWORD:-}" \
      --single-transaction --routines --triggers "${MYSQL_DATABASE}" > "${BAK}"
  fi
fi

# --- Clear target master tables (FK-safe), keep modules/permissions/migrations ---
info "🧹  Clearing target master-data tables…"

# Optional dependents — skip quietly if the table does not exist on this schema.
clear_table_if_exists() {
  local table="$1"
  local extra="${2:-}"
  mysql_exec <<SQL >/dev/null 2>&1 || true
SET FOREIGN_KEY_CHECKS=0;
DELETE FROM ${table} ${extra};
SET FOREIGN_KEY_CHECKS=1;
SQL
}

# Customer-account / CI dependents
clear_table_if_exists "${TABLE_PREFIX}customer_payment_allocations"
clear_table_if_exists "${TABLE_PREFIX}customer_account_payments"
clear_table_if_exists "${TABLE_PREFIX}customer_credit_applications"
clear_table_if_exists "${TABLE_PREFIX}customer_credit_refunds"
clear_table_if_exists "${TABLE_PREFIX}customer_return_credit_allocations"
clear_table_if_exists "${TABLE_PREFIX}customer_return_credits"
clear_table_if_exists "${TABLE_PREFIX}customer_account_audit_events"
clear_table_if_exists "${TABLE_PREFIX}customers_points"
clear_table_if_exists "${TABLE_PREFIX}consolidated_invoice_sales"
clear_table_if_exists "${TABLE_PREFIX}consolidated_invoices"

# Rows that FK into items / employees / people / stock_locations / suppliers
clear_table_if_exists "${TABLE_PREFIX}inventory"
clear_table_if_exists "${TABLE_PREFIX}giftcards"
clear_table_if_exists "${TABLE_PREFIX}expenses"
clear_table_if_exists "${TABLE_PREFIX}cash_up"

mysql_exec <<SQL
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

-- Attributes (item links only cleared; sale/receiving links left if present)
DELETE FROM ${TABLE_PREFIX}attribute_links WHERE item_id IS NOT NULL;

DELETE FROM ${TABLE_PREFIX}item_kit_items;
DELETE FROM ${TABLE_PREFIX}item_kits;
DELETE FROM ${TABLE_PREFIX}item_quantities;
DELETE FROM ${TABLE_PREFIX}items_taxes;
DELETE FROM ${TABLE_PREFIX}items;

DELETE FROM ${TABLE_PREFIX}attribute_values;
DELETE FROM ${TABLE_PREFIX}attribute_definitions;

-- Customers / suppliers / employees (people shared)
DELETE FROM ${TABLE_PREFIX}grants;
DELETE FROM ${TABLE_PREFIX}customers;
DELETE FROM ${TABLE_PREFIX}suppliers;
DELETE FROM ${TABLE_PREFIX}employees;

-- Reload people from dump (IDs preserved so passwords/grants stay aligned)
DELETE FROM ${TABLE_PREFIX}people;

DELETE FROM ${TABLE_PREFIX}customers_packages;
DELETE FROM ${TABLE_PREFIX}dinner_tables;
DELETE FROM ${TABLE_PREFIX}tax_rates;
DELETE FROM ${TABLE_PREFIX}tax_jurisdictions;
DELETE FROM ${TABLE_PREFIX}tax_codes;
DELETE FROM ${TABLE_PREFIX}tax_categories;
-- Keep stock_locations rows that permissions.location_id may reference until
-- replace dump loads; wipe then reload from dump (IDs should match location 1).
DELETE FROM ${TABLE_PREFIX}stock_locations;
DELETE FROM ${TABLE_PREFIX}app_config;

SET FOREIGN_KEY_CHECKS=1;
SQL

# --- Load SQL files in order (each dump toggles FOREIGN_KEY_CHECKS) ---
info "📥  Loading SQL dumps…"
for f in "${SQL_FILES[@]}"; do
  path="${SQL_DIR}/${f}"
  [[ -f "${path}" ]] || continue
  echo "  → ${f}"
  if ! mysql_exec < "${path}"; then
    die "Failed loading ${f}. Check FK/unique conflicts above."
  fi
done

# Drop grants that reference permissions missing on this schema version
info "🔐  Pruning grants for unknown permissions…"
mysql_exec <<SQL
SET FOREIGN_KEY_CHECKS=0;
DELETE g FROM ${TABLE_PREFIX}grants g
LEFT JOIN ${TABLE_PREFIX}permissions p ON p.permission_id = g.permission_id
WHERE p.permission_id IS NULL;
SET FOREIGN_KEY_CHECKS=1;
SQL

# --- Fix AUTO_INCREMENT counters ---
info "🔢  Syncing AUTO_INCREMENT values…"
mysql_exec <<SQL
SET @m := (SELECT IFNULL(MAX(person_id),0)+1 FROM ${TABLE_PREFIX}people);
SET @s := CONCAT('ALTER TABLE ${TABLE_PREFIX}people AUTO_INCREMENT=', @m);
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @m := (SELECT IFNULL(MAX(item_id),0)+1 FROM ${TABLE_PREFIX}items);
SET @s := CONCAT('ALTER TABLE ${TABLE_PREFIX}items AUTO_INCREMENT=', @m);
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @m := (SELECT IFNULL(MAX(definition_id),0)+1 FROM ${TABLE_PREFIX}attribute_definitions);
SET @s := CONCAT('ALTER TABLE ${TABLE_PREFIX}attribute_definitions AUTO_INCREMENT=', @m);
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @m := (SELECT IFNULL(MAX(attribute_id),0)+1 FROM ${TABLE_PREFIX}attribute_values);
SET @s := CONCAT('ALTER TABLE ${TABLE_PREFIX}attribute_values AUTO_INCREMENT=', @m);
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @m := (SELECT IFNULL(MAX(location_id),0)+1 FROM ${TABLE_PREFIX}stock_locations);
SET @s := CONCAT('ALTER TABLE ${TABLE_PREFIX}stock_locations AUTO_INCREMENT=', @m);
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @m := (SELECT IFNULL(MAX(package_id),0)+1 FROM ${TABLE_PREFIX}customers_packages);
SET @s := CONCAT('ALTER TABLE ${TABLE_PREFIX}customers_packages AUTO_INCREMENT=', @m);
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @m := (SELECT IFNULL(MAX(item_kit_id),0)+1 FROM ${TABLE_PREFIX}item_kits);
SET @s := CONCAT('ALTER TABLE ${TABLE_PREFIX}item_kits AUTO_INCREMENT=', @m);
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SQL

# --- Restore uploads ---
info "🖼  Restoring uploads into ${APP_CONTAINER}:/app/public/uploads …"
docker exec "${APP_CONTAINER}" sh -c 'mkdir -p /app/public/uploads/item_pics'

if [[ -d "${UPLOADS_DIR}/company" ]]; then
  while IFS= read -r -d '' file; do
    base="$(basename "${file}")"
    docker cp "${file}" "${APP_CONTAINER}:/app/public/uploads/${base}"
    echo "  → company/${base}"
  done < <(find "${UPLOADS_DIR}/company" -type f -print0 2>/dev/null)
fi

if [[ -d "${UPLOADS_DIR}/item_pics" ]]; then
  # Copy files into item_pics
  TMP_COPY="$(mktemp -d "${TMPDIR:-/tmp}/item-pics.XXXXXX")"
  cp -a "${UPLOADS_DIR}/item_pics/." "${TMP_COPY}/" 2>/dev/null || true
  # docker cp of a directory's contents
  if find "${TMP_COPY}" -type f | grep -q .; then
    docker cp "${TMP_COPY}/." "${APP_CONTAINER}:/app/public/uploads/item_pics/"
    echo "  → item_pics restored"
  else
    echo "  → item_pics empty (skipped)"
  fi
  rm -rf "${TMP_COPY}"
fi

# Ensure web user can read uploads
docker exec "${APP_CONTAINER}" sh -c 'chown -R www-data:www-data /app/public/uploads 2>/dev/null || true; chmod -R ug+rwX /app/public/uploads 2>/dev/null || true'

# --- Verify ---
info ""
info "🔎  Verification counts:"
mysql_exec <<SQL
SELECT 'employees' AS entity, COUNT(*) AS cnt FROM ${TABLE_PREFIX}employees
UNION ALL SELECT 'customers', COUNT(*) FROM ${TABLE_PREFIX}customers
UNION ALL SELECT 'items', COUNT(*) FROM ${TABLE_PREFIX}items
UNION ALL SELECT 'attribute_definitions', COUNT(*) FROM ${TABLE_PREFIX}attribute_definitions
UNION ALL SELECT 'app_config', COUNT(*) FROM ${TABLE_PREFIX}app_config
UNION ALL SELECT 'grants', COUNT(*) FROM ${TABLE_PREFIX}grants;
SQL

info ""
info "🔎  FK integrity spot-checks (should all be 0):"
mysql_exec <<SQL
SELECT 'items.missing_supplier' AS check_name, COUNT(*) AS bad
FROM ${TABLE_PREFIX}items i
LEFT JOIN ${TABLE_PREFIX}suppliers s ON s.person_id = i.supplier_id
WHERE i.supplier_id IS NOT NULL AND s.person_id IS NULL
UNION ALL
SELECT 'customers.missing_person', COUNT(*)
FROM ${TABLE_PREFIX}customers c
LEFT JOIN ${TABLE_PREFIX}people p ON p.person_id = c.person_id
WHERE p.person_id IS NULL
UNION ALL
SELECT 'employees.missing_person', COUNT(*)
FROM ${TABLE_PREFIX}employees e
LEFT JOIN ${TABLE_PREFIX}people p ON p.person_id = e.person_id
WHERE p.person_id IS NULL
UNION ALL
SELECT 'grants.missing_permission', COUNT(*)
FROM ${TABLE_PREFIX}grants g
LEFT JOIN ${TABLE_PREFIX}permissions p ON p.permission_id = g.permission_id
WHERE p.permission_id IS NULL
UNION ALL
SELECT 'grants.missing_employee', COUNT(*)
FROM ${TABLE_PREFIX}grants g
LEFT JOIN ${TABLE_PREFIX}employees e ON e.person_id = g.person_id
WHERE e.person_id IS NULL
UNION ALL
SELECT 'item_qty.missing_location', COUNT(*)
FROM ${TABLE_PREFIX}item_quantities q
LEFT JOIN ${TABLE_PREFIX}stock_locations l ON l.location_id = q.location_id
WHERE l.location_id IS NULL
UNION ALL
SELECT 'attr_links.missing_item', COUNT(*)
FROM ${TABLE_PREFIX}attribute_links a
LEFT JOIN ${TABLE_PREFIX}items i ON i.item_id = a.item_id
WHERE a.item_id IS NOT NULL AND i.item_id IS NULL
UNION ALL
SELECT 'permissions.missing_location', COUNT(*)
FROM ${TABLE_PREFIX}permissions p
LEFT JOIN ${TABLE_PREFIX}stock_locations l ON l.location_id = p.location_id
WHERE p.location_id IS NOT NULL AND l.location_id IS NULL;
SQL

info ""
info "✅  Import complete."
info "Employee logins use the exported password hashes (hash_version preserved)."
info "Sign in with an exported username/password to confirm."
