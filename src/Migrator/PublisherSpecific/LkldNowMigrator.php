<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use NewspackCustomContentMigrator\MigrationLogic\SimpleLocalAvatars;
use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;
use WP_Query;

/**
 * Custom migration scripts for LkldNow.
 */
class LkldNowMigrator implements InterfaceMigrator {

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * @var null|SimpleLocalAvatars Instance of \MigrationLogic\SimpleLocalAvatars
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

			$result = $this->sla_logic->import_avatar( $user->ID, $avatar_id, $avatar_rating );

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
