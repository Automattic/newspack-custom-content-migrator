<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;

class LinkedFeaturedImagesMigrator implements InterfaceMigrator {
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
		WP_CLI::add_command( 'newspack-content-migrator set-featured-images-from-linked', array( $this, 'cmd_set_featured_images_from_linked' ) );
	}

	/**
	 * Callable for attachments_logicset-featured-images-from-linked command.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_set_featured_images_from_linked( $args, $assoc_args ) {
		WP_CLI::line( sprintf( 'Setting featured images from linked attachments...' ) );

		global $wpdb;
		$attachments_with_parents = $wpdb->get_results(
			$wpdb->prepare( "SELECT ID,post_parent FROM $wpdb->posts WHERE post_type = %s AND post_parent IS NOT NULL", 'attachment' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		WP_CLI::line( sprintf( 'Found %s attachments with parent posts set.', count( $attachments_with_parents ) ) );
		foreach ( $attachments_with_parents as $attachment ) {
			$parent_id     = $attachment->post_parent;
			$attachment_id = $attachment->ID;
			if ( ! has_post_thumbnail( $parent_id ) ) {
				set_post_thumbnail( $parent_id, $attachment_id );
				WP_CLI::line( sprintf( 'Set featured image on post %s from attachment %s.', $parent_id, $attachment_id ) );
			}
		}
	}
}
