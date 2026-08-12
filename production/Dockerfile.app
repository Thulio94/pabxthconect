FROM node:22-alpine AS assets
WORKDIR /build
COPY app/package.json app/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY app/resources ./resources
COPY app/public ./public
COPY app/vite.config.js ./vite.config.js
RUN npm run build

FROM php:8.4-fpm-alpine
RUN apk add --no-cache git libpq-dev libzip-dev oniguruma-dev unzip zip \
    && docker-php-ext-install pdo_pgsql mbstring zip bcmath pcntl opcache \
    && apk add --no-cache --virtual .redis-build $PHPIZE_DEPS linux-headers \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .redis-build
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
WORKDIR /var/www/html
COPY app/composer.json app/composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader
COPY app/ ./
COPY --from=assets /build/public/build ./public/build
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/pbx-runtime storage/app/pbx-recordings \
    && chown -R www-data:www-data storage bootstrap/cache
CMD ["php-fpm"]
