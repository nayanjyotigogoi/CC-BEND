# CafeChalao API (Laravel 11) on PHP 8.3 + Apache. Same image for local and production; settings come from env.
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --optimize --no-dev

FROM php:8.3-apache
RUN docker-php-ext-install pdo_mysql opcache \
 && a2enmod rewrite headers \
 && sed -ri 's#/var/www/html#/var/www/html/public#g' /etc/apache2/sites-available/000-default.conf /etc/apache2/apache2.conf \
 && printf '<Directory /var/www/html/public>\n    AllowOverride All\n</Directory>\n' > /etc/apache2/conf-available/laravel.conf \
 && a2enconf laravel
COPY docker/php.ini /usr/local/etc/php/conf.d/cafechalao.ini
WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html
# Uploaded images live in public/storage (served directly by Apache, no storage:link). Keep it on a volume.
RUN mkdir -p public/storage storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache public/storage
COPY docker/entrypoint.sh /usr/local/bin/cafechalao-entrypoint
RUN chmod +x /usr/local/bin/cafechalao-entrypoint
EXPOSE 80
ENTRYPOINT ["cafechalao-entrypoint"]
CMD ["apache2-foreground"]
