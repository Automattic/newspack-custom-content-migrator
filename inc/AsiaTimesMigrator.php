<?php

namespace NewspackCustomContentMigrator;

use \NewspackCustomContentMigrator\InterfaceMigrator;
use \WP_CLI;

/**
 * Custom migration scripts for Asia Times.
 */
class AsiaTimesMigrator implements InterfaceMigrator {

	/**
	 * @var null|PostsMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {}

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
			'newspack-live-migrate asiatimes-topics',
			[ $this, 'cmd_asiatimes_topics' ],
			[
				'shortdesc' => 'Migrates the Asia Times "Topics" taxonomy to terms in "Tag" taxonomy',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-live-migrate asiatimes-excerpts',
			[ $this, 'cmd_asiatimes_excerpts' ],
			[
				'shortdesc' => 'Migrates the Asia Times excerpts to post subtitles',
				'synopsis'  => [],
			]
		);
	}

	/**
	 * Create tags where needed from the 'topics' taxonomy and assign posts to them.
	 */
	public function cmd_asiatimes_topics() {
		// Temporarily register the taxonomy if it's not registered any more, otherwise term functions won't work.
		if ( ! taxonomy_exists( 'topics' ) ) {
			register_taxonomy( 'topics', 'post' );
		}

		$topics = get_terms( [
			'taxonomy'   => 'topics',
			'hide_empty' => false,
		] );

		if ( is_wp_error( $topics ) ) {
			WP_CLI::error( sprintf( 'Error retrieving topics: %s', $topics->get_error_message() ) );
		}

		foreach ( $topics as $topic ) {

			// Find or create the mapped tag.
			$tag = get_term_by( 'slug', $topic->slug, 'post_tag' );
			if ( ! $tag ) {
				$tag_info = wp_insert_term(
					$topic->name,
					'post_tag',
					[
						'slug'        => $topic->slug,
						'description' => $topic->description,
					]
				);

				if ( is_wp_error( $tag_info ) ) {
					WP_CLI::error( sprintf( 'Error creating tag from topic: %s', $tag_info->get_error_message() ) );
				}

				$tag = get_term( $tag_info['term_id'], 'post_tag' );
			}

			// Get all posts in the topic.
			$posts = get_posts( [
				'posts_per_page' => -1,
				'tax_query'      => [
					[
						'taxonomy' => 'topics',
						'field'    => 'term_id',
						'terms'    => $topic->term_id,
					]
				],
			] );

			// Assign posts from the topic to the tag.
			foreach ( $posts as $post ) {
				wp_set_post_terms( $post->ID, $tag->slug, 'post_tag', true );
				WP_CLI::line( sprintf( 'Updated post %d with tag %s.', $post->ID, $tag->slug ) );
			}
		}

		WP_CLI::line( 'Completed topic to tag migration.' );
		wp_cache_flush();
	}

	/**
	 * Copy the post excerpt to the subtitle field and delete the excerpt.
	 */
	public function cmd_asiatimes_excerpts() {
		global $wpdb;

		$data = $wpdb->get_results( "SELECT ID, post_excerpt FROM {$wpdb->prefix}posts WHERE post_type='post' AND post_excerpt != ''", ARRAY_A );

		foreach ( $data as $post_data ) {
			if ( update_post_meta( $post_data['ID'], 'newspack_post_subtitle', $post_data['post_excerpt'] ) ) {
				wp_update_post( [
					'ID'           => $post_data['ID'],
					'post_excerpt' => '',
				] );

				WP_CLI::line( sprintf( 'Moved excerpt to subtitle for post %d.', $post_data['ID'] ) );
			} else {
				WP_CLI::line( sprintf( 'Failed to update subtitle for post %d. Skipping.', $post_data['ID'] ) );
			}
		}

		WP_CLI::line( 'Completed excerpt to subtitle migration.' );
		wp_cache_flush();
	}
}
