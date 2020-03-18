<?php

namespace NewspackCustomContentMigrator;

use \NewspackCustomContentMigrator\InterfaceMigrator;
use \WP_CLI;

class SubtitleMigrator implements InterfaceMigrator {

	/**
	 * Meta field subtitle is stored in.
	 *
	 * @var string
	 */
	CONST NEWSPACK_SUBTITLE_META_FIELD = 'newspack_post_subtitle';

	/**
	 * @var null|PostsMigrator Instance.
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
	 * @return PostsMigrator|null
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
			'newspack-custom-content-migrator migrate-excerpt-to-subtitle',
			[ $this, 'cmd_migrate_excerpt_to_subtitle' ],
			[
				'shortdesc' => 'Convert all post excerpts into post subtitles.',
			]
		);
	}

	/**
	 * Migrate all post excerpts into the subtitle field.
	 */
	public function cmd_migrate_excerpt_to_subtitle() {
		global $wpdb;

		$posts = get_posts( [
			'post_type'      => 'post',
			'posts_per_page' => -1,
		] );

		foreach ( $posts as $post ) {
			if ( empty( trim( $post->post_excerpt ) ) ) {
				continue;
			}

			update_post_meta( $post->ID, self::NEWSPACK_SUBTITLE_META_FIELD, $post->post_excerpt );
			WP_CLI::line( "Updated: " . $post->ID );
		}

		$wpdb->query( "UPDATE $wpdb->posts SET post_excerpt = '' WHERE post_type = 'post'" );

		WP_CLI::success( 'Done.' );
	}
}
