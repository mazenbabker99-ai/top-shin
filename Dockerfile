# ============================================
# Topshine POS - Dockerfile for Railway
# ============================================

FROM php:8.1-apache

# Install PHP extensions needed for MySQL
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Copy custom entrypoint to fix MPM conflicts at container start
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Enable rewrite (prefork will be ensured by entrypoint)
RUN a2enmod rewrite || true

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

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
