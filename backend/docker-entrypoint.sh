#!/usr/bin/bash
set -e

. /app/docker-entrypoint-lib.sh
resolve_secrets
guard_prod_config

until php -r '
    $url = getenv("DATABASE_URL");
    if (!$url) { echo "DATABASE_URL not set\n"; exit(1); }
    $p = parse_url($url);
    $dsn = sprintf("pgsql:host=%s;port=%d;dbname=%s",
        $p["host"], $p["port"] ?? 5432, ltrim($p["path"] ?? "", "/"));
    try {
        new PDO($dsn, $p["user"] ?? "", $p["pass"] ?? "", [PDO::ATTR_TIMEOUT => 3]);
    } catch (PDOException $e) { exit(1); }
' > /dev/null 2>&1; do
    echo "Waiting for database..."
    sleep 2
done

# Dev bind-mounts the source over the image's classmap-authoritative autoloader; refresh it.
if [ "$APP_ENV" = "dev" ]; then
    composer dump-autoload --no-interaction 2>/dev/null || true
fi

php bin/console doctrine:migrations:migrate --no-interaction

# Root at boot guarantees the writable dirs exist (install -d) and repairs legacy volumes
# whose first-mount copy-up kept root ownership; migrations run as root, so the chown runs
# after them, and the top-level probe keeps steady-state boots from walking huge NAS bind mounts.
install -d -o www-data -g www-data \
    "$SECRETS_DIR" \
    /app/var/log /app/var/cache /app/var/uploads /app/var/tiles \
    /data /config
if find "$SECRETS_DIR" /app/var/log /app/var/cache /app/var/uploads /app/var/tiles /data /config \
    -maxdepth 1 -user root -print -quit | grep -q .; then
    chown -R www-data:www-data \
        "$SECRETS_DIR" \
        /app/var/log /app/var/cache /app/var/uploads /app/var/tiles \
        /data /config
fi

# The worker runs here because the Mercure publisher JWT is exported above and exists nowhere else.
# Serving processes run as www-data; setpriv execs, so PIDs and TERM handling are unchanged.
setpriv --reuid=www-data --regid=www-data --init-groups \
    /usr/local/bin/docker-php-entrypoint "$@" &
server_pid=$!

(
    consumer_pid=""
    trap 'kill -TERM "$consumer_pid" 2>/dev/null; exit 0' TERM INT
    while :; do
        setpriv --reuid=www-data --regid=www-data --init-groups \
            php bin/console messenger:consume scheduler_default --time-limit=3600 --memory-limit=128M &
        consumer_pid=$!
        wait "$consumer_pid" || true
        sleep 2
    done
) &
worker_pid=$!

shutdown() {
    trap - TERM INT
    kill -TERM "$server_pid" "$worker_pid" 2>/dev/null || true
    wait
}
trap shutdown TERM INT

wait -n
shutdown
