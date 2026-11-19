#!/bin/sh
# Runs at container start-up via the serversideup/php entrypoint (/etc/entrypoint.d).
# Installs production dependencies only; a failure (e.g. no network) is logged
# but does not stop the container, since vendor/ is committed to the repo.
cd "${APP_BASE_DIR:-/var/www/html}" || exit 0

if [ -f composer.json ]; then
    echo "ℹ️ NOTICE (composer-install): Running composer install --no-dev --optimize-autoloader..."
    composer install --no-dev --optimize-autoloader --no-interaction --no-progress \
        || echo "⚠️ WARNING (composer-install): composer install failed; continuing with existing vendor/" >&2
fi
