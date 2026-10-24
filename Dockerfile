# syntax=docker/dockerfile:1.7

FROM dunglas/frankenphp:1.13.3-php8.5-trixie@sha256:73053ec45f0ad33c8e6f71f60ec3a11133899eecc73d7aa577b6d3a1ff5649c2 AS php-base

RUN install-php-extensions \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        zip \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && echo 'session.upload_progress.enabled=0' > "$PHP_INI_DIR/conf.d/zz-security.ini" \
    && setcap -r /usr/local/bin/frankenphp

FROM composer:2.10.3@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc AS composer
FROM node:24.22.1-bookworm-slim@sha256:55fb5f5a527ea2c5c4a95f963e48ef90aa38c7b346054fdc8ee9957a2b55d205 AS node

FROM php-base AS build

WORKDIR /app

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=node /usr/local/ /usr/local/
COPY composer.json composer.lock package.json pnpm-lock.yaml pnpm-workspace.yaml ./

RUN composer install \
        --classmap-authoritative \
        --no-ansi \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --prefer-dist \
    && corepack enable pnpm \
    && pnpm install --frozen-lockfile

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
    && pnpm run build

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
COPY --chown=www-data:www-data resources/notification-formats resources/notification-formats
COPY --chown=www-data:www-data routes routes
COPY --chown=www-data:www-data storage storage
COPY --chown=www-data:www-data artisan composer.json composer.lock ./
COPY --from=build --chown=www-data:www-data /app/vendor vendor
COPY --from=build --chown=www-data:www-data /app/public/build public/build
COPY --chown=www-data:www-data production/Caddyfile.application /etc/frankenphp/Caddyfile
COPY --chmod=0755 production/docker-entrypoint.production /usr/local/bin/with-production-secrets

RUN chmod -R ug+rwX storage bootstrap/cache

RUN php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $results = $app->make(App\NotificationIngestion\SupportedSpendingNotificationRegistry::class)->verifyFixtures(); if ($results === [] || in_array(false, $results, true)) { throw new RuntimeException("Gmail notification format verification failed."); }'

USER root

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/with-production-secrets"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
