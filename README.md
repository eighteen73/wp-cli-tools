# WP-CLI Commands

A collection of WP-CLI helpers for our team's development workflow.

**Important:** These are extremely opinionated for our development preferences and may not be useful to others.

## Installation

Prerequisites:

- A global copy of [WP-CLI](https://make.wordpress.org/cli/handbook/guides/installing/)

To install the package run the following command:

```shell
wp package install eighteen73/wp-cli-tools
```

## Updates

You should ensure that you are running the latest copy of this package, especially before setting up new websites.

To update the package run the following command:

```shell
wp package update
```

## Available Commands

### `create-site`: Create new website

This site installation command will install a fresh copy of WordPress using our [Nebula](https://github.com/eighteen73/nebula) framework. It will also install our [Pulsar](https://github.com/eighteen73/pulsar) theme and other plugins that are part of our standard website configuration.

It will interactively ask for installation details (e.g. database credentials) as needed.

#### Usage

```shell
# Install under "foobar" in current directory
wp eighteen73 create-site foobar

# Install under "foobar", specifying full path
wp eighteen73 create-site /home/joebloggs/foobar

# Install with WooCommerce
wp eighteen73 create-site foobar --woocommerce
```

---

### `sync`: Import the remote website's database

The sync command requires an SSH connection to the remote website and few configuration settings. The following instructions assume you are running our [Nebula](https://github.com/eighteen73/nebula) framework but are easily adapted for other installation profiles.

#### Config (via `config/environments/development.php`)

Because this doesn't store any sensitive login credentials it may be beneficial to add sync configuration to the environment config and commit to your repo, for the benefit of other developers.

```php
Config::define( 'EIGHTEEN73_SSH_HOST', 'website.example.com' );
Config::define( 'EIGHTEEN73_SSH_PORT', 123 ); // if not port 22
Config::define( 'EIGHTEEN73_SSH_USER', 'username' );
Config::define( 'EIGHTEEN73_SSH_PATH', '/path/to/remote/website' );

// You may also add a list of plugins to automatically activate/deactivate
Config::define( 'EIGHTEEN73_SYNC_ACTIVATE_PLUGINS', 'plugin1,plugin2' );
Config::define( 'EIGHTEEN73_SYNC_DEACTIVATE_PLUGINS', 'plugin3' );
```

#### Config (via `.env`)

If you would rather not share you config, or override the shared configuration added above, you can add this to you personal `.env` file.

```ini
EIGHTEEN73_SSH_HOST=website.example.com
EIGHTEEN73_SSH_PORT=123 # if not port 22
EIGHTEEN73_SSH_USER=username
EIGHTEEN73_SSH_PATH=/path/to/remote/website

# You may also add a list of plugins to automatically activate/deactivate
EIGHTEEN73_SYNC_ACTIVATE_PLUGINS=plugin1,plugin2
EIGHTEEN73_SYNC_DEACTIVATE_PLUGINS=plugin3
```

#### Config (via `wp-config.php`)

**This is ONLY for non-Nebula/Bedrock websites.**

If your website doesn't have either of the above config files you can put the following into `wp-config.php` and run the `wp` command from your website's public root.

```php
define( 'EIGHTEEN73_SSH_HOST', 'website.example.com' );
define( 'EIGHTEEN73_SSH_PORT', '123' ); // if not port 22
define( 'EIGHTEEN73_SSH_USER', 'username' );
define( 'EIGHTEEN73_SSH_PATH', '/path/to/remote/website' );

// You may also add a list of plugins to automatically activate/deactivate
define( 'EIGHTEEN73_SYNC_ACTIVATE_PLUGINS', 'plugin1,plugin2' );
define( 'EIGHTEEN73_SYNC_DEACTIVATE_PLUGINS', 'plugin3' );
```

#### Usage

```shell
# Simple, just applies plugin overrides
wp eighteen73 sync

# Database mode, downloads a fresh copy of the remove database (overwriting all local data) and applies plugin overrides
wp eighteen73 sync --database

# Uploads mode, downloads a fresh copy of uploads directory (overwriting all local files) and applies plugin overrides
wp eighteen73 sync --uploads

# All of the above
wp eighteen73 sync --database --uploads
```

Note that `--uploads` is not necessary if you are using our [Satellite](https://github.com/eighteen73/satellite) plugin and it's remote files feature is enabled.

---

### `first-sync`: Initialise a website using a remote database

The plain `sync` command cannot be run without a working copy of WordPress so this is a special version that can be run before there's a working database on your local website.

#### Config

This requires the same configuration as the `sync` command (see above).

#### Usage

Clone the website's Git repository and run `composer install` as usual before running the following command.

```shell
# Initialise your website from a remote copy
wp eighteen73 first-sync
```
