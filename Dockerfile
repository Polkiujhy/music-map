# syntax=docker/dockerfile:1.7@sha256:a57df69d0ea827fb7266491f2813635de6f17269be881f696fbfdf2d83dda33e

ARG PHP_IMAGE=php:8.4-fpm-alpine@sha256:49734670eccf414af884c2a0c2e558401e228615f8028f1c9fca30a0d4fb1bc2
ARG NODE_IMAGE=node:22-alpine@sha256:c610fcdfb1d5b4740dd70c284ed3cb16bb857e0f7166196e36a5501df7a3aa32
ARG COMPOSER_IMAGE=composer:2@sha256:d8f6343d3fae98107426bc49163ccad46ef85aabd4a27d80a74401fab4aba332
ARG NGINX_IMAGE=nginxinc/nginx-unprivileged:1.29-alpine@sha256:0c79d56aee561a1d81c63f00eee5fb5fe29279560cdc55e91425133104c7fbe6
ARG SOURCE_SHA

FROM ${COMPOSER_IMAGE} AS composer-bin

FROM composer-bin AS frontend-vendor
WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install \
        --classmap-authoritative \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --prefer-dist

FROM ${NODE_IMAGE} AS frontend
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts --no-audit
COPY public ./public
COPY resources ./resources
COPY vite.config.js ./
COPY --from=frontend-vendor /build/vendor/livewire/flux ./vendor/livewire/flux
RUN npm run build

FROM ${PHP_IMAGE} AS php-base
ARG SOURCE_SHA
SHELL ["/bin/ash", "-eo", "pipefail", "-c"]

RUN printf '%s\n' "$SOURCE_SHA" | grep -Eq '^[0-9a-f]{40}$'
LABEL org.opencontainers.image.revision=$SOURCE_SHA

RUN apk add --no-cache libpq=18.6-r0 unzip=6.0-r16 \
    && apk add --no-cache --virtual .php-build-deps \
        autoconf=2.73-r0 \
        dpkg-dev=1.23.7-r0 \
        dpkg=1.23.7-r0 \
        file=5.47-r2 \
        g++=15.2.0-r5 \
        gcc=15.2.0-r5 \
        musl-dev=1.2.6-r2 \
        make=4.4.1-r4 \
        pkgconf=2.5.1-r0 \
        re2c=4.5.1-r0 \
        postgresql18-dev=18.6-r0 \
    && docker-php-ext-install -j"$(getconf _NPROCESSORS_ONLN)" opcache pcntl pdo_pgsql \
    && apk del .php-build-deps

RUN test "$(id -u www-data)" = 82 \
    && test "$(id -g www-data)" = 82

WORKDIR /var/www/html

COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN composer install \
        --classmap-authoritative \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --prefer-dist

COPY app ./app
COPY artisan ./artisan
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY public ./public
COPY resources ./resources
COPY routes ./routes
COPY storage ./storage
COPY --from=frontend /build/public/build ./public/build
COPY docker/php/production.ini /usr/local/etc/php/conf.d/zz-production.ini
COPY docker/entrypoints/fpm.sh /usr/local/bin/music-map-fpm
COPY docker/entrypoints/queue.sh /usr/local/bin/music-map-queue
COPY docker/entrypoints/scheduler.sh /usr/local/bin/music-map-scheduler
COPY docker/entrypoints/wait-for-postgres.php /usr/local/libexec/music-map-wait-for-postgres
COPY docker/entrypoints/bootstrap-runtime.sh /usr/local/libexec/music-map-bootstrap-runtime

RUN rm -f bootstrap/cache/*.php \
    && composer dump-autoload --classmap-authoritative --no-dev --no-interaction --no-scripts \
    && php artisan package:discover --ansi \
    && install -d -m 0555 /usr/local/share/music-map \
    && chown www-data:www-data bootstrap/cache/packages.php bootstrap/cache/services.php \
    && tar -C bootstrap/cache -cf /usr/local/share/music-map/package-manifest.tar packages.php services.php \
    && rm -f bootstrap/cache/*.php \
    && rm -f /usr/local/bin/composer \
    && chmod 0755 /usr/local/bin/music-map-fpm /usr/local/bin/music-map-queue /usr/local/bin/music-map-scheduler \
        /usr/local/libexec/music-map-wait-for-postgres /usr/local/libexec/music-map-bootstrap-runtime \
    && find storage bootstrap/cache -type d -exec chmod 0770 {} + \
    && find storage bootstrap/cache -type f -exec chmod 0660 {} + \
    && chown -R www-data:www-data storage bootstrap/cache

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

USER 82:82
STOPSIGNAL SIGQUIT

FROM php-base AS fpm
EXPOSE 9000
ENTRYPOINT ["/usr/local/bin/music-map-fpm"]
CMD ["php-fpm", "--nodaemonize"]
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD ["php", "-r", "exit(is_resource(@fsockopen('127.0.0.1', 9000)) ? 0 : 1);"]

FROM php-base AS queue
STOPSIGNAL SIGTERM
ENTRYPOINT ["/usr/local/bin/music-map-queue"]
CMD ["php", "artisan", "queue:work", "--sleep=3", "--tries=3", "--timeout=90", "--max-time=3600"]
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD ["php", "-r", "exit(str_contains((string) @file_get_contents('/proc/1/cmdline'), 'queue:work') ? 0 : 1);"]

FROM php-base AS scheduler
STOPSIGNAL SIGTERM
ENTRYPOINT ["/usr/local/bin/music-map-scheduler"]
CMD ["php", "artisan", "schedule:work"]
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD ["php", "-r", "exit(str_contains((string) @file_get_contents('/proc/1/cmdline'), 'schedule:work') ? 0 : 1);"]

FROM ${NGINX_IMAGE} AS nginx
ARG SOURCE_SHA
USER root
SHELL ["/bin/ash", "-eo", "pipefail", "-c"]
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY docker/entrypoints/nginx.sh /usr/local/bin/music-map-nginx
COPY --from=php-base --chown=101:101 /var/www/html/public /usr/share/nginx/html
RUN chmod 0755 /usr/local/bin/music-map-nginx \
    && printf '%s\n' "$SOURCE_SHA" | grep -Eq '^[0-9a-f]{40}$' \
    && test "$(id -u nginx)" = 101 \
    && test "$(id -g nginx)" = 101 \
    && find /usr/share/nginx/html -type d -exec chmod 0555 {} + \
    && find /usr/share/nginx/html -type f -exec chmod 0444 {} +
USER 101:101
LABEL org.opencontainers.image.revision=$SOURCE_SHA
EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/music-map-nginx"]
CMD ["nginx", "-g", "daemon off;"]
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD ["wget", "--quiet", "--spider", "http://127.0.0.1:8080/healthz"]
