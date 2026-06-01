#!/bin/sh
set -eu

mkdir -p /app/var /app/var/cache /app/var/log /app/cache /app/dumps /app/symbols/public
chown -R www-data:www-data /app/var /app/cache /app/dumps /app/symbols

if [ "${APP_ENV:-prod}" = "prod" ]; then
    su -s /bin/sh www-data -c 'php /app/bin/console cache:warmup --env=prod --no-debug'
fi

if [ "${AUTO_MIGRATE:-0}" = "1" ] || [ "${APP_WAIT_FOR_DB:-0}" = "1" ]; then
    until php -r '$url = parse_url(getenv("DATABASE_URL") ?: ""); $host = $url["host"] ?? "db"; $port = $url["port"] ?? 3306; $socket = @fsockopen($host, (int) $port, $errno, $errstr, 2); if (!$socket) { exit(1); } fclose($socket);'; do
        echo "Waiting for database..."
        sleep 2
    done
fi

if [ "${AUTO_MIGRATE:-0}" = "1" ]; then
    migration_attempt=1
    until su -s /bin/sh www-data -c "php /app/bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=${APP_ENV:-prod}"; do
        if [ "$migration_attempt" -ge 10 ]; then
            echo "Database migrations failed after ${migration_attempt} attempts."
            exit 1
        fi

        echo "Database migrations failed, retrying (${migration_attempt}/10)..."
        migration_attempt=$((migration_attempt + 1))
        sleep 3
    done
fi

exec "$@"
