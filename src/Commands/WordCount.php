<?php
/**
 * Count the words in the website's content
 *
 * @package eighteen73/wpi-cli-tools
 */

namespace Eighteen73\WP_CLI\Commands;

use Eighteen73\WP_CLI\Helpers;
use WP_CLI;
use WP_CLI_Command;
use WP_Post;
use WP_Query;

/**
 * Count the words in the website's content, e.g. to estimate translation costs.
 *
 * ## EXAMPLES
 *
 *     # Export a word count for every published post to a spreadsheet
 *     $ wp eighteen73 word-count --format=csv > word-count.csv
 *
 * @package eighteen73/wpi-cli-tools
 */
class WordCount extends WP_CLI_Command {

	/**
	 * Number of posts loaded per query
	 *
	 * @var int
	 */
	private const BATCH_SIZE = 100;

	/**
	 * SEO meta keys that are always counted when present
	 *
	 * @var string[]
	 */
	private const SEO_META_KEYS = [
		'_yoast_wpseo_title',
		'_yoast_wpseo_metadesc',
	];

	/**
	 * The active multilingual plugin ('polylang', 'wpml' or null)
	 *
	 * @var string|null
	 */
	private ?string $multilingual = null;

	/**
	 * The language being counted on multilingual websites
	 *
	 * @var string|null
	 */
	private ?string $language = null;

	/**
	 * Count the words in the website's content, e.g. to estimate translation costs.
	 *
	 * Counts each post's title, excerpt, block/classic content and SEO title/description. Block markup, HTML and
	 * shortcodes are stripped and dynamic blocks are not rendered, so text from other posts isn't counted twice.
	 * String values stored in ACF blocks are included.
	 *
	 * On Polylang and WPML websites only the default language is counted, unless --lang is given. Post types that
	 * aren't translatable are always included.
	 *
	 * Taxonomy terms, menus, form labels and theme/plugin strings are not counted.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<post_types>]
	 * : Comma-separated post types. Defaults to all public post types (except attachments) plus synced patterns.
	 *
	 * [--post_status=<post_status>]
	 * : Comma-separated post statuses.
	 * ---
	 * default: publish
	 * ---
	 *
	 * [--lang=<language>]
	 * : Language to count on Polylang (slug) or WPML (code) websites. Defaults to the website's default language.
	 *
	 * [--meta=<meta_keys>]
	 * : Comma-separated extra meta keys whose text should be counted.
	 *
	 * [--summary]
	 * : Show totals per post type instead of one row per post.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Export a word count for every published post to a spreadsheet
	 *     wp eighteen73 word-count --format=csv > word-count.csv
	 *
	 *     # Show totals per post type
	 *     wp eighteen73 word-count --summary
	 *
	 *     # Count posts and pages, including an ACF field stored in post meta
	 *     wp eighteen73 word-count --post_type=post,page --meta=intro_text
	 *
	 * @when after_wp_load
	 *
	 * @param array $args Arguments
	 * @param array $assoc_args Arguments
	 */
	public function __invoke( array $args, array $assoc_args ) {
		Helpers::version_check();

		$post_types  = $this->get_post_types( $assoc_args );
		$post_status = $this->split_list( $assoc_args['post_status'] ?? 'publish' );
		$meta_keys   = array_unique( array_merge( self::SEO_META_KEYS, $this->split_list( $assoc_args['meta'] ?? '' ) ) );
		$format      = $assoc_args['format'] ?? 'table';

		$this->detect_multilingual( $assoc_args['lang'] ?? null );

		$rows = [];
		foreach ( $post_types as $post_type ) {
			$rows = array_merge( $rows, $this->count_post_type( $post_type, $post_status, $meta_keys ) );
		}

		usort( $rows, fn( $a, $b ) => $b['total_words'] <=> $a['total_words'] );

		if ( isset( $assoc_args['summary'] ) ) {
			$this->output_summary( $rows, $post_types, $format );
			return;
		}

		$fields = [ 'ID', 'post_type', 'title', 'url', 'modified', 'title_words', 'excerpt_words', 'content_words', 'meta_words', 'total_words' ];
		if ( $this->multilingual ) {
			array_splice( $fields, 2, 0, 'language' );
		}

		WP_CLI\Utils\format_items( $format, $rows, $fields );
	}

	/**
	 * Decide which post types to count
	 *
	 * @param array $assoc_args Arguments
	 * @return string[]
	 */
	private function get_post_types( array $assoc_args ): array {
		if ( ! empty( $assoc_args['post_type'] ) ) {
			$post_types = $this->split_list( $assoc_args['post_type'] );
			foreach ( $post_types as $post_type ) {
				if ( ! post_type_exists( $post_type ) ) {
					WP_CLI::error( "Post type '{$post_type}' does not exist." );
				}
			}
			return $post_types;
		}

		$post_types   = array_values( array_diff( get_post_types( [ 'public' => true ] ), [ 'attachment' ] ) );
		$post_types[] = 'wp_block';

		return $post_types;
	}

	/**
	 * Detect Polylang or WPML and the language to count
	 *
	 * @param string|null $language Language requested on the command line
	 * @return void
	 */
	private function detect_multilingual( ?string $language ) {
		if ( function_exists( 'pll_default_language' ) ) {
			$this->multilingual = 'polylang';
			$default            = pll_default_language( 'slug' );
			$available          = function_exists( 'pll_languages_list' ) ? pll_languages_list( [ 'fields' => 'slug' ] ) : [];
		} elseif ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$this->multilingual = 'wpml';
			$default            = apply_filters( 'wpml_default_language', null );
			$available          = array_keys( (array) apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] ) );
		} else {
			if ( $language ) {
				WP_CLI::warning( 'No Polylang or WPML installation detected, so --lang is ignored.' );
			}
			return;
		}

		$this->language = $language ? $language : $default;

		if ( ! $this->language ) {
			WP_CLI::error( 'Could not detect the default language. Please specify --lang.' );
		}
		if ( $available && ! in_array( $this->language, $available, true ) ) {
			WP_CLI::error( "Language '{$this->language}' is not available. Choose from: " . implode( ', ', $available ) );
		}

		// Written to STDERR so it doesn't end up in CSV/JSON output.
		fwrite( STDERR, "Counting language: {$this->language}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	}

	/**
	 * Count the words for every matching post of a post type
	 *
	 * @param string   $post_type   Post type
	 * @param string[] $post_status Post statuses
	 * @param string[] $meta_keys   Meta keys to count
	 * @return array
	 */
	private function count_post_type( string $post_type, array $post_status, array $meta_keys ): array {
		$query_args = [
			'post_type'              => $post_type,
			'post_status'            => $post_status,
			'posts_per_page'         => self::BATCH_SIZE,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
			'suppress_filters'       => true,
		];

		$language = $this->post_type_language( $post_type );
		if ( $language ) {
			if ( 'polylang' === $this->multilingual ) {
				$query_args['lang']      = ''; // Stop Polylang adding its own language filter.
				$query_args['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					[
						'taxonomy' => 'language',
						'field'    => 'slug',
						'terms'    => $language,
					],
				];
			} else {
				$ids = $this->wpml_post_ids( $post_type, $language );
				if ( ! $ids ) {
					return [];
				}
				$query_args['post__in'] = $ids;
			}
		}

		$rows = [];
		$page = 1;
		do {
			$query_args['paged'] = $page;
			$query               = new WP_Query( $query_args );

			foreach ( $query->posts as $post ) {
				$rows[] = $this->count_post( $post, $meta_keys, $language );
			}

			WP_CLI\Utils\wp_clear_object_cache();
			++$page;
		} while ( $page <= $query->max_num_pages );

		return $rows;
	}

	/**
	 * The language to filter a post type by, or null if it doesn't need filtering
	 *
	 * @param string $post_type Post type
	 * @return string|null
	 */
	private function post_type_language( string $post_type ): ?string {
		if ( 'polylang' === $this->multilingual && function_exists( 'pll_is_translated_post_type' ) && pll_is_translated_post_type( $post_type ) ) {
			return $this->language;
		}
		if ( 'wpml' === $this->multilingual && apply_filters( 'wpml_is_translated_post_type', false, $post_type ) ) {
			return $this->language;
		}
		return null;
	}

	/**
	 * Find a post type's post IDs in one WPML language
	 *
	 * @param string $post_type Post type
	 * @param string $language  Language code
	 * @return int[]
	 */
	private function wpml_post_ids( string $post_type, string $language ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT element_id FROM {$wpdb->prefix}icl_translations WHERE element_type = %s AND language_code = %s",
				'post_' . $post_type,
				$language
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Count the words in a single post
	 *
	 * @param WP_Post     $post      Post
	 * @param string[]    $meta_keys Meta keys to count
	 * @param string|null $language  Language of the post, if filtered by language
	 * @return array
	 */
	private function count_post( WP_Post $post, array $meta_keys, ?string $language ): array {
		$title_words   = $this->count_words( $post->post_title );
		$excerpt_words = $this->count_words( $post->post_excerpt );
		$content_words = $this->count_words( $this->content_text( $post->post_content ) );

		$meta_words = 0;
		foreach ( $meta_keys as $meta_key ) {
			foreach ( $this->string_values( get_post_meta( $post->ID, $meta_key, true ) ) as $value ) {
				// Remove Yoast replacement variables such as %%title%%.
				$meta_words += $this->count_words( preg_replace( '/%%[a-z0-9_]+%%/i', ' ', $value ) );
			}
		}

		return [
			'ID'            => $post->ID,
			'post_type'     => $post->post_type,
			'language'      => $language ?? '',
			'title'         => html_entity_decode( $post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'url'           => get_permalink( $post ),
			'modified'      => $post->post_modified,
			'title_words'   => $title_words,
			'excerpt_words' => $excerpt_words,
			'content_words' => $content_words,
			'meta_words'    => $meta_words,
			'total_words'   => $title_words + $excerpt_words + $content_words + $meta_words,
		];
	}

	/**
	 * Extract the translatable text from post content
	 *
	 * @param string $content Post content
	 * @return string
	 */
	private function content_text( string $content ): string {
		if ( ! has_blocks( $content ) ) {
			return $content;
		}

		return implode( ' ', $this->block_text( parse_blocks( $content ) ) );
	}

	/**
	 * Recursively extract the text from parsed blocks
	 *
	 * Each block's innerHTML excludes its inner blocks, so nothing is counted twice.
	 *
	 * @param array $blocks Parsed blocks
	 * @return string[]
	 */
	private function block_text( array $blocks ): array {
		$text = [];

		foreach ( $blocks as $block ) {
			$text[] = $block['innerHTML'] ?? '';

			if ( isset( $block['blockName'], $block['attrs']['data'] ) && str_starts_with( $block['blockName'], 'acf/' ) && is_array( $block['attrs']['data'] ) ) {
				foreach ( $block['attrs']['data'] as $key => $value ) {
					// Skip ACF's field key references (e.g. "_heading" => "field_123abc").
					if ( str_starts_with( (string) $key, '_' ) ) {
						continue;
					}
					$text = array_merge( $text, $this->string_values( $value ) );
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$text = array_merge( $text, $this->block_text( $block['innerBlocks'] ) );
			}
		}

		return $text;
	}

	/**
	 * Flatten a value into a list of its strings
	 *
	 * Numeric strings and ACF field keys are skipped as they aren't translatable.
	 *
	 * @param mixed $value Value
	 * @return string[]
	 */
	private function string_values( $value ): array {
		if ( is_array( $value ) ) {
			$strings = [];
			foreach ( $value as $item ) {
				$strings = array_merge( $strings, $this->string_values( $item ) );
			}
			return $strings;
		}

		if ( ! is_string( $value ) || '' === trim( $value ) || is_numeric( $value ) || preg_match( '/^field_[a-z0-9]+$/i', $value ) ) {
			return [];
		}

		return [ $value ];
	}

	/**
	 * Count the words in a string of HTML
	 *
	 * @param string $html HTML
	 * @return int
	 */
	private function count_words( string $html ): int {
		if ( '' === trim( $html ) ) {
			return 0;
		}

		$text = strip_shortcodes( $html );

		// Space out tags so adjacent elements don't merge into one word once stripped.
		$text = wp_strip_all_tags( str_replace( '<', ' <', $text ) );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return (int) preg_match_all( "/[\p{L}\p{N}][\p{L}\p{N}'’-]*/u", $text );
	}

	/**
	 * Output word count totals per post type
	 *
	 * @param array    $rows       Rows for each post
	 * @param string[] $post_types Post types counted
	 * @param string   $format     Output format
	 * @return void
	 */
	private function output_summary( array $rows, array $post_types, string $format ) {
		$columns = [ 'title_words', 'excerpt_words', 'content_words', 'meta_words', 'total_words' ];
		$blank   = array_fill_keys( array_merge( [ 'posts' ], $columns ), 0 );

		$summary = [];
		foreach ( $post_types as $post_type ) {
			$summary[ $post_type ] = array_merge( [ 'post_type' => $post_type ], $blank );
		}
		$total = array_merge( [ 'post_type' => 'TOTAL' ], $blank );

		foreach ( $rows as $row ) {
			++$summary[ $row['post_type'] ]['posts'];
			++$total['posts'];
			foreach ( $columns as $column ) {
				$summary[ $row['post_type'] ][ $column ] += $row[ $column ];
				$total[ $column ]                        += $row[ $column ];
			}
		}

		$summary   = array_values( $summary );
		$summary[] = $total;

		WP_CLI\Utils\format_items( $format, $summary, array_merge( [ 'post_type', 'posts' ], $columns ) );
	}

	/**
	 * Split a comma-separated list
	 *
	 * @param string $list Comma-separated list
	 * @return string[]
	 */
	private function split_list( string $list ): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', $list ) ) ) );
	}
}
