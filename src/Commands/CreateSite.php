<?php
/**
 * Handles a complete eighteen73 WordPress installation and configuration
 *
 * @package eighteen73/wpi-cli-tools
 */

namespace Eighteen73\WP_CLI\Commands;

use Eighteen73\WP_CLI\Helpers;
use Eighteen73\WP_CLI\Traits\WritesConfig;
use Laravel\Prompts\ConfirmPrompt;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\SelectPrompt;
use Laravel\Prompts\Support\Logger;
use Laravel\Prompts\TextPrompt;
use RuntimeException;
use WP_CLI;
use WP_CLI_Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;
use function Laravel\Prompts\task;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

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
		'skip-deployer' => false,
		'nebula-branch' => null,
	];

	/**
	 * Supplied values passed privately to Nebula's setup process.
	 *
	 * @var array
	 */
	private array $setup_options = [];

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
	 * [--skip-deployer]
	 * : Do not install Deployer
	 *
	 * [--nebula-branch]
	 * : Specify a Nebula branch to use (for development purposes)
	 *
	 * [--nebula-tools-path=<path>]
	 * : Install a copy of a local nebula-tools checkout (development only)
	 *
	 * [--yes]
	 * : Skip the installation directory confirmation
	 *
	 * [--hostname=<hostname>]
	 * : Website hostname without a scheme or path (defaults to HTTPS)
	 *
	 * [--[no-]https]
	 * : Set HTTPS explicitly; --no-https uses HTTP
	 *
	 * [--db-name=<name>]
	 * : Database name; create it if missing, but stop if it contains tables
	 *
	 * [--db-user=<user>]
	 * : Database username
	 *
	 * [--db-password=<password>]
	 * : Database password (an explicitly empty value is allowed)
	 *
	 * [--admin-user=<username>]
	 * : WordPress administrator username
	 *
	 * [--admin-email=<email>]
	 * : WordPress administrator email address
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
		$this->configure_prompts();
		intro( 'Create an eighteen73 WordPress website' );

		// Check options
		$this->check_args( $assoc_args );

		// Check PHP and node versions
		$this->check_path( $args[0], \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false ) );

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

		if ( ! $this->options['skip-deployer'] ) {
			$this->status_message( 'Installing Deployer...' );
			$this->install_deployer();
		}

		if ( $this->options['multisite'] ) {
			warning( 'Refer to https://docs.eighteen73.co.uk/wordpress/build-tools/nebula for multisite Apache/NGINX configuration.' );
		}

		$details = [
			[ 'URL', $this->site_url ],
			[ 'Admin', $this->site_url . '/wp/wp-login.php' ],
			[ 'Username', $this->site_username ],
			[ 'Password', $this->site_password ],
		];
		if ( $this->options['multisite'] ) {
			$details[] = [ 'Network admin', $this->site_url . '/wp/wp-admin/network' ];
		}
		table( [ 'Website details', 'Value' ], $details );
		outro( $this->options['multisite'] ? 'Your multisite is ready.' : 'Your website is ready.' );
	}

	/**
	 * Configure plain input for terminals that cannot render interactive prompts.
	 *
	 * @return void
	 */
	private function configure_prompts() {
		Prompt::fallbackWhen( PHP_OS_FAMILY === 'Windows' || ! stream_isatty( STDIN ) || ! stream_isatty( STDOUT ) || getenv( 'TERM' ) === 'dumb' );

		TextPrompt::fallbackUsing(
			function ( TextPrompt $prompt ) {
				do {
					$value = $this->read_response( $prompt->label, $prompt->default );
					if ( $prompt->transform ) {
						$value = ( $prompt->transform )( $value );
					}
					$error = $prompt->required && $value === '' ? 'A value is required.' : null;
					if ( ! $error && is_callable( $prompt->validate ) ) {
						$error = ( $prompt->validate )( $value );
					}
					if ( $error ) {
						WP_CLI::warning( $error );
					}
				} while ( $error );

				return $value;
			}
		);

		ConfirmPrompt::fallbackUsing(
			function ( ConfirmPrompt $prompt ) {
				return strtolower( $this->read_response( $prompt->label . ' [y/n]', $prompt->default ? 'y' : 'n' ) ) === 'y';
			}
		);

		SelectPrompt::fallbackUsing(
			function ( SelectPrompt $prompt ) {
				$keys = array_keys( $prompt->options );
				$options = array_values( $prompt->options );
				foreach ( $options as $key => $label ) {
					WP_CLI::log( "  [{$key}] {$label}" );
				}
				do {
					$value = $this->read_response( $prompt->label, (string) array_search( $prompt->default, $keys, true ) );
				} while ( ! array_key_exists( $value, $options ) );

				return $keys[ $value ];
			}
		);
	}

	/**
	 * Read a plain response, stopping safely if input has ended.
	 *
	 * @param string $label Question to display
	 * @param string $default Default response
	 * @return string
	 */
	private function read_response( string $label, string $default = '' ): string {
		WP_CLI::log( $label . ( $default !== '' ? " [{$default}]" : '' ) );
		WP_CLI::out( '> ' );
		$response = fgets( STDIN );
		if ( $response === false ) {
			WP_CLI::error( 'Input ended before setup was complete. Run create-site in an interactive terminal or supply the remaining answers on standard input.' );
		}
		$response = trim( $response );
		return $response === '' ? $default : $response;
	}

	/**
	 * Output consistently formatted messages to the user
	 *
	 * @param string $message Text to output
	 * @return void
	 */
	private function status_message( string $message ) {
		note( $message );
	}

	/**
	 * Run a non-interactive process with bounded live output on supported terminals.
	 *
	 * @param string      $command Shell command
	 * @param string      $label Task label
	 * @param string|null $working_dir Working directory
	 * @return void
	 */
	private function run_task( string $command, string $label, ?string $working_dir = null ): void {
		$output = '';
		$run = function ( ?Logger $logger = null ) use ( $command, $working_dir, &$output ) {
			$descriptors = [
				0 => [ 'pipe', 'r' ],
				1 => [ 'pipe', 'w' ],
				2 => [ 'redirect', 1 ],
			];
			$process = proc_open( $command, $descriptors, $pipes, $working_dir );
			if ( ! is_resource( $process ) ) {
				throw new RuntimeException( 'Could not start the installation process.' );
			}
			fclose( $pipes[0] );
			try {
				while ( ( $line = fgets( $pipes[1] ) ) !== false ) {
					$output .= $line;
					foreach ( explode( "\r", rtrim( $line, "\r\n" ) ) as $message ) {
						if ( $logger ) {
							$logger->line( $message );
						} else {
							WP_CLI::log( $message );
						}
					}
				}
			} finally {
				fclose( $pipes[1] );
				$status = proc_close( $process );
			}
			if ( $status !== 0 ) {
				throw new RuntimeException( "Process exited with status {$status}." ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI diagnostic, WordPress is not loaded.
			}
		};

		try {
			if ( PHP_OS_FAMILY !== 'Windows' && stream_isatty( STDIN ) && stream_isatty( STDOUT ) && getenv( 'TERM' ) !== 'dumb' && function_exists( 'pcntl_fork' ) && function_exists( 'posix_kill' ) && ! WP_CLI::get_config( 'quiet' ) && WP_CLI::get_config( 'color' ) !== false ) {
				task( label: $label, callback: $run, limit: 6, keepSummary: true );
			} else {
				$run();
				WP_CLI::log( $label . ' Done.' );
			}
		} catch ( RuntimeException $exception ) {
			WP_CLI::error( $label . "\n" . $exception->getMessage() . "\n" . $output );
		}
	}

	/**
	 * Run Composer without allowing questions inside a task display.
	 *
	 * @param string      $command Composer arguments
	 * @param string|null $working_dir Working directory
	 * @param string      $label Task label
	 * @return void
	 */
	private function composer_command( string $command, ?string $working_dir = null, string $label = 'Installing Composer dependencies...' ): void {
		$this->run_task( 'composer --no-interaction --no-ansi ' . $command, $label, $working_dir );
	}

	/**
	 * Validation the user's options
	 *
	 * @param array $assoc_args Arguments
	 * @return void
	 */
	private function check_args( array $assoc_args ) {
		if ( array_key_exists( 'nebula-tools-path', $assoc_args ) ) {
			$path = $assoc_args['nebula-tools-path'];
			if ( ! is_string( $path ) || $path === '' || preg_match( '/[\x00\r\n]/', $path ) ) {
				WP_CLI::error( 'Option --nebula-tools-path requires a local directory path.' );
			}
			$path = realpath( $path );
			if ( $path === false || ! is_readable( $path . '/composer.json' ) ) {
				WP_CLI::error( 'The --nebula-tools-path directory must contain a readable composer.json.' );
			}
			$package = json_decode( file_get_contents( $path . '/composer.json' ), true );
			if ( ! is_array( $package ) || ( $package['name'] ?? null ) !== 'eighteen73/nebula-tools' ) {
				WP_CLI::error( 'Option --nebula-tools-path must point to an eighteen73/nebula-tools checkout.' );
			}
			$this->options['nebula-tools-path'] = $path;
		}

		foreach ( [ 'hostname', 'db-name', 'db-user', 'db-password', 'admin-user', 'admin-email' ] as $option ) {
			if ( ! array_key_exists( $option, $assoc_args ) ) {
				continue;
			}
			$value = $assoc_args[ $option ];
			if ( ! is_string( $value ) || preg_match( '/[\x00\r\n]/', $value ) ) {
				WP_CLI::error( "Option --{$option} requires a single-line value." );
			}
			if ( $option !== 'db-password' ) {
				$value = trim( $value );
				if ( $value === '' ) {
					WP_CLI::error( "Option --{$option} must not be empty." );
				}
			}
			if ( in_array( $option, [ 'hostname', 'admin-user', 'admin-email' ], true ) ) {
				$value = strtolower( $value );
			}
			if ( $option === 'hostname' && ! filter_var( $value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME ) ) {
				WP_CLI::error( 'Use --hostname with a hostname only, without a scheme, port or path.' );
			}
			if ( $option === 'db-name' && ( strlen( $value ) > 64 || ! preg_match( '/^[a-zA-Z0-9_-]+$/', $value ) ) ) {
				WP_CLI::error( 'Database names must contain only letters, numbers, underscores or hyphens, with at most 64 characters.' );
			}
			if ( $option === 'admin-email' && ! filter_var( $value, FILTER_VALIDATE_EMAIL ) ) {
				WP_CLI::error( 'Enter a valid --admin-email address.' );
			}
			if ( str_starts_with( $option, 'admin-' ) ) {
				$this->options[ $option ] = $value;
			} else {
				$this->setup_options[ $option ] = $value;
			}
		}
		if ( array_key_exists( 'https', $assoc_args ) ) {
			if ( ! is_bool( $assoc_args['https'] ) ) {
				WP_CLI::error( 'Use --https or --no-https without a value.' );
			}
			$this->setup_options['https'] = $assoc_args['https'];
		}

		// Simply options (no value allowed)
		$options = [ 'multisite', 'woocommerce', 'spark', 'skip-deployer' ];
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
	 * @param bool   $yes Skip the installation confirmation
	 * @return void
	 */
	private function check_path( string $site_name, bool $yes = false ) {
		if ( preg_match( '/^\//', $site_name ) ) {
			$this->install_directory = rtrim( $site_name, '/' );
			$this->site_name         = basename( $site_name );
		} else {
			$this->install_directory = rtrim( getcwd(), '/' ) . '/' . rtrim( $site_name, '/' );
			$this->site_name         = $site_name;
		}
		$this->wp_directory = $this->install_directory . '/web/wp';

		// Get confirmation
		if ( ! $yes && ! confirm(
			label: "Install \"{$this->site_name}\" to \"{$this->install_directory}\"?",
			default: false,
		) ) {
			WP_CLI::halt( 0 );
		}

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
		$command = 'create-project --no-scripts --stability=dev eighteen73/nebula ' . escapeshellarg( $this->install_directory );
		if ( $this->options['nebula-branch'] ) {
			$command .= ' ' . escapeshellarg( 'dev-' . $this->options['nebula-branch'] );
		}

		$this->composer_command( $command, null, 'Installing Nebula...' );
		if ( isset( $this->options['nebula-tools-path'] ) ) {
			$this->install_local_nebula_tools();
		}
		if ( $this->setup_options && ! file_exists( $this->install_directory . '/vendor/eighteen73/nebula-tools/includes/classes/SetupOptions.php' ) ) {
			WP_CLI::error( 'The installed nebula-tools release does not support supplied setup values. Update Nebula to use the coordinated nebula-tools release before retrying.' );
		}

		// The wizard needs the real terminal, after the dependency task has closed.
		$descriptors = [
			0 => STDIN,
			1 => STDOUT,
			2 => STDERR,
		];
		$environment = array_merge( getenv(), [ 'NEBULA_SETUP_OPTIONS' => json_encode( $this->setup_options, JSON_THROW_ON_ERROR ) ] );
		$process = proc_open( 'composer run-script post-create-project-cmd', $descriptors, $pipes, $this->install_directory, $environment );
		if ( ! is_resource( $process ) || proc_close( $process ) !== 0 ) {
			WP_CLI::error( 'Nebula configuration failed. Site creation has stopped.' );
		}

		$this->configure_project_composer();
		$this->composer_command( 'update', $this->install_directory, 'Updating Nebula dependencies...' );
	}

	/**
	 * Configure the target hosting runtime and advisory reporting.
	 *
	 * @return void
	 */
	private function configure_project_composer(): void {
		$repository = [
			'type' => 'composer',
			'url' => 'https://repo-wpsecadv.typist.tech',
			'only' => [ 'roots/wordpress', 'roots/wordpress-no-content', 'wpackagist-plugin/*', 'wpackagist-theme/*' ],
		];
		$this->composer_command( 'require --no-update ' . escapeshellarg( 'php:~8.5.0' ) . ' ' . escapeshellarg( 'composer:>=2.10' ), $this->install_directory );
		$this->composer_command( 'config platform.php 8.5', $this->install_directory );
		$this->composer_command( 'config policy.advisories.block false', $this->install_directory );
		$this->composer_command( 'config --unset policy.advisories.ignore-severity', $this->install_directory );
		$this->composer_command(
			'config repositories.wpsecadv ' . escapeshellarg( json_encode( $repository, JSON_THROW_ON_ERROR ) ),
			$this->install_directory
		);
	}

	/**
	 * Install a local development copy without symlinking or changing its checkout.
	 *
	 * @return void
	 */
	private function install_local_nebula_tools(): void {
		$repository = [
			'type' => 'path',
			'url' => $this->options['nebula-tools-path'],
			'options' => [
				'symlink' => false,
				'reference' => 'none',
				'versions' => [ 'eighteen73/nebula-tools' => '2.0.x-dev' ],
			],
		];
		warning( 'Development install: the generated project will retain a local nebula-tools path repository. Do not deploy it with this configuration.' );
		$this->composer_command(
			'config repositories.nebula-tools-local ' . escapeshellarg( json_encode( $repository, JSON_THROW_ON_ERROR ) ),
			$this->install_directory,
			'Configuring local Nebula tools...'
		);
		$this->composer_command(
			'update eighteen73/nebula-tools --with-dependencies --no-scripts',
			$this->install_directory,
			'Installing local Nebula tools...'
		);
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
		$this->composer_command( 'create-project eighteen73/pulsar ' . escapeshellarg( $this->install_directory . '/web/app/themes/pulsar' ) . ' --stability=dev', null, 'Installing Pulsar...' );
		Helpers::wp_command( 'theme activate pulsar', $this->wp_directory );

		$new_ignore = "!web/app/themes/pulsar/\n";
		$this->add_config_lines(
			config_filepath: "{$this->install_directory}/.gitignore",
			new_lines: $new_ignore,
			after_line_containing: 'your-custom-theme'
		);

		$this->commit_repo( 'Add Pulsar theme' );
		WP_CLI::log( '   ... done' );
	}

	/**
	 * Install Packages
	 *
	 * @return void
	 */
	private function install_node_packages() {
		$this->run_task( 'npm install', 'Installing Node packages...', $this->install_directory );
		$this->run_task( 'npm run prepare', 'Preparing Node packages...', $this->install_directory );
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

		$this->site_username = $this->options['admin-user'] ?? text(
			label: 'Admin username',
			required: true,
			transform: fn( string $value ) => strtolower( trim( $value ) ),
		);

		$this->site_email = $this->options['admin-email'] ?? text(
			label: 'Admin email address',
			placeholder: 'you@example.com',
			required: true,
			validate: fn( string $value ) => filter_var( $value, FILTER_VALIDATE_EMAIL ) ? null : 'Enter a valid email address.',
			transform: fn( string $value ) => strtolower( trim( $value ) ),
		);

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
		$htaccess_filepath = "{$this->install_directory}/web/.htaccess";

		// Gather the multisite options
		$option = select(
			label: 'How should sites in the network be addressed?',
			options: [
				'subdirectories' => 'Sub-directories',
				'subdomains' => 'Sub-domains',
			],
			default: 'subdirectories',
		);
		$subdomain_install = $option === 'subdomains';

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

		$domain_current_site = substr( $this->site_url, strpos( $this->site_url, '//' ) + 2 );

		// Write the new config
		$multisite_config = [
			"Config::define( 'WP_ALLOW_MULTISITE', true );",
			"Config::define( 'MULTISITE', true );",
			"Config::define( 'SUBDOMAIN_INSTALL', " . ( $subdomain_install ? 'true' : 'false' ) . ' );',
			"Config::define( 'DOMAIN_CURRENT_SITE', \$_ENV['DOMAIN_CURRENT_SITE'] );",
			"Config::define( 'PATH_CURRENT_SITE', '/' );",
			"Config::define( 'SITE_ID_CURRENT_SITE', 1 );",
			"Config::define( 'BLOG_ID_CURRENT_SITE', 1 );",
		];
		$this->add_config_file( 'multisite.php', implode( "\n", $multisite_config ) );

		$new_dotenv = "\n";
		$new_dotenv .= "# Multisite\n";
		$new_dotenv .= "DOMAIN_CURRENT_SITE=\"\"\n";
		$this->add_config_lines(
			config_filepath: "{$this->install_directory}/.env.example",
			new_lines: $new_dotenv,
			after_line_containing: 'WP_SITEURL'
		);

		$new_dotenv = "\n";
		$new_dotenv .= "# Multisite\n";
		$new_dotenv .= "DOMAIN_CURRENT_SITE=\"{$domain_current_site}\"\n";
		$this->add_config_lines(
			config_filepath: "{$this->install_directory}/.env",
			new_lines: $new_dotenv,
			after_line_containing: 'WP_SITEURL'
		);

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

		// Do the install
		$output = Helpers::wp_command(
			[
				'core multisite-install',
				$options,
			],
			$this->wp_directory
		);

		return $output;
	}

	/**
	 * Install our preferred plugins
	 *
	 * @return void
	 */
	private function install_plugins() {
		$plugins = [
			'eighteen73-plugin/kinsta-mu-plugins' => [
				'activate' => true,
				'dev' => false,
			],
			'eighteen73/block-theme-developer' => [
				'activate' => true,
				'dev' => true,
			],
			'eighteen73/pulsar-extensions' => [
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
			'wpackagist-plugin/docket-cache' => [
				'activate' => true,
				'dev' => false,
				'version' => '^26.4.11',
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
			'wpackagist-plugin/simple-smtp' => [
				'activate' => true,
				'dev' => false,
			],
			'wpackagist-plugin/two-factor' => [
				'activate' => true,
				'dev' => false,
			],
		];

		// Add our repo
		$this->composer_command( 'config repositories.eighteen73 composer https://code.eighteen73.co.uk/pkg/wordpress', $this->install_directory, 'Configuring the plugin repository...' );

		// Prepare Docket's configuration and build hooks before installing plugins.
		$this->configure_docket_cache();

		// Get the plugins
		$production_plugins = [];
		$development_plugins = [];
		foreach ( $plugins as $plugin => $options ) {
			$requirement = escapeshellarg( $plugin . ( isset( $options['version'] ) ? ':' . $options['version'] : '' ) );
			if ( $options['dev'] ) {
				$development_plugins[] = $requirement;
			} else {
				$production_plugins[] = $requirement;
			}
		}
		$this->composer_command( 'require ' . implode( ' ', $production_plugins ), $this->install_directory );
		$this->composer_command( 'require --dev ' . implode( ' ', $development_plugins ), $this->install_directory );

		// Activate the plugins
		$plugins_to_activate = [];
		foreach ( $plugins as $plugin => $options ) {
			if ( ! $options['activate'] ) {
				continue;
			}
			$plugins_to_activate[] = substr( $plugin, strrpos( $plugin, '/' ) + 1 );
		}
		Helpers::wp_command( 'plugin activate ' . implode( ' ', $plugins_to_activate ), $this->wp_directory );

		// Imsanity: constrain new uploads without cropping or bulk processing.
		$imsanity_options = [
			'imsanity_max_width' => 2560,
			'imsanity_max_height' => 2560,
			'imsanity_max_width_library' => 2560,
			'imsanity_max_height_library' => 2560,
			'imsanity_max_width_other' => 2560,
			'imsanity_max_height_other' => 2560,
			'imsanity_quality' => 82,
			'imsanity_bmp_to_jpg' => 1,
			'imsanity_png_to_jpg' => 0,
		];
		foreach ( $imsanity_options as $option => $value ) {
			Helpers::wp_update_option( $option, (string) $value, $this->wp_directory );
		}

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

		$new_config = "\n";
		$new_config .= "// Local mail catcher\n";
		$new_config .= "Config::define( 'SMTP_HOST', '127.0.0.1' );\n";
		$new_config .= "Config::define( 'SMTP_PORT', {$port} );\n";
		$new_config .= "Config::define( 'SMTP_USER', '' );\n";
		$new_config .= "Config::define( 'SMTP_PASS', '' );\n";
		$new_config .= "Config::define( 'SMTP_FROM', '' );\n";
		$new_config .= "Config::define( 'SMTP_FROMNAME', '' );\n";
		$new_config .= "Config::define( 'SMTP_SEC', 'off' );\n";
		$new_config .= "Config::define( 'SMTP_AUTH', false );\n";
		$this->add_config_lines(
			config_filepath: "{$this->install_directory}/config/environments/development.php",
			new_lines: $new_config,
			append: true,
		);

		// Kinsta config
		$kinsta_config = [
			'$mu_plugins_url = Config::get( \'WP_CONTENT_URL\' ) . \'/mu-plugins\';',
			"Config::define( 'KINSTA_CDN_USERDIRS', 'app' );",
			'Config::define( \'KINSTAMU_CUSTOM_MUPLUGIN_URL\', "{$mu_plugins_url}/kinsta-mu-plugins" );',
			"Config::define( 'KINSTAMU_CAPABILITY', 'publish_pages' );",
			"Config::define( 'KINSTAMU_WHITELABEL', true );",
		];
		$this->add_config_file( 'kinsta.php', implode( "\n", $kinsta_config ) );

		$this->commit_repo( 'Add house plugins' );

		WP_CLI::log( '   ... done' );
	}

	/**
	 * Configure persistent object caching and its database-independent build hook.
	 *
	 * @return void
	 */
	private function configure_docket_cache(): void {
		$this->install_project_template( 'config/includes/docket-cache.php' );
		$this->install_project_template( 'scripts/install-docket-cache.php' );
		$this->add_config_lines(
			config_filepath: "{$this->install_directory}/.gitignore",
			new_lines: "\n# Docket Cache generated files (local to each release)\n/web/app/object-cache.php\n/web/app/cache/docket-cache/\n/web/app/docket-cache-data/\n/web/app/.object-cache*\n",
			append: true
		);

		$composer = json_decode( file_get_contents( $this->install_directory . '/composer.json' ), true, 512, JSON_THROW_ON_ERROR );
		$installer = '@php scripts/install-docket-cache.php';
		foreach ( [ 'post-install-cmd', 'post-update-cmd' ] as $event ) {
			$scripts = (array) ( $composer['scripts'][ $event ] ?? [] );
			if ( ! in_array( $installer, $scripts, true ) ) {
				$scripts[] = $installer;
			}
			$this->composer_command( 'config scripts.' . $event . ' ' . implode( ' ', array_map( 'escapeshellarg', $scripts ) ), $this->install_directory );
		}
	}

	/**
	 * Install and configure WooCommerce
	 *
	 * @return void
	 */
	private function install_woocommerce() {
		$this->composer_command( 'require wpackagist-plugin/woocommerce wpackagist-plugin/woocommerce-gateway-stripe', $this->install_directory );
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
		$this->composer_command( 'require eighteen73/spark', $this->install_directory );
		Helpers::wp_command( 'plugin activate spark', $this->wp_directory );

		// Spark config
		$spark_config = [
			"Config::define( 'SPARK_API_URI', \$_ENV['SPARK_API_URI'] ?? '' );",
			"Config::define( 'SPARK_API_USERNAME', \$_ENV['SPARK_API_USERNAME'] ?? '' );",
			"Config::define( 'SPARK_API_PASSWORD', \$_ENV['SPARK_API_PASSWORD'] ?? '' );",
		];
		$this->add_config_file( 'spark.php', implode( "\n", $spark_config ) );

		// Add the domain to .env.example
		$new_dotenv = "\n";
		$new_dotenv .= "# Spark\n";
		$new_dotenv .= "SPARK_API_URI=\n";
		$new_dotenv .= "SPARK_API_USERNAME=\n";
		$new_dotenv .= "SPARK_API_PASSWORD=\n";
		$this->add_config_lines(
			config_filepath: "{$this->install_directory}/.env.example",
			new_lines: $new_dotenv,
			after_line_containing: 'WP_SITEURL'
		);

		// Add the domain to .env
		$new_dotenv = "\n";
		$new_dotenv .= "# Spark\n";
		$new_dotenv .= "SPARK_API_URI=\n";
		$new_dotenv .= "SPARK_API_USERNAME=\n";
		$new_dotenv .= "SPARK_API_PASSWORD=\n";
		$this->add_config_lines(
			config_filepath: "{$this->install_directory}/.env",
			new_lines: $new_dotenv,
			after_line_containing: 'WP_SITEURL'
		);

		$this->commit_repo( 'Add Spark' );

		WP_CLI::log( '   ... done' );
	}


	/**
	 * Install and configure Deployer
	 *
	 * @return void
	 */
	private function install_deployer() {
		$this->composer_command( 'require --dev ' . escapeshellarg( 'eighteen73/wordpress-deployer:^2.0' ), $this->install_directory );

		foreach ( [ 'deploy.php', '.gitlab-ci.yml', 'autoupdate.json', 'renovate.json', 'SETUP.md' ] as $filename ) {
			$this->install_project_template( $filename );
		}

		$this->add_config_lines(
			config_filepath: "{$this->install_directory}/config/environments/development.php",
			new_lines: file_get_contents( dirname( __DIR__, 2 ) . '/templates/create-site/sync.php.dist' ),
			append: true
		);
		$this->add_config_lines(
			config_filepath: "{$this->install_directory}/README.md",
			new_lines: "\n## Project setup\n\nSee [SETUP.md](SETUP.md) for local onboarding, Kinsta deployment and Renovate configuration.\n",
			append: true
		);

		$this->add_config_lines(
			config_filepath: "{$this->install_directory}/web/app/themes/pulsar/.gitignore",
			new_lines: "\n# Assets are built by Deployer\n/build",
			append: true
		);

		Helpers::git_command( 'rm -r --cached web/app/themes/pulsar/build/', $this->install_directory );
		$this->commit_repo( 'Add Deployer and maintenance configuration' );
		note( 'Complete the placeholders and hosting setup in SETUP.md before enabling Renovate or deploying.' );

		WP_CLI::log( '   ... done' );
	}

	/**
	 * Install a new project configuration file without replacing upstream files.
	 *
	 * @param string $filename Project-relative filename
	 * @return void
	 */
	private function install_project_template( string $filename ): void {
		$destination = $this->install_directory . '/' . $filename;
		if ( file_exists( $destination ) ) {
			WP_CLI::error( "Cannot scaffold {$filename}: the file already exists. Reconcile the project template before continuing." );
		}
		$directory = dirname( $destination );
		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0777, true ) ) {
			WP_CLI::error( "Could not create the directory for {$filename}." );
		}
		if ( ! copy( dirname( __DIR__, 2 ) . '/templates/create-site/' . $filename . '.dist', $destination ) ) {
			WP_CLI::error( "Could not create {$filename}." );
		}
	}
}
