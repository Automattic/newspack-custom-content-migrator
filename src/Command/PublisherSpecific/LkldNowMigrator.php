<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use NewspackContentConverter\ContentPatcher\ElementManipulators\SquareBracketsElementManipulator;
use NewspackCustomContentMigrator\Logic\SimpleLocalAvatars;
use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \WP_CLI;
use WP_Query;

/**
 * Custom migration scripts for LkldNow.
 */
class LkldNowMigrator implements InterfaceCommand {

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * @var null|SimpleLocalAvatars Instance of \Logic\SimpleLocalAvatars
	 */
	private $sla_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->sla_logic = new SimpleLocalAvatars();
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceCommand|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator lkldnow-migrate-avatars',
			[ $this, 'cmd_lkldnow_migrate_avatars' ],
			[
				'shortdesc' => 'Migrates the users\' avatars from WP User Avatars to Simple Local Avatars.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator lkldnow-migrate-republished-content',
			[ $this, 'cmd_lkldnow_republished_content' ],
			[
				'shortdesc' => 'Append a hyperlink to the original article to the end of the article.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator lkldnow-migrate-themify-box-shortcodes',
			[ $this, 'cmd_lkld_migrate_themify_box' ],
			[
				'shortdesc' => 'Convert all themify_box shortcodes to Group Gutenberg block.',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Run the migrator without making changes to the database.',
						'optional'    => true,
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator lkldnow-migrate-themify-icons-shortcodes',
			[ $this, 'cmd_lkld_migrate_themify_icon' ],
			[
				'shortdesc' => 'Convert all themify_icon shortcodes to normal anchof links.',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Run the migrator without making changes to the database.',
						'optional'    => true,
					],
				],
			]
		);
	}

	/**
	 * Extract the old avatars from meta and migrate them to Simple Local Avatars.
	 */
	public function cmd_lkldnow_migrate_avatars() {
		if ( ! $this->sla_logic->is_sla_plugin_active() ) {
			WP_CLI::warning( 'Simple Local Avatars not found. Install and activate it before using this command.' );
			return;
		}

        /**
		 * Simple Local Avatars already has a method for migrating from 'WP User Avatar', so we use it instead of rewriting it
		 */

		$first_migration_count = $this->sla_logic->simple_local_avatars->migrate_from_wp_user_avatar();

		WP_CLI::log( sprintf( '%d avatars were migrated from WP User Avatar to Simple Local Avatars.', $first_migration_count ) );

		/**
		 * Migrate avatars from 'WP User Avatars' to Simple Local Avatars
		 */

		$from_avatar_meta_key = 'wp_user_avatars';
		$from_avatar_rating_meta_key = 'wp_user_avatars_rating';

		$users = get_users(
			array(
				'meta_key'     => $from_avatar_meta_key,
				'meta_compare' => 'EXISTS',
			)
		);

		$second_migration_count = 0;

		foreach ( $users as $user ) {
			$avatar_data = maybe_unserialize( get_user_meta( $user->ID, $from_avatar_meta_key, true ) );

			if ( ! is_array( $avatar_data) ) {
				continue;
			}

			// If media_id doesn't exist, try finding the media ID using the avatar URL
			if ( isset( $avatar_data['media_id'] ) ) {
				$avatar_id = $avatar_data['media_id'];
			} else if ( isset( $avatar_data['full'] ) ) {
				$avatar_id = attachment_url_to_postid( $avatar_data['full'] );

				// Sometimes the avatar is uploaded without being linked to an attachment
				// in that case we insert a new attachment
				if ( $avatar_id == 0 ) {
					$avatar_url = $avatar_data['full'];
					$avatar_id = $this->assign_upload_file_to_attachment( $avatar_url );
				}
			}

			// If we can't find the avatar ID, skip this user
			if ( $avatar_id === null || is_wp_error( $avatar_id ) || $avatar_id === 0 ) {
				WP_CLI::warning( sprintf( 'Could not get the avatar ID for User #%d', $user->ID ) );
				continue;
			}

			// If the avatar has a rating (G, PG, R etc.) attached to it, we migrate that too
			$avatar_rating = get_user_meta( $user->ID, $from_avatar_rating_meta_key, true );

			$result = $this->sla_logic->assign_avatar( $user->ID, $avatar_id, $avatar_rating );

			if ( $result ) {
				$second_migration_count++;
			}
		}

		WP_CLI::log( sprintf( '%d avatars were migrated from WP User Avatars to Simple Local Avatars.', $second_migration_count ) );

		// Remove the old meta data that's no longer used

		// Remove the metadata used by 'WP User Avatar'
		global $wpdb;
		$wp_user_avatar_meta_key = $wpdb->get_blog_prefix() . 'user_avatar';
		delete_metadata( 'user', 0, $wp_user_avatar_meta_key, false, true );

		// Remove the metadata used by 'WP User Avatars'
		delete_metadata( 'user', 0, $from_avatar_meta_key, false, true );
		delete_metadata( 'user', 0, $from_avatar_rating_meta_key, false, true );

		WP_CLI::success( 'All avatars were migrated and the old metadata was deleted.' );
	}

	public function cmd_lkldnow_republished_content( $args, $assoc_args ) {
		$dry_run = isset( $assoc_args['dry-run'] ) ? true : false;

		// Define constants

		$log_filename = 'lkld_updated_articles.log';

		$already_updated_meta_key = '_newspack_original_content_appended';

		$source_link_meta_key = 'aalink';
		$source_name_meta_key = 'aaname';

		$paragraph_template = '
		
		<!-- wp:paragraph -->
		<p>Source: <a href="%s" target="_blank" rel="noreferrer noopener">%s</a></p>
		<!-- /wp:paragraph -->';

		$args = array(
			'meta_query' => array(
				array(
					'key' => 'aalink',
					'compare' => 'EXISTS',
				),
				array(
					'key' => $already_updated_meta_key,
					'compare' => 'NOT EXISTS',
				),
			),
			'nopaging' => true,
		);

		$query = new WP_Query( $args );

		$updated_posts_count = 0;

		foreach ( $query->posts as $index => $post ) {
			if ( ( $index  + 1 ) % 100 == 0 ) {
				WP_CLI::log( 'Sleeping for 1 second... ');
				sleep( 1 );
			}

			WP_CLI::log( sprintf( 'Updating post #%d', $post->ID ) );

			$source_link = get_post_meta( $post->ID, $source_link_meta_key, true );
			$source_name = get_post_meta( $post->ID, $source_name_meta_key, true );

			// Skip if both metas are empty
			if ( empty( $source_link ) ) {
				WP_CLI::log( 'The link meta value is empty. Skipping... ');
				continue;
			}

			// If the link name is empty, use the URL
			if ( empty( $source_name ) ) {
				$source_name = $source_link;
			}

			// Escape the values and add them to the paragraph temaplte

			$formatted_paragraph = sprintf(
				$paragraph_template,
				esc_url( $source_link ),
				esc_html( $source_name ),
			);

			$new_post_content = $post->post_content . $formatted_paragraph;

			$this->log( $log_filename, sprintf( "New content for post #%d:\n%s", $post->ID, $new_post_content ) );

			if ( ! $dry_run ) {
				$result = wp_update_post( array(
					'ID' => $post->ID,
					'post_content' => $new_post_content,
				) );

				if ( is_wp_error( $result ) ) {
					WP_CLI::warning( sprintf( 'Could not update post #%d', $post->ID ) );
				} else {
					update_post_meta( $post->ID, $already_updated_meta_key, true );
					$updated_posts_count++;
					WP_CLI::log( sprintf( 'Updated #%d successfully.', $post->ID ) );
				}
			}
		}

		WP_CLI::success( sprintf( 'Done! %d posts were updated.', $updated_posts_count ) );
	}

	public function cmd_lkld_migrate_themify_box( $pos_args, $assoc_args ) {
		$dry_run = isset( $assoc_args['dry-run'] ) ? true : false;

		global $wpdb;

		$args = array(
			'post_type' => array( 'post', 'page' ),
			'nopaging' => true,
			'post_status' => 'any',
		);

		$query = new WP_Query( $args );

		$block_wrapper = <<<HTML
<!-- wp:html -->
<div class="newspack-box %s">
%s
</div>
<!-- /wp:html -->
HTML;

		$comment_block_template = <<<HTML
<!-- wp:shortcode -->
%s
<!-- /wp:shortcode -->
HTML;

		$shortcode_pattern = '/\[themify_box.*?](.*?)\[\/themify_box]/s';

		$shortcodes_manipulator = new SquareBracketsElementManipulator();

		foreach ( $query->posts as $post ) {
			preg_match_all( $shortcode_pattern, $post->post_content, $matches );

			if ( isset( $matches[1] ) && count( $matches[1] ) > 0  ) {
				$inner_texts = $matches[1];

				foreach ( $inner_texts as $index => $inner_text ) {
					$fixed_shortcode = $this->fix_shortcode( $matches[0][ $index ] );

					$class = $shortcodes_manipulator->get_attribute_value( 'style', $fixed_shortcode ) ?? '';

					$fixed_inner_text = $this->fix_shortcode( $inner_text );
					$inner_text_wrapped = sprintf( $block_wrapper, $class, $fixed_inner_text );
					$inner_texts[ $index ] = $inner_text_wrapped;
				}

				$finds_with_comments = array_map( function( $shortcode) use( $comment_block_template) {
					return sprintf( $comment_block_template, $shortcode );
				}, $matches[0] );

				$new_post_content = str_replace( $finds_with_comments, $inner_texts, $post->post_content );
				$new_post_content = str_replace( $matches[0], $inner_texts, $new_post_content );

				$this->log( sprintf( '%d.txt', $post->ID ), sprintf( "Old content:\n%s\nNew content:\n%s\n", $post->post_content, $new_post_content ) );

				if ( ! $dry_run && $new_post_content != $post->post_content ) {
					$wpdb->update(
						$wpdb->prefix . 'posts',
						[ 'post_content' => $new_post_content ],
						[ 'ID' => $post->ID ]
					);
					WP_CLI::log( sprintf( 'Updated post #%s', $post->ID ) );
				}
			}
		}

		WP_CLI::success( 'Finished converting themify_box shortcodes to Group blocks.' );
	}

	function cmd_lkld_migrate_themify_icon( $pos_args, $assoc_args ) {
		$dry_run = isset( $assoc_args['dry-run'] ) ? true : false;

		global $wpdb;

		$shortcodes_manipulator = new SquareBracketsElementManipulator();

		$args = array(
			'post_type' => array( 'post', 'page' ),
			'nopaging' => true,
		);

		$query = new WP_Query( $args );

		$shortcode_pattern = '\[themify_icon.*?]';

		$link_template = '<a href="%s">%s</a>';
		$comment_block_template = <<<HTML
<!-- wp:shortcode -->
%s
<!-- /wp:shortcode -->
HTML;

		foreach ( $query->posts as $post ) {

			$results = preg_match_all( '/' . $shortcode_pattern . '/s', $post->post_content, $matches );

			if ( $results === false || $results === 0 ) {
				continue;
			}

			$shortcodes = $matches[0];

			$searches = array();
			$replaces = array();

			foreach ( $shortcodes as $shortcode ) {
				// Make sure the attributes are wrapped in quotation marks
				$fixed_shortcode = $this->fix_shortcode( $shortcode );

				$label = $shortcodes_manipulator->get_attribute_value( 'label', $fixed_shortcode );
				$link = $shortcodes_manipulator->get_attribute_value( 'link', $fixed_shortcode );

				$searches[] = $shortcode;

				// If there is no link, remove the shortcode completely
				if ( strlen( $link ) == 0 || strlen( $label ) == 0 ) {
					$replaces[] = '';
					continue;
				}

				$replaces[] = sprintf( $link_template, $link, $label );
			}

			$searches_with_comments = array_map( function( $shortcode) use( $comment_block_template ) {
				return sprintf( $comment_block_template, $shortcode );
			}, $searches );

			$new_post_content = str_replace( $searches_with_comments, $replaces, $post->post_content );
			$new_post_content = str_replace( $searches, $replaces, $new_post_content );

			$this->log( sprintf( '%d.txt', $post->ID ), sprintf( "Old content:\n%s\nNew content:\n%s\n", $post->post_content, $new_post_content ) );

			if ( ! $dry_run && $new_post_content != $post->post_content ) {
				$wpdb->update(
					$wpdb->prefix . 'posts',
					[ 'post_content' => $new_post_content ],
					[ 'ID' => $post->ID ]
				);
				WP_CLI::log( sprintf( 'Updated post #%s', $post->ID ) );
			}
		}

		WP_CLI::success( 'Finished converting themify_icon shortcodes to anchor links.' );
	}

	/**
	 * Create an attachment from a URL
	 */
	public function assign_upload_file_to_attachment( $url ) {
		$attachment = array(
			'guid'           => $url,
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment );

		return $attachment_id;
	}

	/**
	 * Make sure the shortcode is using quotation marks for attributes, instead of other special characters
	 */
	public function fix_shortcode( $shortcode ) {
		return strtr(
			html_entity_decode( $shortcode ),
			array(
				'”' => '"',
				'″' => '"',
			),
		);
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
