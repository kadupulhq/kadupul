# Kadupul production image.
#
# This is a rewrite, not a carry-over. Cacti's docker/Dockerfile is a
# development image: it says so, the application arrives through a bind mount,
# Apache runs as root, and the base tag floats. None of that belongs in a
# release artefact.
#
# One image, two roles. `web` serves the interface over FastCGI; `poller` runs
# the collection cycle. They share a filesystem and a database, so they must be
# the same build.

# --- dependencies -----------------------------------------------------------
FROM composer@sha256:d8f6343d3fae98107426bc49163ccad46ef85aabd4a27d80a74401fab4aba332 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
COPY src ./src
COPY tools/dependencies ./tools/dependencies
RUN composer install \
      --no-dev --no-interaction --no-progress --ignore-platform-req=ext-* \
      --prefer-dist --optimize-autoloader --classmap-authoritative

# Browser dependencies are built once; Node is not shipped in the runtime.
FROM node:26.8.2-bookworm-slim@sha256:cd9f682fa2885cd1056e830424764158570061c59736a1da836bc3d73df095ae AS assets
WORKDIR /app
COPY package.json package-lock.json ./
COPY tools/dependencies ./tools/dependencies
COPY include/js/jquery.tablesorter.pager.js ./include/js/jquery.tablesorter.pager.js
RUN npm ci --ignore-scripts --no-audit --no-fund && node tools/dependencies/build.mjs

# --- runtime ----------------------------------------------------------------
FROM php@sha256:075b11566518bfa979bb9f2fe2e5359148326d659b15a2f414c2c305a0479a4e AS runtime

ARG VERSION=dev
ARG TARGETARCH

LABEL org.opencontainers.image.title="Kadupul" \
      org.opencontainers.image.description="Network monitoring and graphing" \
      org.opencontainers.image.source="https://github.com/kadupulhq/kadupul" \
      org.opencontainers.image.licenses="GPL-3.0-or-later" \
      org.opencontainers.image.version="${VERSION}"

# rrdtool and net-snmp are the collection path, not optional extras. Everything
# else here is a build input for a PHP extension and is removed in the same
# layer so the headers do not ship.
# hadolint ignore=DL3008,SC2086
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        rrdtool snmp libsnmp40 \
        libfreetype6 libjpeg62-turbo libpng16-16 libgmp10 libldap-2.5-0 \
        libicu72 libxml2 libzip4 default-mysql-client; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get install -y --no-install-recommends \
        librrd-dev libsnmp-dev libfreetype6-dev libjpeg62-turbo-dev \
        libpng-dev libgmp-dev libldap2-dev libicu-dev libxml2-dev \
        libonig-dev libzip-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" \
        gd gmp intl ldap mbstring pdo pdo_mysql mysqli \
        snmp pcntl posix sockets xml zip opcache; \
    pecl install apcu && docker-php-ext-enable apcu; \
    apt-mark auto '.*' > /dev/null; \
    apt-mark manual $savedAptMark > /dev/null; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*

# A container-native cron that runs unprivileged. The system cron daemon wants
# root, which is the whole thing this image is avoiding.
ARG SUPERCRONIC_VERSION=v0.2.49
# hadolint ignore=DL4006
RUN set -eux; \
    case "${TARGETARCH}" in \
      amd64) sha=a53ae236602c7338aba3fbaff40bda6300eae3b9fedb8261eb06cfe3724430c1 ;; \
      arm64) sha=02aa0cb229ba09050cba6638059dadb9eedc2276632ea43d6a57a2f8c1629dd5 ;; \
      *) echo "unsupported architecture: ${TARGETARCH}" >&2; exit 1 ;; \
    esac; \
    curl -fsSL --proto '=https' --tlsv1.2 -o /usr/local/bin/supercronic \
      "https://github.com/aptible/supercronic/releases/download/${SUPERCRONIC_VERSION}/supercronic-linux-${TARGETARCH}"; \
    echo "${sha}  /usr/local/bin/supercronic" | sha256sum -c -; \
    chmod 0755 /usr/local/bin/supercronic

COPY docker/php.ini /usr/local/etc/php/conf.d/kadupul.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-kadupul.conf
COPY docker/crontab /etc/kadupul/crontab
COPY docker/entrypoint.sh /usr/local/bin/entrypoint

WORKDIR /var/www/html

# The application is baked in. An image whose code comes from a bind mount is
# not a release artefact.
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/include/vendor ./include/vendor
COPY --from=assets --chown=www-data:www-data /app/include/js ./include/js
COPY --from=assets --chown=www-data:www-data /app/include/fa ./include/fa
COPY --from=assets --chown=www-data:www-data /app/include/vendor/flag-icons ./include/vendor/flag-icons

# Writable state is exactly these three directories and nothing else. They are
# declared as volumes so an operator who forgets to mount them still keeps data
# across a restart.
RUN set -eux; \
    mkdir -p cache log rra var; \
    chown -R www-data:www-data cache log rra var; \
    chmod 0755 /usr/local/bin/entrypoint; \
    rm -rf docker
VOLUME ["/var/www/html/rra", "/var/www/html/log", "/var/www/html/cache"]

# Everything from here runs unprivileged. Numeric on purpose: Kubernetes
# runAsNonRoot cannot verify a username, so a name here fails an admission
# policy that a uid passes. 33:33 is www-data in this base.
USER 33:33

EXPOSE 9000

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD ["/usr/local/bin/entrypoint", "healthcheck"]

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["web"]
