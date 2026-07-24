# syntax=docker/dockerfile:1.7

FROM dunglas/frankenphp:1.12.0-php8.5-trixie@sha256:7b54e661c5be17c5ace0efeec6b41f612509ea75028c0b72269f9ba8491431da AS php-base

RUN install-php-extensions \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        zip \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && setcap -r /usr/local/bin/frankenphp

FROM composer:2.9.5@sha256:698d3801b2a622ace460c4743c781282fcbcb733a4cbf8b31c44731e846585e8 AS composer
FROM node:24.13.0-bookworm-slim@sha256:4660b1ca8b28d6d1906fd644abe34b2ed81d15434d26d845ef0aced307cf4b6f AS node

FROM php-base AS build

WORKDIR /app

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=node /usr/local/ /usr/local/
COPY composer.json composer.lock package.json package-lock.json ./

RUN composer install \
        --classmap-authoritative \
        --no-ansi \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --prefer-dist \
    && npm ci --no-audit --no-fund

COPY app app
COPY bootstrap bootstrap
COPY config config
COPY database database
COPY public public
COPY resources resources
COPY routes routes
COPY storage storage
COPY artisan artisan
COPY vite.config.ts tsconfig.json components.json ./

RUN composer dump-autoload \
        --classmap-authoritative \
        --no-ansi \
        --no-dev \
        --no-interaction \
    && php artisan wayfinder:generate --with-form --no-interaction \
    && npm run build

FROM php-base AS production

ARG VCS_REF=unknown

LABEL org.opencontainers.image.source="https://github.com/rgomeztinoco/money-assistant" \
    org.opencontainers.image.revision="${VCS_REF}"

WORKDIR /app

COPY --chown=www-data:www-data app app
COPY --chown=www-data:www-data bootstrap bootstrap
COPY --chown=www-data:www-data config config
COPY --chown=www-data:www-data database database
COPY --chown=www-data:www-data public public
COPY --chown=www-data:www-data resources/views resources/views
COPY --chown=www-data:www-data routes routes
COPY --chown=www-data:www-data storage storage
COPY --chown=www-data:www-data artisan composer.json composer.lock ./
COPY --from=build --chown=www-data:www-data /app/vendor vendor
COPY --from=build --chown=www-data:www-data /app/public/build public/build
COPY --chown=www-data:www-data Caddyfile.application /etc/frankenphp/Caddyfile
COPY --chmod=0755 docker-entrypoint.production /usr/local/bin/with-production-secrets

RUN chmod -R ug+rwX storage bootstrap/cache

USER root

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/with-production-secrets"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
