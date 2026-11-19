#!/bin/sh
# Runs at container start-up via the serversideup/php entrypoint (/etc/entrypoint.d).
# Production installs without dev dependencies. docker-compose.override.yml sets
# COMPOSER_NO_DEV=0 for local development so dev dependencies (phpunit, etc.) are kept.
# A failure (e.g. no network) is logged but does not stop the container.
cd "${APP_BASE_DIR:-/var/www/html}" || exit 0

if [ -f composer.json ]; then
    export COMPOSER_NO_DEV="${COMPOSER_NO_DEV:-1}"
    if [ "$COMPOSER_NO_DEV" = "0" ]; then mode="with dev dependencies"; else mode="--no-dev"; fi
    echo "ℹ️ NOTICE (composer-install): Running composer install --optimize-autoloader ($mode)..."
    composer install --optimize-autoloader --no-interaction --no-progress \
        || echo "⚠️ WARNING (composer-install): composer install failed; continuing with existing vendor/" >&2
fi
