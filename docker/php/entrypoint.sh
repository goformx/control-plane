#!/bin/sh
set -eu

# Waaseyaa binds this artifact to the installed framework and live schema.
# Produce it after the deployment migration command and before FPM accepts
# requests. The project tree stays root-owned. FPM retains its root supervisor
# for the image's stderr pipe and runs request workers as www-data.
# A failed preflight leaves the container unready.
if [ "${1:-}" = "php-fpm" ]; then
    if [ "$(id -u)" -ne 0 ]; then
        echo 'Production preflight requires the image entrypoint to start as root.' >&2
        exit 1
    fi
    umask 022
    if ! php /app/vendor/bin/waaseyaa field-access:preflight --write-artifact >/dev/null; then
        echo 'Field-access preflight failed; PHP-FPM was not started.' >&2
        exit 1
    fi
    exec "$@"
fi

exec su-exec www-data "$@"
