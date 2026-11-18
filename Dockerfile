FROM php:8.2-apache

# Install PHP extensions and Composer
RUN apt-get update && apt-get install -y git unzip \
    && docker-php-ext-install mysqli pdo pdo_mysql \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite (if you use .htaccess or clean URLs)
RUN a2enmod rewrite

# Copy project files into Apache's web root
COPY . /var/www/html/
RUN cd /var/www/html && composer install --no-dev --optimize-autoloader --no-interaction

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80