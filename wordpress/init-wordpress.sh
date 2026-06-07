#!/bin/sh
set -eu

uploads_root="/var/www/html/wp-content/uploads"
year_dir="$(date +%Y)"
month_dir="$(date +%m)"

mkdir -p "${uploads_root}/${year_dir}/${month_dir}"
chown -R www-data:www-data "${uploads_root}"
find "${uploads_root}" -type d -exec chmod 755 {} \;
find "${uploads_root}" -type f -exec chmod 644 {} \;

exec docker-entrypoint.sh apache2-foreground
