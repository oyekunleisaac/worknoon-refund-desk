#!/bin/sh
set -e

# An app key is required by the framework; generate an ephemeral one when
# none is supplied (no persistent data is encrypted with it).
if [ -z "$APP_KEY" ]; then
  export APP_KEY="$(php artisan key:generate --show --no-ansi)"
fi

echo "Waiting for database at ${DB_HOST}:${DB_PORT}..."
for i in $(seq 1 30); do
  if php -r 'try { new PDO("pgsql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); exit(0); } catch (Throwable $e) { exit(1); }'; then
    break
  fi
  sleep 1
done

php artisan config:cache
php artisan route:cache
php artisan migrate --force
php artisan demo:seed-if-empty

if [ -n "$ANTHROPIC_API_KEY" ] || [ -n "$OPENAI_API_KEY" ]; then
  echo "AI mode: LLM enabled (provider: ${LLM_PROVIDER:-auto})"
else
  echo "AI mode: keyword fallback (set OPENAI_API_KEY or ANTHROPIC_API_KEY to enable)"
fi

exec "$@"
