# syntax=docker/dockerfile:1.7

ARG PHP_IMAGE=php:8.4-fpm-alpine3.23
ARG COMPOSER_IMAGE=composer:2.10.2
ARG NGINX_IMAGE=nginx:1.28.0-alpine3.21

FROM ${COMPOSER_IMAGE} AS composer

FROM ${PHP_IMAGE} AS php-base

ARG REDIS_EXTENSION_VERSION=6.2.0

RUN apk add --no-cache \
        icu-libs \
        libpq \
        libzip \
    && apk add --no-cache --virtual .build-dependencies \
        $PHPIZE_DEPS \
        icu-dev \
        libpq-dev \
        libzip-dev \
        linux-headers \
    && docker-php-ext-install -j4 \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        zip \
    && pecl install redis-${REDIS_EXTENSION_VERSION} \
    && docker-php-ext-enable redis \
    && apk del .build-dependencies \
    && addgroup -g 1000 -S app \
    && adduser -u 1000 -S -D -G app app

WORKDIR /var/www/html

COPY docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/php/fpm/zz-app.conf /usr/local/etc/php-fpm.d/zz-app.conf

FROM php-base AS vendor-production

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./

RUN apk add --no-cache git \
    && composer install \
    --no-dev \
    --no-interaction \
    --no-autoloader \
    --no-progress \
    --no-scripts \
    --prefer-dist

COPY app ./app

RUN composer dump-autoload \
    --classmap-authoritative \
    --no-dev \
    --no-interaction \
    --no-scripts

FROM php-base AS vendor-development

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./

RUN apk add --no-cache git \
    && composer install \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist

FROM php-base AS development

ARG XDEBUG_VERSION=3.4.3

USER root

RUN apk add --no-cache --virtual .xdebug-build-dependencies \
        $PHPIZE_DEPS \
        linux-headers \
    && pecl install xdebug-${XDEBUG_VERSION} \
    && docker-php-ext-enable xdebug \
    && apk del .xdebug-build-dependencies \
    && apk add --no-cache git su-exec \
    && git config --system --add safe.directory /var/www/html

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY --chown=app:app . .
COPY --from=vendor-development --chown=app:app /var/www/html/vendor ./vendor
COPY docker/php/conf.d/development.ini /usr/local/etc/php/conf.d/zz-environment.ini

RUN mkdir -p \
        bootstrap/cache \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
    && chown -R app:app bootstrap/cache storage

USER app

HEALTHCHECK --interval=10s --timeout=3s --start-period=20s --retries=5 \
    CMD php -r 'exit(@fsockopen("127.0.0.1", 9000) ? 0 : 1);'

CMD ["php-fpm"]

FROM php-base AS production

ENV APP_ENV=production \
    APP_DEBUG=false

COPY --chown=app:app . .
COPY --from=vendor-production --chown=app:app /var/www/html/vendor ./vendor
COPY docker/php/conf.d/production.ini /usr/local/etc/php/conf.d/zz-environment.ini

RUN mkdir -p \
        bootstrap/cache \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
    && chown -R app:app bootstrap/cache storage

USER app

RUN php artisan package:discover --ansi

HEALTHCHECK --interval=10s --timeout=3s --start-period=20s --retries=5 \
    CMD php -r 'exit(@fsockopen("127.0.0.1", 9000) ? 0 : 1);'

CMD ["php-fpm"]

FROM ${NGINX_IMAGE} AS nginx

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=production /var/www/html/public /var/www/html/public

HEALTHCHECK --interval=10s --timeout=3s --start-period=20s --retries=5 \
    CMD wget --quiet --tries=1 --spider http://127.0.0.1/up || exit 1
