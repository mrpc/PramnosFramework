FROM php:8.5-apache

# Install required system packages and PHP extensions
RUN apt-get update && apt-get install -y \
    libonig-dev \
    libzip-dev \
    libicu-dev \
    libpq-dev \
    libxml2-dev \
    libpng-dev \
    libjpeg-dev \
    libwebp-dev \
    libfreetype6-dev \
    unzip \
    git \
    nodejs \
    npm \
    postgresql-client \
    default-mysql-client \
    && docker-php-ext-configure intl \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install mysqli pdo_mysql pdo_pgsql pgsql mbstring zip intl bcmath gd ftp pcntl \
    && pecl install redis xdebug apcu \
    && docker-php-ext-enable redis xdebug apcu

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Configure Xdebug for coverage
RUN echo "xdebug.mode=coverage" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

# APCu under CLI as well as under Apache.
#
# `apcu.enable_cli` defaults to 0, which means the test suite — CLI — sees
# `apcu_enabled() === false` and every APCu path in the framework is unreachable.
# That is how the migration fingerprint cache's APCu branch, the one that actually
# runs in production, came to have no covered line: the code was fine and the
# environment could not reach it.
#
# Each CLI process gets its own empty cache, so nothing carries between tests.
RUN echo "apc.enable_cli=1" >> /usr/local/etc/php/conf.d/docker-php-ext-apcu.ini

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
