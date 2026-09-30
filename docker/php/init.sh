#!/bin/sh

set -eu

SCHEME="$( [ "${FORCE_HTTPS:-true}" = "true" ] && echo https || echo http )"

if grep -q '^app.baseURL=' /app/.env; then
    sed -i "s|^app.baseURL=.*|app.baseURL='${SCHEME}://${APP_DOMAIN}/'|" /app/.env
else
    echo "app.baseURL='${SCHEME}://${APP_DOMAIN}/'" >> /app/.env
fi

if grep -q '^app.allowedHostnames=' /app/.env; then
    sed -i "s|^app.allowedHostnames=.*|app.allowedHostnames='${ALLOWED_HOSTNAMES}'|" /app/.env
else
    echo "app.allowedHostnames='${ALLOWED_HOSTNAMES}'" >> /app/.env
fi

# Ensure CodeIgniter has a valid encryption key.
if ! grep -Eq '^encryption\.key=(hex2bin:)?[0-9a-fA-F]{64}$' /app/.env; then
    KEY="$(openssl rand -hex 32)"

    if grep -q '^encryption.key=' /app/.env; then
        sed -i "s|^encryption.key=.*|encryption.key=hex2bin:${KEY}|" /app/.env
    else
        echo "encryption.key=hex2bin:${KEY}" >> /app/.env
    fi
fi

rm -f /app/writable/cache/FactoriesCache_config /app/writable/cache/FileLocatorCache

if grep -q '^logger.threshold=' /app/.env; then
    sed -i 's|^logger.threshold=.*|logger.threshold=4|' /app/.env
else
    echo 'logger.threshold=4' >> /app/.env
fi

mkdir -p /app/writable/logs
chown www-data:www-data /app/writable/logs
chmod 775 /app/writable/logs

# Apache runs as www-data; DotEnv must be able to read /.env
chown www-data:www-data /app/.env
chmod 644 /app/.env

exec apache2-foreground
