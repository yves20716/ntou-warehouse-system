FROM php:8.4-apache
RUN docker-php-ext-install pdo_mysql
WORKDIR /srv/warehouse
COPY app/ app/
COPY public/ public/
COPY bin/ bin/
COPY deploy/apache.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/php.ini /usr/local/etc/php/conf.d/warehouse.ini
RUN printf 'ServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-available/warehouse.conf \
    && a2enconf warehouse
EXPOSE 80
