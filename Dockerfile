FROM php:8.2-apache

# Install mysqli extension
RUN docker-php-ext-install mysqli && docker-php-ext-enable mysqli

# Copy files
COPY potholeapi.php /var/www/html/potholeapi.php
COPY db_config.php /var/www/html/db_config.php
COPY img1.jpg /var/www/html/uploads/img1.jpg
COPY img2.jpg /var/www/html/uploads/img2.jpg

# Create uploads directory and set ownership/permissions for Apache www-data user
RUN mkdir -p /var/www/html/uploads && \
    chown -R www-data:www-data /var/www/html && \
    chmod -R 775 /var/www/html/uploads

# Enable Apache rewrite module
RUN a2enmod rewrite

# Expose port 80
EXPOSE 80
