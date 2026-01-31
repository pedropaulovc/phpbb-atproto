# phpBB Extension Development Container
FROM mcr.microsoft.com/devcontainers/base:ubuntu-22.04

# Install PHP 8.2 and required extensions
RUN apt-get update && apt-get install -y software-properties-common \
    && add-apt-repository ppa:ondrej/php \
    && apt-get update \
    && apt-get install -y \
        php8.2 \
        php8.2-xml \
        php8.2-mbstring \
        php8.2-curl \
        php8.2-zip \
        php8.2-mysql \
        php8.2-sqlite3 \
        php8.2-gd \
        php8.2-intl \
        php8.2-xdebug \
        libapache2-mod-php8.2 \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Install MySQL
RUN apt-get update && apt-get install -y mysql-server \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Git (for cloning phpBB)
RUN apt-get update && apt-get install -y git \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Configure Xdebug
RUN echo "xdebug.mode=debug" >> /etc/php/8.2/apache2/conf.d/20-xdebug.ini \
    && echo "xdebug.start_with_request=yes" >> /etc/php/8.2/apache2/conf.d/20-xdebug.ini \
    && echo "xdebug.client_host=localhost" >> /etc/php/8.2/apache2/conf.d/20-xdebug.ini \
    && echo "xdebug.client_port=9003" >> /etc/php/8.2/apache2/conf.d/20-xdebug.ini \
    && echo "xdebug.idekey=VSCODE" >> /etc/php/8.2/apache2/conf.d/20-xdebug.ini

# Configure Apache
RUN echo "Listen 8080" >> /etc/apache2/ports.conf \
    && a2enmod rewrite \
    && a2enmod php8.2

# Set PHP CLI to use PHP 8.2
RUN update-alternatives --set php /usr/bin/php8.2
