FROM php:8.2-apache

# Install PHP extensions required by phpBB
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    unzip \
    curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd mysqli pdo_mysql zip opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache modules
RUN a2enmod rewrite

# Set PHP configuration
RUN { \
    echo 'upload_max_filesize = 10M'; \
    echo 'post_max_size = 10M'; \
    echo 'memory_limit = 256M'; \
    echo 'max_execution_time = 300'; \
} > /usr/local/etc/php/conf.d/phpbb.ini

# Download and extract phpBB
ARG PHPBB_VERSION=3.3.14
RUN curl -L "https://download.phpbb.com/pub/release/3.3/${PHPBB_VERSION}/phpBB-${PHPBB_VERSION}.zip" -o /tmp/phpbb.zip \
    && unzip /tmp/phpbb.zip -d /tmp \
    && rm -rf /var/www/html/* \
    && mv /tmp/phpBB3/* /var/www/html/ \
    && rm -rf /tmp/phpbb.zip /tmp/phpBB3 \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Set working directory
WORKDIR /var/www/html

EXPOSE 80
