<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use Simple_Local_Avatars;
use \WP_CLI;

/**
 * Custom migration scripts for LkldNow.
 */
class LkldNowMigrator implements InterfaceMigrator {

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * @var null|Simple_Local_Avatars Instance of Simple_Local_Avatars
	 */
	private $simple_local_avatars;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$plugins_path = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ABSPATH . 'wp-content/plugins';

		$simple_local_avatars_plugin_file = $plugins_path . '/simple-local-avatars/simple-local-avatars.php';
		
		if ( is_file( $simple_local_avatars_plugin_file ) && include_once $simple_local_avatars_plugin_file ) {
			$this->simple_local_avatars = new Simple_Local_Avatars();
		}
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
	}

	/**
	 * Extract the old avatars from meta and migrate them to Simple Local Avatars.
	 */
	public function cmd_lkldnow_migrate_avatars() {
		if ( $this->simple_local_avatars == null ) {
			WP_CLI::warning( 'Simple Local Avatars not found. Install and activate it before using this command.' );
			return;
		}

        /**
		 * Simple Local Avatars already has a method for migrating from 'WP User Avatar', so we use it instead of rewriting it
		 */

		$first_migration_count = $this->simple_local_avatars->migrate_from_wp_user_avatar();

		WP_CLI::log( sprintf( '%d avatars were migrated from WP User Avatar to Simple Local Avatars.', $first_migration_count ) );

		/**
		 * Migrate avatars from 'WP User Avatars' to Simple Local Avatars
		 */
		
		$from_avatar_meta_key = 'wp_user_avatars';
		$from_avatar_rating_meta_key = 'wp_user_avatars_rating';

		$to_avatar_meta_key = 'simple_local_avatar';
		$to_avatar_rating_meta_key = 'simple_local_avatar_rating';

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
			
			$this->simple_local_avatars->assign_new_user_avatar( (int) $avatar_id, $user->ID );

			// If the avatar has a rating (G, PG, R etc.) attached to it, we migrate that too
			$avatar_rating = get_user_meta( $user->ID, $from_avatar_rating_meta_key, true );

			if ( ! empty( $avatar_rating ) ) {
				update_user_meta( $user->ID, $to_avatar_rating_meta_key, $avatar_rating );
			}

			$is_migrated = get_user_meta( $user->ID, $to_avatar_meta_key, true );

			if ( ! empty( $is_migrated ) ) {

				$new_avatar = get_user_meta( $user->ID, $to_avatar_meta_key, true );

				if ( ! empty( $new_avatar ) ) {
					$second_migration_count++;
				}
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

	/**
	 * Create an attachment from a URL
	 */
	public function assign_upload_file_to_attachment( $url ) {
		$attachment = array(
			'guid'           => $url, 
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $this->get_filename_from_upload_url( $url ) );

		return $attachment_id;
	}

	/** Convert a URL to a file name
	 *  For example https://site.local/wp-content/uploads/2022/08/image.jpg to 2022/08/image.jpg
	 */
	public function get_filename_from_upload_url( $url ) {
		$url_parsed = wp_parse_url( $url );
		$file_path = $url_parsed['path'];

		$relative_path = str_replace( '/wp-content/uploads/', '', $file_path );

		return $relative_path;
	}
}
