#!/bin/bash
set -e

# Create moodledata if it does not exist
mkdir -p /var/www/moodledata
chown -R www-data:www-data /var/www/moodledata
chmod -R 0777 /var/www/moodledata

# Auto-create config.php from config.example.php if missing
if [ ! -f /var/www/html/config.php ] && [ -f /var/www/html/config.example.php ]; then
    echo "[Docker Entrypoint] Auto-generating config.php from config.example.php..."
    cp /var/www/html/config.example.php /var/www/html/config.php
fi

exec "$@"
