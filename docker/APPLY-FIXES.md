# Apply domain / CSP / reports fixes (no data loss)

Use this on a running production-style stack when you change `APP_DOMAIN`
(e.g. to `ghazi-pos.local`) or hit:

- CSP: `Connecting to 'https://localhost/...' violates connect-src 'self'`
- `GET /reports` → HTTP 500 (`var_export does not handle circular references`)

These steps **recreate only the `app` (and Traefik if needed) containers**.
They do **not** touch the MariaDB `db_data` volume, so employees, sales,
items, and config stay intact.

## Root causes (short)

1. **CI4 Factories config cache** froze `App::$baseURL` as `https://localhost/`
   (often from the Apache healthcheck Host header). AJAX then called localhost
   while the browser was on `ghazi-pos.local` → CSP blocked it.
2. **Saving that cache** threw circular-reference errors → `/reports` 500.
3. Container `/app/.env` did not always rewrite `app.baseURL` /
   `app.allowedHostnames` when you only edited `docker/.env`.

Docker now: disables config caching, always syncs domain into `/app/.env` on
start, clears factories cache, and healthchecks with `Host: $APP_DOMAIN`.

---

## 0. Prerequisites

```bash
cd /path/to/pos/docker   # e.g. ~/Sites/pos/docker
```

Confirm containers:

```bash
docker compose ps
```

Optional but recommended DB backup (data stays either way):

```bash
mkdir -p backups
docker exec pos-db mariadb-dump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers "$MYSQL_DATABASE" \
  > "backups/pos-before-domain-fix-$(date +%Y%m%d-%H%M%S).sql"
```

Load root password from `.env` if needed:

```bash
set -a && source ./.env && set +a
```

---

## 1. Set domain + hosts

Edit `docker/.env`:

```env
APP_DOMAIN=ghazi-pos.local
FORCE_HTTPS=true
```

On the **host** machine `/etc/hosts`:

```text
127.0.0.1   ghazi-pos.local
```

(If phones/LAN PCs use the shop IP, also point that hostname at the LAN IP,
and keep `LAN_IP` updated via `./scripts/start.sh`.)

Mirror allowed hosts on the **host copy** of app env (documentation / future
image builds; runtime is synced by Compose on start):

```bash
# optional sync of opensourcepos/.env (host file; container uses image .env)
# app.baseURL='https://ghazi-pos.local/'
# app.allowedHostnames='ghazi-pos.local,<LAN_IP>,localhost'
```

---

## 2. Apply stack fix **without** dropping the database

Recreate **app only** (DB volume untouched):

```bash
cd /path/to/pos/docker
set -a && source ./.env && set +a

# Pull compose changes into a fresh app container
docker compose up -d --force-recreate --no-deps app

# Clear any leftover CI caches inside the running app
docker exec pos-app sh -c 'rm -f /app/writable/cache/FactoriesCache_config /app/writable/cache/FileLocatorCache /app/writable/cache/settings'

# Confirm domain landed in the container .env
docker exec pos-app grep -E '^(app\.baseURL|app\.allowedHostnames|logger\.threshold)=' /app/.env
```

Expected:

```text
app.allowedHostnames='ghazi-pos.local,<your-lan-ip>,localhost'
app.baseURL='https://ghazi-pos.local/'
logger.threshold=4
```

If Traefik routes look stale:

```bash
docker compose up -d --force-recreate --no-deps traefik app
```

**Do not** run `docker compose down -v` — that deletes `db_data`.

---

## 3. Verify

```bash
# Should be 200/302 (not 500)
curl -sk -o /dev/null -w "%{http_code}\n" https://ghazi-pos.local/reports

# HTML should not generate https://localhost links when Host is the custom domain
curl -sk https://ghazi-pos.local/home | grep -oE 'https://[^"'\'' ]+' | sort -u | head
```

In the browser:

1. Hard-refresh (or clear site cookies for both `localhost` and `ghazi-pos.local`).
2. Log in at `https://ghazi-pos.local/`.
3. Open **Employees → view**, confirm no CSP console error about `https://localhost/...`.
4. Open **Reports**, confirm no HTTP 500.

Logs (host bind-mount):

```bash
tail -f ../opensourcepos/writable/logs/log-$(date +%Y-%m-%d).log
```

---

## 4. One-liner “prod safe” apply

From `docker/`:

```bash
set -a && source ./.env && set +a \
  && docker compose up -d --force-recreate --no-deps app \
  && docker exec pos-app sh -c 'rm -f /app/writable/cache/FactoriesCache_config /app/writable/cache/FileLocatorCache /app/writable/cache/settings' \
  && docker exec pos-app grep -E '^(app\.baseURL|app\.allowedHostnames)=' /app/.env \
  && curl -sk -o /dev/null -w "reports HTTP %{http_code}\n" https://"${APP_DOMAIN}/reports"
```

---

## What this does **not** change

| Kept | Removed / reset |
|------|-----------------|
| MariaDB `db_data` (all POS data) | App container filesystem caches |
| Uploads volume `app_uploads` | Poisoned `FactoriesCache_config` |
| Employee accounts & permissions | Stale `app.baseURL=localhost` in container `.env` |

To wipe transactional data on purpose, see [PURGE-TRANSACTIONAL-DATA.md](./PURGE-TRANSACTIONAL-DATA.md).
