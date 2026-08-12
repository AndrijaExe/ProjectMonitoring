#!/bin/sh
set -eu

: "${PORT:=10000}"
: "${APP_ENV:=prod}"
: "${ADMIN_TOKEN:=}"
export PORT

fail() {
    echo "startup: $1" >&2
    exit 1
}

# The image ships a .env with development defaults. Booting production with those would
# publish an admin console whose token is in the repository, so refuse instead.
if [ "$APP_ENV" = 'prod' ]; then
    case "$ADMIN_TOKEN" in
        '')
            fail 'ADMIN_TOKEN is not set. Add it to the service environment before deploying.'
            ;;
        change-me-admin-token)
            fail 'ADMIN_TOKEN still holds the example value. Generate a private one.'
            ;;
    esac

    if [ "${#ADMIN_TOKEN}" -lt 16 ]; then
        fail 'ADMIN_TOKEN must be at least 16 characters.'
    fi

    if [ -z "${CORS_ALLOWED_ORIGINS:-}" ]; then
        echo 'startup: warning, CORS_ALLOWED_ORIGINS is empty, so browsers will block the console.' >&2
    fi
fi

# Render assigns the port at runtime, so Apache learns it here rather than at build time.
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf

# A managed database can still be accepting connections a moment after the container starts.
attempt=1
until php bin/console app:db-setup --no-interaction --quiet; do
    if [ "$attempt" -ge 10 ]; then
        fail 'database is unreachable after 10 attempts, giving up.'
    fi

    echo "startup: database not ready yet, retrying ($attempt/10)" >&2
    attempt=$((attempt + 1))
    sleep 3
done

# Neutral wording: the cron job runs through this same entrypoint.
echo 'startup: schema ready'

exec "$@"
