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
FROM composer@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
COPY src ./src
COPY tools/dependencies ./tools/dependencies
RUN composer install \
      --no-dev --no-interaction --no-progress --ignore-platform-req=ext-* \
      --prefer-dist --optimize-autoloader --classmap-authoritative

# Browser dependencies are built once; Node is not shipped in the runtime.
FROM node:26.10.0-bookworm-slim@sha256:662933cf47f013bc8e4beb31a6116448427a82057ba7c42c97e4c5ba766504c2 AS assets
WORKDIR /app
COPY package.json package-lock.json ./
COPY tools/dependencies ./tools/dependencies
COPY include/js/jquery.tablesorter.pager.source.js ./include/js/jquery.tablesorter.pager.source.js
COPY include/themes/midwinter ./include/themes/midwinter
RUN npm ci --ignore-scripts --no-audit --no-fund && node tools/dependencies/build.mjs

# --- runtime ----------------------------------------------------------------
FROM php:8.4-fpm-bookworm@sha256:6bfef8e416977aa41f48e3e42a40c1e08050d24e4a938c6edb421400bff24601 AS runtime

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
        rrdtool snmp libsnmp40 procps \
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
    pecl install apcu-5.1.28 && docker-php-ext-enable apcu; \
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
COPY --from=assets --chown=www-data:www-data /app/include/themes/midwinter ./include/themes/midwinter
COPY --from=assets --chown=www-data:www-data /app/include/vendor/flag-icons ./include/vendor/flag-icons

# asset-map:compile writes digested copies of the theme, script and font files
# legacy pages load. It leaves a root-owned kernel cache behind, which is
# removed so the runtime rebuilds it as www-data.
#
# Writable state is exactly these three directories and nothing else. They are
# declared as volumes so an operator who forgets to mount them still keeps data
# across a restart.
RUN set -eux; \
    php bin/console asset-map:compile --no-debug; \
    rm -rf var/cache; \
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
