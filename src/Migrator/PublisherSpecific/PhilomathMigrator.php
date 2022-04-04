<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use NewspackCustomContentMigrator\MigrationLogic\ContentDiffMigrator;
use WP_CLI;

/**
 * Custom migration scripts for Rafu Shimpo.
 */
class PhilomathMigrator implements InterfaceMigrator {

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * @var ContentDiffMigrator Instance.
	 */
	private $content_diff = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
		global $wpdb;
		$this->content_diff = new ContentDiffMigrator( $wpdb );
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceMigrator|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator philomath-update-gallery-image-links',
			[ $this, 'cmd_update_gallery_image_links' ],
		);
		WP_CLI::add_command(
			'newspack-content-migrator philomath-update-jp-blocks-ids',
			[ $this, 'cmd_update_jp_blocks_ids' ],
		);
	}

	/**
	 * @param array $args       CLI arguments.
	 * @param array $assoc_args CLI associative arguments.
	 */
	public function cmd_update_jp_blocks_ids( $args, $assoc_args ) {
		global $wpdb;

		// Get $imported_attachment_ids.
		$live_posts_prefix = 'live_wp_';
		$live_posts = $live_posts_prefix . 'posts';
		$live_postmeta = $live_posts_prefix . 'postmeta';
		$results = $wpdb->get_results( "select ID, guid from $live_posts where post_type = 'attachment'; ", ARRAY_A );

		// Build a map of old to new att. IDs.
		$mising_old_atts = [];
		foreach ( $results as $key_result => $result ) {

			// Get old live site att ID, and file.
			$id_old = $result[ 'ID' ];
			$guid_parsed = parse_url( $result['guid'] );
			$att_path_old = $guid_parsed['path'];
			if ( ! $att_path_old ) {
				$d = 1;
			}

			// Match existing att ID and file.
			$id_new = $wpdb->get_var( $wpdb->prepare( "select ID from $wpdb->posts where guid like %s ; ", '%'. $att_path_old ) );
			if ( ! $id_new ) {
				$mising_old_atts[ $id_old ] = $att_path_old;
				continue;
			}

			$imported_attachment_ids[ (int) $id_old ] = (int) $id_new;
		}

		// Upate IDs in content.
		$results = $wpdb->get_results( "select ID, post_content from {$wpdb->posts} where post_type = 'post'; ", ARRAY_A );
		foreach ( $results as $key_result => $result ) {
			echo sprintf( "(%d)/(%d) %d", $key_result+1, count( $results ), $result[ 'ID' ] ) . "\n";

			$content_updated = $result[ 'post_content' ];
			$content_updated = $this->content_diff->update_gutenberg_blocks_multiple_ids( $imported_attachment_ids, $content_updated );
			$content_updated = $this->content_diff->update_image_element_class_attribute( $imported_attachment_ids, $content_updated );
			$content_updated = $this->content_diff->update_image_element_data_id_attribute( $imported_attachment_ids, $content_updated );

			if ( $result[ 'post_content' ] != $content_updated ) {
				$wpdb->update(
					$wpdb->posts,
					[ 'post_content' => $content_updated ],
					[ 'ID' => $result[ 'ID' ] ]
				);
				echo 'Updated' . "\n";
			}
		}

		wp_cache_flush();
	}

	/**
	 * @param array $args       CLI arguments.
	 * @param array $assoc_args CLI associative arguments.
	 */
	public function cmd_update_gallery_image_links( $args, $assoc_args ) {
		global $wpdb;
		$posts_rows = $wpdb->get_results( "select ID, post_content from {$wpdb->posts} where post_type in ( 'post', 'page' ) and post_content like '%<!-- wp:gallery%' ;", ARRAY_A );
		foreach ( $posts_rows as $key_post_row => $post_row ) {
			$post_content_updated = $post_row[ 'post_content' ];

			// Match Image Block headers.
			$p = '|
				'. $this->escape_regex_pattern_string( '<!-- wp:image {' ) .'
				.*?
				"linkDestination"\:"none"
				.*?
				'. $this->escape_regex_pattern_string( '} -->' ) .'
			|xims';
			preg_match_all( $p, $post_content_updated, $matches );
			if ( empty ( $matches[0] ) ) {
				continue;
			}
			foreach ( $matches[0] as $match ) {
				$html = $match;
				// Replace the linkDestination arg.
				$html_replaced = str_replace( '"linkDestination":"none"', '"linkDestination":"attachment"', $html );

				$post_content_updated = str_replace( $html, $html_replaced, $post_content_updated );
			}

			if ( $post_row[ 'post_content' ] != $post_content_updated ) {
				$updated = $wpdb->update(
					$wpdb->posts,
					[ 'post_content' => $post_content_updated ],
					[ 'ID' => $post_row[ 'ID' ] ]
				);
				if ( false == $updated || $updated != 1 ) {
					$d = 1;
				}
			}
		}
	}

	private function escape_regex_pattern_string( $subject ) {
		$special_chars = [ ".", "\\", "+", "*", "?", "[", "^", "]", "$", "(", ")", "{", "}", "=", "!", "<", ">", "|", ":", ];
		$subject_escaped = $subject;
		foreach ( $special_chars as $special_char ) {
			$subject_escaped = str_replace( $special_char, '\\'. $special_char, $subject_escaped );
		}
		// Space.
		$subject_escaped = str_replace( ' ', '\s', $subject_escaped );

		return $subject_escaped;
	}
}
