<?php

namespace Eighteen73\WP_CLI\Commands;

use WP_CLI;
use WP_CLI_Command;

/**
 * Managed an eighteen73 WordPress installation.
 *
 * ## EXAMPLES
 *
 *     # Install a new WordPress website using Nebula and it's install wizard
 *     $ wp eighteen73 create foobar
 *
 * @package eighteen73/wpi-cli-tools
 */
class CreateSite extends WP_CLI_Command {

	/**
	 * Creates a new website.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The name (directory) for the website.
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp eighteen73 create foobar
	 *
	 * @when before_wp_load
	 */
	public function create( array $args, array $assoc_args ) {

		// Get the installation name and path
		$site_name = $args[0];
		if (preg_match('/^\//', $site_name)) {
			$install_directory = rtrim($site_name, '/');
			$site_name = basename($site_name);
		} else {
			$install_directory = rtrim(getcwd(), '/') . '/' . rtrim($site_name, '/');
		}

		// Get confirmation
		WP_CLI::confirm("Installing \"{$site_name}\" to \"{$install_directory}\". Is this OK?");

		// Is it writable?
		if ( ! is_dir( $install_directory ) ) {
			if ( ! is_writable( dirname( $install_directory ) ) ) {
				WP_CLI::error( "Insufficient permission to create directory '{$install_directory}'." );
			}

			WP_CLI::log( "Creating directory '{$install_directory}'." );
			if ( ! @mkdir( $install_directory, 0777, true /*recursive*/ ) ) {
				$error = error_get_last();
				WP_CLI::error( "Failed to create directory '{$install_directory}': {$error['message']}." );
			}
		}
		if ( ! is_writable( $install_directory ) ) {
			WP_CLI::error( "'{$install_directory}' is not writable by current user." );
		}

		// Use create the Nebula project
		shell_exec('composer create-project --stability=dev eighteen73/nebula ' . escapeshellarg($install_directory) );
		shell_exec('composer update --working-dir=' . escapeshellarg($install_directory) );

		// Init the Git repo
		exec('git -C ' . escapeshellarg($install_directory) . ' init', $output );
		exec('git -C ' . escapeshellarg($install_directory) . ' add .', $output );
		exec('git -C ' . escapeshellarg($install_directory) . ' commit -m "Initial commit"', $output );

		// Add the Pulsar theme
		exec('composer create-project --stability=dev eighteen73/pulsar ' . escapeshellarg($install_directory . '/web/app/themes/pulsar'), $output );
		exec('git -C ' . escapeshellarg($install_directory) . ' add .', $output );
		exec('git -C ' . escapeshellarg($install_directory) . ' commit -m "Add Pulsar theme"', $output );

		// Install WordPress
		$url = null;
		$fp = @fopen( $install_directory . '/.env', 'r' );
		while (($buffer = fgets($fp, 4096)) !== false) {
			if (preg_match('/^WP_HOME="(.+)"$/', $buffer, $matches)) {
				$url = $matches[1];
				break;
			}
		}
		fclose($fp);

		WP_CLI::line();
		WP_CLI::line('Enter your admin username');
		WP_CLI::out('> ');
		$username = strtolower(trim(fgets(STDIN)));

		WP_CLI::line();
		WP_CLI::line('Enter your admin email address');
		WP_CLI::out('> ');
		$email = strtolower(trim(fgets(STDIN)));

		exec( 'wp --path=' . escapeshellarg($install_directory . '/web/wp') . ' core install --skip-email --url=' . escapeshellarg($url . '/web') . ' --title=' . escapeshellarg($site_name ) . ' --admin_user=' . escapeshellarg($username ) . ' --admin_email=' . escapeshellarg($email ), $output );
		exec( 'wp --path=' . escapeshellarg($install_directory . '/web/wp') . ' theme activate pulsar' );

		$password = '';
		foreach ($output as $output_line) {
			if (preg_match('/^Admin password: (.+)/', $output_line, $matches)) {
				$password = trim($matches[1]);
				break;
			}
		}

		WP_CLI::line();
		WP_CLI::line();
		WP_CLI::success( 'Your website is ready.' );
		WP_CLI::line();
		WP_CLI::line('URL:      ' . $url);
		WP_CLI::line('Admin:    ' . $url . '/wp/wp-admin' );
		WP_CLI::line();
		WP_CLI::line('Username: ' . $username);
		WP_CLI::line('Password: ' . $password);
	}
}
