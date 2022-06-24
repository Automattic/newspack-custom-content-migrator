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
			'newspack-content-migrator philomath-get-jp-slideshow-from-urls',
			[ $this, 'cmd_get_jp_slideshow_from_urls' ],
		);
	}

	public function cmd_get_jp_slideshow_from_urls( $args, $assoc_args ) {
		$urls = [
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-2330.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0323.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0383.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0402.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0408.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0427.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0415.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0451.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0447.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0472.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0017.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0538.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0558.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0640.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0680.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0780.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0722.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0767.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0817.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0783.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0844.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0931.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0903.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0950.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-1003.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-1011.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0986.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-1038.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-1049.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-1082.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-1105.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-1109.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-1126.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-1127.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-1131.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-1146.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-1159.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-1174.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-1186.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0055.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-1228.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-1269.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-1293.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-1294.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-1330.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-1991.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-2013.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-2020.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-2061.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-2070.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-2086.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-2073.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0102.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-2112.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0105.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0114.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-2145.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0143.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-2134.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-2182.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0164.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0173.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0188.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-2197.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0207.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0203.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0215.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-2206.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0225.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0234.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-2212.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0237.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-2214.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-2237.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-2247.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0252.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-2267.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-2283.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-2278.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-2307.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-2361.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-2365.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-2411.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-2419.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-2438.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-2460.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-2447.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-2477.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-2469.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-2489.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-2487.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-2509.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-2615.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-0284.jpg',
			'https://philomath.test/wp-content/uploads/2022/02/070821_frolic_day1-2621.jpg',
		];
		$ids = [];
		foreach ( $urls as $key => $url ) {
			WP_CLI::log( sprintf( "%d/%d", $key + 1, count( $urls ) ) );
			$ids[ $url ] = $this->get_attachment_id_from_url( $url );
			if ( empty( $ids[ $url ] ) || is_null( $ids[ $url ] ) || 0 == $ids[ $url ] ) {
				$d=1;
			}
		}

		$gallery = $this->posts_logic->generate_jetpack_slideshow_block_from_media_posts( array_values( $ids ) );

		$gallery_live = str_replace( '//philomath.test/', '//philomathnews.com/', $gallery );

		$d=1;
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
