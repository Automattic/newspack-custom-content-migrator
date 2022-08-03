<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \WP_CLI;
use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use NewspackCustomContentMigrator\MigrationLogic\Posts as PostsLogic;
use NewspackContentConverter\ContentPatcher\ElementManipulators\HtmlElementManipulator;
use NewspackContentConverter\ContentPatcher\ElementManipulators\WpBlockManipulator;
use NewspackCustomContentMigrator\MigrationLogic\CoAuthorPlus as CoAuthorPlusLogic;

/**
 * Custom migration scripts for Colorado Sun.
 */
class ColoradoSunMigrator implements InterfaceMigrator {

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * @var PostsLogic
	 */
	private $posts_logic;

	/**
	 * @var CoAuthorPlusLogic
	 */
	private $coauthors_logic;

	/**
	 * @var WpBlockManipulator
	 */
	private $wpb_lock_manipulator;

	/**
	 * @var HtmlElementManipulator
	 */
	private $html_element_manipulator;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts_logic = new PostsLogic();
		$this->coauthors_logic = new CoAuthorPlusLogic();
		$this->wpb_lock_manipulator = new WpBlockManipulator();
		$this->html_element_manipulator = new HtmlElementManipulator();
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
			'newspack-content-migrator coloradosun-remove-reusable-block-from-end-of-content',
			[ $this, 'cmd_remove_reusable_block_from_end_of_content' ],
		);
		WP_CLI::add_command(
			'newspack-content-migrator coloradosun-move-custom-subtitle-meta-to-newspack-subtitle',
			[ $this, 'cmd_move_custom_subtitle_meta_to_newspack_subtitle' ],
		);
	}

	public function cmd_move_custom_subtitle_meta_to_newspack_subtitle( $positional_args, $assoc_args ) {
		global $wpdb;

		$rows = $wpdb->get_results( "select meta_id, post_id, meta_key, meta_value from $wpdb->postmeta where meta_key = 'dek' ;", ARRAY_A );
		foreach ( $rows as $key_row => $row ) {
			$meta_id = $row['meta_id'];
			$post_id = $row['post_id'];
			$meta_value = $row['meta_value'];

			WP_CLI::log( sprintf( '(%d)/(%d) meta_id %d', $key_row + 1, count( $rows ), $meta_id ) );

			if ( empty( $meta_value ) ) {
				WP_CLI::log( 'Empty, skipping.' );
				continue;
			}

			update_post_meta( $post_id, 'newspack_post_subtitle', $meta_value );
			delete_post_meta( $post_id, 'dek' );

			$this->log( 'cs_subtitlesmoved.log', $post_id );
		}
	}

	public function cmd_remove_reusable_block_from_end_of_content( $positional_args, $assoc_args ) {
		global $wpdb;

		// e.g. <!-- wp:block {"ref":14458} /-->
		$reusable_block_html = '<!-- wp:block {"ref":14458} /-->';
		$reusable_block_additional_search = 'wp:block {"ref":14458}';
		$results = $wpdb->get_results( "select ID, post_content from $wpdb->posts where post_type in ( 'post', 'page' ) and post_status in ( 'publish', 'future', 'draft', 'pending', 'private', 'inherit' ) ; ", ARRAY_A );

		$post_ids_where_additional_blocks_are_found = [];
		foreach ( $results as $key_result => $result ) {

			$id = $result['ID'];
			$post_content = $result['post_content'];

			WP_CLI::log( sprintf( '(%d)/(%d) %d', $key_result + 1, count( $results ), $id ) );

			// Remove strings from the end of the post_content.
			$remove_strings = [
				// Some posts end with extra breaks.
				"<!-- wp:paragraph -->
<p></p>
<!-- /wp:paragraph -->",
				// Some posts have two or three of these at the end.
				"<!-- wp:paragraph -->
<p><br></p>
<!-- /wp:paragraph -->",
				"<!-- wp:paragraph -->
<p><br></p>
<!-- /wp:paragraph -->",
				"<!-- wp:paragraph -->
<p><br></p>
<!-- /wp:paragraph -->",
				// More types breaks used at the end of their content.
				"<!-- wp:paragraph -->
<p><strong><br></strong></p>
<!-- /wp:paragraph -->",
				"<!-- wp:paragraph -->
<p> </p>
<!-- /wp:paragraph -->",
				$reusable_block_html,
			];
			// Doing rtrim directly on post_content because we'll be comparing them later, and we don't want to update a Post just
			// for spaces or line breaks.
			$post_content = rtrim( $post_content );
			$post_content_updated = $post_content;
			foreach ( $remove_strings as $remove_string ) {
				$post_content_updated = $this->remove_string_from_end_of_string( $post_content_updated, $remove_string );
				$post_content_updated = rtrim( $post_content_updated );
			}

			// Double check if this block is still used anywhere else in this content.
			if ( false !== strpos( $post_content_updated, $reusable_block_additional_search ) ) {
				$post_ids_where_additional_blocks_are_found[] = $id;
			}

			if ( $post_content_updated != $post_content ) {
				// A simple control check.
				$diff = strcasecmp( $post_content_updated, $post_content );
				if ( $diff > 40 ) {
					$dbg = 1;
				}

				// Save and log.
				$wpdb->update( $wpdb->posts, [ 'post_content' => $post_content_updated ], [ 'ID' => $id ] );
				$this->log( 'cs_reusableblocksremovedfromendofcontent.log', $id );
				$this->log( 'cs_'.$id.'_1_before.log', $post_content );
				$this->log( 'cs_'.$id.'_2_after.log', $post_content_updated );
				WP_CLI::success( 'Saved' );
			}
		}

		WP_CLI::log( 'Done. See log cs_reusableblocksremovedfromendofcontent.log' );
		WP_CLI::log( sprintf( 'Additional found blocks: %s', implode( ',', $post_ids_where_additional_blocks_are_found ) ) );
	}

	public function remove_string_from_end_of_string( $subject, $remove ) {
		$subject_reverse = strrev( $subject );
		$remove_reverse = strrev( $remove );

		if ( 0 === strpos( $subject_reverse, $remove_reverse ) ) {
			return strrev( substr( $subject_reverse, strlen( $remove_reverse ) ) );
		}

		return $subject;
	}

	public function escape_regex_pattern_string( $subject ) {
		$special_chars = [ ".", "\\", "+", "*", "?", "[", "^", "]", "$", "(", ")", "{", "}", "=", "!", "<", ">", "|", ":", ];
		$subject_escaped = $subject;
		foreach ( $special_chars as $special_char ) {
			$subject_escaped = str_replace( $special_char, '\\'. $special_char, $subject_escaped );
		}

		// Space.
		$subject_escaped = str_replace( ' ', '\s', $subject_escaped );

		return $subject_escaped;
	}

	/**
	 * Simple file logging.
	 *
	 * @param string $file    File name or path.
	 * @param string $message Log message.
	 */
	public function log( $file, $message ) {
		$message .= "\n";
		file_put_contents( $file, $message, FILE_APPEND );
	}
}
