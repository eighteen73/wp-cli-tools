<?php
/**
 * Configure a newly launched website
 *
 * @package eighteen73/wpi-cli-tools
 */

namespace Eighteen73\WP_CLI\Commands;

use Eighteen73\WP_CLI\Helpers;
use WP_CLI;
use WP_CLI\ExitException;
use WP_CLI_Command;

/**
 * Run common post-launch commands on a website to make sure it's prepared for the live domain.
 *
 * ## EXAMPLES
 *
 *     # Configure a newly launched website
 *     $ wp @production eighteen73 just-launched
 *
 * @package eighteen73/wpi-cli-tools
 */
class JustLaunched extends WP_CLI_Command {


	/**
	 * The new website domain
	 *
	 * @var string|null
	 */
	private ?string $new_domain = null;

	/**
	 * The old website domain(s)
	 *
	 * @var array
	 */
	private array $old_domains = [];

	/**
	 * Configure a newly launched website.
	 *
	 * This script should be used just after a website goes live, when its domain and file paths may have changes
	 * which affect certain functionality from working properly.
	 *
	 * The command can do a simple search-replace for old and new domains (see example below) but if you need more
	 * control please use `wp search-replace` separately.
	 *
	 * There is no harm in running this script multiple times or later after a website launch.
	 *
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp @production eighteen73 just-launched
	 *
	 *      wp @production eighteen73 just-launched --old-domain=beta.example.com --new-domain=example.com
	 *
	 * @when before_wp_load
	 *
	 * @param array $args Arguments
	 * @param array $assoc_args Arguments
	 */
	public function __invoke( array $args, array $assoc_args ) {
		Helpers::version_check();

		$this->check_args( $assoc_args );

		$this->change_domain();
		$this->reset_data_and_caches();

		WP_CLI::log( '' );
		WP_CLI::success( 'Complete' );
	}

	/**
	 * Run `wp search-replace` to update domain references
	 *
	 * @return void
	 */
	private function change_domain(): void {
		if ( ! $this->old_domains || ! $this->new_domain ) {
			return;
		}

		// Search and replace for domain changes
		foreach ( $this->old_domains as $old_domain ) {
			WP_CLI::log( '' );
			WP_CLI::log( "Replacing: {$old_domain} → {$this->new_domain}" );
			WP_CLI::log( '' );
			Helpers::wp_command(
				'search-replace "//' . $old_domain . '" "//' . $this->new_domain . '"',
				null,
				false
			);
		}
	}

	/**
	 * Clear important caches (inc. transients)
	 *
	 * @return void
	 */
	private function reset_data_and_caches(): void {
		WP_CLI::log( '' );
		WP_CLI::log( 'Clearing caches' );
		WP_CLI::log( '' );
		Helpers::wp_command( 'transient delete --all', null, false );
		Helpers::wp_command( 'cache flush', null, false );

		$has_yoast = ! empty( Helpers::wp_command( 'plugin status wordpress-seo' ) );
		if ( $has_yoast ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Reindexing Yoast' );
			WP_CLI::log( '' );
			Helpers::wp_command( 'yoast index --reindex', null, false );
		}
	}

	/**
	 * Validation the user's options
	 *
	 * @param array $assoc_args Arguments
	 * @return void
	 */
	private function check_args( array $assoc_args ): void {
		// Validate domains provided for search-replace. They must be straightforward domains, no protocols or paths.
		$new_domain = $assoc_args['new-domain'] ?? null;
		$old_domains = isset( $assoc_args['old-domain'] ) ? array_filter( array_unique( explode( ',', $assoc_args['old-domain'] ) ) ) : [];
		if ( $old_domains ) {
			foreach ( $old_domains as $old_domain ) {
				if ( ! filter_var( $old_domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME ) ) {
					WP_CLI::error( "{$old_domain} is not a valid domain. If you have a complex replacement please use `wp search-replace`" );
				}
			}
			if ( ! filter_var( $new_domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME ) ) {
				WP_CLI::error( "{$new_domain} is not a valid domain. If you have a complex replacement please use `wp search-replace`" );
			}
			$this->old_domains = $old_domains;
			$this->new_domain = $new_domain;
		}
	}
}
