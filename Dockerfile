FROM php:8.3-apache
# Adds jpeg-support for gd
RUN apt-get update && apt-get install -y \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libcurl4-openssl-dev \
        git \
    && docker-php-ext-configure gd --with-freetype=/usr/include/ --with-jpeg=/usr/include/ \
    && docker-php-ext-install -j$(nproc) gd curl
# Enable rewrite + headers (headers is required by the .htaccess security
# headers: X-Content-Type-Options, forced download of HTML in files/)
RUN a2enmod rewrite headers
# Copy custom apache conf
COPY ./scripts/docker/apache2.conf /etc/apache2/apache2.conf
# Customize the php entrypoint to clean up permissions
COPY ./scripts/docker/docker-php-entrypoint /usr/local/bin/
# Make sure the entrypoint is executable
RUN chmod +x /usr/local/bin/docker-php-entrypoint
# Copy HAXcms into the web root directory
COPY --chown=www-data:www-data . /var/www/html
# Security (SEC-DEPLOY): runtime state (config.php, SALT.txt, keys, user data,
# sites) lives in these directories; declare them as volumes so they persist
# across image upgrades and are never baked into an image layer. They are
# created up front owned by www-data so fresh named volumes inherit that
# ownership and the installer (install.php) can populate them.
RUN mkdir -p /var/www/html/_config /var/www/html/_sites /var/www/html/_published /var/www/html/_archived \
    && chown www-data:www-data /var/www/html/_config /var/www/html/_sites /var/www/html/_published /var/www/html/_archived
VOLUME ["/var/www/html/_config", "/var/www/html/_sites", "/var/www/html/_published", "/var/www/html/_archived"]
EXPOSE 80
