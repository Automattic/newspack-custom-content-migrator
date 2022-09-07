<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use NewspackCustomContentMigrator\MigrationLogic\ContentDiffMigrator;
use NewspackCustomContentMigrator\MigrationLogic\Posts as PostsLogic;
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
		$this->posts_logic = new PostsLogic();
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
		WP_CLI::add_command(
			'newspack-content-migrator philomath-recreate-jp-slideshow-code',
			[ $this, 'cmd_recreate_wp_jetpack_slideshow_code' ],
		);
	}

	/**
	 * Helper command used to manually fix Philomath JP Slideshow galleries.
	 *
	 * Certain JP Slideshow galleries on live needed to be recreated. This command reads block code from file (convenient for
	 * manual processing) and pulls out all image URLs. It then takes these URLs and finds the actual attachment IDs in the DB
	 * and recreates the whole Slideshow gallery. It also does an intermediary change of hostname (live VS local dev hostname).
	 *
	 * @return void
	 */
	public function cmd_recreate_wp_jetpack_slideshow_code() {

		// Get wp:jetpack/slideshow block from local dev file.
		$content = file_get_contents( '/var/www/philomath.test/public/0_philomathgals_geturls.txt' );

		// Replace either data-url=" or src=" with \n.
		$str_beginning_of_url = false != strpos( $content, 'data-url="' ) ? 'data-url="' : 'src="';
		$content = str_replace( $str_beginning_of_url, "\n" . $str_beginning_of_url, $content );
		$content_exploded = explode( "\n", $content );
		foreach ( $content_exploded as $key_content_line => $content_line ) {
			if ( 0 !== strpos( $content_line, $str_beginning_of_url ) ) {
				unset( $content_exploded[$key_content_line] );
			}
		}
		$content = implode( "\n", array_values( $content_exploded ) );
		$content = str_replace( $str_beginning_of_url, '', $content );

		// Remove everything after " and replace hostnames.
		$content_exploded = explode( "\n", $content );
		foreach ( $content_exploded as $key_content_line => $content_line ) {
			$pos = strpos( $content_line, '"' );
			$content_exploded[$key_content_line] = substr( $content_line, 0, $pos );
			$content_exploded[$key_content_line] = str_replace( 'philomathnews.com', 'philomath.test', $content_exploded[$key_content_line] );
		}

		// All ourls.
		$urls = array_values( $content_exploded );

		$ids = [];
		foreach ( $urls as $key => $url ) {
			WP_CLI::log( sprintf( "%d/%d", $key + 1, count( $urls ) ) );
			$ids[ $url ] = $this->get_attachment_id_from_url( $url );
			if ( empty( $ids[ $url ] ) || is_null( $ids[ $url ] ) || 0 == $ids[ $url ] ) {
				$d=1;
				unset( $ids[ $url ] );
			}
		}

		if ( ! empty( $ids ) ) {
			$gallery = $this->posts_logic->generate_jetpack_slideshow_block_from_media_posts( array_values( $ids ) );
			$gallery_tiled = $this->posts_logic->generate_skeleton_jetpack_tiled_gallery_from_attachment_ids( array_values( $ids ) );

 			// These are the full JP Slideshow galleries for use on live.
			$gallery_live = str_replace( '//philomath.test/', '//philomathnews.com/', $gallery );
			$gallery_tiled_live = str_replace( '//philomath.test/', '//philomathnews.com/', $gallery_tiled );
		} else {
			// EMPTY
			$d=1;
		}

 		return;
	}

	/**
	 * Taken from https://wordpress.stackexchange.com/a/7094 , custom extended with built-in WP function call attempt.
	 *
	 * @param string $url
	 *
	 * @return false|float|int|string|\WP_Post
	 */
	public function get_attachment_id_from_url( $url ) {

		// try built in function
		$att_id = attachment_url_to_postid( $url );
		if ( is_numeric( $att_id ) && 0 != $att_id ) {
			return $att_id;
		}

		$dir = wp_upload_dir();

		// baseurl never has a trailing slash
		if ( false === strpos( $url, $dir['baseurl'] . '/' ) ) {
			// URL points to a place outside of upload directory
			return false;
		}

		$file  = basename( $url );
		$query = [
			'post_type'  => 'attachment',
			'fields'     => 'ids',
			'meta_query' => [
				[
					'key'     => '_wp_attached_file',
					'value'   => $file,
					'compare' => 'LIKE',
				],
			]
		];

		// query attachments
		$ids = get_posts( $query );
		if ( ! empty( $ids ) ) {
			foreach ( $ids as $id ) {
				// first entry of returned array is the URL
				if ( $url === array_shift( wp_get_attachment_image_src( $id, 'full' ) ) ) {
					return $id;
				}
			}
		}

		$query['meta_query'][0]['key'] = '_wp_attachment_metadata';

		// query attachments again
		$ids = get_posts( $query );
		if ( empty( $ids) ) {
			return false;
		}
		foreach ( $ids as $id ) {
			$meta = wp_get_attachment_metadata( $id );
			foreach ( $meta['sizes'] as $size => $values ) {
				if ( $values['file'] === $file && $url === array_shift( wp_get_attachment_image_src( $id, $size ) ) )
					return $id;
			}
		}

		return false;
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
			$content_updated = $this->content_diff->update_gutenberg_blocks_headers_multiple_ids( $imported_attachment_ids, $content_updated );
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
