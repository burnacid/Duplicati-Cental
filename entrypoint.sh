#!/bin/sh
# entrypoint.sh

npm run build
php artisan migrate --force

exec "$@"