# ============================================
# Topshine POS - Dockerfile for Railway
# ============================================

FROM php:8.1-apache

# Disable all MPM modules first
RUN a2dismod mpm_event mpm_worker mpm_prefork 2>/dev/null || true

# Install PHP extensions needed for MySQL
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Enable prefork MPM
RUN a2enmod mpm_prefork rewrite

# Set working directory
WORKDIR /var/www/html

# Copy all project files
COPY . /var/www/html/

# Set correct permissions
RUN mkdir -p /var/www/html/assets/images/products \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/assets

# Apache config
RUN echo '<Directory /var/www/html>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/topshine.conf \
    && a2enconf topshine

ENV PORT=80
EXPOSE 80

CMD ["apache2-foreground"]
