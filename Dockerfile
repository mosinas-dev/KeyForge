# syntax=docker/dockerfile:1.7
# Multi-stage build for KeyForge.
# Stages:  base -> vendor / vendor-dev -> test (gate) -> runtime
# The runtime stage pulls a marker from the test stage, so the default
# `docker build` (target=runtime) FAILS if the unit-test gate fails.

# ---- base: php-fpm + extensions, shared by every stage ----
# Extensions via mlocati/docker-php-extension-installer: prebuilt where available
# (fast + deterministic), auto-manages system libs, and avoids the parallel
# `docker-php-ext-install -j` race ("cp: can't stat 'modules/*'"). Supports PHP 8.5.
FROM php:8.5-fpm-alpine AS base
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions \
 && install-php-extensions pdo_pgsql pgsql intl mbstring zip opcache
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/php.ini     /usr/local/etc/php/conf.d/keyforge.ini
# entrypoint in base so BOTH the test stage (compose `keyforge-test` waits for DB
# + migrates the test DB) and runtime can exec it. 0755: readable by non-root user.
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod 0755 /usr/local/bin/entrypoint.sh
WORKDIR /var/www/keyforge

# ---- vendor: production deps only (no dev) ----
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader \
 || composer install --no-dev --no-scripts --no-interaction --prefer-dist

# ---- vendor-dev: full deps incl. dev tooling (for the test stage) ----
FROM composer:2 AS vendor-dev
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-scripts --no-interaction --prefer-dist \
 || composer install --no-scripts --no-interaction --prefer-dist

# ---- test: build-time gate. Runs the DB-less unit suite. ----
# Integration/e2e (need Postgres) run in CI and via `make test` — see compose `test` profile.
FROM base AS test
COPY . .
COPY --from=vendor-dev /app/vendor ./vendor
# `build` generates actor classes; the gate runs only the DB-less Unit suite
# (--skip Integration) since this build stage has no Postgres. The Integration
# suite runs in CI and `make test` against a real Postgres.
RUN vendor/bin/codecept build \
 && vendor/bin/codecept run --skip Integration --no-colors \
 && mkdir -p /artifacts && date > /artifacts/unit-tests-passed

# ---- runtime: slim production image ----
FROM base AS runtime
COPY . .
COPY --from=vendor /app/vendor ./vendor
# Force the unit-test gate into the default build graph:
# runtime cannot build unless the test stage succeeded.
COPY --from=test /artifacts/unit-tests-passed /var/www/keyforge/.unit-tests-passed
# Slim the image: tests and CI config are not needed at runtime.
RUN rm -rf tests .github \
 && addgroup -g 1000 keyforge && adduser -u 1000 -G keyforge -S keyforge \
 && mkdir -p backend/runtime backend/web/assets \
             frontend/runtime frontend/web/assets console/runtime \
 && chown -R keyforge:keyforge /var/www/keyforge
USER keyforge
EXPOSE 9000
ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
