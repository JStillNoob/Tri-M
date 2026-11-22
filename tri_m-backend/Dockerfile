FROM php:8.3-apache-bookworm AS runtime

ENV PORT=10000 \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/* \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && a2enmod rewrite headers

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/99-tri-m.ini
RUN printf 'Listen 0.0.0.0:${PORT}\n' > /etc/apache2/ports.conf \
    && apache2ctl configtest

WORKDIR /var/www/html

FROM runtime AS dependencies
COPY --from=composer:2.11 /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress
COPY app/ app/
COPY database/ database/
RUN composer dump-autoload --no-dev --no-scripts --optimize --no-interaction \
    && composer check-platform-reqs --no-dev

FROM runtime AS production
COPY --from=dependencies /var/www/html/vendor/ vendor/
COPY app/ app/
COPY bootstrap/ bootstrap/
COPY config/ config/
COPY database/ database/
COPY public/ public/
COPY resources/ resources/
COPY routes/ routes/
COPY artisan ./
RUN mkdir -p bootstrap/cache storage/app/private storage/app/public \
        storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && php artisan package:discover --no-interaction \
    && chown -R www-data:www-data bootstrap/cache storage

EXPOSE 10000
CMD ["apache2-foreground"]
