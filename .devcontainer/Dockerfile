# Ambiente do GitHub Codespaces / Dev Containers: PHP 8.3 com GD (WebP/JPEG) e Composer.
FROM php:8.3-cli-bookworm

RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip libwebp-dev libjpeg62-turbo-dev libpng-dev libfreetype6-dev libzip-dev \
 && docker-php-ext-configure gd --with-webp --with-jpeg --with-freetype \
 && docker-php-ext-install -j"$(nproc)" gd zip \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
