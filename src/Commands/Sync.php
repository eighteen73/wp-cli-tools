<?php
/**
 * Get a remote website's database and files
 *
 * @package eighteen73/wpi-cli-tools
 */

namespace Eighteen73\WP_CLI\Commands;

use Eighteen73\WP_CLI\Helpers;
use WP_CLI;
use WP_CLI_Command;

/**
 * Get a remote website's database and files.
 *
 * ## EXAMPLES
 *
 *     # Refresh the local website's database with the one from the remote website.
 *     $ wp eighteen73 sync --database
 *
 * @package eighteen73/wpi-cli-tools
 */
class Sync extends WP_CLI_Command {


	/**
	 * Which features should be run
	 *
	 * @var array
	 */
	private array $options = [
		'database'         => false,
		'uploads'          => false,
		'urls'             => false,
		'active_plugins'   => true,
		'inactive_plugins' => true,
	];

	/**
	 * Various command settings
	 *
	 * @var array
	 */
	private array $settings = [
		'ssh_host' => null,
		'ssh_port' => '22',
		'ssh_user' => null,
		'ssh_path' => null,
		'plugins'  => [
			'activate'   => null,
			'deactivate' => null,
		],
	];

	/**
	 * Is the pv command available
	 *
	 * @var bool
	 */
	private bool $has_pv = false;

	/**
	 * Path to local wp
	 *
	 * @var string
	 */
	private string $local_wp = '';

	/**
	 * Path to remote wp
	 *
	 * @var string
	 */
	private string $remote_wp = '';

	/**
	 * Get a remote website's database.
	 *
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp eighteen73 sync
	 *
	 * @when after_wp_load
	 *
	 * @param array $args Arguments
	 * @param array $assoc_args Arguments
	 */
	public function __invoke( array $args, array $assoc_args ) {
		Helpers::version_check();

		$version = Helpers::wp_command( 'core version' );
		if ( empty( $version ) ) {
			WP_CLI::error( 'Not a WordPress directory' );
		}

		if ( ! $this->is_safe_environment() ) {
			WP_CLI::error( 'This can only be run in a development and staging environments. Check your wp_get_environment_type() setting.' );
		}

		if ( ! $this->has_all_settings() ) {
			WP_CLI::error( 'You are missing some environment config. Refer to the package\'s README at https://github.com/eighteen73/wp-cli-tools' );
		}

		$this->check_for_pv();
		$this->find_local_wp();

		if ( ! $this->has_remote_access() ) {
			WP_CLI::error( 'Cannot access remote website. Please check your connection settings.' );
		}

		$this->find_remote_wp();

		$this->get_options( $assoc_args );

		$done_something = false;

		if ( $this->options['database'] ) {
			$done_something = true;
			$this->fetch_database();
			$this->enable_stripe_test_mode();
		}

		if ( $this->options['urls'] ) {
			$done_something = true;
			$this->replace_urls();
		}

		if ( $this->options['uploads'] ) {
			$done_something = true;
			$this->fetch_uploads();
		}

		if ( $this->options['active_plugins'] ) {
			$this->activate_plugins();
		}

		if ( $this->options['inactive_plugins'] ) {
			$this->deactivate_plugins();
		}

		if ( $done_something ) {
			$this->clear_caches();
		} else {
			WP_CLI::log( '' );
			WP_CLI::warning( 'You may have intended to run "wp eighteen73 sync --database" to update your local database.' );
		}

		WP_CLI::log( '' );
		WP_CLI::success( 'Complete' );
	}

	/**
	 * Get the current environment
	 *
	 * @return array|false|string
	 */
	private function environment() {
		if ( defined( 'WP_ENV' ) ) {
			return getenv( 'WP_ENV' );
		}

		if ( function_exists( 'wp_get_environment_type' ) ) {
			return wp_get_environment_type();
		}

		// For older websites we'll just have to trust the user's in a development environment
		return 'development';
	}

	/**
	 * Development and staging use only
	 */
	private function is_safe_environment(): bool {
		return in_array( $this->environment(), [ 'development', 'local', 'staging' ], true );
	}

	/**
	 * Load settings from .env or config (.env takes precedence)
	 */
	private function has_all_settings(): bool {
		$this->settings['ssh_host'] = $this->get_config_item( 'EIGHTEEN73_SSH_HOST' );
		$this->settings['ssh_user'] = $this->get_config_item( 'EIGHTEEN73_SSH_USER' );
		$this->settings['ssh_path'] = $this->get_config_item( 'EIGHTEEN73_SSH_PATH' );
		if ( empty( $this->settings['ssh_host'] ) || empty( $this->settings['ssh_user'] ) || empty( $this->settings['ssh_path'] ) ) {
			return false;
		}

		// Special case for SSH port
		$ssh_port                   = $this->get_config_item( 'EIGHTEEN73_SSH_PORT' ) ?? '22';
		$this->settings['ssh_port'] = strval( $ssh_port );
		if ( ! preg_match( '/^[0-9]+$/', $this->settings['ssh_port'] ) ) {
			$this->settings['ssh_port'] = null;
		}

		foreach ( $this->settings as $setting ) {
			if ( empty( $setting ) ) {
				return false;
			}
		}

		// Plugin (de)activations
		$activated_plugins = $this->get_config_item( 'EIGHTEEN73_SYNC_ACTIVATE_PLUGINS' );
		if ( $activated_plugins !== null ) {
			$activated_plugins = preg_split( '/[\s,]+/', $activated_plugins );
			$activated_plugins = array_filter( $activated_plugins );
		}
		$this->settings['plugins']['activate'] = $activated_plugins;

		$deactivated_plugins = $this->get_config_item( 'EIGHTEEN73_SYNC_DEACTIVATE_PLUGINS' );
		if ( $deactivated_plugins !== null ) {
			$deactivated_plugins = preg_split( '/[\s,]+/', $deactivated_plugins );
			$deactivated_plugins = array_filter( $deactivated_plugins );
		}
		$this->settings['plugins']['deactivate'] = $deactivated_plugins;

		return true;
	}

	/**
	 * Check it the pv command is available
	 *
	 * @return void
	 */
	private function check_for_pv() {
		$this->has_pv = ! empty( Helpers::cli_command( 'which pv' ) );
		if ( ! $this->has_pv ) {
			WP_CLI::warning( "You may wish to install 'pv' to see progress when running this command." );
		}
	}

	/**
	 * Check it the local wp command is available and where it's located
	 *
	 * @return void
	 */
	private function find_local_wp() {
		// Possible `wp` locations, with the most preferable ones first
		$possible_paths = [
			'./vendor/bin/wp',
			'~/.config/composer/vendor/bin/wp',
			'/opt/homebrew/bin/wp',
			'/usr/local/bin/wp',
			'wp',
		];
		foreach ( $possible_paths as $path ) {
			if ( ! empty( Helpers::cli_command( "which '{$path}'" ) ) ) {
				$this->local_wp = $path;

				return;
			}
		}
	}

	/**
	 * Check it the remote wp command is available and where it's located
	 *
	 * @return void
	 */
	private function find_remote_wp() {
		// Possible `wp` locations, with the most preferable ones first
		$possible_paths = [
			"{$this->settings['ssh_path']}/vendor/bin/wp",
			'~/.config/composer/vendor/bin/wp',
			'/usr/local/bin/wp',
			'wp',
		];
		foreach ( $possible_paths as $path ) {
			// Try remote WP-CLI
			$command            = "{$this->settings['ssh_command']} \"bash -c \\\"test -f {$path} && echo true || echo false\\\"\"";
			$live_server_status = exec( $command );
			if ( $live_server_status === 'true' ) {
				$this->remote_wp = $path;
				break;
			}
		}

		if ( ! $this->remote_wp ) {
			WP_CLI::error( "Cannot find WP-CLI at {$this->settings['ssh_user']}@{$this->settings['ssh_host']}" );
		}
	}

	/**
	 * Get the user's command arguments
	 *
	 * @param array $assoc_args User arguments
	 *
	 * @return void
	 */
	private function get_options( array $assoc_args ) {
		$true_values = [ true, 'true', 1, '1', 'yes' ];
		if ( isset( $assoc_args['database'] ) ) {
			$this->options['database'] = in_array( $assoc_args['database'], $true_values, true );
		}
		if ( isset( $assoc_args['urls'] ) ) {
			$this->options['urls'] = in_array( $assoc_args['urls'], $true_values, true );
		}
		if ( isset( $assoc_args['uploads'] ) ) {
			$this->options['uploads'] = in_array( $assoc_args['uploads'], $true_values, true );
		}
	}

	/**
	 * Can the remote website be accessed
	 *
	 * @return bool
	 */
	private function has_remote_access(): bool {
		$this->settings['ssh_command'] = "ssh -q -p {$this->settings['ssh_port']} {$this->settings['ssh_user']}@{$this->settings['ssh_host']}";

		// Try SSH
		$command            = "{$this->settings['ssh_command']} exit; echo $?";
		$live_server_status = Helpers::cli_command( $command );
		if ( $live_server_status === '255' ) {
			WP_CLI::error( "Cannot connect to {$this->settings['ssh_user']}@{$this->settings['ssh_host']} over SSH" );
		}

		return true;
	}

	/**
	 * Reusable title output for CLI feedback
	 *
	 * @param string $title Title text
	 *
	 * @return void
	 */
	private function print_action_title( string $title ) {
		WP_CLI::log( WP_CLI::colorize( '%b' ) );
		WP_CLI::log( strtoupper( $title ) );
		WP_CLI::log( WP_CLI::colorize( str_pad( '', strlen( $title ), '~' ) . '%n' ) );
	}

	/**
	 * Overwrite the local database using the remote one
	 *
	 * @return void
	 */
	private function fetch_database() {
		$this->print_action_title( 'Fetching database' );

		// Does the remote server support the "gtid-purged" setting?
		// Why? ... Modern MySQL servers with replication need this disabled for transferable DB dumps but MariaDB will error if it's used
		$gtid_purged_command = $this->settings['ssh_command'] . ' "bash -c \'cd ' . $this->settings['ssh_path'] . ' && ' . $this->remote_wp . ' db query \"SHOW VARIABLES LIKE \\\'gtid_purged\\\'\\G\"\'"';
		$gtid_purged_exists = ! empty( trim( shell_exec( $gtid_purged_command ) ?? '' ) );
		$gtid_purged_flag = $gtid_purged_exists ? '--set-gtid-purged=OFF' : '';

		$pipe    = $this->has_pv ? ' | pv | ' : ' | ';
		$command = "{$this->settings['ssh_command']} \"bash -c \\\"cd {$this->settings['ssh_path']} && {$this->remote_wp} db export --quiet --single-transaction {$gtid_purged_flag} - | gzip -cf\\\"\" {$pipe} gunzip -c | {$this->local_wp} db import --quiet -";
		system( $command );
	}

	/**
	 * Replace URLs in the local database using remote and local home URLs
	 *
	 * @return void
	 */
	private function replace_urls() {
		$this->print_action_title( 'Replacing URLs' );

		$get_remote_home_url_command = "{$this->settings['ssh_command']} \"cd {$this->settings['ssh_path']} && {$this->remote_wp} option get home\"";
		$remote_home_url = trim( shell_exec( $get_remote_home_url_command ) );

		$get_local_home_url_command = "{$this->local_wp} option get home";
		$local_home_url = trim( shell_exec( $get_local_home_url_command ) );

		$search_replace_command = "{$this->local_wp} search-replace '{$remote_home_url}' '{$local_home_url}' --skip-columns=guid --quiet";
		system( $search_replace_command );
	}

	/**
	 * Put Stripe in test mode if applicable
	 *
	 * @return void
	 */
	private function enable_stripe_test_mode() {
		if ( $this->is_plugin_available_and_active( 'woocommerce-gateway-stripe/woocommerce-gateway-stripe.php' ) ) {
			WP_CLI::log( 'Enabling Stripe test mode' );
			$option             = get_option( 'woocommerce_stripe_settings' );
			$option['testmode'] = 'yes';
			update_option( 'woocommerce_stripe_settings', $option );
		}
	}

	/**
	 * Download remote uploaded files
	 *
	 * @return void
	 */
	private function fetch_uploads() {
		$this->print_action_title( 'Fetching uploads' );

		// Get local dir
		$response = wp_upload_dir();
		$local_dir = escapeshellarg( $response['basedir'] . '/' );

		// Get remote dir
		$command = "{$this->settings['ssh_command']} \"bash -c \\\"cd {$this->settings['ssh_path']} && {$this->remote_wp} config get WP_CONTENT_DIR\\\"\"";
		$response = Helpers::cli_command( $command );
		$remote_dir = escapeshellarg( $response[0] . '/uploads/' );

		// Run as a system command (rather than using the helper) so we see output
		$command = "rsync -avhP --port={$this->settings['ssh_port']} {$this->settings['ssh_user']}@{$this->settings['ssh_host']}:{$remote_dir} {$local_dir}";
		system( $command );
	}

	/**
	 * Activate named plugins
	 *
	 * @return void
	 */
	private function activate_plugins() {
		if ( empty( $this->settings['plugins']['activate'] ) ) {
			return;
		}

		$this->print_action_title( 'Activating Plugins' );

		foreach ( $this->settings['plugins']['activate'] as $plugin ) {
			if ( $this->is_plugin_available( $plugin ) ) {
				if ( ! $this->is_plugin_active( $plugin ) ) {
					WP_CLI::log( $plugin );
					Helpers::wp_command( "plugin install {$plugin} --activate" );
				} else {
					WP_CLI::warning( "Plugin {$plugin} is already active" );
				}
			} else {
				WP_CLI::warning( "Plugin {$plugin} is not available to activate" );
			}
		}
	}

	/**
	 * Deactivate named plugins
	 *
	 * @return void
	 */
	private function deactivate_plugins() {
		if ( empty( $this->settings['plugins']['deactivate'] ) ) {
			return;
		}

		$this->print_action_title( 'Deactivating Plugins' );

		foreach ( $this->settings['plugins']['deactivate'] as $plugin ) {
			if ( $this->is_plugin_available( $plugin ) ) {
				if ( $this->is_plugin_active( $plugin ) ) {
					WP_CLI::log( $plugin );
					Helpers::wp_command( "plugin deactivate {$plugin}" );
				} else {
					WP_CLI::warning( "Plugin {$plugin} is already inactive" );
				}
			} else {
				WP_CLI::warning( "Plugin {$plugin} is not available to deactivate" );
			}
		}
	}

	/**
	 * Check if a plugin is installed
	 *
	 * @param string $plugin_slug Plugin name
	 *
	 * @return bool
	 */
	private function is_plugin_available( string $plugin_slug ): bool {
		$installed_plugins = get_plugins();

		foreach ( $installed_plugins as $plugin ) {
			if ( $plugin['TextDomain'] === $plugin_slug ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if a plugin is enabled
	 *
	 * @param string $plugin_slug Plugin name
	 *
	 * @return bool
	 */
	private function is_plugin_active( string $plugin_slug ): bool {
		$result = Helpers::wp_command( "plugin status {$plugin_slug}" );

		return str_contains( $result, 'Status: Active' );
	}

	/**
	 * Check if a plugin is installed and enabled
	 *
	 * @param string $plugin_slug Plugin name
	 *
	 * @return bool
	 */
	private function is_plugin_available_and_active( string $plugin_slug ): bool {
		return $this->is_plugin_available( $plugin_slug ) && $this->is_plugin_active( $plugin_slug );
	}

	/**
	 * Get a setting from wherever this website has placed them
	 *
	 * @param string $item Setting name
	 *
	 * @return array|false|mixed|string|void|null
	 */
	private function get_config_item( string $item ) {
		if ( defined( $item ) ) {
			return constant( $item );
		}
		if ( getenv( $item ) ) {
			return getenv( $item );
		}
		if ( class_exists( '\Roots\WPConfig\Config' ) ) {
			try {
				return \Roots\WPConfig\Config::get( $item );
			} catch ( \Roots\WPConfig\Exceptions\UndefinedConfigKeyException $e ) {
				return null;
			}
		}
	}

	/**
	 * Clear any caches etc. that might benefit from that after a data refresh
	 *
	 * @return void
	 */
	private function clear_caches() {
		$this->print_action_title( 'Clearing caches' );

		Helpers::wp_command( 'rewrite flush', null, false );
		Helpers::wp_command( 'transient delete --all', null, false );
		Helpers::wp_command( 'cache flush', null, false );
	}
}
