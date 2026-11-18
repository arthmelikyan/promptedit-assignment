FROM node:24-bookworm-slim AS node
FROM dunglas/frankenphp:1-php8.5-bookworm AS base

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && install-php-extensions pdo_pgsql pdo_sqlite redis pcntl intl zip bcmath sockets \
    && rm -rf /var/lib/apt/lists/*

ARG APP_UID=1000
ARG APP_GID=1000
RUN groupadd --gid ${APP_GID} app \
    && useradd --uid ${APP_UID} --gid app --create-home app \
    && mkdir -p /app/vendor /app/node_modules /app/storage/app/private /app/storage/app/public /app/storage/framework/cache/data /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs /app/bootstrap/cache /data/caddy /config/caddy \
    && chown -R app:app /app /data /config
COPY Caddyfile /etc/caddy/Caddyfile
COPY php.ini /usr/local/etc/php/conf.d/99-app.ini
ENV SERVER_NAME=:8080
WORKDIR /app
EXPOSE 8080
USER app
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile", "--adapter", "caddyfile"]

FROM base AS development
USER root
RUN apt-get update && apt-get install -y --no-install-recommends git unzip && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules/npm /usr/local/lib/node_modules/npm
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx
USER app

FROM development AS assets
COPY --chown=app:app . /app
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && npm ci \
    && npm run build

FROM base AS production
COPY --chown=app:app . /app
COPY --from=assets --chown=app:app /app/vendor /app/vendor
COPY --from=assets --chown=app:app /app/public/build /app/public/build
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && php artisan storage:link --no-interaction
ENV APP_ENV=production APP_DEBUG=false INERTIA_SSR_ENABLED=false
