#!/bin/sh
set -eu

html=/var/www/html
app="${html}/sneeze"
src=/opt/sneeze-src

# A Coolify volume on the document root hides the image's /sneeze copy.
# Refresh the app from the image and leave sneeze/data (and published HTML) in place.
# Local compose bind-mounts the repo and does not set SNEEZE_SYNC_ON_START, so this is skipped.
if [ "${SNEEZE_SYNC_ON_START:-}" = "1" ] || [ ! -f "${app}/index.php" ]; then
  mkdir -p "${app}"
  if [ "${SNEEZE_SYNC_ON_START:-}" = "1" ]; then
    rsync -a --delete --exclude data "${src}/" "${app}/"
  else
    rsync -a "${src}/" "${app}/"
  fi
fi

mkdir -p \
  "${html}/uploads" \
  "${app}/data/uploads" \
  "${app}/data/backups" \
  "${app}/data/thumbs"

if [ "${SNEEZE_SYNC_ON_START:-}" = "1" ]; then
  chown -R www-data:www-data "${html}" 2>/dev/null || true
else
  chown -R www-data:www-data "${html}/uploads" "${app}/data" 2>/dev/null || true
fi

exec "$@"
