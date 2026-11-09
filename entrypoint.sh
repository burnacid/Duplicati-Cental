#!/bin/sh
# entrypoint.sh: prepare storage, configuration and database, then start supervisord.
set -e

cd /app

# A bind-mounted or empty storage volume needs Laravel's directory structure.
mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
         storage/framework/sessions storage/framework/views storage/logs \
         "$XDG_DATA_HOME" "$XDG_CONFIG_HOME"

# Generate a unique APP_KEY per installation and keep it in storage/,
# so it survives container rebuilds when storage/ is a volume.
if [ -z "$APP_KEY" ]; then
    KEY_FILE=/app/storage/.app_key
    if [ ! -s "$KEY_FILE" ]; then
        php -r 'echo "base64:".base64_encode(random_bytes(32));' > "$KEY_FILE"
        chmod 600 "$KEY_FILE"
    fi
    APP_KEY="$(cat "$KEY_FILE")"
    export APP_KEY
fi

# SQLite: older images stored the database at /app/laravel (outside the volume). Move it once.
if [ "$DB_CONNECTION" = "sqlite" ]; then
    if [ -s /app/laravel ] && [ ! -e "$DB_DATABASE" ]; then
        echo "Moving SQLite database from /app/laravel to $DB_DATABASE"
        mv /app/laravel "$DB_DATABASE"
    fi
    [ -e "$DB_DATABASE" ] || touch "$DB_DATABASE"
fi

# Cache config, routes, views and events for the environment this container runs with.
php artisan optimize --no-interaction

php artisan migrate --force --no-interaction

exec "$@"
