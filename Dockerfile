# syntax=docker/dockerfile:1.7

FROM php:8.4-fpm-alpine AS php-base

RUN apk add --no-cache \
        curl \
        freetype \
        icu-libs \
        libjpeg-turbo \
        libpng \
        libzip \
        nginx \
        supervisor \
        unzip \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        freetype-dev \
        icu-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libzip-dev \
        linux-headers \
        oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" bcmath exif gd intl mbstring opcache pcntl pdo_mysql zip \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/* /tmp/*

WORKDIR /var/www/html

FROM php-base AS php-dependencies
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY . .
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader

FROM node:22-alpine AS frontend-assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY public ./public
COPY vite.config.js ./
RUN npm run build

FROM php-base AS runtime

COPY --from=php-dependencies /var/www/html /var/www/html
COPY --from=frontend-assets /app/public/build /var/www/html/public/build
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/approvedesk-entrypoint

RUN chmod +x /usr/local/bin/approvedesk-entrypoint \
    && mkdir -p /run/nginx /var/log/supervisor \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    CACHE_STORE=file \
    QUEUE_CONNECTION=database \
    SESSION_DRIVER=file

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl --fail --silent http://127.0.0.1:8080/up > /dev/null || exit 1

ENTRYPOINT ["approvedesk-entrypoint"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
