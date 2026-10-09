# Construtor Rankly · imagem do MODO DEMONSTRAÇÃO (bin/demo.sh): editor e sites num endereço só,
# banco SQLite em /dados. Para testar e mostrar; produção usa Apache/LiteSpeed + MySQL (README).
#
#   docker build -t rankly-demo .
#   docker run -p 8080:8080 -v rankly-dados:/dados rankly-demo
#   → http://localhost:8080/editor/   (demo@rankly.app / demo12345)

FROM php:8.3-cli-bookworm

RUN apt-get update \
 && apt-get install -y --no-install-recommends libwebp-dev libjpeg62-turbo-dev libpng-dev libfreetype6-dev libzip-dev unzip \
 && docker-php-ext-configure gd --with-webp --with-jpeg --with-freetype \
 && docker-php-ext-install -j"$(nproc)" gd zip \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --no-dev --optimize-autoloader --no-scripts
COPY . .
RUN composer dump-autoload --no-dev --optimize

ENV RANKLY_CONFIG=/app/config/config.demo.php \
    RANKLY_DADOS=/dados \
    PORTA=8080
VOLUME ["/dados"]
EXPOSE 8080

CMD ["bin/demo.sh"]
