FROM php:8.2-apache

# Extensions used by the app:
# - pdo_mysql/mysqli for Aiven MySQL
# - curl for WhatsApp notifications
# - mbstring for UTF-8 email subjects
# - opcache for production performance
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev; \
    docker-php-ext-install curl mbstring mysqli pdo pdo_mysql opcache; \
    rm -rf /var/lib/apt/lists/*

# Keep only the MPM compatible with mod_php.
RUN set -eux; \
    rm -f /etc/apache2/mods-enabled/mpm_*; \
    a2dismod -f mpm_event mpm_worker || true; \
    a2enmod mpm_prefork; \
    test "$(apache2ctl -M 2>/dev/null | grep -c 'mpm_')" = "1"; \
    apache2ctl -t

RUN printf "DirectoryIndex index.php index.html\n" > /etc/apache2/conf-enabled/directory-index.conf

COPY . /var/www/html/

# The admin panel writes uploaded images and config.xml.
RUN mkdir -p /var/www/html/assets/uploads && \
    chown -R www-data:www-data /var/www/html/assets /var/www/html/config.xml && \
    chmod -R 775 /var/www/html/assets && \
    chmod 664 /var/www/html/config.xml

EXPOSE 80

# Railway injects PORT at runtime. Apache must listen on that port.
CMD ["bash", "-lc", "set -e; export APACHE_PORT=${PORT:-80}; export APP_CONFIG_PATH=${APP_CONFIG_PATH:-/var/www/html/config.xml}; export UPLOAD_DIR=${UPLOAD_DIR:-/var/www/html/assets/uploads}; mkdir -p \"$UPLOAD_DIR\"; chown -R www-data:www-data \"$UPLOAD_DIR\"; if [ \"$APP_CONFIG_PATH\" != /var/www/html/config.xml ]; then mkdir -p \"$(dirname \"$APP_CONFIG_PATH\")\"; if [ ! -f \"$APP_CONFIG_PATH\" ] && [ -f /var/www/html/config.xml ]; then cp /var/www/html/config.xml \"$APP_CONFIG_PATH\"; fi; chown -R www-data:www-data \"$(dirname \"$APP_CONFIG_PATH\")\"; else chown www-data:www-data \"$APP_CONFIG_PATH\"; fi; printf 'Listen %s\\n' \"$APACHE_PORT\" > /etc/apache2/ports.conf; sed -ri \"s/<VirtualHost \\*:80>/<VirtualHost *:${APACHE_PORT}>/\" /etc/apache2/sites-available/000-default.conf; rm -f /etc/apache2/mods-enabled/mpm_*; a2dismod -f mpm_event mpm_worker >/dev/null 2>&1 || true; a2enmod mpm_prefork >/dev/null 2>&1; test \"$(apache2ctl -M 2>/dev/null | grep -c 'mpm_')\" = \"1\"; apache2ctl -D FOREGROUND"]
