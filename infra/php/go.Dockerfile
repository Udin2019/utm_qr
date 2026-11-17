FROM php:8.3-fpm-bookworm
RUN docker-php-ext-install pdo_mysql
COPY go/ /opt/cargo/go/
COPY infra/php/go-pool.conf /usr/local/etc/php-fpm.d/zz-cargo.conf
RUN mkdir -p /var/empty && chown www-data:www-data /var/empty
WORKDIR /var/empty
USER www-data
CMD ["php-fpm", "-F"]
