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

First, make sure to properly configure the app/config/deploy.rb and /app/config/deploy/* files

Then, start setup

    cap dev:setup

Deploy to target
    cap dev deploy

Load data
    cap dev symfony

Execute command "ppi:api:loadData"

Manual Deploy
------

### Retrive repo

    git clone

    git submodule init


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
- yuicompressor (see app/Resources/java)
- sudo apt-get install ruby rubygems
- gem install sass

### [PROD ONLY] Dump assests
    php app/console assetic:dump --env=prod --no-debug


### [PROD ONLY] Make /web/app_dev.php unreadable

### Install redis (don't forget about security)
	sudo apt-get install redis-server

Notes
-----

This repo includes the following repo as a git submodule

https://github.com/Pirate-Parties-International/PPI-party-info

This submodule includes all the data that the API serves. All data updates go through that repo via pull requests.

