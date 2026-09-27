FROM php:8.4-cli-bookworm

ARG RRDPROXY_REF=54aad579803f2b9cf1e9dd979881247656853230

RUN apt-get update \
 && apt-get install -y --no-install-recommends ca-certificates git gpg procps rrdtool libgmp-dev libldap2-dev unzip \
 && docker-php-ext-install -j"$(nproc)" gmp ldap pcntl sockets \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN git clone https://github.com/Cacti/rrdproxy.git /opt/rrdproxy \
 && git -C /opt/rrdproxy checkout "${RRDPROXY_REF}" \
 && composer install --working-dir=/opt/rrdproxy --no-dev --prefer-dist --no-interaction --no-progress \
 && mkdir -p /opt/rrdproxy/rra /opt/rrdproxy/archive /opt/rrdproxy/run \
 && chown -R www-data:www-data /opt/rrdproxy

COPY rrd-proxy/start-proxy.php /usr/local/bin/start-proxy.php
WORKDIR /opt/rrdproxy
EXPOSE 40301
CMD ["php", "/usr/local/bin/start-proxy.php"]
