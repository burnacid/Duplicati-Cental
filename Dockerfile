# syntax=docker/dockerfile:1
#
# Duplicati Central image: FrankenPHP (Caddy + PHP) serving the app, plus the queue
# worker, scheduler and remote-control websocket server, managed by supervisord.
# Built in stages so Composer and Node are not part of the final image.

# ---------------------------------------------------------------- PHP dependencies
FROM composer:2 AS vendor
WORKDIR /app
COPY app/composer.json app/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs

# ---------------------------------------------------------------- frontend assets
FROM node:22-slim AS assets
WORKDIR /app
COPY app/package.json app/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY app/vite.config.js ./
COPY app/resources ./resources
# Tailwind scans Flux's Blade stubs and imports its CSS from vendor/.
COPY --from=vendor /app/vendor/livewire/flux ./vendor/livewire/flux
COPY --from=vendor /app/vendor/laravel/framework/src/Illuminate/Pagination/resources/views ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views
RUN npm run build

# ---------------------------------------------------------------- runtime
FROM dunglas/frankenphp:1-php8.4-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends supervisor unzip \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pcntl pdo_mysql opcache intl zip bcmath gmp \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-duplicati-central.ini
COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY supervisord.conf /etc/supervisor/conf.d/app.conf

WORKDIR /app
COPY app/ /app/
COPY --from=vendor /app/vendor /app/vendor
COPY --from=assets /app/public/build /app/public/build
COPY entrypoint.sh /app/entrypoint.sh

RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && chmod +x /app/entrypoint.sh \
    && rm /usr/local/bin/composer

# Database, generated APP_KEY, remote-control keys, logs and Caddy certificates live here.
VOLUME ["/app/storage"]

ENV APP_ENV=production \
    APP_DEBUG=false \
    APP_NAME="Duplicati Central" \
    APP_URL=http://localhost:8181 \
    APP_LOCALE=en \
    APP_FALLBACK_LOCALE=en \
    APP_FAKER_LOCALE=en_US \
    APP_MAINTENANCE_DRIVER=file \
    BCRYPT_ROUNDS=12 \
    LOG_CHANNEL=stack \
    LOG_STACK=daily \
    LOG_DAILY_DAYS=14 \
    LOG_DEPRECATIONS_CHANNEL=null \
    LOG_LEVEL=info \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/app/storage/database.sqlite \
    SESSION_DRIVER=database \
    SESSION_LIFETIME=120 \
    SESSION_ENCRYPT=false \
    SESSION_PATH=/ \
    BROADCAST_CONNECTION=log \
    FILESYSTEM_DISK=local \
    QUEUE_CONNECTION=database \
    CACHE_STORE=database \
    MAIL_MAILER=log \
    MAIL_FROM_ADDRESS="hello@example.com" \
    MAIL_FROM_NAME="Duplicati Central" \
    SERVER_NAME=":8181" \
    XDG_CONFIG_HOME=/app/storage/caddy/config \
    XDG_DATA_HOME=/app/storage/caddy/data

# Version is passed in by release.ps1 (--build-arg APP_VERSION=x.y.z). Declared late to keep layer caching.
ARG APP_VERSION=dev
ARG VCS_REF=unknown
ARG BUILD_DATE=unknown
ENV APP_VERSION=${APP_VERSION}
LABEL org.opencontainers.image.title="Duplicati Central" \
      org.opencontainers.image.description="Central dashboard, alerting and remote control for Duplicati backups" \
      org.opencontainers.image.version="${APP_VERSION}" \
      org.opencontainers.image.revision="${VCS_REF}" \
      org.opencontainers.image.created="${BUILD_DATE}" \
      org.opencontainers.image.source="https://github.com/burnacid/Duplicati-Cental"

# 8181: web UI, API and remote-control websocket. 8090: websocket directly (older setups).
# 80/443: only used when SERVER_NAME is a domain (automatic HTTPS).
EXPOSE 8181 8090 80 443

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD case "$SERVER_NAME" in \
          :*) curl -fsS "http://127.0.0.1${SERVER_NAME%%,*}/up" ;; \
          *) host="${SERVER_NAME%%,*}"; curl -fsSk --resolve "${host}:443:127.0.0.1" "https://${host}/up" ;; \
        esac || exit 1

ENTRYPOINT ["/app/entrypoint.sh"]
CMD ["supervisord", "-n", "-c", "/etc/supervisor/conf.d/app.conf"]
