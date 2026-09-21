# Purge invoices / items (keep employees)

SQL for the MariaDB database used by this stack (`MYSQL_DATABASE`, usually `pos`).
Table prefix: `ospos_`.

**Warning:** these deletes are permanent. Take a backup first.

```bash
cd /path/to/pos/docker
set -a && source ./.env && set +a
mkdir -p backups
docker exec pos-db mariadb-dump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers "$MYSQL_DATABASE" \
  > "backups/pos-before-purge-$(date +%Y%m%d-%H%M%S).sql"
```

Run interactively:

```bash
docker exec -it pos-db mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"
```

Or pipe a file:

```bash
docker exec -i pos-db mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" < purge.sql
```

---

## A. Always keep

These are **not** deleted by the required sections below:

- `ospos_employees` + their rows in `ospos_people`
- `ospos_grants` / `ospos_permissions` / `ospos_modules` (login rights)
- `ospos_app_config` (store settings)
- `ospos_stock_locations` (unless you choose otherwise)
- Tax setup tables (`ospos_tax_*`) unless you clear them yourself

---

## B. Required — delete sales (invoices) + items

Run inside a transaction. Order respects foreign keys.

```sql
START TRANSACTION;

-- ---------- Sales / invoices ----------
DELETE FROM ospos_sales_items_taxes;
DELETE FROM ospos_sales_taxes;
DELETE FROM ospos_sales_reward_points;
DELETE FROM ospos_sales_payments;
DELETE FROM ospos_sales_items;
DELETE FROM ospos_customers_points WHERE sale_id IS NOT NULL;
DELETE FROM ospos_attribute_links WHERE sale_id IS NOT NULL;
DELETE FROM ospos_sales;

-- ---------- Receivings (stock in) tied to items ----------
DELETE FROM ospos_receivings_items;
DELETE FROM ospos_attribute_links WHERE receiving_id IS NOT NULL;
DELETE FROM ospos_receivings;

-- ---------- Item kits + stock movement ----------
DELETE FROM ospos_item_kit_items;
DELETE FROM ospos_item_kits;
DELETE FROM ospos_items_taxes;
DELETE FROM ospos_item_quantities;
DELETE FROM ospos_inventory;
DELETE FROM ospos_attribute_links WHERE item_id IS NOT NULL;
DELETE FROM ospos_items;

-- Optional: cash-ups / expenses often tied to daily ops (safe for “fresh catalogue”)
DELETE FROM ospos_cash_up;
DELETE FROM ospos_expenses;

COMMIT;
```

After this:

- Employees can still log in.
- Customers / suppliers / people rows remain (unless you run section C/D).
- Item **category** is a string column on `ospos_items` — deleting items removes those category values automatically. There is no separate categories table.

Reset AUTO_INCREMENT (optional):

```sql
ALTER TABLE ospos_sales AUTO_INCREMENT = 1;
ALTER TABLE ospos_items AUTO_INCREMENT = 1;
ALTER TABLE ospos_receivings AUTO_INCREMENT = 1;
ALTER TABLE ospos_item_kits AUTO_INCREMENT = 1;
ALTER TABLE ospos_cash_up AUTO_INCREMENT = 1;
ALTER TABLE ospos_expenses AUTO_INCREMENT = 1;
```

---

## C. Optional — clear customers

Keeps employees. Removes customer people who are not also employees/suppliers.

```sql
START TRANSACTION;

DELETE FROM ospos_customers_points;
UPDATE ospos_giftcards SET person_id = NULL WHERE person_id IN (SELECT person_id FROM ospos_customers);
-- If gift cards should go entirely:
-- DELETE FROM ospos_giftcards;

DELETE FROM ospos_customers;

DELETE FROM ospos_people
WHERE person_id NOT IN (SELECT person_id FROM ospos_employees)
  AND person_id NOT IN (SELECT person_id FROM ospos_suppliers)
  AND person_id NOT IN (SELECT person_id FROM ospos_giftcards WHERE person_id IS NOT NULL);

COMMIT;
```

---

## D. Optional — clear vendors / suppliers

Run **after** items are gone (or after `supplier_id` is cleared). Section B already deleted items.

```sql
START TRANSACTION;

UPDATE ospos_expenses SET supplier_id = NULL WHERE supplier_id IS NOT NULL;
DELETE FROM ospos_suppliers;

DELETE FROM ospos_people
WHERE person_id NOT IN (SELECT person_id FROM ospos_employees)
  AND person_id NOT IN (SELECT person_id FROM ospos_customers)
  AND person_id NOT IN (SELECT person_id FROM ospos_giftcards WHERE person_id IS NOT NULL);

COMMIT;
```

---

## E. Optional — clear item attribute definitions (“categories” extras)

OSPOS item categories are free-text on `ospos_items.category`.
Custom attributes live in `ospos_attribute_*`. Only run if you want those wiped too.

```sql
START TRANSACTION;

DELETE FROM ospos_attribute_links;
DELETE FROM ospos_attribute_values;
DELETE FROM ospos_attribute_definitions;

COMMIT;
```

Expense categories (not item categories):

```sql
START TRANSACTION;

DELETE FROM ospos_expenses;
DELETE FROM ospos_expense_categories;

COMMIT;
```

---

## F. Optional — clear gift cards only

```sql
DELETE FROM ospos_giftcards;
```

---

## G. Verify counts

```sql
SELECT 'employees' AS what, COUNT(*) AS n FROM ospos_employees
UNION ALL SELECT 'people', COUNT(*) FROM ospos_people
UNION ALL SELECT 'sales', COUNT(*) FROM ospos_sales
UNION ALL SELECT 'items', COUNT(*) FROM ospos_items
UNION ALL SELECT 'customers', COUNT(*) FROM ospos_customers
UNION ALL SELECT 'suppliers', COUNT(*) FROM ospos_suppliers
UNION ALL SELECT 'attribute_definitions', COUNT(*) FROM ospos_attribute_definitions;
```

Expect: `employees` unchanged, `sales`/`items` = 0 after section B.

---

## Rollback

```bash
docker exec -i pos-db mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" < backups/pos-before-purge-YYYYMMDD-HHMMSS.sql
```
