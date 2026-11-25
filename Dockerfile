FROM php:8.4-cli

RUN docker-php-ext-install pdo_mysql
WORKDIR /srv/warehouse
COPY --chown=www-data:www-data app/ app/
COPY --chown=www-data:www-data public/ public/
COPY --chown=www-data:www-data bin/ bin/
USER www-data
EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
