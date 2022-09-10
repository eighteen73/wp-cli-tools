<?php

namespace Eighteen73\WP_CLI\Commands;

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
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp eighteen73 first-sync
	 *
	 * @when before_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ) {
		WP_CLI::success( 'All done!' );
	}

}
