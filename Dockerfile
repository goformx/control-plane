# Digests are the published multi-platform manifests verified for linux/amd64.
FROM php:8.5-fpm-alpine@sha256:fa01fb1645cd0fc566a5f146b099adace33b906571f972f71f2182a7c12d1cd7 AS php-base

RUN apk add --no-cache icu-libs libzip su-exec \
    && apk add --no-cache --virtual .build-deps icu-dev libzip-dev \
    && docker-php-ext-install intl zip \
    && apk del .build-deps \
    && php -r 'foreach (["fileinfo", "intl", "pdo_sqlite", "sqlite3", "sodium", "zip"] as $ext) { if (!extension_loaded($ext)) { fwrite(STDERR, "Missing PHP extension: $ext\n"); exit(1); } }'

COPY --from=composer:2.10.0@sha256:1b73755de4f19775ba6087fd5313664493e06fab72b6fc27dc2044e87bb7c4c3 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app

FROM php-base AS deps
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --no-progress --prefer-dist --optimize-autoloader

FROM php-base AS production
ARG VCS_REF=unknown
LABEL org.opencontainers.image.source="https://github.com/goformx/control-plane" \
      org.opencontainers.image.revision="$VCS_REF"
COPY --from=deps /app/vendor /app/vendor
COPY composer.json composer.lock ./
COPY .waaseyaa/ ./.waaseyaa/
COPY config/ ./config/
COPY src/ ./src/
COPY templates/ ./templates/
COPY public/ ./public/
COPY docker/php/entrypoint.sh /usr/local/bin/goformx-php-entrypoint
RUN composer dump-autoload --no-dev --optimize \
    && mkdir -p /app/storage \
    && chown -R www-data:www-data /app/storage \
    && chmod 0755 /usr/local/bin/goformx-php-entrypoint
ENV APP_ENV=production APP_DEBUG=false WAASEYAA_DB=/app/storage/waaseyaa.sqlite
EXPOSE 9000
ENTRYPOINT ["/usr/local/bin/goformx-php-entrypoint"]
CMD ["php-fpm", "-F"]

FROM nginx:1.29.4-alpine@sha256:4870c12cd2ca986de501a804b4f506ad3875a0b1874940ba0a2c7f763f1855b2 AS web
ARG VCS_REF=unknown
LABEL org.opencontainers.image.source="https://github.com/goformx/control-plane" \
      org.opencontainers.image.revision="$VCS_REF"
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY public/assets/ /usr/share/nginx/html/assets/
COPY public/assets/favicon.svg /usr/share/nginx/html/favicon.svg
EXPOSE 8080
