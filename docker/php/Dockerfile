FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
    git \
    libpq-dev \
    libzip-dev \
    oniguruma-dev \
    unzip \
    zip \
    && docker-php-ext-install pdo_pgsql mbstring zip bcmath pcntl opcache

RUN apk add --no-cache --virtual .redis-build $PHPIZE_DEPS linux-headers \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .redis-build

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/run-tests /usr/local/bin/run-tests
RUN chmod +x /usr/local/bin/run-tests

WORKDIR /var/www/html

COPY app/composer.json app/composer.lock ./
RUN composer install --no-interaction --no-scripts --prefer-dist --optimize-autoloader

COPY app/ ./

RUN composer dump-autoload --optimize --no-interaction

RUN chown -R www-data:www-data storage bootstrap/cache

CMD ["php-fpm"]
