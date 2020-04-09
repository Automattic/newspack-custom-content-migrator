<?php

namespace NewspackCustomContentMigrator;

use \NewspackCustomContentMigrator\InterfaceMigrator;
use \WP_CLI;

class YubaNetMigrator implements InterfaceMigrator {

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
			'newspack-custom-content-migrator yubanet-migrate-subtitle-field',
			[ $this, 'cmd_migrate_subtitle_field' ],
			[
				'shortdesc' => 'Migrate YubaNet custom subtitle into Newspack subtitles.',
			]
		);
	}

	/**
	 * Migrate all post excerpts into the subtitle field.
	 */
	public function cmd_migrate_subtitle_field() {
		global $wpdb;

		$posts = get_posts( [
			'post_type'      => 'post',
			'posts_per_page' => -1,
			'meta_query'     => [ [
				'key'     => 'td_post_theme_settings',
				'compare' => 'EXISTS',
			] ],
		] );

		foreach ( $posts as $post ) {

			WP_CLI::line( sprintf( 'Processing post %d', $post->ID ) );

			$yn_subtitle = get_post_meta( $post->ID, 'td_post_theme_settings', true );
			if ( ! isset( $yn_subtitle['td_subtitle'] ) ) {
				WP_CLI::warning( sprintf( 'Skipping %d because subtitle is empty.', $post->ID ) );
				continue;
			}

			$np_subtitle = get_post_meta( $post->ID, self::NEWSPACK_SUBTITLE_META_FIELD, true );
			if ( ! empty( $np_subtitle ) ) {
				WP_CLI::warning( sprintf( 'Skipping %d because we migrated already.', $post->ID ) );
				continue;
			}

			WP_CLI::line( sprintf(
				'Updating %d to migrate subtitle "%s".',
				$post->ID,
				$yn_subtitle['td_subtitle']
			) );
			update_post_meta(
				$post->ID,
				self::NEWSPACK_SUBTITLE_META_FIELD,
				$yn_subtitle['td_subtitle']
			);
			WP_CLI::success( "Updated: " . $post->ID );

		}

		wp_cache_flush();
	}
}
