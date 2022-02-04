<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;

/**
 * Custom migration scripts for Rafu Shimpo.
 */
class PhilomathMigrator implements InterfaceMigrator {

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
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
