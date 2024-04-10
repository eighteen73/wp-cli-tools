<?php
/**
 * Import a custom block's scaffolding into Pulsar
 *
 * @package eighteen73/wpi-cli-tools
 */

namespace Eighteen73\WP_CLI\Commands;

use WP_CLI;

/**
 * Import a custom block's scaffolding into Pulsar
 */
class ImportBlock {

	/**
	 * All available blocks
	 *
	 * @var array
	 */
	private array $all_blocks = [];

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->get_available_blocks();
	}

	/**
	 * Copy the block's code into the theme
	 *
	 * @return void
	 */
	public function add() {
		$blocks = $this->ask_user_for_blocks();

		// TODO Confirm a Pulsar theme is active
		// TODO Copy each block into the theme

		$dir = get_template_directory();
		WP_CLI::log( '' );
		foreach ( $blocks as $block ) {
			WP_CLI::log( "Copying {$block} to {$dir}" );
		}
	}

	/**
	 * Detect all available blocks
	 *
	 * @return void
	 */
	private function get_available_blocks() {
		// TODO Replace with real logic
		$this->all_blocks = [
			'carousel',
			'carousel-slide',
		];
	}

	/**
	 * Ask the user which block(s) they want
	 *
	 * @return array
	 */
	private function ask_user_for_blocks(): array {
		WP_CLI::log( 'Available blocks:' );
		foreach ( $this->all_blocks as $block ) {
			WP_CLI::log( '  ' . $block );
		}
		do {
			if ( isset( $valid_blocks ) ) {
				WP_CLI::log( '' );
				WP_CLI::log( 'Invalid block(s)' );
			}
			WP_CLI::log( '' );
			WP_CLI::log( 'Which blocks (comma separated) would you like to add?' );
			WP_CLI::out( '> ' );
			$blocks = strtolower( trim( fgets( STDIN ) ) );
			$blocks = (array) preg_split( '/[\s,]/', $blocks );
			$blocks = array_filter( array_unique( $blocks ) );
			sort( $blocks );
			$valid_blocks = (array) array_intersect( $blocks, $this->all_blocks );
			$num_blocks   = count( $blocks );
		} while ( ! $num_blocks || $blocks !== $valid_blocks );
		return $blocks;
	}
}
