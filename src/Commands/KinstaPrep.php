<?php
/**
 * Prepares the website for use with Kinsta
 *
 * @package eighteen73/wpi-cli-tools
 */

namespace Eighteen73\WP_CLI\Commands;

use Eighteen73\WP_CLI\Helpers;
use WP_CLI;
use WP_CLI_Command;

/**
 * Installs and configures the Kinsta MU plugin.
 *
 * ## EXAMPLES
 *
 *     # Prepares the website for use with Kinsta
 *     $ wp eighteen73 kinsta-prep
 *
 * @package eighteen73/wpi-cli-tools
 */
class KinstaPrep extends WP_CLI_Command {

	/**
	 * The project directory
	 *
	 * @var string
	 */
	private string $root_directory;

	/**
	 * Is it a composer project
	 *
	 * @var bool
	 */
	private bool $is_composer;

	/**
	 * Installs and configures the Kinsta MU plugin.
	 *
	 * Works on vanilla, Bedrock, and Nebula websites.
	 *
	 * This is designed to be run locally and the project's Git repository must be in a clean state before the running the command.
	 * It is safe to re-run on a website that already has the plugin.
	 *
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp eighteen73 kinsta-prep
	 *
	 * @when before_wp_load
	 *
	 * @param array $args Arguments
	 * @param array $assoc_args Arguments
	 */
	public function __invoke( array $args, array $assoc_args ) {
		Helpers::version_check();

		/*
		 * Check for WordPress
		 * A wp-cli version check  confirms that `composer install` has been run too
		 */
		$version = Helpers::wp_command( 'core version' );
		if ( empty( $version ) ) {
			WP_CLI::error( 'Not a WordPress directory' );
		}

		// Check for a clean repo
		if ( ! Helpers::repo_is_clean() ) {
			WP_CLI::error( 'The Git repo must not have any uncommitted code. Please commit/stash your changes then try again.' );
		}

		$this->root_directory = Helpers::wp_command( 'config get root_dir' );

		// Nebula/bedrock vs other?
		$this->is_composer = Helpers::has_package( 'roots/wordpress' );

		// Install the plugin
		if ( $this->is_composer ) {
			$this->composerInstall();
		} else {
			$this->manualInstall();
		}

		// Add the config
		$this->addConfig();

		// Make a commit
		$git_dir =
		Helpers::git_command( 'add .' );
		Helpers::git_command( 'commit -m "Add kinsta-mu-plugins"' );

		WP_CLI::success( 'Complete' );
	}


	/**
	 * Install the plugin on a composer project
	 */
	private function composerInstall(): void {
		// Checks for repository existence manually or else re-adding to a list (as opposed to an object) causes a duplicate
		$has_our_repo = false;
		$composer_json = json_decode( file_get_contents( $this->root_directory . '/composer.json' ), true );
		foreach ( $composer_json['repositories'] ?? [] as $repository ) {
			if ( preg_match( '/^https:\/\/code\.(eighteen73|orphans)\.co\.uk\/pkg\/wordpress$/', ( $repository['url'] ?? '' ) ) ) {
				$has_our_repo = true;
				break;
			}
		}
		if ( ! $has_our_repo ) {
			Helpers::composer_command( 'config repositories.eighteen73 composer https://code.eighteen73.co.uk/pkg/wordpress', null, false );
		}

		// Add the plugin (no harm if it's already there)
		Helpers::composer_command( 'require eighteen73-plugin/kinsta-mu-plugins', null, false );
	}

	/**
	 * Install the plugin on a non-composer project
	 */
	private function manualInstall(): void {
		$plugins_dir = getcwd() . '/wp-content/plugins';
		$mu_plugins_dir = getcwd() . '/wp-content/mu-plugins';
		if ( file_exists( $plugins_dir ) && is_dir( $plugins_dir ) ) {
			if ( ! file_exists( $mu_plugins_dir ) ) {
				mkdir( $mu_plugins_dir );
			}
			if ( file_exists( $mu_plugins_dir . '/kinsta-mu-plugins.php' ) ) {
				return;
			}
			$zip_url = 'https://kinsta.com/kinsta-tools/kinsta-mu-plugins.zip';
			$temp_zip = $mu_plugins_dir . '/kinsta-mu-plugins.zip';
			file_put_contents( $temp_zip, file_get_contents( $zip_url ) );
			$zip = new \ZipArchive();
			if ( $zip->open( $temp_zip ) === true ) {
				$zip->extractTo( $mu_plugins_dir );
				$zip->close();
				unlink( $temp_zip );

				return;
			}
		}

		WP_CLI::error( 'Could not install the plugin. Please do it manually using https://kinsta.com/docs/wordpress-hosting/kinsta-mu-plugin/' );
	}

	/**
	 * Add the plugin's config
	 */
	private function addConfig(): void {
		$config_values = [
			'KINSTA_CDN_USERDIRS' => '\'app\'',
			'KINSTAMU_CUSTOM_MUPLUGIN_URL' => '"{$mu_plugins_url}/kinsta-mu-plugins"',
			'KINSTAMU_CAPABILITY' => '\'publish_pages\'',
			'KINSTAMU_WHITELABEL' => 'true',
		];

		// Bedrock/Nebula config
		$config_filepath = $this->root_directory . '/config/application.php';
		if ( file_exists( $config_filepath ) ) {

			// Check it's not already in there and bail if so
			$existing_config = file_get_contents( $config_filepath );
			foreach ( $config_values as $key => $value ) {
				if ( str_contains( $existing_config, $key ) ) {
					WP_CLI::log( 'Config already exists so we\'ll leave it untouched.' );
					return;
				}
			}

			// Add the config after the salts
			$new_config = '';
			$fp         = fopen( $config_filepath, 'r' );
			while ( ! feof( $fp ) ) {
				$line        = fgets( $fp );
				$new_config .= $line;
				if ( ! str_contains( $line, 'NONCE_SALT' ) ) {
					continue;
				}
				$new_config .= "\n";
				$new_config .= "/**\n";
				$new_config .= " * Kinsta\n";
				$new_config .= " */\n";
				$new_config .= '$mu_plugins_url = Config::get( \'WP_CONTENT_URL\' ) . \'/mu-plugins\';' . "\n";
				foreach ( $config_values as $key => $value ) {
					$new_config .= 'Config::define( \'' . $key . '\', ' . $value . ' );' . "\n";
				}
			}
			fclose( $fp );
			file_put_contents( $config_filepath, $new_config );

			return;
		}

		// Vanilla WordPress
		$config_filepath = getcwd() . '/wp-config.php';
		if ( file_exists( $config_filepath ) ) {

			// Check it's not already in there and bail if so
			$existing_config = file_get_contents( $config_filepath );
			foreach ( $config_values as $key => $value ) {
				if ( str_contains( $existing_config, $key ) ) {
					WP_CLI::log( 'Config already exists so we\'ll leave it untouched.' );
					return;
				}
			}

			// Add the config after the salts
			$new_config = '';
			$fp         = fopen( $config_filepath, 'r' );
			while ( ! feof( $fp ) ) {
				$line        = fgets( $fp );
				$new_config .= $line;
				if ( ! str_contains( $line, '$table_prefix' ) ) {
					continue;
				}
				$new_config .= "\n";
				$new_config .= "/**\n";
				$new_config .= " * Kinsta\n";
				$new_config .= " */\n";
				$new_config .= 'define( \'KINSTAMU_CAPABILITY\', \'publish_pages\' );' . "\n";
				$new_config .= 'define( \'KINSTAMU_WHITELABEL\', true );' . "\n";
			}
			fclose( $fp );
			file_put_contents( $config_filepath, $new_config );
		}
	}
}
