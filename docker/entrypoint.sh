#!/bin/sh
set -eu

# Keep working when the app is bind-mounted over /var/www/html/sneeze for rapid testing.
mkdir -p \
  /var/www/html/uploads \
  /var/www/html/sneeze/data/uploads \
  /var/www/html/sneeze/data/backups \
  /var/www/html/sneeze/data/thumbs

chown -R www-data:www-data /var/www/html/uploads /var/www/html/sneeze/data 2>/dev/null || true

exec "$@"
