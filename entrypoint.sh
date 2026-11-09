#!/bin/sh
# entrypoint.sh
set -e

# Generate a unique APP_KEY per installation and keep it in storage/,
# so it survives container rebuilds when storage/ is mounted as a volume.
if [ -z "$APP_KEY" ]; then
    KEY_FILE=/app/storage/.app_key
    if [ ! -s "$KEY_FILE" ]; then
        php -r 'echo "base64:".base64_encode(random_bytes(32));' > "$KEY_FILE"
        chmod 600 "$KEY_FILE"
    fi
    APP_KEY="$(cat "$KEY_FILE")"
    export APP_KEY
fi

php artisan migrate --force

exec "$@"
