FROM php:8.4-apache AS app-base

ENV APACHE_DOCUMENT_ROOT=/app/public \
    COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update \
    && dpkg --add-architecture i386 \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        binutils \
        ca-certificates \
        curl \
        git \
        gnupg \
        libc6-i386 \
        libicu-dev \
        lib32gcc-s1 \
        lib32stdc++6 \
        lib32z1 \
        libxslt1-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-install bcmath intl pdo_mysql xsl zip \
    && printf "ServerName localhost\n" > /etc/apache2/conf-available/servername.conf \
    && a2enmod headers rewrite expires \
    && a2enconf servername \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php/vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php/php.ini /usr/local/etc/php/conf.d/throttle.ini

WORKDIR /app

FROM app-base AS dev

RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get update \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

FROM node:20-bookworm-slim AS frontend-build

WORKDIR /app

COPY package.json package-lock.json webpack.config.js svg-sprite.json ./
COPY assets ./assets

RUN npm ci --no-audit --no-fund
RUN npm run build

FROM app-base AS prod

ENV APP_ENV=prod \
    APP_DEBUG=0

COPY composer.json composer.lock symfony.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

COPY . /app
COPY --from=frontend-build /app/public/build /app/public/build
COPY docker/php/entrypoint.sh /usr/local/bin/app-entrypoint

RUN chmod +x /usr/local/bin/app-entrypoint \
    && composer dump-autoload --classmap-authoritative --no-dev \
    && mkdir -p /app/var /app/cache /app/dumps /app/symbols/public \
    && chown -R www-data:www-data /app/var /app/cache /app/dumps /app/symbols

ENTRYPOINT ["app-entrypoint"]
CMD ["apache2-foreground"]
