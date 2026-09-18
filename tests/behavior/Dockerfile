# Cacti runtime image for the e2e harness. The repo is baked into the image.

# The tag is mutable: a base refresh can change diagnostics or defaults with no
# source diff. The manifest records the resolved digest of this image and of the
# database image, so a refresh shows up there. A digest pin here would fix one
# PHP version and break the version matrix.
ARG PHP_VERSION=8.2
FROM php:${PHP_VERSION}-apache

ARG POLLER_COVERAGE=0
RUN if [ "$POLLER_COVERAGE" = 1 ]; then \
        pecl install pcov-1.0.12 && docker-php-ext-enable pcov; \
    fi

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
        rrdtool \
        snmp \
        snmpd \
        libsnmp-dev \
        default-mysql-client \
        openssl \
        ca-certificates \
        curl \
        git \
        unzip \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libldap2-dev \
        libgmp-dev \
        libicu-dev \
        libonig-dev \
        libxml2-dev \
        libzip-dev \
        zlib1g-dev \
 && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" \
        gd \
        mysqli \
        pcntl \
        pdo_mysql \
        snmp \
        sockets \
        gmp \
        ldap \
        intl \
        mbstring

RUN a2enmod rewrite ssl headers

RUN { \
        echo 'date.timezone = UTC'; \
        echo 'memory_limit = 512M'; \
        echo 'max_execution_time = 60'; \
        echo 'post_max_size = 32M'; \
        echo 'upload_max_filesize = 32M'; \
        # poller.php forks worker processes, and a -d flag on the parent does
        # not reach them, so diagnostics raised in a child went unrecorded while
        # the capture claimed to characterize PHP diagnostics. Setting it here
        # covers every interpreter in the container, children included.
        echo 'auto_prepend_file = /harness/errors.php'; \
    } > /usr/local/etc/php/conf.d/cacti.ini

# cli/install_cacti.php calls parse_ini_file(php_ini_loaded_file()); the official
# php image ships php.ini-{production,development} but does not symlink either
# into place, so the CLI loads no main ini and the call gets an empty path.
RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini

RUN mkdir -p /var/cacti-state \
 && chown -R www-data:www-data /var/cacti-state

# Bake the application into the image so the harness owns the file system.
# The bind-mount approach used previously meant the host's
# include/config.php (whatever the developer had) clobbered our
# container-friendly config, killing every protected request.
COPY --chown=www-data:www-data . /var/www/html
RUN rm -f /var/www/html/include/config.php

# Inline entrypoint. When ROLE=poller, generate a self-signed cert and switch Apache
# to HTTPS on 443. Otherwise serve plain HTTP on 80.
RUN cat > /usr/local/bin/cacti-entrypoint.sh <<'EOS' && chmod +x /usr/local/bin/cacti-entrypoint.sh
#!/usr/bin/env bash
set -euo pipefail

ROLE="${ROLE:-master}"

if [ "$ROLE" = "poller" ]; then
    if [ ! -f /etc/ssl/private/poller.crt ]; then
        mkdir -p /etc/ssl/private
        openssl req -x509 -nodes -newkey rsa:2048 -days 30 \
            -subj "/CN=cacti-poller" \
            -addext "subjectAltName=DNS:cacti-poller,DNS:localhost,IP:127.0.0.1" \
            -keyout /etc/ssl/private/poller.key \
            -out /etc/ssl/private/poller.crt
        chmod 600 /etc/ssl/private/poller.key
    fi
    cat > /etc/apache2/sites-available/cacti-ssl.conf <<'CONF'
<VirtualHost *:443>
    DocumentRoot /var/www/html
    SSLEngine on
    SSLCertificateFile /etc/ssl/private/poller.crt
    SSLCertificateKeyFile /etc/ssl/private/poller.key
    <Directory /var/www/html>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
CONF
    a2dissite 000-default >/dev/null 2>&1 || true
    a2ensite cacti-ssl >/dev/null
    sed -i 's/^Listen 80$/# Listen 80 (disabled in poller role)/' /etc/apache2/ports.conf
fi

# Make sure include/config.php exists with the right DB pointer. We don't overwrite
# an existing one (setup.sh may have customised it).
if [ ! -f /var/www/html/include/config.php ] && [ -f /var/www/html/include/config.php.dist ]; then
    cp /var/www/html/include/config.php.dist /var/www/html/include/config.php
    sed -i \
        -e "s/\\\$database_hostname *=.*/\$database_hostname = '${DB_HOST:-cacti-db}';/" \
        -e "s/\\\$database_default *=.*/\$database_default  = '${DB_NAME:-cacti}';/" \
        -e "s/\\\$database_username *=.*/\$database_username = '${DB_USER:-cactiuser}';/" \
        -e "s/\\\$database_password *=.*/\$database_password = '${DB_PASS:-cactiuser}';/" \
        -e "s|\\\$url_path *=.*|\$url_path = '/';|" \
        /var/www/html/include/config.php
    chown www-data:www-data /var/www/html/include/config.php
fi

exec "$@"
EOS

EXPOSE 80 443

ENTRYPOINT ["/usr/local/bin/cacti-entrypoint.sh"]
CMD ["apache2-foreground"]

# .dockerignore keeps cache/, log/ and rra/ out of the build context, so the
# cache subdirectories the installer checks for writability arrive empty. Recreate
# them here rather than relaxing the installer's check.
# Record every rrdtool invocation. Graph and update behaviour is a contract
# about the arguments Cacti builds, not about the bytes rrdtool returns, so the
# harness asserts argv. RRD_FAIL lets a scenario make the tool fail on demand.
RUN mv /usr/bin/rrdtool /usr/bin/rrdtool.real \
 && cat > /usr/bin/rrdtool <<'SHIM' && chmod +x /usr/bin/rrdtool
#!/bin/sh
# Cacti drives rrdtool in pipe mode ("rrdtool -"), so the real update and graph
# commands arrive on stdin, not in argv. Record both or the log says only "-".
if [ -n "${RRD_FAIL:-}" ] || [ -f /artifacts/rrd-fail ]; then
    # Only the first failing process crashes, closing stdin before Cacti's
    # first write so the broken pipe is always seen on that write. Later
    # processes read their input before failing; exiting early would race
    # each write against process exit and vary the crash-retry count.
    if (set -C; : > /artifacts/rrd-fail-crashed) 2>/dev/null; then
        exec 0<&-
        crashed=1
    fi
    printf 'ARGV %s\n' "$*" >> /artifacts/rrd-argv.log 2>/dev/null || true
    echo "ERROR: injected rrdtool failure" >&2
    [ -n "${crashed:-}" ] || cat > /dev/null
    exit 1
fi
printf 'ARGV %s\n' "$*" >> /artifacts/rrd-argv.log 2>/dev/null || true
if [ "$1" = "-" ]; then
    tee -a /artifacts/rrd-stdin.log | /usr/bin/rrdtool.real "$@"
    exit $?
fi
exec /usr/bin/rrdtool.real "$@"
SHIM

RUN mkdir -p /var/www/html/log /var/www/html/rra /artifacts \
        /var/www/html/cache/boost \
        /var/www/html/cache/mibcache \
        /var/www/html/cache/realtime \
        /var/www/html/cache/spikekill \
 && chown -R www-data:www-data /var/www/html/log /var/www/html/rra /var/www/html/cache /artifacts
