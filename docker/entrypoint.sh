#!/bin/sh
set -eu
cd /app
: "${APP_KEY:?Set APP_KEY securely before starting.}"
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage/framework storage/logs bootstrap/cache
php artisan config:cache
php artisan route:cache
php artisan view:cache
case "${PROCESS_ROLE:-web}" in
 web)
  : "${UPLOAD_STORAGE_PATH:=/data/uploads}"
  case "$UPLOAD_STORAGE_PATH" in /app/public* ) echo 'Upload storage must be outside public/' >&2; exit 1;; esac
  mkdir -p "$UPLOAD_STORAGE_PATH"
  chown www-data:www-data "$UPLOAD_STORAGE_PATH"
  upload_mb=$(( (${UPLOAD_MAX_KB:-10240} + 1023) / 1024 + 2 ))
  sed "s/__PORT__/${PORT:-8080}/g;s/__UPLOAD_MB__/$upload_mb/g" /etc/nginx/tasksure.conf.template > /tmp/tasksure-nginx.conf
  printf 'upload_max_filesize=%sM\npost_max_size=%sM\n' "$upload_mb" "$((upload_mb+2))" > /usr/local/etc/php/conf.d/upload-limits.ini
  php-fpm -D
  exec nginx -c /tmp/tasksure-nginx.conf -g 'daemon off;'
  ;;
 worker) exec php artisan queue:work database --sleep=3 --tries=3 --timeout=90 ;;
 scheduler) exec php artisan schedule:work ;;
 *) exec "$@" ;;
esac
