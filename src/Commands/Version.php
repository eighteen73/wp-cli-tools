<?php
/**
 * Check if there's a newer version of this package available
 *
 * @package eighteen73/wpi-cli-tools
 */

namespace Eighteen73\WP_CLI\Commands;

use Eighteen73\WP_CLI\Helpers;
use WP_CLI;
use WP_CLI_Command;

/**
 * Check if there's a newer version of this package available.
 *
 * ## EXAMPLES
 *
 *     $ wp eighteen73 version
 *
 * @package eighteen73/wpi-cli-tools
 */
class Version extends WP_CLI_Command {

	/**
	 * Check if there's a newer version of this package available.
	 *
	 * This is run automatically before other commands.
	 *
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp eighteen73 version
	 *
	 * @when before_wp_load
	 *
	 * @param array $args Arguments
	 * @param array $assoc_args Arguments
	 */
	public function __invoke( array $args, array $assoc_args ) {

		$local_version = [];
		$local_tags    = Helpers::git_command( 'describe --tags', __DIR__ );
		if ( ! is_array( $local_tags ) || ! isset( $local_tags[0] ) || ! preg_match( '/^v?([0-9]+)\.([0-9]+)\.([0-9]+)/', $local_tags[0], $local_version ) ) {
			WP_CLI::error( 'Update required. Please run: wp package update' );
		}
		$local_version = $this->format_version( $local_version );

		$remote_version = [];
		$remote_tags    = Helpers::git_command( 'ls-remote --tags https://github.com/eighteen73/wp-cli-tools.git' );
		if ( ! is_array( $remote_tags ) || ! isset( $remote_tags[0] ) || ! preg_match( '/v?([0-9]+)\.([0-9]+)\.([0-9]+)$/', $remote_tags[0], $remote_version ) ) {
			WP_CLI::warning( 'Could not detect latest version. You may need to run: wp package update' );
		}
		$remote_version = $this->format_version( $remote_version );

		$needs_update = false;
		if ( $local_version->major < $remote_version->major ) {
			$needs_update = true;
		}
		if ( ! $needs_update && $local_version->minor < $remote_version->minor ) {
			$needs_update = true;
		}
		if ( ! $needs_update && $local_version->patch < $remote_version->patch ) {
			$needs_update = true;
		}

		if ( $needs_update ) {
			WP_CLI::error( "Update required ({$local_version->version} to {$remote_version->version}). Please run: wp package update" );
		}
	}

	/**
	 * Format the version breakdown in a user friendly object
	 *
	 * @param array $version Version breakdown
	 * @return \stdClass
	 */
	protected function format_version( $version ) {
		$out = new \stdClass();

		$out->version = $version[0];
		$out->major   = intval( $version[1] );
		$out->minor   = intval( $version[2] );
		$out->patch   = intval( $version[3] );

		return $out;
	}
}
