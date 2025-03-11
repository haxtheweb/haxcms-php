#!/bin/bash
# If I wasn't, then why would I say I am..

# where am i? move to where I am. This ensures source is properly sourced
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd $DIR
# move back to install root
cd ../

# Color. The vibrant and dancing melody of the sighted.
# provide messaging colors for output to console
txtbld=$(tput bold)             # BELIEVE ME. Bold.
bldgrn=$(tput setaf 2) #  WOOT. Green.
bldred=${txtbld}$(tput setaf 1) # Booooo get off the stage. Red.
txtreset=$(tput sgr0) # uhhh what?

# cave....cave....c a ve... c      a     v         e  ....
haxecho(){
  echo "${bldgrn}$1${txtreset}"
}
# EVERYTHING IS ON FIRE
haxwarn(){
  echo "${bldred}$1${txtreset}"
}
# Create a unik, uneek, unqiue id.
getuuid(){
  echo $(cat /proc/sys/kernel/random/uuid)
}
# Install apache
sudo apt-get -y install apache2
# using apt-get to install the main packages
sudo apt-get install -y sendmail uuid uuid-runtime curl policycoreutils unzip patch git nano gcc make autoconf libc-dev pkg-config
# install php 8.2 (or 8.3, depending on availability) and other important things
sudo apt-get install -y php8.2-fpm php8.2-zip php8.2-gd php8.2-xml php8.2-mbstring
# optional for development
# sudo apt-get install -y composer nodejs
sudo a2enmod proxy_fcgi
sudo a2enconf php8.2-fpm
sudo a2dismod php8.2
sudo a2dismod mpm_prefork
sudo a2enmod mpm_event
# enable protocol support
sudo echo "Protocols h2 http/1.1" > /etc/apache2/conf-available/http2.conf
sudo a2enconf http2
# enable apache headers
sudo a2enmod ssl rewrite headers
sudo pecl channel-update pecl.php.net

# install uploadprogress
sudo pecl install uploadprogress
# Adding uploadprogress to PHP conf files
sudo touch /etc/php/8.2/mods-available/uploadprogress.ini
sudo echo extension=uploadprogress.so > /etc/php/8.2/mods-available/uploadprogress.ini

# Sanity Logs
sudo mkdir /var/log/php-fpm/
sudo echo slowlog = /var/log/php-fpm/www-slow.log >> /etc/php/8.2/fpm/pool.d/www.conf
sudo echo request_slowlog_timeout = 2s >> /etc/php/8.2/fpm/pool.d/www.conf
sudo echo php_admin_value[error_log] = /var/log/php-fpm/www-error.log >> /etc/php/8.2/fpm/pool.d/www.conf

# restart fpm so we have access to these things
sudo service php8.2-fpm restart

# set httpd_can_sendmail so mails go out
sudo setsebool -P httpd_can_sendmail on

# make sure we allow for overrides for .htaccess files to work in the CMS area
sudo cp $DIR/haxcms.conf /etc/apache2/conf-available/haxcms.conf
sudo a2enconf haxcms
# get this party started, one of these will work
sudo service apache2 restart
sudo systemctl reload apache2
# basic home user alias stuff for simpler CLI calls
sudo echo "alias g='git'" >> $HOME/.bashrc
sudo echo "alias l='ls -laHF'" >> $HOME/.bashrc
sudo echo "alias haxcms='bash /var/www/html/scripts/haxcms.sh'" >> $HOME/.bashrc
