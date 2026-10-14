#!/bin/sh
# Entrypoint of the inventaire.cloud image.
#
# The first argument chooses what the container does; "app" is the default.
#
#   app        The web server. Before it starts, the instance is prepared according
#              to what `php artisan inventaire:install-status` reports:
#                installed    -> `inventaire:upgrade --optimize` applies the pending
#                                migrations and rebuilds the caches;
#                environment  -> not installed, but every setting comes from the
#                                environment: `inventaire:install --from-env` installs
#                                the instance by itself, then the caches are built;
#                wizard       -> not installed and not fully configured: the server
#                                starts as it is and the install wizard (/install)
#                                does the rest.
#              If the preparation fails the container stops with a non-zero status
#              instead of serving a half-updated instance. (A cache that cannot be
#              rebuilt is only a warning: the application works without it.)
#   worker     The queue worker. It waits until the instance is installed.
#   scheduler  The task scheduler. It waits until the instance is installed.
#   other      Any other command is run as it is, e.g.
#              `docker compose run --rm app php artisan tinker`.
#
# Whatever the role, the folders Laravel writes to are created first: a new, empty
# volume mounted on /app/storage hides the ones the image ships.
#
# Only the "app" role migrates, so that running several workers or schedulers never
# races a migration. All roles run as the same non-root user (see the Dockerfile).

set -eu

cd /app

# Messages go to stdout, next to the application's own output (`docker compose logs`).
log() {
    printf '[inventaire] %s\n' "$*"
}

# Recreates the storage skeleton on an empty volume.
prepare_storage() {
    if [ ! -w storage ]; then
        log "/app/storage is not writable by uid $(id -u): give that user the volume (chown -R $(id -u) on the mounted folder)."
        exit 1
    fi

    for directory in framework/cache/data framework/sessions framework/views logs app/public app/private installer; do
        if ! mkdir -p "storage/$directory"; then
            log "Cannot create /app/storage/$directory as uid $(id -u): a folder of the storage volume belongs to someone else (chown -R $(id -u) on the mounted folder)."
            exit 1
        fi
    done
}

# Prepares the instance, then becomes the web server.
run_app() {
    # A configuration cache left in this container by an earlier run must not hide edits of the
    # configuration file (a new database password, say): everything below reads it afresh.
    php artisan config:clear --quiet

    status=$(php artisan inventaire:install-status)

    case "$status" in
        installed)
            log "Installed instance: applying updates."
            php artisan inventaire:upgrade --optimize
            ;;
        environment)
            log "Not installed, and the whole configuration comes from the environment: installing."
            php artisan inventaire:install --from-env --no-interaction
            php artisan inventaire:upgrade --optimize
            ;;
        wizard)
            log "Not installed: open /install to run the install wizard (setup token: php artisan inventaire:install-token)."
            ;;
        *)
            log "Unexpected answer of inventaire:install-status: '$status'."
            exit 1
            ;;
    esac

    exec frankenphp run --config /etc/caddy/Caddyfile --adapter caddyfile
}

# Blocks until the instance is installed (by the app container: wizard or environment).
# A status check that keeps failing (its error is in the log) stops the container instead of
# waiting for ever in silence.
wait_until_installed() {
    # `sleep` in the background keeps the shell able to react to docker stop.
    trap 'log "Stopping."; exit 0' TERM INT

    failures=0

    while true; do
        if status=$(php artisan inventaire:install-status); then
            failures=0

            [ "$status" = "installed" ] && break

            log "Waiting for the instance to be installed..."
        else
            failures=$((failures + 1))
            log "The install status could not be read (attempt $failures of 3)."

            if [ "$failures" -ge 3 ]; then
                exit 1
            fi
        fi

        sleep 5 &
        wait $!
    done

    trap - TERM INT
}

prepare_storage

role="${1:-app}"

case "$role" in
    app)
        run_app
        ;;
    worker)
        wait_until_installed
        exec php artisan queue:work --tries=3 --max-time=3600
        ;;
    scheduler)
        wait_until_installed
        exec php artisan schedule:work
        ;;
    *)
        exec "$@"
        ;;
esac
