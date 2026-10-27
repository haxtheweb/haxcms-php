# Standalone HAXsite image (PHP + Apache).
#
# HAXCMS_IMAGE supplies the HAXcms core (build/, system/, babel files,
# wc-registry.json) that stamped sites normally symlink up to a parent install.
# It tracks the latest published image by default; pin a version with
#   docker build --build-arg HAXCMS_IMAGE=ghcr.io/haxtheweb/haxcms-php:<tag> .
ARG HAXCMS_IMAGE=ghcr.io/haxtheweb/haxcms-php:latest
FROM ${HAXCMS_IMAGE} AS haxcms

FROM php:8.3-apache

# gd (image handling) + curl are required by the HAXcms PHP backend
RUN apt-get update && apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libcurl4-openssl-dev \
    && docker-php-ext-configure gd --with-freetype=/usr/include/ --with-jpeg=/usr/include/ \
    && docker-php-ext-install -j"$(nproc)" gd curl \
    && rm -rf /var/lib/apt/lists/*

# rewrite + headers are both required by the site's .htaccess
RUN a2enmod rewrite headers
# honor the site's own .htaccess (Apache 2.4 syntax)
COPY ./docker/haxcms-site.conf /etc/apache2/conf-available/haxcms-site.conf
RUN a2enconf haxcms-site

COPY . /var/www/html
# Inside a stamped site these are symlinks to a parent install that does not
# exist in the container; replace them with the real files from HAXCMS_IMAGE.
RUN rm -rf /var/www/html/build \
        /var/www/html/dist \
        /var/www/html/wc-registry.json \
        /var/www/html/assets/babel-top.js \
        /var/www/html/assets/babel-bottom.js
COPY --from=haxcms /var/www/html/build /var/www/html/build
COPY --from=haxcms /var/www/html/system /var/www/html/system
COPY --from=haxcms /var/www/html/babel/babel-top.js /var/www/html/assets/babel-top.js
COPY --from=haxcms /var/www/html/babel/babel-bottom.js /var/www/html/assets/babel-bottom.js
COPY --from=haxcms /var/www/html/wc-registry.json /var/www/html/wc-registry.json
RUN chown -R www-data:www-data /var/www/html
