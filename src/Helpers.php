<?php
/**
 * Useful methods that are used across many commands
 *
 * @package eighteen73/wpi-cli-tools
 */

namespace Eighteen73\WP_CLI;

use WP_CLI;

/**
 * Useful methods that are used across many commands
 */
class Helpers {

	/**
	 * Current working directory
	 *
	 * @var string
	 */
	private static string $cwd;

	/**
	 * WordPress directory
	 *
	 * @var string
	 */
	private static string $wp_dir;

	/**
	 * Find the important directory paths
	 *
	 * @return void
	 */
	public static function paths() {
		self::$cwd = getcwd();

		$modern_path = self::$cwd . '/web/wp';
		if ( file_exists( $modern_path ) && is_dir( $modern_path ) ) {
			self::$wp_dir = $modern_path;
		} else {
			self::$wp_dir = self::$cwd . '';
		}
	}

	/**
	 * Run a version check and quit if one is needed
	 *
	 * @return void
	 */
	public static function version_check() {
		WP_CLI::runcommand(
			'eighteen73 version',
			[
				'exit_error' => true,
				'return'     => false,
			]
		);
	}

	/**
	 * Run a command on the CLI
	 *
	 * @param string $command Command
	 * @return mixed
	 */
	public static function cli_command( $command ) {
		$command = self::prepare_command( $command );
		exec( $command, $output, $result );
		return $output;
	}

	/**
	 * Run and Git command
	 *
	 * @param string $command Command
	 * @param string $working_dir Path
	 * @return mixed
	 */
	public static function git_command( $command, $working_dir = null ) {
		self::paths();
		$full_command = 'git';
		if ( $working_dir ) {
			$full_command .= ' -C ' . escapeshellarg( $working_dir );
		}
		$full_command .= ' ' . self::prepare_command( $command );
		return self::cli_command( $full_command );
	}

	/**
	 * Run a composer command
	 *
	 * @param string $command Command
	 * @param string $working_dir Path
	 * @param bool   $quiet No output
	 * @return mixed
	 */
	public static function composer_command( $command, $working_dir = null, $quiet = true ) {
		self::paths();
		$command = 'composer ' . self::prepare_command( $command );
		if ( $working_dir ) {
			$command .= ' --working-dir=' . escapeshellarg( $working_dir );
		}
		if ( $quiet ) {
			$command .= ' --quiet';
		}
		return self::cli_command( $command );
	}

	/**
	 * Wun a WP-CLI command
	 *
	 * @param string $command Command
	 * @param string $working_dir Path
	 * @param bool   $return Return the response
	 * @return int|mixed|object|null
	 */
	public static function wp_command( $command, $working_dir = null, $return = true ) {
		self::paths();
		$path    = $working_dir ?? self::$wp_dir;
		$command = self::prepare_command( $command ) . ' --path=' . escapeshellarg( $path );
		return WP_CLI::runcommand(
			$command,
			[
				'exit_error' => false,
				'return'     => $return,
			]
		);
	}

	/**
	 * Add an option to the website
	 *
	 * @param string $key Option key
	 * @param string $value Option value
	 * @param bool   $autoload Is autoload
	 * @param string $working_dir Path
	 * @param bool   $json Is JSON
	 * @return int|mixed|object|null
	 */
	public static function wp_add_option( $key, $value, $autoload, $working_dir = null, $json = false ) {
		$command = 'option add ' . $key . ' ' . $value;
		if ( $autoload ) {
			$command .= ' --autoload=yes';
		}
		if ( $json ) {
			$command .= ' --format=json';
		}
		return self::wp_command( $command, $working_dir );
	}

	/**
	 * Update and existing an option on the website
	 *
	 * @param string $key Option key
	 * @param string $value Option value
	 * @param string $working_dir Path
	 * @param bool   $json Is JSON
	 * @return int|mixed|object|null
	 */
	public static function wp_update_option( $key, $value, $working_dir = null, $json = false ) {
		$command = 'option update ' . $key . ' ' . $value;
		if ( $json ) {
			$command .= ' --format=json';
		}
		return self::wp_command( $command, $working_dir );
	}

	/**
	 * Prepare a command and it's options for execution
	 *
	 * @param mixed $command Command parts
	 * @return string
	 */
	private static function prepare_command( $command ) {
		if ( is_string( $command ) ) {
			$command = [ $command ];
		}

		$cmd = '';
		foreach ( $command as $part ) {
			if ( is_string( $part ) ) {
				$cmd .= " {$part}";
				continue;
			}
			foreach ( $part as $param => $param_value ) {
				if ( $param_value === null ) {
					$cmd .= " --{$param}";
				} else {
					$cmd .= " --{$param}={$param_value}";
				}
			}
		}
		return trim( $cmd );
	}
}
