<?php
/**
 * Handles a complete eighteen73 WordPress installation and configuration
 *
 * @package eighteen73/wpi-cli-tools
 */

namespace Eighteen73\WP_CLI\Commands;

use Eighteen73\WP_CLI\Helpers;
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

		$this->status_message( 'Installing default plugins...' );
		$this->install_plugins();

		if ( $this->options['woocommerce'] ) {
			$this->status_message( 'Installing WooCommerce...' );
			$this->install_woocommerce();
		}

		if ( $this->options['multisite'] ) {
			WP_CLI::line();
			WP_CLI::line();
			WP_CLI::success( 'Your multisite is ready.' );
			WP_CLI::line( 'Important: Refer to https://docs.eighteen73.co.uk/wordpress/build-tools/nebula for multisite Apache/NGINX configuration.' );
			WP_CLI::line();
			WP_CLI::line( 'Network admin:  ' . $this->site_url . '/wp/wp-admin/network' );
			WP_CLI::line();
			WP_CLI::line( 'Website URL:    ' . $this->site_url );
			WP_CLI::line( 'Website admin:  ' . $this->site_url . '/wp/wp-admin' );
			WP_CLI::line();
			WP_CLI::line( 'Username:       ' . $this->site_username );
			WP_CLI::line( 'Password:       ' . $this->site_password );
		} else {
			WP_CLI::line();
			WP_CLI::line();
			WP_CLI::success( 'Your website is ready.' );
			WP_CLI::line();
			WP_CLI::line( 'URL:       ' . $this->site_url );
			WP_CLI::line( 'Admin:     ' . $this->site_url . '/wp/wp-admin' );
			WP_CLI::line();
			WP_CLI::line( 'Username:  ' . $this->site_username );
			WP_CLI::line( 'Password:  ' . $this->site_password );
		}

	}

	/**
	 * Output consistently formatted messages to the user
	 *
	 * @param string $message Text to output
	 * @return void
	 */
	private function status_message( string $message ) {
		WP_CLI::line();
		WP_CLI::line( str_pad( '', strlen( $message ) + 3, '*' ) );
		WP_CLI::line( "* {$message}" );
		WP_CLI::line( str_pad( '', strlen( $message ) + 3, '*' ) );
		WP_CLI::line();
	}

	/**
	 * Validation the user's options
	 *
	 * @param array $assoc_args Arguments
	 * @return void
	 */
	private function check_args( array $assoc_args ) {
		if ( isset( $assoc_args['multisite'] ) && $assoc_args['multisite'] === true ) {
			$this->options['multisite'] = true;
		} elseif ( isset( $assoc_args['multisite'] ) ) {
			WP_CLI::error( 'Option `--multisite` must not have a value' );
		}

		if ( isset( $assoc_args['woocommerce'] ) && $assoc_args['woocommerce'] === true ) {
			$this->options['woocommerce'] = true;
		} elseif ( isset( $assoc_args['woocommerce'] ) ) {
			WP_CLI::error( 'Option `--woocommerce` must not have a value' );
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
		Helpers::composer_command( 'create-project eighteen73/nebula ' . escapeshellarg( $this->install_directory ) . ' --stability=dev', null, false );
		Helpers::composer_command( 'update', $this->install_directory );
		WP_CLI::line( '   ... done' );
	}

	/**
	 * Create a fresh Git repo
	 *
	 * @return void
	 */
	private function create_repo() {
		Helpers::git_command( 'init', $this->install_directory );
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
		Helpers::cli_command( 'npm install --prefix ' . escapeshellarg( $this->install_directory . '/web/app/themes/pulsar' ) );
		Helpers::wp_command( 'theme activate pulsar', $this->wp_directory );
		$this->commit_repo( 'Add Pulsar theme' );
		WP_CLI::line( '   ... done' );
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

		WP_CLI::line();
		WP_CLI::line( 'Enter your admin username' );
		WP_CLI::out( '> ' );
		$this->site_username = strtolower( trim( fgets( STDIN ) ) );

		WP_CLI::line();
		WP_CLI::line( 'Enter your admin email address' );
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
		WP_CLI::line( '   ... done' );
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
		$config_filepath = "{$this->install_directory}/config/application.php";
		$dotenv_filepath = "{$this->install_directory}/.env";

		// Look for the WP_ALLOW_MULTISITE setting in in config/application.php and enable
		$fp = fopen( $config_filepath, 'r+' );
		while ( ! feof( $fp ) ) {
			$line = fgets( $fp );
			if ( ! str_contains( $line, 'WP_ALLOW_MULTISITE' ) ) {
				continue;
			}
			$new_line = str_replace( 'false', 'true', $line );
			fseek( $fp, -strlen( $line ), SEEK_CUR );
			fwrite( $fp, $new_line );
		}
		fclose( $fp );

		// Gather the multisite options
		do {
			WP_CLI::line();
			WP_CLI::line( 'Would like sites to use sub-directories or sub-domains: [0]' );
			WP_CLI::line( '  [0] Sub-directories' );
			WP_CLI::line( '  [1] Sub-domains' );
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
		$new_config = '';
		$fp         = fopen( $config_filepath, 'r' );
		while ( ! feof( $fp ) ) {
			$line        = fgets( $fp );
			$new_config .= $line;
			if ( ! str_contains( $line, 'WP_ALLOW_MULTISITE' ) ) {
				continue;
			}
			$new_config .= "Config::define( 'MULTISITE', true );\n";
			if ( $subdomain_install ) {
				$new_config .= "Config::define( 'SUBDOMAIN_INSTALL', true );\n";
			} else {
				$new_config .= "Config::define( 'SUBDOMAIN_INSTALL', false );\n";
			}
			$new_config .= "Config::define( 'DOMAIN_CURRENT_SITE', \$_ENV['DOMAIN_CURRENT_SITE'] );\n";
			$new_config .= "Config::define( 'PATH_CURRENT_SITE', '/' );\n";
			$new_config .= "Config::define( 'SITE_ID_CURRENT_SITE', 1 );\n";
			$new_config .= "Config::define( 'BLOG_ID_CURRENT_SITE', 1 );";
		}
		fclose( $fp );
		file_put_contents( $config_filepath, $new_config );

		return $output;
	}

	/**
	 * Install our preferred plugins
	 *
	 * @return void
	 */
	private function install_plugins() {
		$plugins = [
			'always' => [
				'wp-media/wp-rocket',
				'wpackagist-plugin/duracelltomi-google-tag-manager',
				'wpackagist-plugin/limit-login-attempts-reloaded',
				'wpackagist-plugin/redirection',
				'wpackagist-plugin/webp-express',
				'wpackagist-plugin/wordpress-seo',
			],
			'dev'    => [
				'wpackagist-plugin/spatie-ray',
			],
		];

		// Let the installer choose their mail plugin
		$mail_plugins = [
			'Easy WP SMTP (choose if unsure)' => 'wpackagist-plugin/easy-wp-smtp',
			'Mailgun for WordPress'           => 'wpackagist-plugin/mailgun',
		];
		$max_option   = count( $mail_plugins ) - 1;
		do {
			WP_CLI::line();
			WP_CLI::line( 'Mail plugin: [0]' );
			for ( $i = 0; $i <= $max_option; $i++ ) {
				$plugin_name = array_keys( $mail_plugins )[ $i ];
				WP_CLI::line( "  [{$i}] {$plugin_name}" );
			}
			WP_CLI::out( '> ' );
			$option = strtolower( trim( fgets( STDIN ) ) );
			if ( $option === '' ) {
				$option = '0';
			}
		} while ( ! preg_match( "/^[0-{$max_option}]$/", $option ) );
		$plugins['always'][] = array_values( $mail_plugins )[ $option ];

		Helpers::composer_command( 'require ' . implode( ' ', $plugins['always'] ), $this->install_directory );
		Helpers::composer_command( 'require --dev ' . implode( ' ', $plugins['dev'] ), $this->install_directory );

		Helpers::wp_command( 'plugin activate --all', $this->wp_directory );

		// Limit login attempts
		Helpers::wp_add_option( 'limit_login_lockout_notify', '""', true, $this->wp_directory );
		Helpers::wp_add_option( 'limit_login_show_warning_badge', '0', true, $this->wp_directory );
		Helpers::wp_add_option( 'limit_login_hide_dashboard_widget', '1', true, $this->wp_directory );
		Helpers::wp_add_option( 'limit_login_show_top_level_menu_item', '0', true, $this->wp_directory );
		Helpers::wp_command( 'transient delete llar_welcome_redirect', $this->wp_directory );

		// Redirection
		Helpers::wp_command( 'redirection database install', $this->wp_directory );

		// Mailgun (if used)
		if ( in_array( 'wpackagist-plugin/mailgun', $plugins['always'] ) ) {
			$value = escapeshellarg(
				json_encode(
					[
						'region'        => 'eu',
						'useAPI'        => '1',
						'domain'        => 'site-email.com',
						'apiKey'        => '',
						'username'      => '',
						'password'      => '',
						'secure'        => '1',
						'sectype'       => 'ssl',
						'track-clicks'  => 'no',
						'track-opens'   => '1',
						'from-address'  => '',
						'from-name'     => '',
						'override-from' => '0',
						'campaign-id'   => '',
					]
				)
			);
			Helpers::wp_add_option( 'mailgun', $value, true, $this->wp_directory, true );
		}

		$this->commit_repo( 'Add house plugins' );

		WP_CLI::line( '   ... done' );
	}

	/**
	 * Install and configure WooCommerce
	 *
	 * @return void
	 */
	private function install_woocommerce() {
		Helpers::composer_command( 'require wpackagist-plugin/woocommerce wpackagist-plugin/woocommerce-gateway-stripe', $this->install_directory );
		Helpers::wp_command( 'plugin activate --all', $this->wp_directory );

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

		WP_CLI::line( '   ... done' );
	}
}
