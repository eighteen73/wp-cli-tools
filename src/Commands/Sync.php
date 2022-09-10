<?php

namespace Eighteen73\WP_CLI\Commands;

use WP_CLI;
use WP_CLI_Command;

/**
 * Get a remote website's database.
 *
 * ## EXAMPLES
 *
 *     # Refresh the local website's database with the one from the remote website.
 *     $ wp eighteen73 sync
 *
 * @package eighteen73/wpi-cli-tools
 */
class Sync extends WP_CLI_Command {

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
	 */
	public function __invoke( array $args, array $assoc_args ) {
		WP_CLI::success( 'All done!' );
	}

}
