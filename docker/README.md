# BrainCortex — Production Docker Setup

A fully self-contained, production-ready local deployment of [BrainCortex](https://github.com/opensourcepos/opensourcepos) using Docker Compose with Traefik as the reverse proxy.

---

## 📁 Folder Structure

```
pos/
├── docker/                     ← All Docker config (this folder)
│   ├── .env                    ← ⚙️  Master config — edit this first
│   ├── docker-compose.yml      ← Main orchestration
│   ├── traefik/
│   │   └── traefik.yml         ← Traefik static config
│   ├── php/
│   │   ├── Dockerfile          ← Production PHP 8.2 + Apache image
│   │   ├── php.ini             ← PHP runtime settings
│   │   └── apache.conf         ← Apache vhost
│   ├── backup/
│   │   ├── Dockerfile          ← Backup sidecar image
│   │   ├── backup.sh           ← mysqldump + rotation script
│   │   └── entrypoint.sh       ← Cron scheduler entrypoint
│   ├── mariadb/
│   │   └── init/
│   │       └── 01-init.sh      ← DB charset/grants (runs on first start)
│   ├── backups/                ← 💾 DB backup files land here
│   └── scripts/
│       ├── start.sh            ← One-command start
│       └── stop.sh             ← One-command stop
└── opensourcepos/              ← Application source code
```

---

## ⚙️ Configuration

Edit **`docker/.env`** before starting. Key settings:

| Variable | Default | Description |
|---|---|---|
| `APP_DOMAIN` | `localhost` | Hostname for the app (no http://) |
| `LAN_IP` | auto | Optional pin; otherwise `start.sh` detects Wi‑Fi IP |
| `APP_PORT` | `80` | HTTP port (redirects to HTTPS) |
| `APP_HTTPS_PORT` | `443` | HTTPS port Traefik listens on |
| `FORCE_HTTPS` | `true` | Tell the PHP app to use HTTPS URLs |
| `TRAEFIK_DASHBOARD_PORT` | `8080` | Traefik dashboard port |
| `MYSQL_ROOT_PASSWORD` | *(set this!)* | MariaDB root password |
| `MYSQL_USER` | `admin` | App DB user |
| `MYSQL_PASSWORD` | *(set this!)* | App DB password |
| `BACKUP_INTERVAL_HOURS` | `12` | How often to run DB backups |
| `BACKUP_KEEP_DAYS` | `7` | How many days of backups to keep |
| `PHP_TIMEZONE` | `UTC` | PHP/app timezone |

### Using a custom domain (e.g. `pos.local`)

1. Set `APP_DOMAIN=pos.local` in `.env`
2. Add to `/etc/hosts`:
   ```
   127.0.0.1  pos.local
   ```
3. Access at `https://pos.local` (HTTP on port 80 redirects to HTTPS)

### Access from phones / PCs on the same Wi‑Fi

`start.sh` binds Traefik to `0.0.0.0`, detects this Mac’s LAN IP, writes it into `.env` as `LAN_IP=…`, and puts that IP in the TLS certificate + Traefik host rules.

1. Start the stack: `./scripts/start.sh`
2. On another device, open either:
   - `https://<LAN-IP>` (always works on the same Wi‑Fi)
   - `https://ghazi-pos.local` after adding hosts: `<LAN-IP>  ghazi-pos.local`
3. Accept the self-signed certificate warning once

To pin a fixed LAN IP across Wi‑Fi changes, set `LAN_IP=x.x.x.x` and `LAN_IP_PINNED=true` in `.env`.

On first start, `scripts/start.sh` creates a self-signed TLS certificate for `APP_DOMAIN` + LAN IP in `traefik/certs/`.

---

## 📂 Live code mount (why not the whole `/app`?)

Compose mounts the **whole** application PHP tree:

```yaml
- ../opensourcepos/app:/app/app
```

| Host path | Container path | What it is |
|---|---|---|
| `opensourcepos/app/` | `/app/app` | Controllers, Models, Views, Libraries, Migrations |
| *(from image)* | `/app` | Full project: `vendor/`, built `public/`, `spark`, Composer |
| *(from image)* | `/app/public` | Web root (CSS/JS built by gulp during image build) |

**Do not** mount `../opensourcepos/app:/app` (or `/app/`). That overwrites the image’s `vendor/` and `public/` with only the CI `app` folder → Apache/Traefik **404 / 403**.

**Do not** mount `../opensourcepos:/app` either unless the host has already run `composer install` + `npx gulp` (host currently has no `vendor/` or `public/resources/`).

`start.sh` runs `./scripts/sync-app-header.sh` after build so gulp-injected CSS/JS tags in `header.php` match the hashed files inside the image. If the UI looks unstyled after a rebuild, run that script once.

---

## 🗄️ Reset database (fresh install)

This wipes **all** POS data and recreates the MariaDB volume. Migrations then run automatically on first login.

### 1. Stop stack and delete the DB volume

```bash
cd /Users/usman/Sites/pos/docker

# Stops containers and removes named volumes (db_data, uploads, logs, …)
docker compose --project-name pos down --volumes
```

Optional: also remove the app image so the next start rebuilds from scratch:

```bash
docker compose --project-name pos down --volumes --rmi local
```

### 2. Start again (empty DB)

```bash
./scripts/start.sh
```

On first boot MariaDB creates database `${MYSQL_DATABASE}` (default `pos`) and runs `mariadb/init/01-init.sh` (charset + grants). Schema tables are **not** in that init script — OSPOS creates them via migrations.

### 3. Open the app and let migrations run

1. Open `https://ghazi-pos.local` (or your `APP_DOMAIN` / LAN IP)
2. You land on the login page. For a **new empty database**, OSPOS detects `isNewInstall` and runs all migrations on the first POST / login flow automatically
3. Wait until the page reloads (large installs can take a minute)

You can also trigger migrations explicitly (empty DB needs no credentials):

```bash
curl -sk -X POST https://ghazi-pos.local/migrate
```

Or from inside the app container:

```bash
docker exec -it pos-app php spark migrate
```

(`spark migrate` may not seed the default admin the same way as the login migrator — prefer the browser / `/migrate` path for a full OSPOS install.)

### 4. Default admin user (after fresh migrations)

| Field | Value |
|---|---|
| Username | `admin` |
| Password | `pointofsale` |

That user is created by the initial schema migration (`ospos_employees`). Change the password after first login (Employees module).

### 5. Reset admin password later (if locked out)

```bash
# Generate a bcrypt hash
docker exec pos-app php -r "echo password_hash('pointofsale', PASSWORD_DEFAULT), PHP_EOL;"

# Apply it (replace HASH and use your MYSQL_* from .env)
docker exec -it pos-db mariadb -u admin -p'Admin@123' pos -e "
UPDATE ospos_employees
SET password = 'HASH',
    hash_version = 2
WHERE username = 'admin';
"
```

### 6. Clear data but keep the volume (optional)

Drop and recreate only the app database without removing Docker volumes:

```bash
docker exec -it pos-db mariadb -u root -p'Admin@123' -e "
DROP DATABASE IF EXISTS pos;
CREATE DATABASE pos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON pos.* TO 'admin'@'%';
FLUSH PRIVILEGES;
"
```

Then open the login page (or `POST /migrate`) so migrations recreate schema + `admin` / `pointofsale`.

---

## 🚀 Quick Start

```bash
# 1. Configure
cd /Users/usman/Sites/pos/docker
cp .env .env.local   # optional: keep a backup of defaults
nano .env            # set your passwords and APP_DOMAIN

# 2. Start
chmod +x scripts/start.sh scripts/stop.sh
./scripts/start.sh

# 3. Open
open https://localhost       # BrainCortex (accept self-signed cert)
open http://localhost:8080   # Traefik Dashboard
```

**Default login:** `admin` / `pointofsale`

---

## 🐳 Manual Docker Commands

```bash
# All commands from the docker/ folder:
cd /Users/usman/Sites/pos/docker

# Start (build + run)
docker compose --project-name pos up -d --build

# Stop (preserves data)
docker compose --project-name pos down

# Stop and WIPE database (⚠️ destructive)
docker compose --project-name pos down --volumes

# View logs
docker compose --project-name pos logs -f

# View logs for a specific service
docker compose --project-name pos logs -f app
docker compose --project-name pos logs -f traefik
docker compose --project-name pos logs -f backup

# Container status
docker compose --project-name pos ps
```

---

## 💾 Database Backups

Backups run automatically per `BACKUP_INTERVAL_HOURS` (default: every 12 hours).
Files appear in `docker/backups/` on your host machine:

```
backups/
  ospos_2024-01-15_00-00.sql.gz
  ospos_2024-01-15_12-00.sql.gz
  ...
```

### Manual backup
```bash
docker exec pos-backup /scripts/backup.sh
```

### Restore a backup
```bash
gunzip -c docker/backups/ospos_2024-01-15_12-00.sql.gz | \
  docker exec -i pos-db mariadb -u admin -pYOUR_PASSWORD ospos
```

---

## 🏗️ Services

| Container | Role | Internal Port |
|---|---|---|
| `pos-traefik` | Reverse proxy + dashboard | 80 (redirect), 443 (HTTPS), 8080 (dashboard) |
| `pos-app` | PHP 8.2 + Apache | 80 (internal only) |
| `pos-db` | MariaDB 10.11 | 3306 (internal only) |
| `pos-backup` | DB backup sidecar | — |

---

## 🔧 Troubleshooting

**App not loading after start?**
The app container waits for the DB health check to pass. Give it ~60 seconds on first boot.

**Check app health:**
```bash
docker inspect --format='{{.State.Health.Status}}' pos-app
```

**Where are CodeIgniter logs?**
They are bind-mounted to the host at:

```text
pos/opensourcepos/writable/logs/log-YYYY-MM-DD.log
```

Inside the container that is `/app/writable/logs`. In production, CI threshold is `4` (errors and above).

**HTTP 500 with empty JSON body and no CI log?**
That was caused by a poisoned CI4 `writable/cache/FactoriesCache_config` (OSPOS embeds a cache handler that cannot be `var_export`’d). The app source is not patched; Docker handles it via:

- `docker/php/ci4-cache-guard.php` (`auto_prepend_file`) — drops a poisoned factories cache before boot
- `docker/php/Optimize.php` mount — keeps config caching enabled without editing `opensourcepos/`
- container start clears `FactoriesCache_config` / `FileLocatorCache`

**Reset everything (⚠️ deletes all data):**
```bash
docker compose --project-name pos down --volumes --rmi local
```
