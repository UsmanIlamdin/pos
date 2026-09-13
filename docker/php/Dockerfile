# =============================================================
#  Stage 1: Build frontend assets (Node 20 + Composer)
#  Build context = pos/ (one level above docker/)
# =============================================================
FROM node:20-alpine AS builder

WORKDIR /build

# Install PHP CLI for Composer — only needs to resolve deps,
# so we use --ignore-platform-reqs (real extensions live in the prod stage)
RUN apk add --no-cache php83 php83-curl php83-json php83-mbstring php83-openssl \
    php83-phar php83-tokenizer php83-xml php83-xmlwriter curl git \
    && ln -sf /usr/bin/php83 /usr/bin/php

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Copy application source (context root = pos/)
COPY opensourcepos /build

# Install PHP dependencies — ignore platform reqs since the
# actual extensions are installed in the production PHP image
RUN composer install \
    --no-dev \
    --no-interaction \
    --optimize-autoloader \
    --prefer-dist \
    --no-progress \
    --ignore-platform-reqs

# Install Node dependencies and build assets
# Use npm install to ensure devDependencies (like gulp) are installed
RUN npm install --include=dev --no-audit && \
    npx gulp --production || npx gulp

# =============================================================
#  Stage 2: Production PHP 8.2 + Apache runtime
# =============================================================
FROM php:8.2-apache AS production

LABEL maintainer="BrainCortex Docker Production Setup"

# Install required PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
        libicu-dev \
        libgd-dev \
        libzip-dev \
        curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        mysqli \
        bcmath \
        intl \
        gd \
        zip \
        opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers

# OPcache — production-optimised settings
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.memory_consumption=128'; \
    echo 'opcache.interned_strings_buffer=8'; \
    echo 'opcache.max_accelerated_files=4000'; \
    echo 'opcache.revalidate_freq=60'; \
    echo 'opcache.fast_shutdown=1'; \
    echo 'opcache.enable_cli=0'; \
} > /usr/local/etc/php/conf.d/opcache.ini

# PHP runtime settings
COPY docker/php/php.ini /usr/local/etc/php/conf.d/app.ini

# Apache vhost
COPY docker/php/apache.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /app

# Copy built app from builder stage
COPY --from=builder --chown=www-data:www-data /build /app

# Also allow www-data to write /app/.env (app auto-provisions throttle.key there)
# Set correct permissions for writable application files
RUN chmod -R 750 \
        /app/writable/logs \
        /app/writable/uploads \
        /app/writable/cache \
        /app/public/uploads \
    && ln -nsf /app/public /var/www/html \
    && cp /app/.env.example /app/.env \
    && chown -R www-data:www-data /app \
    && chmod 660 /app/.env

EXPOSE 80
