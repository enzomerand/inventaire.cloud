# syntax=docker/dockerfile:1
#
# Image of the inventaire.cloud web application: FrankenPHP (Caddy and PHP in one
# process) serving Laravel on port 8080, run by a non-root user.
#
# Build it from this folder (web/): `docker build -t inventaire-cloud .`, or let
# docker/compose.yaml do it. The image holds the code only: no .env, no installer
# state, no development dependency. Everything an instance writes (configuration,
# uploads, installer state, logs) lives in /app/storage, which is meant to be a volume.

############################################################
# base: PHP with every extension the application needs. The
# vendor and runtime stages start from it, so the extensions
# are compiled once.
############################################################
FROM dunglas/frankenphp:1-php8.5-trixie AS base

# opcache is part of PHP 8.5; mbstring, openssl, tokenizer, xml, ctype, json, fileinfo,
# curl and posix come with the base image. The check below fails the build if one is missing.
RUN install-php-extensions bcmath gd intl pcntl pdo_mysql pdo_pgsql zip \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-inventaire.ini"

RUN php -r '$missing = array_filter(["mbstring", "openssl", "tokenizer", "xml", "ctype", "json", "bcmath", "fileinfo", "intl", "zip", "curl", "pdo", "pdo_pgsql", "pdo_mysql", "gd", "pcntl", "posix", "Zend OPcache"], static fn (string $name): bool => ! extension_loaded($name)); if ($missing !== []) { fwrite(STDERR, "Missing PHP extensions: ".implode(", ", $missing)."\n"); exit(1); }'

WORKDIR /app

############################################################
# vendor: production dependencies only
############################################################
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/root/.cache/composer \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# The class map needs the application classes, hence the late autoloader.
COPY app ./app
COPY database ./database
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts

############################################################
# assets: the front end (Vite build into public/build)
############################################################
FROM node:22-slim AS assets

WORKDIR /build

COPY package.json package-lock.json .npmrc ./
RUN npm ci

COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

############################################################
# runtime
############################################################
FROM base AS runtime

ARG USER=inventaire
ARG UID=1000

# One dedicated user for every process of the container (web server, queue worker,
# scheduler, artisan): the files they share in the storage volume belong to it.
# FrankenPHP's own recipe for running without root: the binary gets no file
# capability (it only binds port 8080), and Caddy's config and data folders are the user's.
RUN useradd --uid "$UID" --create-home --shell /usr/sbin/nologin "$USER" \
    && setcap -r /usr/local/bin/frankenphp \
    && mkdir -p /data/caddy /config/caddy \
    && chown -R "$USER:$USER" /data/caddy /config/caddy

COPY --chmod=0755 docker/entrypoint.sh /usr/local/bin/inventaire-entrypoint

COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /build/public/build ./public/build
COPY --exclude=docker --exclude=package.json --exclude=package-lock.json --exclude=.npmrc --exclude=vite.config.js . .

# The code is read-only for the application's user; only storage/ and bootstrap/cache/ are
# writable. public/storage serves the public disk, which lives in the storage volume.
RUN chmod -R a+rX /app \
    && ln -s /app/storage/app/public /app/public/storage \
    && chown -R "$USER:$USER" /app/storage /app/bootstrap/cache

USER $USER

# Registers the packages' service providers (composer ran with --no-scripts).
RUN php artisan package:discover --ansi

# Defaults a self-hoster can override with real environment variables:
# - APP_ENVIRONMENT_FILE: where the installer keeps the generated configuration (APP_KEY,
#   settings entered in the wizard), on the storage volume so that it survives updates;
# - production mode, with logs on stderr (`docker compose logs`);
# - SERVER_NAME=:8080: plain HTTP on 8080, put a reverse proxy in front for HTTPS.
ENV APP_ENV=production \
    APP_DEBUG=false \
    APP_ENVIRONMENT_FILE=/app/storage/.env \
    LOG_CHANNEL=stderr \
    SERVER_NAME=:8080

EXPOSE 8080

# /up answers 200 before and after the installation. The start period covers the update
# or the automatic installation the entrypoint performs before the server starts.
HEALTHCHECK --interval=15s --timeout=5s --start-period=120s --retries=3 \
    CMD curl -fsS -o /dev/null http://127.0.0.1:8080/up || exit 1

LABEL org.opencontainers.image.title="inventaire.cloud" \
      org.opencontainers.image.source="https://github.com/enzomerand/inventaire.cloud" \
      org.opencontainers.image.licenses="AGPL-3.0-only"

ENTRYPOINT ["/usr/local/bin/inventaire-entrypoint"]
CMD ["app"]
