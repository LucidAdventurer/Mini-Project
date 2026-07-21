FROM php:8.2-apache

# Install PostgreSQL PDO driver + common extensions your app likely needs
RUN apt-get update && apt-get install -y \
    libpq-dev \
    unzip \
    git \
    && docker-php-ext-install pdo pdo_pgsql pgsql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache rewrite module (for clean URLs, if used)
RUN a2enmod rewrite

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy app source
COPY . /var/www/html/

WORKDIR /var/www/html

# Install PHP dependencies (PHPMailer etc.)
RUN if [ -f composer.json ]; then composer install --no-dev --optimize-autoloader; fi

# Set correct permissions for uploads directory
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Render expects the app to listen on port 10000
RUN sed -i 's/80/10000/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf
EXPOSE 10000

CMD ["apache2-foreground"]
