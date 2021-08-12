<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;

class FeaturedImagesMigrator implements InterfaceMigrator {
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
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command( 'newspack-content-migrator set-featured-images-from-first-image', array( $this, 'cmd_set_featured_images_from_first_image' ) );
	}

	/**
	 * Callable for newspack-content-migrator set-featured-images-from-first-image command.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_set_featured_images_from_first_image( $args, $assoc_args ) {
		WP_CLI::line( sprintf( "Setting Posts' featured images from first image attachment..." ) );

		global $wpdb;
		$image_attachments_with_parents = $wpdb->get_results(
			"SELECT wp.ID, wp.post_parent
			FROM {$wpdb->posts} wp
			JOIN {$wpdb->posts} wp2
			ON wp2.ID = wp.post_parent AND wp2.post_type = 'post'
			WHERE wp.post_type = 'attachment'
			AND wp.post_mime_type LIKE 'image/%'
			GROUP BY wp.post_parent;"
		);
		WP_CLI::line( sprintf( 'Found %s attachment images with parent posts set.', count( $image_attachments_with_parents ) ) );
		foreach ( $image_attachments_with_parents as $key_image_attachments_with_parents => $attachment ) {
			$parent_id     = $attachment->post_parent;
			$attachment_id = $attachment->ID;
			if ( ! has_post_thumbnail( $parent_id ) ) {
				set_post_thumbnail( $parent_id, $attachment_id );
				WP_CLI::line( sprintf( '(%d/%d) Set featured image on post %s from attachment %s.', $key_image_attachments_with_parents + 1, count( $image_attachments_with_parents ), $parent_id, $attachment_id ) );
			} else {
				WP_CLI::line( sprintf( '(%d/%d) Skipping, Post %d already has a featured image %d.', $key_image_attachments_with_parents + 1, count( $image_attachments_with_parents ), $parent_id, $attachment_id ) );
			}
		}
	}
}
