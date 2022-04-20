<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use Exception;
use NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;

/**
 * EnergeticCityMigrator.
 */
class EnergeticCityMigrator implements InterfaceMigrator {

	/**
	 * EnergeticCityMigrator Instance.
	 *
	 * @var EnergeticCityMigrator
	 */
	private static $instance;

	/**
	 * Constructor.
	 */
	public function __construct() {
	}

	/**
	 * Get Instance.
	 *
	 * @return EnergeticCityMigrator
	 */
	public static function get_instance() {
		$class = get_called_class();

		if ( null === self::$instance ) {
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator energetic-city-fix-missing-featured-images',
			[ $this, 'fix_missing_featured_images' ],
			[
				'shortdesc' => 'Will handle migrating custom content from wp_postmeta to wp_post.post_content',
				'synopsis'  => [],
			]
		);
	}

	/**
	 * Handler.
	 */
	public function fix_missing_featured_images() {
		/*
		 * Find all posts with post_title beginning with https://media.socastsrm.com
		 * For each post_title, parse and obtain last path string
		 * remove any extension
		 * use that string to search in posts table for matching post_name
		 * if found, delete any wp_postmeta row for post with meta_key = '_thumbnail_id'
		 * then create new row with meta_key = '_thumbnail_id', post_id, and meta_value = to the media post_id
		 */

		global $wpdb;

		$image_posts = $wpdb->get_results( "SELECT ID, post_name, post_title, post_type FROM $wpdb->posts WHERE post_type = 'attachment' AND post_title LIKE 'https://media.socastsrm.com%'" );

		foreach ( $image_posts as $post ) {
			$path          = parse_url( $post->post_title, PHP_URL_PATH );
			$exploded_path = explode( '/', $path );
			$filename      = array_pop( $exploded_path );
			$position      = strrpos( $filename, '.', - 1 );

			if ( false !== $position ) {
				$filename = substr( $filename, 0, $position );
			}

			WP_CLI::line( $filename );
			$found_post = $wpdb->get_row( "SELECT ID, post_name, post_title, post_type FROM $wpdb->posts WHERE post_type = 'post' AND post_name = '$filename'" );

			if ( $found_post ) {
				$deleted = $wpdb->delete(
					$wpdb->postmeta,
					[
						'meta_key' => '_thumbnail_id',
						'post_id'  => $post->ID,
					]
				);

				if ( false !== $deleted ) {
					WP_CLI::line( "Removed $deleted featured image rows in $wpdb->postmeta" );
				}

				$wpdb->insert(
					$wpdb->postmeta,
					[
						'meta_key'   => '_thumbnail_id',
						'meta_value' => $found_post->ID,
						'post_id'    => $post->ID,
					]
				);
				WP_CLI::line( 'Updated.' );
			}
		}
	}

}
