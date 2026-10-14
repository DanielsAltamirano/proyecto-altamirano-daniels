FROM php:8.3-apache

# Instalar extensiones requeridas para MariaDB (pdo_mysql) y Redis
RUN apt-get update && apt-get install -y --no-install-recommends \
    libicu-dev \
    && docker-php-ext-install pdo pdo_mysql \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && a2enmod rewrite headers \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Configurar Apache para enrutar todas las peticiones a index.php
RUN printf '<Directory /var/www/html>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
    FallbackResource /index.php\n\
</Directory>\n' > /etc/apache2/conf-available/api-routing.conf \
    && a2enconf api-routing

WORKDIR /var/www/html
COPY src/ /var/www/html/
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80