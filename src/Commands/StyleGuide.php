<?php
/**
 * Add a predetermined style guide to the website.
 *
 * @package eighteen73/wpi-cli-tools
 */

namespace Eighteen73\WP_CLI\Commands;

use Eighteen73\WP_CLI\Helpers;
use WP_Block_Patterns_Registry;
use WP_CLI;
use WP_CLI_Command;

/**
 * Add a predetermined style guide to the website.
 *
 * ## EXAMPLES
 *
 *     # Add a style guide to the website for testing/sign-off
 *     $ wp eighteen73 style-guide
 *
 * @package eighteen73/wpi-cli-tools
 */
class StyleGuide extends WP_CLI_Command {

	/**
	 * Add a predetermined style guide to the website.
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
	 *     wp eighteen73 style-guide
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

		$parent_page_id = $this->prepare_parent_page();

		$content_files = $this->get_style_guide_content();
		foreach ( $content_files as $content_file ) {
			$page_id = $this->get_style_guide_page( $content_file['slug'] );

			// Skip if we don't have a page (i.e. we aren't overwriting existing)
			if ( ! $page_id ) {
				continue;
			}

			$this->update_style_guide_page( $parent_page_id, $page_id, $content_file['title'], $content_file['path'], $content_file['special_insert'] );
		}

		$this->add_links_to_parent_page( $parent_page_id );

		WP_CLI::line();
		WP_CLI::success( 'Complete' );
	}

	/**
	 * Checks the arguments passed to the method.
	 *
	 * @param array $assoc_args The array of associative arguments.
	 *
	 * @return void
	 */
	private function check_args( array $assoc_args ): void {
		if ( isset( $assoc_args['force'] ) && $assoc_args['force'] === true ) {
			$this->options['force'] = true;
		} elseif ( isset( $assoc_args['force'] ) ) {
			WP_CLI::error( 'Option `--force` must not have a value' );
		}
	}

	/**
	 * Get a list of the style guide files that are available in this package
	 *
	 * @return array
	 */
	protected function get_style_guide_content(): array {
		$out     = [];
		$dirname = dirname( __DIR__, 2 ) . '/style-guide';
		$files   = array_filter( scandir( $dirname ), fn ( $filename ) => str_ends_with( $filename, '.html' ) );
		foreach ( $files as $file ) {

			$basename = substr( $file, 0, -5 );

			// Slug
			$slug = "{$basename}";

			// Make a human page title
			$title = $basename;
			$title = preg_replace( '/[^a-zA-Z0-9]+/', ' ', $title );
			$title = ucwords( $title );

			// Special rules
			$special_insert = match ( $file ) {
				'patterns.html' => 'theme_patterns',
				default => null,
			};

			$out[] = [
				'filename'       => $file,
				'path'           => "{$dirname}/{$file}",
				'slug'           => $slug,
				'title'          => $title,
				'special_insert' => $special_insert,
			];
		}
		return $out;
	}

	/**
	 * Load or create the style guide page
	 *
	 * @param string $path The page path
	 * @return int
	 */
	protected function get_style_guide_page( string $path ): ?int {

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
	 * Update the content of a style guide page.
	 *
	 * Given a page ID and a path to a style guide template file, this method updates the content
	 * of the specified page with the contents of the style guide template file.
	 *
	 * @param int     $parent_page_id The ID of the page parent.
	 * @param int     $page_id The ID of the page to update.
	 * @param string  $title The title of the page to update.
	 * @param string  $style_guide_content_filepath The path to the style guide template file.
	 * @param ?string $special_insert The style guide's requirement for any special inserted content.
	 *
	 * @return void
	 */
	protected function update_style_guide_page( int $parent_page_id, int $page_id, string $title, string $style_guide_content_filepath, string $special_insert = null ): void {
		$arg = [
			'post_parent'  => $parent_page_id,
			'ID'           => $page_id,
			'post_status'  => 'private',
			'post_title'   => $title,
			'post_content' => file_get_contents( $style_guide_content_filepath ),
		];

		if ( $special_insert === 'theme_patterns' ) {
			$arg['post_content'] .= $this->insert_theme_patterns();
		}

		wp_update_post( $arg );
	}

	/**
	 * Prepares the parent page
	 *
	 * @return int The ID of the parent page
	 */
	protected function prepare_parent_page(): int {
		$parent_page_id = $this->get_style_guide_page( 'styleguide' );

		$arg = [
			'ID'          => $parent_page_id,
			'post_status' => 'private',
			'post_title'  => 'Style Guide',
			'post_parent' => null,
		];
		wp_update_post( $arg );

		return $parent_page_id;
	}

	/**
	 * Adds links to child pages to the content of the parent page.
	 *
	 * @param int $parent_page_id The ID of the parent page.
	 *
	 * @return void
	 */
	protected function add_links_to_parent_page( int $parent_page_id ): void {

		$pages = get_pages(
			[
				'child_of'    => $parent_page_id,
				'post_status' => [ 'publish', 'private' ],
			]
		);

		$content  = '<!-- wp:paragraph {"className":""} -->';
		$content .= '<p>This page and it\'s children serve the purpose of allowing the website\'s team to validate the website\'s styles and content blocks. They are automatically generated so if you edit them please be aware that the changes may be overwritten in the future. </p>';
		$content .= '<!-- /wp:paragraph -->';
		$content .= '<!-- wp:list {"className":""} -->';
		$content .= '<ul>';
		foreach ( $pages as $page ) {
			$content .= '<!-- wp:list-item {"className":""} -->';
			$content .= '<li><a href="' . get_the_permalink( $page ) . '">' . $page->post_title . '</a></li>';
			$content .= '<!-- /wp:list-item -->';
		}
		$content .= '</ul>';
		$content .= '<!-- /wp:list -->';

		$arg = [
			'ID'           => $parent_page_id,
			'post_content' => $content,
		];
		wp_update_post( $arg );
	}

	/**
	 * Get all the theme's patterns for content insertion.
	 *
	 * This is based on our Pulsar theme which contains a "patterns" subdirectory for all patterns, which may themselves
	 * be arranged into subdirectories.
	 *
	 * @return string The generated special patterns in HTML format.
	 */
	protected function insert_theme_patterns(): string {
		$patterns = WP_Block_Patterns_Registry::get_instance()->get_all_registered();

		if ( empty( $patterns ) ) {
			return '<!-- wp:paragraph {"className":""} --><p>This website has no patterns.</p><!-- /wp:paragraph -->';
		}

		// Add each pattern, headed by the dir name (h1) and filename (h2)
		// TODO Group output by category using WP_Block_Pattern_Categories_Registry::get_instance()->get_all_registered();
		$markup = '';
		foreach ( $patterns as $pattern ) {
			$markup .= '<!-- wp:heading {"level":2,"className":""} --><h2 class="wp-block-heading">' . $pattern['title'] . '</h2><!-- /wp:heading -->';
			if ( $pattern['description'] ) {
				$markup .= '<!-- wp:paragraph {"className":""} --><p>' . $pattern['description'] . '</p><!-- /wp:paragraph -->';
			}
			$markup .= "\n";
			$markup .= $pattern['content'];
			$markup .= "\n";
		}

		return $markup;
	}
}
