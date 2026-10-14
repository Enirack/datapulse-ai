#!/bin/sh
set -e

if [ ! -f config/jwt/private.pem ] || [ ! -f config/jwt/public.pem ]; then
    echo "Generating JWT keypair (not present in this container/volume)..."
    mkdir -p config/jwt
    php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction
fi

mkdir -p var/storage/datasets var/storage/tmp

# Auto-run database migrations on container startup if enabled
if [ "${AUTO_MIGRATE:-1}" = "1" ]; then
    echo "Running database migrations..."
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration || true
fi

exec "$@"
