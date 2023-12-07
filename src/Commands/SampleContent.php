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
 *
 * @package eighteen73/wpi-cli-tools
 */
class SampleContent extends WP_CLI_Command {

	/**
	 * Add predetermined sample content to the website.
	 *
	 * Pages are published privately. You'll be asked for permission to overwrite pre-existing pages.
	 *
	 *  ## OPTIONS
	 *
	 *  [--force]
	 *  : Overwrite existing matching pages without asking for confirmation
	 *
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp eighteen73 sample-content
	 *
	 * @when after_wp_load
	 *
	 * @param array $args Arguments
	 * @param array $assoc_args Arguments
	 */
	public function __invoke( array $args, array $assoc_args ) {
		Helpers::version_check();

		// Check options
		$this->check_args( $assoc_args );

		$sample_files = $this->get_sample_files();
		foreach ( $sample_files as $sample_file ) {
			$page_id = $this->get_sample_page( $sample_file['slug'] );

			// Skip if we don't have a page (i.e. we aren't overwriting existing)
			if ( ! $page_id ) {
				continue;
			}

			$this->update_sample_page( $page_id, $sample_file['title'], $sample_file['path'] );
		}

		WP_CLI::line();
		WP_CLI::success( 'Complete' );
	}

	/**
	 * Validation the user's options
	 *
	 * @param array $assoc_args Arguments
	 * @return void
	 */
	private function check_args( array $assoc_args ) {
		if ( isset( $assoc_args['force'] ) && $assoc_args['force'] === true ) {
			$this->options['force'] = true;
		} elseif ( isset( $assoc_args['force'] ) ) {
			WP_CLI::error( 'Option `--force` must not have a value' );
		}
	}

	/**
	 * Get a list of the sample files that are available in plugin
	 *
	 * @return array
	 */
	protected function get_sample_files(): array {
		$out     = [];
		$dirname = dirname( __DIR__, 2 ) . '/sample-content';
		$files   = array_filter( scandir( $dirname ), fn ( $filename ) => str_ends_with( $filename, '.html' ) );
		foreach ( $files as $file ) {

			$basename = substr( $file, 0, -5 );

			// Slug
			$slug = "sample-{$basename}";

			// Make a human page title
			$title = $basename;
			$title = preg_replace( '/[^a-zA-Z0-9]+/', ' ', $title );
			$title = 'Sample ' . ucwords( $title );

			// Special rules
			$special_rule = match ( $file ) {
				'patterns.html' => 'patterns',
				default => null,
			};

			$out[] = [
				'filename' => $file,
				'path'     => "{$dirname}/{$file}",
				'slug'     => $slug,
				'title'    => $title,
				'insert'   => $special_rule,
			];
		}
		return $out;
	}

	/**
	 * Load or create the sample page
	 *
	 * @param string $path The page path
	 * @return int
	 */
	protected function get_sample_page( string $path ): ?int {

		// Try existing
		$args  = [
			'name'        => $path,
			'post_type'   => 'page',
			'post_status' => [ 'publish', 'private', 'draft' ],
		];
		$pages = get_posts( $args );

		// Make new
		if ( empty( $pages ) ) {
			$page_id = wp_insert_post(
				[
					'post_name' => $path,
					'post_type' => 'page',
				]
			);
		} else {
			if ( ! $this->options['force'] ) {
				$answer = Helpers::ask( "Page \"/{$path}\" already exists. Overwrite with new content?", true );
				if ( ! $answer ) {
					WP_CLI::line( " ... Skipping /{$path}" );
					return null;
				}
			}
			$page_id = $pages[0]->ID;
		}

		return $page_id;
	}

	/**
	 * Update the content of a sample page.
	 *
	 * Given a page ID and a path to a sample file, this method updates the content
	 * of the specified page with the contents of the sample file.
	 *
	 * @param int    $page_id The ID of the page to update.
	 * @param string $title The title of the page to update.
	 * @param string $sample_filepath The path to the sample file.
	 *
	 * @return void
	 */
	protected function update_sample_page( int $page_id, string $title, string $sample_filepath ) {
		$arg = [
			'ID'           => $page_id,
			'post_status'  => 'private',
			'post_title'   => $title,
			'post_content' => file_get_contents( $sample_filepath ),
		];
		wp_update_post( $arg );
	}
}
