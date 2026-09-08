FROM php:8.4-cli-alpine

RUN apk add --no-cache postgresql-dev icu-dev libzip-dev $PHPIZE_DEPS \
    && docker-php-ext-install pdo pdo_pgsql intl opcache zip \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-progress

COPY . .
RUN composer dump-autoload --optimize \
    && mkdir -p var/cache var/log var/storage/datasets \
    && chmod -R 777 var \
    && cp docker/uploads.ini /usr/local/etc/php/conf.d/uploads.ini \
    && chmod +x docker/docker-entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["docker/docker-entrypoint.sh"]
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
