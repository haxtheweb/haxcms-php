#!/usr/bin/bash
# haxcms-php devcontainer setup
# -----------------------------------------------------------------------------
# Runs once after the container is created (postCreateCommand).
# Mirrors the production Dockerfile's PHP runtime requirements so the dev
# container can run, edit, and review the same code that ships in production.
# -----------------------------------------------------------------------------

set -e

# Run privileged commands via sudo when we are not root. The
# mcr.microsoft.com/devcontainers/php image may default to a non-root user
# with passwordless sudo; when already root, sudo is a no-op.
if [ "$(id -u)" -ne 0 ]; then
  SUDO=sudo
else
  SUDO=
fi

# --- 1. Node.js dependencies (gulp/babel JS asset build via gulpfile.cjs) ----
echo ">> Installing Node.js dependencies"
npm install

# --- 2. PHP extension parity with the production Dockerfile -------------------
# Production Dockerfile (FROM php:8.3-apache) builds:
#     docker-php-ext-configure gd --with-freetype=/usr/include/ --with-jpeg=/usr/include/
#     docker-php-ext-install -j$(nproc) gd curl
#
# ASSUMPTION about mcr.microsoft.com/devcontainers/php:8.3:
#   This image is based on the official php:8.3 image and adds dev tooling
#   (Xdebug, etc.). It MAY already ship gd and/or curl, but it does NOT
#   guarantee gd is compiled with freetype+jpeg the way production requires.
#   To guarantee EXACT parity with the production Dockerfile we build the
#   extensions from the -dev libraries whenever they are not already loaded.
#   Each step is guarded so it is a no-op when the extension is already present.
NEEDS_APT=0
php -m 2>/dev/null | grep -qi '^gd$'   || NEEDS_APT=1
php -m 2>/dev/null | grep -qi '^curl$' || NEEDS_APT=1

if [ "$NEEDS_APT" -eq 1 ]; then
  echo ">> Installing PHP extension build dependencies"
  $SUDO apt-get update
  $SUDO apt-get install -y --no-install-recommends \
      libfreetype6-dev libjpeg62-turbo-dev libpng-dev libcurl4-openssl-dev
fi

if ! php -m 2>/dev/null | grep -qi '^gd$'; then
  echo ">> Building PHP gd extension (freetype + jpeg) to match production"
  $SUDO docker-php-ext-configure gd --with-freetype=/usr/include/ --with-jpeg=/usr/include/
  $SUDO docker-php-ext-install -j"$(nproc)" gd
else
  echo ">> PHP extension 'gd' already loaded - skipping"
fi

if ! php -m 2>/dev/null | grep -qi '^curl$'; then
  echo ">> Building PHP curl extension to match production"
  $SUDO docker-php-ext-install -j"$(nproc)" curl
else
  echo ">> PHP extension 'curl' already loaded - skipping"
fi

# --- 3. Enable Apache rewrite (matches production: a2enmod rewrite) ----------
echo ">> Enabling Apache rewrite module"
$SUDO a2enmod rewrite

# --- 4. Next steps -----------------------------------------------------------
echo ""
echo "==========================================="
echo " haxcms-php devcontainer setup complete"
echo "==========================================="
echo ""
echo "Next steps:"
echo "  - Apache is available on port 80"
echo "    (document root is /var/www/html by default; point it at the"
echo "     workspace folder, or use the dev server, to serve this code)"
echo "  - Build JS assets:  npx gulp   (see gulpfile.cjs)"
echo "  - Dev server:       npm run dev"
echo "  - E2E tests:        npm run test:e2e"
echo ""
