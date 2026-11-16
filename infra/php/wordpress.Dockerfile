ARG WORDPRESS_IMAGE=wordpress:php8.3-fpm
FROM composer:2 AS composer
FROM ${WORDPRESS_IMAGE}
RUN apt-get update && apt-get install -y --no-install-recommends libicu-dev librsvg2-bin libpng-dev libjpeg62-turbo-dev libfreetype6-dev unzip curl ca-certificates util-linux \
    && docker-php-ext-install intl pdo_mysql \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY plugin/cargo-utm /opt/cargo/plugin
# A release must contain composer.lock. Bootstrap lock generation is deliberately
# explicit, in scripts/lock-dependencies.sh, never an implicit production update.
RUN test -f /opt/cargo/plugin/composer.lock \
    && cd /opt/cargo/plugin && composer install --no-dev --prefer-dist --no-interaction \
    && composer check-platform-reqs --no-dev
RUN curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp && chmod +x /usr/local/bin/wp
COPY infra/php/wordpress.ini /usr/local/etc/php/conf.d/cargo.ini
COPY scripts/setup.php /opt/cargo/setup.php
COPY scripts/repair-site-urls.php /opt/cargo/repair-site-urls.php
COPY scripts/wp-wrapper.sh /usr/local/bin/cargo-wp-entrypoint
RUN chmod +x /usr/local/bin/cargo-wp-entrypoint
ENTRYPOINT ["cargo-wp-entrypoint"]
CMD ["php-fpm"]
