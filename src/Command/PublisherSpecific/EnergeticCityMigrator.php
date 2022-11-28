<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use DateTime;
use DateTimeZone;
use Exception;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use \WP_CLI;

/**
 * EnergeticCityMigrator.
 */
class EnergeticCityMigrator implements InterfaceCommand {

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
				'shortdesc' => 'Will attempt to tie featured images to posts.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator energetic-city-update-posts',
			[ $this, 'update_posts' ],
			[
				'shortdesc' => 'Will fix the metadata for posts that were brought in from an XML import.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator energetic-city-move-posts',
			[ $this, 'move_posts' ],
			[
				'shortdesc' => 'Will copy and paste posts from missing data set to live data set.',
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

	/**
	 * THIS NOT SHOULD NOT BE RUN THE SITE. LOCAL ONLY.
	 *
	 * @throws Exception
	 */
	public function update_posts() {
		global $wpdb;

		$posts = $wpdb->get_results(
			"SELECT p.*, IF(lu.user_nicename IS NOT NULL, lu.ID, 41) as live_post_author
                  FROM $wpdb->posts p
                           LEFT JOIN $wpdb->users u ON p.post_author = u.ID
                           LEFT JOIN live_users lu ON u.user_nicename = lu.user_nicename"
		);

		foreach ( $posts as $post ) {
			$post_date          = new DateTime( $post->post_date, new DateTimeZone( 'America/Edmonton' ) );
			$post_modified_date = new DateTime( $post->post_modified, new DateTimeZone( 'America/Edmonton' ) );
			$post_date->setTimezone( new DateTimeZone( 'GMT' ) );
			$post_modified_date->setTimezone( new DateTimeZone( 'GMT' ) );

			$wpdb->update(
				$wpdb->posts,
				[
					'post_author'       => $post->live_post_author,
					'post_date_gmt'     => $post_date->format( 'Y-m-d H:i:s' ),
					'post_modified_gmt' => $post_date->format( 'Y-m-d H:i:s' ),
					'post_name'         => sanitize_title_with_dashes( $post->post_title ),
					'post_status'       => 'publish',
					'comment_status'    => 'closed',
					'ping_status'       => 'closed',
					'guid'              => "https://energeticcity.ca/?p=$post->ID",
				],
				[
					'ID' => $post->ID,
				]
			);
		}
	}

	/**
	 * Will copy posts that were recovered and inserted into missing_2_posts table into the main wp_posts table.
	 */
	public function move_posts() {
		global $wpdb;

		$missing_posts = $wpdb->get_results( 'SELECT * FROM missing_2_posts' );

		foreach ( $missing_posts as $post ) {

			$new_post_id = wp_insert_post(
				[
					'post_author'           => $post->post_author,
					'post_date'             => $post->post_date,
					'post_date_gmt'         => $post->post_date_gmt,
					'post_content'          => $post->post_content,
					'post_title'            => $post->post_title,
					'post_excerpt'          => $post->post_excerpt,
					'post_status'           => $post->post_status,
					'comment_status'        => $post->comment_status,
					'post_password'         => $post->post_password,
					'post_name'             => $post->post_name,
					'to_ping'               => $post->to_ping,
					'pinged'                => $post->pinged,
					'post_modified'         => $post->post_modified,
					'post_modified_gmt'     => $post->post_modified_gmt,
					'post_content_filtered' => $post->post_content_filtered,
					'post_parent'           => $post->post_parent,
					'guid'                  => $post->guid,
					'menu_order'            => $post->menu_order,
					'post_type'             => $post->post_type,
					'post_mime_type'        => $post->post_mime_type,
					'comment_count'         => $post->comment_count,
				]
			);

			if ( $new_post_id ) {
				WP_CLI::line( "Old Post ID: $post->ID New Post ID: $new_post_id" );
				$wpdb->update(
					$wpdb->posts,
					[
						'guid' => "https://energeticcity.ca/?p=$new_post_id",
					],
					[
						'ID' => $new_post_id,
					]
				);
			}
		}
	}
}
