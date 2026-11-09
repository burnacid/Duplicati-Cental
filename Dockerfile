FROM php:8.4
RUN apt-get update -y && apt-get install -y openssl zip unzip git npm supervisor
RUN docker-php-ext-install pcntl pdo_mysql
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
WORKDIR /app
COPY /app /app
COPY entrypoint.sh .
COPY supervisord.conf /etc/supervisor/conf.d/app.conf
RUN chmod +x /app/entrypoint.sh
RUN composer install --no-dev --optimize-autoloader --no-interaction
RUN npm ci && npm run build

# Keep the database, generated APP_KEY and remote-control keys across container rebuilds.
VOLUME ["/app/storage"]

ENV APP_ENV=production
ENV APP_DEBUG=false
ENV APP_NAME=Laravel

ENV APP_URL=http://localhost
ENV APP_LOCALE=en
ENV APP_FALLBACK_LOCALE=en
ENV APP_FAKER_LOCALE=en_US
ENV APP_MAINTENANCE_DRIVER=file
ENV PHP_CLI_SERVER_WORKERS=4
ENV BCRYPT_ROUNDS=12
ENV LOG_CHANNEL=stack
ENV LOG_STACK=single
ENV LOG_DEPRECATIONS_CHANNEL=null
ENV LOG_LEVEL=info
ENV DB_CONNECTION=sqlite
ENV DB_HOST=127.0.0.1
ENV DB_PORT=3306
ENV DB_DATABASE=laravel
ENV DB_USERNAME=root
ENV DB_PASSWORD=

ENV SESSION_DRIVER=database
ENV SESSION_LIFETIME=120
ENV SESSION_ENCRYPT=false
ENV SESSION_PATH=/
ENV SESSION_DOMAIN=null

ENV BROADCAST_CONNECTION=log
ENV FILESYSTEM_DISK=local
ENV QUEUE_CONNECTION=database

ENV CACHE_STORE=database


ENV MEMCACHED_HOST=127.0.0.1

ENV REDIS_CLIENT=phpredis
ENV REDIS_HOST=127.0.0.1
ENV REDIS_PASSWORD=null
ENV REDIS_PORT=6379

ENV MAIL_MAILER=log
ENV MAIL_SCHEME=null
ENV MAIL_HOST=127.0.0.1
ENV MAIL_PORT=2525
ENV MAIL_USERNAME=null
ENV MAIL_PASSWORD=null
ENV MAIL_FROM_ADDRESS="hello@example.com"
ENV MAIL_FROM_NAME="${APP_NAME}"
ENV VITE_APP_NAME="${APP_NAME}"

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

ENTRYPOINT ["./entrypoint.sh"]
CMD ["supervisord", "-n", "-c", "/etc/supervisor/conf.d/app.conf"]
EXPOSE 8181
EXPOSE 8090
