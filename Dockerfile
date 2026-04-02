FROM php:8.4-apache

# Install required system packages and PHP extensions
RUN apt-get update && apt-get install -y \
    libonig-dev \
    libzip-dev \
    libicu-dev \
    unzip \
    git \
    && docker-php-ext-configure intl \
    && docker-php-ext-install pdo_mysql mbstring zip intl \
    && pecl install redis xdebug \
    && docker-php-ext-enable redis xdebug

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Configure Xdebug for coverage
RUN echo "xdebug.mode=coverage" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
