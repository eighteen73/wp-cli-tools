<?php
/**
 * Add predetermined sample content to the website.
 *
 * @package eighteen73/wpi-cli-tools
 */

namespace Eighteen73\WP_CLI\Commands;

use Eighteen73\WP_CLI\Helpers;
use WP_CLI;
use WP_CLI_Command;

/**
 * Add predetermined sample content to the website.
 *
 * ## EXAMPLES
 *
 *     # Add sample content to the website for testing
 *     $ wp eighteen73 sample-content
 *     $ wp @production eighteen73 sample-content
 *
 * @package eighteen73/wpi-cli-tools
 */
class SampleContent extends WP_CLI_Command {

	/**
	 * Add predetermined sample content to the website.
	 *
	 * 1. A kitchen sink (/sample/kitchen-sink)
	 * 2. An arrangement of default blocks (/sample/blocks)
	 *
	 * Pages are published privately. You'll be asked for permission to overwrite pre-existing pages.
	 *
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp @production eighteen73 sample-content
	 *
	 *      wp @production eighteen73 sample-content
	 *
	 * @when before_wp_load
	 *
	 * @param array $args Arguments
	 * @param array $assoc_args Arguments
	 */
	public function __invoke( array $args, array $assoc_args ) {
		Helpers::version_check();

		WP_CLI::line();
		WP_CLI::success( 'Complete' );
	}
}
