FROM php:8.3-apache

# Sneeze needs PDO SQLite, DOM/XML (bundled), mbstring, and optional GD/zip.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libsqlite3-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libzip-dev \
        rsync \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_sqlite gd zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Set the container's web root to /var/www/html (serves /)
WORKDIR /var/www/html

# Allow .htaccess (mod_rewrite) under the site root and /sneeze
RUN printf '%s\n' \
    '<Directory /var/www/html>' \
    '    Options Indexes FollowSymLinks' \
    '    AllowOverride All' \
    '    Require all granted' \
    '</Directory>' \
    > /etc/apache2/conf-available/sneeze.conf \
    && a2enconf sneeze

# Immutable app tree. Coolify mounts a volume over /var/www/html, which hides
# the copy below; the entrypoint refreshes /sneeze from this path and keeps data/.
COPY . /opt/sneeze-src/

# Public site is /var/www/html (/). Studio lives in the /sneeze subfolder.
COPY . /var/www/html/sneeze/

# Writable site root (published HTML) and studio data dirs
RUN mkdir -p \
        /var/www/html/uploads \
        /var/www/html/sneeze/data/uploads \
        /var/www/html/sneeze/data/backups \
        /var/www/html/sneeze/data/thumbs \
    && chown -R www-data:www-data /var/www/html

COPY docker/entrypoint.sh /usr/local/bin/sneeze-entrypoint
RUN chmod +x /usr/local/bin/sneeze-entrypoint

EXPOSE 80

ENTRYPOINT ["sneeze-entrypoint"]
CMD ["apache2-foreground"]
