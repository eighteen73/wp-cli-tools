<?php

namespace Eighteen73\WP_CLI\Commands;

use Eighteen73\WP_CLI\Helpers;
use WP_CLI;
use WP_CLI_Command;

/**
 * Get a remote website's database and sets it up locally (prior to when the regular sync command can be run).
 * Requires the website's code and .env to pre-prepared.
 *
 * ## EXAMPLES
 *
 *     # Install an existing WordPress website using a remote database
 *     $ wp eighteen73 first-sync
 *
 * @package eighteen73/wpi-cli-tools
 */
class FirstSync extends WP_CLI_Command {

	/**
	 * Import the database from a remote website.
	 *
	 * This is a use-once script to get a website running using a remote database, however the usual sync script
	 * can't be run until WordPress can be bootstrapped, so this just does a quick clean install before running that
	 * command.
	 *
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp eighteen73 first-sync
	 *
	 * @when before_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ) {

		/*
		 * Check for WordPress
		 * A wp-cli version check  confirms that `composer install` has been run too
		 */
		$version = Helpers::wp_command('core version');
		if (empty($version)) {
			WP_CLI::error('Not a WordPress directory');
		}

		/*
		 * Check if it's already installed
		 */
		try {
			$already_installed = Helpers::wp_command('core is-installed');
		} catch (\Exception $e) {
			WP_CLI::error('WordPress is already installed. Use `wp eighteen73 sync` instead.');
		}

		/*
		 * This is just so we can run `wp eighteen73 sync`, so the weak credentials are never actually used
		 */
		$response = Helpers::wp_command([
			'core install',
			[
				'url' => 'example.com',
				'title' => 'Example',
				'admin_user' => 'admin',
				'admin_password' => 'weakpassword',
				'admin_email' => 'admin@example.com',
			]
		]);
		if (preg_match('/already installed/', $response)) {
			WP_CLI::error('WordPress is already installed. Use: `wp eighteen73 sync`');
		} elseif (preg_match('/error/', $response)) {
			WP_CLI::error('Please check your .env');
		}

		/*
		 * Invoke our regular database sync
		 */
		$response = Helpers::wp_command('eighteen73 sync');

		WP_CLI::success( 'All done!' );
	}

}
