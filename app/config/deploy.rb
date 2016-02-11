set :application, "PPI rest api"
set :domain,      "deploy.pss.web"
set :deploy_to,   "/var/www/sites/ppi/rest-api.dev"
set :app_path,    "app"
set :user,        "deploy"

role :web,        domain                         # Your HTTP server, Apache/etc
role :app,        domain, :primary => true       # This may be the same as your `Web` server

set :repository,  "git@github.com:Pirate-Parties-International/PPI-rest-api.git"
set :scm,         :git
set :branch,      "master"
set :deploy_via, :remote_cache
set :git_enable_submodules, 1

set :use_composer, true
set :update_vendors, false # to update vendors just change to true before deploy
set :copy_vendors, true
set :composer_options,  "--no-dev --verbose --prefer-dist --optimize-autoloader"

#set :model_manager, "doctrine"
set :use_sudo,    false


set :dump_assetic_assets, false

set :writable_dirs,       ["app/cache", "app/logs"]
set :webserver_user,      "www-data"
set :permission_method,   :acl
set :use_set_permissions, true

set :shared_files,      ["app/config/parameters.yml"]
set :shared_children,   [app_path + "/logs", web_path + "/uploads", "vendor", "app/Resources/java", "etc"]

set :stages,        %w(production dev)
set :default_stage, "dev"
set :stage_dir,     "app/config/deploy"
require 'capistrano/ext/multistage'

set  :keep_releases,  3

before 'symfony:composer:update', 'symfony:copy_vendors'

namespace :symfony do
  desc "Copy vendors from previous release"
  task :copy_vendors, :except => { :no_release => true } do
    if Capistrano::CLI.ui.agree("Do you want to copy last release vendor dir then do composer install ?: (y/N)")
      capifony_pretty_print "--> Copying vendors from previous release"

      run "cp -a #{previous_release}/vendor #{latest_release}/"
      capifony_puts_ok
    end
  end
end

after "deploy:update", "deploy:cleanup"
after "deploy", "deploy:set_permissions"

default_run_options[:pty] = true

# IMPORTANT = 0
# INFO      = 1
# DEBUG     = 2
# TRACE     = 3
# MAX_LEVEL = 3
logger.level = Logger::MAX_LEVEL
