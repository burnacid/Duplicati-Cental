FROM php:8.4
RUN apt-get update -y && apt-get install -y openssl zip unzip git npm
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
#RUN docker-php-ext-install pdo mbstring
WORKDIR /app
COPY /app /app
COPY entrypoint.sh .
RUN ls
RUN ls /app
RUN composer install
RUN npm install
RUN npm run build

ENV APP_ENV=production
ENV APP_DEBUG=false
ENV APP_NAME=Laravel
ENV APP_KEY="base64:94rFONlXiMHhnB9Ht94n5Wyo2f9eT5MhOxvW9qzhBUI="
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
ENV LOG_LEVEL=debug
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


ENTRYPOINT ["./entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8181"]
EXPOSE 8181
