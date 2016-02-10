Deployment
==========

## Note

This repo includes the following repo as a capistrano dependency

https://github.com/Pirate-Parties-International/PPI-party-info

This repo includes all the data that the API serves. All data updates go through that repo via pull requests.

## 1) Prerequesits

Nginx, php5-fpm, MySQL

## 2) Deployment (development)

### Git clone

Clone the repository

    clone 

### Setup permissions

[Follow instucrions here](http://symfony.com/doc/current/book/installation.html#book-installation-permissions) or, if you use Ubuntu just do:

Install setfacl

    sudo apt-get install acl

Setup permission

    ./permissions.sh

### Install dependencies

Install composer [globally](https://getcomposer.org/doc/00-intro.md#globally)

    curl -sS https://getcomposer.org/installer | php
    mv composer.phar /usr/local/bin/composer

Run composer install

    composer install

**NOTE:** You will be prompted to enter paramaters config data. (The installer also installs the app/config/paramaters.yml file)

Explanation:
* mailer_* leave as default
* locale leave as default

### Modify nginx.conf file

Go to config folder

    cd etc/

Copy distribution file

    cp nginx.conf.dist nginx.conf

Update your:

* project paths
* php-fpm sock file path
* server_name (I recommend dashboard.lan, with an alias in /etc/hosts file)

### Setup DB

First setup the schema:

    php app/console doctrine:schema:create

Run fixtures import

    php app/console doctrine:fixtures:load

Than run the data import:

    php app/console papi:loadData



## Production deploy

Install capistrano & caponify
http://capifony.org/

Install rest via manual deploy

First, make sure to properly configure the app/config/deploy.rb and /app/config/deploy/* files

Then, start setup

    cap dev:setup

Deploy to target
    cap dev deploy

Load data
    cap dev symfony

Execute command "papi:loadData"
