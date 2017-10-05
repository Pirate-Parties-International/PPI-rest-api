Deployment
==========

## Note

This repo includes the following repo as a composer dependency

https://github.com/Pirate-Parties-International/PPI-party-info

This repo includes all the data that the API serves. All data updates go through that repo via pull requests.

## 1) Prerequesits

Nginx, php5-fpm, MySQL >5.5

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

Then run the data import:

    php app/console papi:loadData



## Production deploy

Install [Deployer](https://deployer.org)

    curl -LO https://deployer.org/deployer.phar
    mv deployer.phar /usr/local/bin/dep
    chmod +x /usr/local/bin/dep

Setup your ssh connection

    nano ~/.ssh/config

Then just deploy

    dep deploy production
