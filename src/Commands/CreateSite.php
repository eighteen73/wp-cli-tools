<?php
/**
 * Handles a complete eighteen73 WordPress installation and configuration
 *
 * @package eighteen73/wpi-cli-tools
 */

namespace Eighteen73\WP_CLI\Commands;

use Eighteen73\WP_CLI\Helpers;
use Eighteen73\WP_CLI\Traits\WritesConfig;
use WP_CLI;
use WP_CLI_Command;

/**
 * Handles a complete eighteen73 WordPress installation and configuration.
 *
 * ## EXAMPLES
 *
 *     # Install a new WordPress website using Nebula and it's install wizard
 *     $ wp eighteen73 create-site foobar
 *
 * @package eighteen73/wpi-cli-tools
 */
class CreateSite extends WP_CLI_Command {
	use WritesConfig;

	/**
	 * Installation directory
	 *
	 * @var string
	 */
	private string $install_directory;

	/**
	 * WordPress directory
	 *
	 * @var string
	 */
	private string $wp_directory;

	/**
	 * Website name
	 *
	 * @var string
	 */
	private string $site_name;

	/**
	 * Website URL
	 *
	 * @var string
	 */
	private string $site_url;

	/**
	 * Admin username
	 *
	 * @var string
	 */
	private string $site_username;

	/**
	 * Admin email
	 *
	 * @var string
	 */
	private string $site_email;

	/**
	 * Admin password
	 *
	 * @var string
	 */
	private string $site_password;

	/**
	 * Installation options
	 *
	 * @var array|false[]
	 */
	private array $options = [
		'multisite'   => false,
		'woocommerce' => false,
		'spark' => false,
		'nebula-branch' => null,
	];

	/**
	 * Creates a new website.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The name (directory) for the website
	 *
	 * [--multisite]
	 * : Install as a multisite network
	 *
	 * [--woocommerce]
	 * : Include WooCommerce
	 *
	 * [--spark]
	 * : Include the Spark pattern library
	 *
	 * [--nebula-branch]
	 * : Specify a Nebula branch to use (for development purposes)
	 *
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp eighteen73 create-site foobar
	 *
	 * @when before_wp_load
	 *
	 * @param array $args Arguments
	 * @param array $assoc_args Arguments
	 */
	public function __invoke( array $args, array $assoc_args ) {
		Helpers::version_check();

		// Check options
		$this->check_args( $assoc_args );

		// Check PHP and node versions
		$this->check_path( $args[0] );

		$this->status_message( 'Installing WordPress...' );
		$this->download_nebula();
		$this->create_repo();
		$this->install_wordpress();

		$this->status_message( 'Installing theme...' );
		$this->download_pulsar();

		$this->status_message( 'Installing Node packages...' );
		$this->install_node_packages();

		$this->status_message( 'Installing default plugins...' );
		$this->install_plugins();

		if ( $this->options['spark'] ) {
			$this->status_message( 'Installing Spark...' );
			$this->install_spark();
		}

		if ( $this->options['woocommerce'] ) {
			$this->status_message( 'Installing WooCommerce...' );
			$this->install_woocommerce();
		}

		if ( $this->options['multisite'] ) {
			WP_CLI::log( '' );
			WP_CLI::log( '' );
			WP_CLI::success( 'Your multisite is ready.' );
			WP_CLI::log( 'Important: Refer to https://docs.eighteen73.co.uk/wordpress/build-tools/nebula for multisite Apache/NGINX configuration.' );
			WP_CLI::log( '' );
			WP_CLI::log( 'Network admin:  ' . $this->site_url . '/wp/wp-admin/network' );
			WP_CLI::log( '' );
			WP_CLI::log( 'Website URL:    ' . $this->site_url );
			WP_CLI::log( 'Website admin:  ' . $this->site_url . '/wp/wp-login.php' );
			WP_CLI::log( '' );
			WP_CLI::log( 'Username:       ' . $this->site_username );
			WP_CLI::log( 'Password:       ' . $this->site_password );
		} else {
			WP_CLI::log( '' );
			WP_CLI::log( '' );
			WP_CLI::success( 'Your website is ready.' );
			WP_CLI::log( '' );
			WP_CLI::log( 'URL:       ' . $this->site_url );
			WP_CLI::log( 'Admin:     ' . $this->site_url . '/wp/wp-login.php' );
			WP_CLI::log( '' );
			WP_CLI::log( 'Username:  ' . $this->site_username );
			WP_CLI::log( 'Password:  ' . $this->site_password );
		}
	}

	/**
	 * Output consistently formatted messages to the user
	 *
	 * @param string $message Text to output
	 * @return void
	 */
	private function status_message( string $message ) {
		WP_CLI::log( '' );
		WP_CLI::log( str_pad( '', strlen( $message ) + 3, '*' ) );
		WP_CLI::log( "* {$message}" );
		WP_CLI::log( str_pad( '', strlen( $message ) + 3, '*' ) );
		WP_CLI::log( '' );
	}

	/**
	 * Validation the user's options
	 *
	 * @param array $assoc_args Arguments
	 * @return void
	 */
	private function check_args( array $assoc_args ) {

		// Simply options (no value allowed)
		$options = [ 'multisite', 'woocommerce', 'spark' ];
		foreach ( $options as $option ) {
			if ( isset( $assoc_args[ $option ] ) && $assoc_args[ $option ] === true ) {
				$this->options[ $option ] = true;
			} elseif ( isset( $assoc_args[ $option ] ) ) {
				WP_CLI::error( "Option `--{$option}` must not have a value" );
			}
		}

		if ( isset( $assoc_args['nebula-branch'] ) && ! empty( $assoc_args['nebula-branch'] ) ) {
			$this->options['nebula-branch'] = $assoc_args['nebula-branch'];
		}
	}

	/**
	 * Check the project's paths
	 *
	 * @param string $site_name Website name
	 * @return void
	 */
	private function check_path( string $site_name ) {
		if ( preg_match( '/^\//', $site_name ) ) {
			$this->install_directory = rtrim( $site_name, '/' );
			$this->site_name         = basename( $site_name );
		} else {
			$this->install_directory = rtrim( getcwd(), '/' ) . '/' . rtrim( $site_name, '/' );
			$this->site_name         = $site_name;
		}
		$this->wp_directory = $this->install_directory . '/web/wp';

		// Get confirmation
		WP_CLI::confirm( "Installing \"{$this->site_name}\" to \"{$this->install_directory}\". Is this OK?" );

		// Is it writable?
		if ( ! is_dir( $this->install_directory ) ) {
			if ( ! is_writable( dirname( $this->install_directory ) ) ) {
				WP_CLI::error( "Insufficient permission to create directory '{$this->install_directory}'." );
			}

			WP_CLI::log( "Creating directory '{$this->install_directory}'." );
			if ( ! @mkdir( $this->install_directory, 0777, true /*recursive*/ ) ) {
				$error = error_get_last();
				WP_CLI::error( "Failed to create directory '{$this->install_directory}': {$error['message']}." );
			}
		}
		if ( ! is_writable( $this->install_directory ) ) {
			WP_CLI::error( "'{$this->install_directory}' is not writable by current user." );
		}
	}

	/**
	 * Download our Nebula WordPress framework
	 *
	 * @return void
	 */
	private function download_nebula() {
		$command = 'create-project --stability=dev eighteen73/nebula ' . escapeshellarg( $this->install_directory );
		if ( $this->options['nebula-branch'] ) {
			$command .= ' dev-' . $this->options['nebula-branch'];
		}

		Helpers::composer_command( $command, null, false );
		Helpers::composer_command( 'update', $this->install_directory );
		WP_CLI::log( '   ... done' );
	}

	/**
	 * Create a fresh Git repo
	 *
	 * @return void
	 */
	private function create_repo() {
		Helpers::git_command( 'init -b develop', $this->install_directory );
		$this->commit_repo( 'Initial commit' );
	}

	/**
	 * Make a Git commit
	 *
	 * @param string $message Commit message
	 * @return void
	 */
	private function commit_repo( string $message ) {
		Helpers::git_command( 'add .', $this->install_directory );
		Helpers::git_command( 'commit -m ' . escapeshellarg( $message ), $this->install_directory );
	}

	/**
	 * Download our Pulsar WordPress theme
	 *
	 * @return void
	 */
	private function download_pulsar() {
		Helpers::composer_command( 'create-project eighteen73/pulsar ' . escapeshellarg( $this->install_directory . '/web/app/themes/pulsar' ) . ' --stability=dev' );
		Helpers::wp_command( 'theme activate pulsar', $this->wp_directory );

		$gitignore_filepath = "{$this->install_directory}/.gitignore";
		$gitignore = file_get_contents( $gitignore_filepath );
		$gitignore = str_replace( '#!web/app/themes/your-custom-theme/', "#!web/app/themes/your-custom-theme/\n!web/app/themes/pulsar/", $gitignore );
		file_put_contents( $gitignore_filepath, $gitignore );

		$this->commit_repo( 'Add Pulsar theme' );
		WP_CLI::log( '   ... done' );
	}

	/**
	 * Install Packages
	 *
	 * @return void
	 */
	private function install_node_packages() {
		Helpers::cli_command( 'npm install --prefix ' . escapeshellarg( $this->install_directory ) );
		Helpers::cli_command( 'npm prepare --prefix ' . escapeshellarg( $this->install_directory ) );
		WP_CLI::log( '   ... done' );
	}

	/**
	 * Install WordPress
	 *
	 * @return void
	 */
	private function install_wordpress() {
		$fp = @fopen( $this->install_directory . '/.env', 'r' );
		while ( ( $buffer = fgets( $fp, 4096 ) ) !== false ) {
			if ( preg_match( '/^WP_HOME="(.+)"$/', $buffer, $matches ) ) {
				$this->site_url = $matches[1];
				break;
			}
		}
		fclose( $fp );

		WP_CLI::log( '' );
		WP_CLI::log( 'Enter your admin username' );
		WP_CLI::out( '> ' );
		$this->site_username = strtolower( trim( fgets( STDIN ) ) );

		WP_CLI::log( '' );
		WP_CLI::log( 'Enter your admin email address' );
		WP_CLI::out( '> ' );
		$this->site_email = strtolower( trim( fgets( STDIN ) ) );

		// Install using multisite or not
		if ( $this->options['multisite'] ) {
			$output = $this->install_multisite();
		} else {
			$output = $this->install_site();
		}

		// Set language
		Helpers::wp_command( 'language core install en_GB', $this->wp_directory );
		Helpers::wp_command( 'site switch-language en_GB', $this->wp_directory );

		// Options
		Helpers::wp_update_option( 'blogdescription', '""', $this->wp_directory );
		Helpers::wp_update_option( 'date_format', '"d/m/Y"', $this->wp_directory );
		Helpers::wp_update_option( 'timezone_string', '"Europe/London"', $this->wp_directory );
		Helpers::wp_update_option( 'default_ping_status', '""', $this->wp_directory );
		Helpers::wp_update_option( 'default_pingback_flag', '""', $this->wp_directory );
		Helpers::wp_update_option( 'comments_notify', '""', $this->wp_directory );
		Helpers::wp_update_option( 'default_comment_status', '""', $this->wp_directory );
		Helpers::wp_update_option( 'comment_moderation', '1', $this->wp_directory );
		Helpers::wp_update_option( 'comment_registration', '1', $this->wp_directory );
		Helpers::wp_update_option( 'moderation_notify', '""', $this->wp_directory );
		Helpers::wp_update_option( 'page_comments', '""', $this->wp_directory );
		Helpers::wp_update_option( 'comment_previously_approved', '1', $this->wp_directory );
		Helpers::wp_update_option( 'show_avatars', '""', $this->wp_directory );
		Helpers::wp_update_option( 'permalink_structure', '"/%postname%/"', $this->wp_directory );

		$this->site_password = '';
		if ( preg_match( '/^Admin password: (.+)\s/', $output, $matches ) ) {
			$this->site_password = trim( $matches[1] );
		}
		WP_CLI::log( '   ... done' );
	}

	/**
	 * Install as a basic website
	 *
	 * @return int|mixed|object|null
	 */
	private function install_site() {
		return Helpers::wp_command(
			[
				'core install',
				[
					'skip-email'  => null,
					'url'         => escapeshellarg( $this->site_url . '/web' ),
					'title'       => escapeshellarg( $this->site_name ),
					'admin_user'  => escapeshellarg( $this->site_username ),
					'admin_email' => escapeshellarg( $this->site_email ),
				],
			],
			$this->wp_directory
		);
	}

	/**
	 * Install and configure a multisite network
	 *
	 * @return int|mixed|object|null
	 */
	private function install_multisite() {
		$dotenv_filepath = "{$this->install_directory}/.env";
		$htaccess_filepath = "{$this->install_directory}/web/.htaccess";

		// TODO Can I skip this given that it's set below?
		// $this->editConfigValue('WP_ALLOW_MULTISITE', true);

		// Gather the multisite options
		do {
			WP_CLI::log( '' );
			WP_CLI::log( 'Would like sites to use sub-directories or sub-domains: [0]' );
			WP_CLI::log( '  [0] Sub-directories' );
			WP_CLI::log( '  [1] Sub-domains' );
			WP_CLI::out( '> ' );
			$option = strtolower( trim( fgets( STDIN ) ) );
			if ( $option === '' ) {
				$option = '0';
			}
		} while ( ! preg_match( '/^[0-1]$/', $option ) );
		$subdomain_install = $option === '1';

		$options = [
			'skip-email'  => null,
			'skip-config' => null,
			'url'         => escapeshellarg( $this->site_url . '/web' ),
			'title'       => escapeshellarg( $this->site_name ),
			'admin_user'  => escapeshellarg( $this->site_username ),
			'admin_email' => escapeshellarg( $this->site_email ),
		];

		if ( $subdomain_install ) {
			$options['subdomains'] = null;
		}

		// Do the install
		$output = Helpers::wp_command(
			[
				'core multisite-install',
				$options,
			],
			$this->wp_directory
		);

		$domain_current_site = substr( $this->site_url, strpos( $this->site_url, '//' ) + 2 );

		// Add the domain to .env.example
		$new_dotenv = '';
		$fp         = fopen( "{$dotenv_filepath}.example", 'r' );
		while ( ! feof( $fp ) ) {
			$line        = fgets( $fp );
			$new_dotenv .= $line;
			if ( ! str_contains( $line, 'WP_SITEURL' ) ) {
				continue;
			}
			$new_dotenv .= "\n";
			$new_dotenv .= "# Multisite\n";
			$new_dotenv .= "DOMAIN_CURRENT_SITE=\"\"\n";
		}
		fclose( $fp );
		file_put_contents( "{$dotenv_filepath}.example", $new_dotenv );

		// Add the domain to .env
		$new_dotenv = '';
		$fp         = fopen( $dotenv_filepath, 'r' );
		while ( ! feof( $fp ) ) {
			$line        = fgets( $fp );
			$new_dotenv .= $line;
			if ( ! str_contains( $line, 'WP_SITEURL' ) ) {
				continue;
			}
			$new_dotenv .= "\n";
			$new_dotenv .= "# Multisite\n";
			$new_dotenv .= 'DOMAIN_CURRENT_SITE="' . $domain_current_site . "\"\n";
		}
		fclose( $fp );
		file_put_contents( $dotenv_filepath, $new_dotenv );

		// Write the new config
		$multisite_config = [
			"Config::define( 'WP_ALLOW_MULTISITE', true );",
			"Config::define( 'MULTISITE', true );",
			"Config::define( 'SUBDOMAIN_INSTALL', ".($subdomain_install ? 'true' : 'false')." );\n",
			"Config::define( 'DOMAIN_CURRENT_SITE', \$_ENV['DOMAIN_CURRENT_SITE'] );",
			"Config::define( 'PATH_CURRENT_SITE', '/' );",
			"Config::define( 'SITE_ID_CURRENT_SITE', 1 );",
			"Config::define( 'BLOG_ID_CURRENT_SITE', 1 );",
		];
		$this->addConfigFile('multisite.php', implode("\n", $multisite_config));

		// Write the .htaccess (this file will not exist yet)
		$htaccess_content  = "# BEGIN WordPress Multisite\n";
		if ( $subdomain_install ) {
			$htaccess_content .= "# Using subdomain network type: https://wordpress.org/documentation/article/htaccess/#multisite\n";
		} else {
			$htaccess_content .= "# Using subfolder network type: https://wordpress.org/documentation/article/htaccess/#multisite\n";
		}
		$htaccess_content .= "\n";
		$htaccess_content .= "RewriteEngine On\n";
		$htaccess_content .= "RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n";
		$htaccess_content .= "RewriteBase /\n";
		$htaccess_content .= "RewriteRule ^index\.php$ - [L]\n";
		$htaccess_content .= "\n";
		$htaccess_content .= "# add a trailing slash to /wp-admin\n";
		if ( $subdomain_install ) {
			$htaccess_content .= "RewriteRule ^wp-admin$ wp-admin/ [R=301,L]\n";
		} else {
			$htaccess_content .= "RewriteRule ^([_0-9a-zA-Z-]+/)?wp-admin$ $1wp-admin/ [R=301,L]\n";
		}
		$htaccess_content .= "\n";
		$htaccess_content .= "RewriteCond %{REQUEST_FILENAME} -f [OR]\n";
		$htaccess_content .= "RewriteCond %{REQUEST_FILENAME} -d\n";
		$htaccess_content .= "RewriteRule ^ - [L]\n";
		if ( $subdomain_install ) {
			$htaccess_content .= "RewriteRule ^(wp-(content|admin|includes).*) $1 [L]\n";
			$htaccess_content .= "RewriteRule ^(.*\.php)$ $1 [L]\n";
		} else {
			$htaccess_content .= "RewriteRule ^([_0-9a-zA-Z-]+/)?(wp-(content|admin|includes).*) $2 [L]\n";
			$htaccess_content .= "RewriteRule ^([_0-9a-zA-Z-]+/)?(.*\.php)$ $2 [L]\n";
		}
		$htaccess_content .= "RewriteRule . index.php [L]\n";
		$htaccess_content .= "\n";
		$htaccess_content .= "# END WordPress Multisite\n";
		file_put_contents( $htaccess_filepath, $htaccess_content );

		return $output;
	}

	/**
	 * Install our preferred plugins
	 *
	 * @return void
	 */
	private function install_plugins() {
		$dev_config_filepath = "{$this->install_directory}/config/environments/development.php";
		$gitignore_filepath = "{$this->install_directory}/.gitignore";

		$plugins = [
			'eighteen73-plugin/kinsta-mu-plugins' => [
				'activate' => true,
				'dev' => false,
			],
			'eighteen73/pulsar-blocks' => [
				'activate' => true,
				'dev' => false,
			],
			'wpackagist-plugin/attachment-taxonomies' => [
				'activate' => true,
				'dev' => false,
			],
			'wpackagist-plugin/block-visibility' => [
				'activate' => true,
				'dev' => false,
			],
			'wpackagist-plugin/duracelltomi-google-tag-manager' => [
				'activate' => false,
				'dev' => false,
			],
			'wpackagist-plugin/imsanity' => [
				'activate' => true,
				'dev' => false,
			],
			'wpackagist-plugin/query-monitor' => [
				'activate' => true,
				'dev' => true,
			],
			'wpackagist-plugin/redirection' => [
				'activate' => false,
				'dev' => false,
			],
			'wpackagist-plugin/simple-smtp' => [
				'activate' => true,
				'dev' => false,
			],
			'wpackagist-plugin/two-factor' => [
				'activate' => true,
				'dev' => false,
			],
			'wpackagist-plugin/wordpress-seo' => [
				'activate' => false,
				'dev' => false,
			],
		];

		// Add our repo
		Helpers::composer_command( 'config repositories.eighteen73 composer https://code.eighteen73.co.uk/pkg/wordpress', $this->install_directory );

		// Get the plugins
		Helpers::composer_command( 'require ' . implode( ' ', array_keys( array_filter( $plugins, fn( $plugin ) => ! $plugin['dev'] ) ) ), $this->install_directory );
		Helpers::composer_command( 'require --dev ' . implode( ' ', array_keys( array_filter( $plugins, fn( $plugin ) => $plugin['dev'] ) ) ), $this->install_directory );

		// Activate the plugins
		$plugins_to_activate = [];
		foreach ( $plugins as $plugin => $options ) {
			if ( ! $options['activate'] ) {
				continue;
			}
			$plugins_to_activate[] = substr( $plugin, strrpos( $plugin, '/' ) + 1 );
		}
		Helpers::wp_command( 'plugin activate ' . implode( ' ', $plugins_to_activate ), $this->wp_directory );

		// Redirection
		Helpers::wp_command( 'redirection database install', $this->wp_directory );

		// Simple SMTP (Pre-fill some Mailgun details for convenience later)
		$value = escapeshellarg(
			json_encode(
				[
					'host' => 'smtp.eu.mailgun.org',
					'port' => '587',
					'user' => '',
					'pass' => '',
					'from' => '',
					'fromname' => '',
					'sec' => 'tls',
				]
			)
		);
		Helpers::wp_add_option( 'wpssmtp_smtp', $value, true, $this->wp_directory, true );

		// Simple SMTP (local mail catcher for development)
		$ports = [ 2525, 8025, 1025 ]; // Fallback is last in list
		foreach ( $ports as $port ) {
			if ( @fsockopen( '127.0.0.1', $port, timeout: 0.3 ) ) {
				break;
			}
		}

		$fp = fopen( $dev_config_filepath, 'a' );
		fwrite( $fp, "\n" );
		fwrite( $fp, "// Local mail catcher\n" );
		fwrite( $fp, "Config::define( 'SMTP_HOST', '127.0.0.1' );\n" );
		fwrite( $fp, "Config::define( 'SMTP_PORT', {$port} );\n" );
		fwrite( $fp, "Config::define( 'SMTP_USER', '' );\n" );
		fwrite( $fp, "Config::define( 'SMTP_PASS', '' );\n" );
		fwrite( $fp, "Config::define( 'SMTP_FROM', '' );\n" );
		fwrite( $fp, "Config::define( 'SMTP_FROMNAME', '' );\n" );
		fwrite( $fp, "Config::define( 'SMTP_SEC', 'off' );\n" );
		fwrite( $fp, "Config::define( 'SMTP_AUTH', false );\n" );
		fclose( $fp );

		// Ignore Yoast's llms.txt
		$fp = fopen( $gitignore_filepath, 'a' );
		fwrite( $fp, "\n" );
		fwrite( $fp, "# Yoast\n" );
		fwrite( $fp, "/web/llms.txt\n" );
		fclose( $fp );

		// Kinsta config
		$kinsta_config = [
			'$mu_plugins_url = Config::get( \'WP_CONTENT_URL\' ) . \'/mu-plugins\';',
			"Config::define( 'KINSTA_CDN_USERDIRS', 'app' );",
			'Config::define( \'KINSTAMU_CUSTOM_MUPLUGIN_URL\', "{$mu_plugins_url}/kinsta-mu-plugins" );',
			"Config::define( 'KINSTAMU_CAPABILITY', 'publish_pages' );",
			"Config::define( 'KINSTAMU_WHITELABEL', true );",
		];
		$this->addConfigFile('kinsta.php', implode("\n", $kinsta_config));

		$this->commit_repo( 'Add house plugins' );

		WP_CLI::log( '   ... done' );
	}

	/**
	 * Install and configure WooCommerce
	 *
	 * @return void
	 */
	private function install_woocommerce() {
		Helpers::composer_command( 'require wpackagist-plugin/woocommerce wpackagist-plugin/woocommerce-gateway-stripe', $this->install_directory );
		Helpers::wp_command( 'plugin activate woocommerce woocommerce-gateway-stripe', $this->wp_directory );

		// Options
		Helpers::wp_update_option( 'woocommerce_default_country', 'GB', $this->wp_directory );
		Helpers::wp_update_option( 'woocommerce_currency', 'GBP', $this->wp_directory );
		Helpers::wp_add_option( 'woocommerce_onboarding_profile', escapeshellarg( json_encode( [ 'skipped' => true ] ) ), true, $this->wp_directory, true );
		Helpers::wp_add_option( 'woocommerce_task_list_prompt_shown', '0', true, $this->wp_directory );
		Helpers::wp_update_option( 'woocommerce_task_list_prompt_shown', '1', $this->wp_directory );
		Helpers::wp_update_option( 'woocommerce_allowed_countries', 'specific', $this->wp_directory );
		Helpers::wp_update_option( 'woocommerce_all_except_countries', escapeshellarg( json_encode( [] ) ), $this->wp_directory, true );
		Helpers::wp_update_option( 'woocommerce_specific_allowed_countries', escapeshellarg( json_encode( [ 'GB' ] ) ), $this->wp_directory, true );
		Helpers::wp_update_option( 'woocommerce_specific_ship_to_countries', escapeshellarg( json_encode( [] ) ), $this->wp_directory, true );
		Helpers::wp_update_option( 'woocommerce_calc_taxes', 'yes', $this->wp_directory );
		Helpers::wp_update_option( 'woocommerce_enable_reviews', 'no', $this->wp_directory );
		Helpers::wp_update_option( 'woocommerce_manage_stock', 'no', $this->wp_directory );
		Helpers::wp_update_option( 'woocommerce_prices_include_tax', 'yes', $this->wp_directory );
		Helpers::wp_update_option( 'woocommerce_shipping_tax_class', 'zero-rate', $this->wp_directory );
		Helpers::wp_update_option( 'woocommerce_tax_display_shop', 'incl', $this->wp_directory );
		Helpers::wp_update_option( 'woocommerce_tax_display_cart', 'incl', $this->wp_directory );
		Helpers::wp_update_option( 'woocommerce_tax_total_display', 'single', $this->wp_directory );
		Helpers::wp_update_option( 'woocommerce_enable_checkout_login_reminder', 'yes', $this->wp_directory );
		Helpers::wp_update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'yes', $this->wp_directory );
		Helpers::wp_update_option( 'woocommerce_enable_myaccount_registration', 'yes', $this->wp_directory );
		Helpers::wp_update_option( 'woocommerce_show_marketplace_suggestions', 'no', $this->wp_directory );
		Helpers::wp_command( 'wc tax create --user=1 --country=GB --rate=20', $this->wp_directory );
		Helpers::wp_command( 'wc tax create --user=1 --country=GB --rate=5 --class=reduced-rate', $this->wp_directory );
		Helpers::wp_command( 'wc tax create --user=1 --country=GB --rate=0 --class=zero-rate', $this->wp_directory );

		$this->commit_repo( 'Add WooCommerce' );

		WP_CLI::log( '   ... done' );
	}

	/**
	 * Install and configure Spark
	 *
	 * @return void
	 */
	private function install_spark() {
		$dotenv_filepath = "{$this->install_directory}/.env";

		Helpers::composer_command( 'require eighteen73/spark', $this->install_directory );
		Helpers::wp_command( 'plugin activate spark', $this->wp_directory );

		// Spark config
		$spark_config = [
			'Config::define( \'SPARK_API_URI\', $_ENV[\'SPARK_API_URI\'] ?? \'\' );',
			'Config::define( \'SPARK_API_USERNAME\', $_ENV[\'SPARK_API_USERNAME\'] ?? \'\' );',
			'Config::define( \'SPARK_API_PASSWORD\', $_ENV[\'SPARK_API_PASSWORD\'] ?? \'\' );',
		];
		$this->addConfigFile('spark.php', implode("\n", $spark_config));

		// Add the domain to .env.example
		$new_dotenv = '';
		$fp         = fopen( "{$dotenv_filepath}.example", 'r' );
		while ( ! feof( $fp ) ) {
			$line        = fgets( $fp );
			$new_dotenv .= $line;
			if ( ! str_contains( $line, 'WP_SITEURL' ) ) {
				continue;
			}
			$new_dotenv .= "\n";
			$new_dotenv .= "# Spark\n";
			$new_dotenv .= "SPARK_API_URI=\n";
			$new_dotenv .= "SPARK_API_USERNAME=\n";
			$new_dotenv .= "SPARK_API_PASSWORD=\n";
		}
		fclose( $fp );
		file_put_contents( "{$dotenv_filepath}.example", $new_dotenv );

		// Add the domain to .env
		$new_dotenv = '';
		$fp         = fopen( "{$dotenv_filepath}", 'r' );
		while ( ! feof( $fp ) ) {
			$line        = fgets( $fp );
			$new_dotenv .= $line;
			if ( ! str_contains( $line, 'WP_SITEURL' ) ) {
				continue;
			}
			$new_dotenv .= "\n";
			$new_dotenv .= "# Spark\n";
			$new_dotenv .= "SPARK_API_URI=\n";
			$new_dotenv .= "SPARK_API_USERNAME=\n";
			$new_dotenv .= "SPARK_API_PASSWORD=\n";
		}
		fclose( $fp );
		file_put_contents( "{$dotenv_filepath}", $new_dotenv );

		$this->commit_repo( 'Add Spark' );

		WP_CLI::log( '   ... done' );
	}
}
