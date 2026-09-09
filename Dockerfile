FROM php:8.2-apache

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Install SQLite extensions
RUN apt-get update && apt-get install -y libsqlite3-dev \
    && docker-php-ext-install pdo pdo_sqlite

# Copy application files to the Apache document root
COPY . /var/www/html/

# Set ownership and permissions so PHP can write to the SQLite database
RUN chown -R www-data:www-data /var/www/html/
RUN chmod -R 775 /var/www/html/

# Expose port 80 for Render
EXPOSE 80
