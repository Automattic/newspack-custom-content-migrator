<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;

/**
 * Custom migration scripts for Asia Times.
 */
class AsiaTimesMigrator implements InterfaceMigrator {

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {}

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
			'newspack-content-migrator asiatimes-topics',
			[ $this, 'cmd_asiatimes_topics' ],
			[
				'shortdesc' => 'Migrates the Asia Times "Topics" taxonomy to terms in "Tag" taxonomy',
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
}
