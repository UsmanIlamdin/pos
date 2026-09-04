FROM alpine:3.19

LABEL maintainer="BrainCortex Backup Service"

# Install MariaDB client tools + bash + tzdata
RUN apk add --no-cache \
    mariadb-client \
    bash \
    gzip \
    tzdata \
    && mkdir -p /backups /scripts

COPY backup.sh /scripts/backup.sh
COPY entrypoint.sh /scripts/entrypoint.sh

RUN chmod +x /scripts/backup.sh /scripts/entrypoint.sh

VOLUME ["/backups"]

ENTRYPOINT ["/scripts/entrypoint.sh"]
