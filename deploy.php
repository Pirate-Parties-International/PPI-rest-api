<?php
namespace Deployer;

require 'recipe/symfony.php';

// Configuration

set('repository', 'git@github.com:Pirate-Parties-International/PPI-rest-api.git');
set('git_tty', true); // [Optional] Allocate tty for git on first deployment
set('http_user', 'www-data');
set('writable_use_sudo', false);
set('dump_assets', false);

add('shared_files', []);
add('shared_dirs', [
	'app/logs', 
	'etc',
	"web/img/pp-flag",
	"web/img/pp-logo",
	"web/img/fb-covers",
    "web/img/uploads"
	]);
add('writable_dirs', []);
set('allow_anonymous_stats', false);

// Hosts

host('deploy.pss.web')
    ->stage('production')  
    ->forwardAgent()  
    ->set('deploy_path', '/var/www/sites/deployed/ppi_registry.prod');


// Tasks

// [Optional] if deploy fails automatically unlock.
after('deploy:failed', 'deploy:unlock');

// Migrate database before symlink new release.

//before('deploy:symlink', 'database:migrate');

