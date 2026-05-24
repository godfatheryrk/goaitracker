FROM serversideup/php:8.4-fpm-nginx

ENV APP_ENV=production
ENV APP_DEBUG=false
ENV LOG_CHANNEL=stderr
ENV AUTORUN_ENABLED=true

USER root
COPY --chown=www-data:www-data . /var/www/html/

WORKDIR /var/www/html
RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && chown -R www-data:www-data /var/www/html

USER www-data
