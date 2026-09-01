FROM composer:2.8 AS vendor

WORKDIR /app
COPY . .
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader

FROM php:8.5-cli-alpine

RUN apk add --no-cache curl curl-dev libxml2-dev oniguruma-dev \
    && docker-php-ext-install curl dom mbstring pdo_mysql

WORKDIR /var/www/html
COPY --from=vendor /app .

RUN chown -R www-data:www-data storage bootstrap/cache

USER www-data
EXPOSE 8000

CMD ["sh", "-c", "php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=8000"]
