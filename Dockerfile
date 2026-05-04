FROM php:8.2-apache

# Install pdo_mysql extension (pdo is already bundled in PHP 8)
RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite
