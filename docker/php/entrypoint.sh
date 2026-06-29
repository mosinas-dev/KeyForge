#!/bin/sh
set -e

DB_HOST="${KEYFORGE_DB_HOST:-keyforge-postgres}"
DB_PORT="${KEYFORGE_DB_PORT:-5432}"

# Wait for PostgreSQL to accept connections before the app starts.
echo "[entrypoint] waiting for postgres at ${DB_HOST}:${DB_PORT} ..."
until php -r '$h=getenv("KEYFORGE_DB_HOST")?:"keyforge-postgres"; $p=(int)(getenv("KEYFORGE_DB_PORT")?:5432); exit(@fsockopen($h,$p)?0:1);'; do
  sleep 1
done
echo "[entrypoint] postgres is up."

# Migrations (+ seed config) auto-apply ONLY in disposable envs (local compose / CI)
# where RUN_MIGRATIONS=true. Default stays false so the published image never
# migrates a shared/prod DB unattended (project hard rule).
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  echo "[entrypoint] RUN_MIGRATIONS=true -> applying migrations + seed"
  php yii migrate --interactive=0
fi

exec "$@"
