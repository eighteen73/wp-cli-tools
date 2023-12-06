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
	 *     wp @production eighteen73 sample-content
	 *
	 * @when after_wp_load
	 *
	 * @param array $args Arguments
	 * @param array $assoc_args Arguments
	 */
	public function __invoke( array $args, array $assoc_args ) {
		Helpers::version_check();

		// Try existing
		$args = [
			'name'      => 'kitchen-sink',
			'post_type' => 'page',
			'post_status'    => array( 'publish', 'private', 'draft' ),
		];
		$page = get_posts( $args );

		// Make new
		if ( empty( $page ) ) {
			$page_id = wp_insert_post( [
				'post_name'   => 'kitchen-sink',
				'post_status' => 'publish',
				'post_title'  => 'Kitchen Sink',
				'post_type'   => 'page',
			] );
			$page = get_post( $page_id );
		}

		ray($page);

		WP_CLI::line();
		WP_CLI::success( 'Complete' );
	}
}
