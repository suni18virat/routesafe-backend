FROM php:8.2-apache

# Install mysqli extension
RUN docker-php-ext-install mysqli && docker-php-ext-enable mysqli

# Copy files
COPY potholeapi.php /var/www/html/potholeapi.php
COPY db_config.php /var/www/html/db_config.php

# Enable Apache rewrite module
RUN a2enmod rewrite

# Expose port 80
EXPOSE 80
