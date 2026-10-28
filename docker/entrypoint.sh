#!/bin/sh
# Runs on every container start: wait for MySQL, apply new migrations, cache config, then start Apache.
set -e
cd /var/www/html
if [ -n "$DB_HOST" ]; then
  echo "Waiting for MySQL at $DB_HOST..."
  for i in $(seq 1 30); do
    php -r "try { new PDO('mysql:host=' . getenv('DB_HOST') . ';port=' . (getenv('DB_PORT') ?: 3306), getenv('DB_USERNAME'), getenv('DB_PASSWORD')); exit(0); } catch (Exception \$e) { exit(1); }" && break
    sleep 2
  done
fi
chown -R www-data:www-data public/storage storage bootstrap/cache
php artisan migrate --force
if [ "$APP_ENV" = "production" ]; then
  php artisan config:cache
  php artisan route:cache
fi
exec "$@"
