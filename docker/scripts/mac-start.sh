#!/usr/bin/env bash

set -e

DOCKER_DIR="$HOME/Sites/pos/docker"
LOG_FILE="$DOCKER_DIR/mac-start.log"

exec >> "$LOG_FILE" 2>&1

echo "=================================================="
echo "POS Docker startup: $(date)"
echo "User: $(whoami)"
echo "Home: $HOME"
echo "=================================================="

echo "Waiting for Docker Desktop..."

for i in {1..60}; do
    if docker info >/dev/null 2>&1; then
        echo "Docker is ready."
        break
    fi

    echo "Docker not ready yet... attempt $i"
    sleep 2
done

if ! docker info >/dev/null 2>&1; then
    echo "ERROR: Docker Desktop was not ready."
    exit 1
fi

cd "$DOCKER_DIR"

echo "Starting POS Docker stack..."

docker compose --project-name pos up -d

echo "POS Docker stack started."
echo "Finished: $(date)"