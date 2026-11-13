#!/bin/sh
set -e
cd /app

if [ ! -f artisan ]; then
  echo ">> First run: creating the Laravel skeleton (this takes a few minutes)..."
  # Clean leftovers from a previous failed attempt (composer needs an empty folder).
  find /app -mindepth 1 -delete
  composer create-project laravel/laravel . --no-interaction --prefer-dist
  # Laravel Sanctum provides the API tokens used for login.
  composer require laravel/sanctum --no-interaction
  php artisan vendor:publish --provider="Laravel\\Sanctum\\SanctumServiceProvider" --force
fi

# Copy our application code over the skeleton (also updates it on later starts).
cp -a /overlay/. /app/

# "artisan serve" ignores the container's environment variables and reads only the .env file,
# so we write the settings from docker-compose into .env on every start.
set_env() {
  grep -v -E "^#?[[:space:]]*$1=" .env > .env.tmp || true
  printf '%s=%s\n' "$1" "$2" >> .env.tmp
  mv .env.tmp .env
}
for name in APP_ENV APP_DEBUG DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD \
            MAIL_MAILER MAIL_HOST MAIL_PORT MAIL_FROM_ADDRESS \
            CONTENT_SERVICE_URL CONTENT_SERVICE_KEY MIDTRANS_SERVER_KEY; do
  value="$(printenv "$name" || true)"
  if [ -n "$value" ]; then
    set_env "$name" "$value"
  fi
done

echo ">> Running migrations and seeding demo data..."
php artisan migrate --force
php artisan db:seed --force

echo ">> API ready on http://localhost:8000"
exec php artisan serve --host=0.0.0.0 --port=8000 --no-reload