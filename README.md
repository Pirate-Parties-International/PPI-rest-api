PPI REST API
============

API in use at api.piratetimes.net

Developer setup
---------------

Install capistrano & caponify
http://capifony.org/

Install rest via manual deploy

Capistrano deploy
-----------------

cap dev deploy


Manual Deploy
------

### Configure nginx
Copy etc/nginx.conf.dist to etc/nginx.conf and configure it

### Setup permissions
(For ubuntu. You may have to install setfacl)

    sudo setfacl -R -m u:www-data:rwX -m u:`whoami`:rwX app/cache app/logs
    sudo setfacl -dR -m u:www-data:rwx -m u:`whoami`:rwx app/cache app/logs

### Configure application
Copy app/conf/paramaters.yml.dist to app/conf/paramaters.yml and configure correctly

### Run composer

Install composer
    curl -s http://getcomposer.org/installer | php

Install dependencies
    php composer.phar install

### Install SASS
- Java
- youcompressor
- sudo apt-get install ruby rubygems
- gem install sass

### [PROD ONLY] Dump assests
    php app/console assetic:dump --env=prod --no-debug


### [PROD ONLY] Make /web/app_dev.php unreadable

### Install redis (don't forget about security)
	sudo apt-get install redis-server
