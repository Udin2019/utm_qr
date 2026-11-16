FROM composer:2 AS composer
FROM php:8.3-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libicu-dev libonig-dev libpng-dev git unzip \
    && docker-php-ext-install intl mbstring gd pdo_mysql \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
ENTRYPOINT ["composer"]
