<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;

class SubtitleMigrator implements InterfaceMigrator {

	/**
	 * Meta field subtitle is stored in.
	 *
	 * @var string
	 */
	CONST NEWSPACK_SUBTITLE_META_FIELD = 'newspack_post_subtitle';

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
			'newspack-content-migrator migrate-excerpt-to-subtitle',
			[ $this, 'cmd_migrate_excerpt_to_subtitle' ],
			[
				'shortdesc' => 'Convert all post excerpts into post subtitles.',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator migrate-custom-field-to-subtitle',
			[ $this, 'cmd_migrate_custom_field_to_subtitle' ],
			[
				'shortdesc' => 'Migrate subtitle from a custom field to the Newspack field.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'meta-key',
						'description' => 'Key of the custom field to convert.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-id',
						'description' => 'ID of a specific post to convert.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Migrate all post excerpts into the subtitle field.
	 */
	public function cmd_migrate_excerpt_to_subtitle() {
		global $wpdb;

		$data = $wpdb->get_results( "SELECT ID, post_excerpt FROM $wpdb->posts WHERE post_type='post' AND post_excerpt != ''", ARRAY_A );

		foreach ( $data as $post_data ) {
			if ( empty( trim ( $post_data['post_excerpt'] ) ) ) {
				continue;
			}

			update_post_meta( $post_data['ID'], self::NEWSPACK_SUBTITLE_META_FIELD, $post_data['post_excerpt'] );
			WP_CLI::line( "Updated: " . $post_data['ID'] );
		}

		$wpdb->query( "UPDATE $wpdb->posts SET post_excerpt = '' WHERE post_type = 'post'" );

		wp_cache_flush();
		WP_CLI::success( 'Done.' );
	}

	/**
	 * Migrate subtitle from a custom field to the Newspack field
	 */
	public function cmd_migrate_custom_field_to_subtitle( $args, $assoc_args ) {

		// Get the meta key.
		$meta_key = $args[0];

		// Grab the post ID, if there is one.
		$post_id = isset( $assoc_args[ 'post-id' ] ) ? (int) $assoc_args['post-id'] : false;

		// Grab the posts to convert then.
		if ( $post_id ) {
			$posts = is_null( get_post( $post_id ) ) ? [] : [ get_post( $post_id ) ];
		} else {
			$posts = get_posts( [
				'posts_per_page' => -1,
				'post_status'    => 'any',
				'meta_query'     => [
					'relation' => 'AND',
					[
						// Ignore posts without the specified meta key.
						'key'     => $meta_key,
						'compare' => 'EXISTS',
					],
					[
						// Exclude posts that already have a subtitle.
						'key'     => 'newspack_post_subtitle',
						'compare' => 'NOT EXISTS',
					],
				],
			] );
		}

		if ( empty( $posts ) ) {
			WP_CLI::error( 'No posts found.' );
		}

		foreach ( $posts as $post ) {

			$subtitle          = get_post_meta( $post->ID, esc_sql( $meta_key ), true );
			$newspack_subtitle = update_post_meta( $post->ID, 'newspack_post_subtitle', esc_sql( $subtitle ) );

			if ( ! $newspack_subtitle ) {
				WP_CLI::warning( sprintf( 'Failed to update subtitle on %d', $post->ID ) );
			} else {
				WP_CLI::success( sprintf( 'Migrated subtitle on post %d', $post->ID ) );
				add_post_meta( $post->ID, 'np_subtitle_migration', sprintf(
					'Migrated subtitle "%s" from meta key "%s" at %s.',
					esc_sql( $subtitle ),
					esc_sql( $meta_key ),
					date('c')
				) );
			}

		}

		wp_cache_flush();

	}

}
