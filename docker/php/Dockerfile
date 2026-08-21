FROM node:22-bookworm-slim AS node

FROM php:8.4-fpm-bookworm

ARG UID=1000
ARG GID=1000

RUN apt-get update && apt-get install -y --no-install-recommends \
        git curl libfreetype6-dev libicu-dev libjpeg62-turbo-dev libpng-dev libpq-dev libxml2-dev libzip-dev unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" bcmath gd intl opcache pcntl pdo_pgsql soap zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=node /usr/local/ /usr/local/

RUN groupmod -o -g ${GID} www-data && usermod -o -u ${UID} -g www-data www-data

WORKDIR /var/www/html

COPY --chown=www-data:www-data composer.json composer.lock package.json ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-progress \
    && npm install --ignore-scripts

COPY --chown=www-data:www-data . .
RUN composer dump-autoload --optimize --no-interaction \
    && npm run build \
    && mkdir -p storage/framework/{cache,sessions,testing,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data vendor node_modules public/build storage bootstrap/cache

COPY docker/php/php.ini /usr/local/etc/php/conf.d/marketplace-saas.ini

USER www-data

CMD ["php-fpm"]
