<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;

/**
 * Custom migration scripts for Washington Monthly.
 */
class WashingtonMonthlyMigrator implements InterfaceMigrator {

	CONST PARENT_ISSUES_CATEGORY = 'Issues';

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
			'newspack-content-migrator washingtonmonthly-transform-custom-taxonomies',
			[ $this, 'cmd_transform_taxonomies' ],
			[
				'shortdesc' => 'Transform custom taxonomies to Categories.',
				'synopsis'  => [],
			]
		);
	}

	/**
	 * Callable for `newspack-content-migrator washingtonmonthly-transform-custom-taxonomies`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_transform_taxonomies( $args, $assoc_args ) {
		// Get all issues.
		$issues_taxonomies = $this->get_all_issues_taxonomies();
		// Get all posts in taxonomies.
		$posts_in_termtaxonomies = [];

		foreach ( $issues_taxonomies as $issue_taxonomy ) {
			$term_taxonomy_id = $issue_taxonomy[ 'term_taxonomy_id' ];
			$posts_in_termtaxonomies[ $term_taxonomy_id ] = $this->get_posts_in_issue( $term_taxonomy_id );
		}

		// Get/create the parent category.
		$parent_cat_id = wp_create_category( self::PARENT_ISSUES_CATEGORY );
		if ( is_wp_error( $parent_cat_id ) ) {
			WP_CLI::error( sprintf( 'Could not create/get parent category %s.', self::PARENT_ISSUES_CATEGORY ) );
		}

        // Get/create subcategories with name and slug, parent category should be "Issues".
		$errors = [];
		$categories = [];
		WP_CLI::log( sprintf( 'Creating %d categories...', count( $issues_taxonomies ) ) );
		foreach ( $issues_taxonomies as $issue_taxonomy ) {
			$category_name = $issue_taxonomy[ 'name' ];
			$category_slug = $issue_taxonomy[ 'slug' ];

			$issue_cat_id = wp_insert_category( [
				'cat_name' => $category_name,
				'category_nicename' => $category_slug,
				'category_parent' => $parent_cat_id
			] );
			if ( is_wp_error( $issue_cat_id ) ) {
				$msg = sprintf( 'Error creating cat %s %s.', $category_name, $category_slug );
				WP_CLI::warning( $msg );
				$errors[] = $msg;
				continue;
			}

			$categories[ $issue_taxonomy[ 'term_taxonomy_id' ] ] = $issue_cat_id;
		}
		if ( empty( $errors ) ) {
			WP_CLI::success( 'Done creating cats.' );
		} else {
			WP_CLI::error( sprintf( 'Errors while creating cats: %s', implode( "\n", $errors ) ) );
		}

		// Assign new categories to all the posts issues.
		WP_CLI::log( 'Assigning posts to their new categories...' );
		$errors = [];
		foreach ( $posts_in_termtaxonomies as $term_taxonomy_id => $posts ) {
			$cat_id = $categories[ $term_taxonomy_id ] ?? null;
			if ( is_null( $cat_id ) ) {
				$msg = sprintf( 'Could not fetch category for term_taxonomy_id %d).', (int) $term_taxonomy_id );
				WP_CLI::warning( $msg );
				$errors[] = $msg;
			}

			foreach ( $posts as $post_id ) {
				$assigned = wp_set_post_categories( $post_id, $cat_id, true );
				if ( is_wp_error( $assigned ) ) {
					$msg = sprintf( 'Could not assign category %d to post %d.', (int) $cat_id, (int) $post_id );
					WP_CLI::warning( $msg );
					$errors[] = $msg;
				}
			}
		}

		if ( empty( $errors ) ) {
			WP_CLI::success( 'Done assigning cats to posts issues.' );
		} else {
			WP_CLI::error( sprintf( 'Errors while assigning cats: %s', implode( "\n", $errors ) ) );
		}
	}

	/**
	 * Gets a list of all the Issues as Taxonomies.
	 *
	 * @return array Taxonomies as subarrays described by key value pairs term_taxonomy_id, term_id, name, slug.
	 */
	private function get_all_issues_taxonomies() {
		global $wpdb;

		$issues_taxonomies = [];

		$results = $wpdb->get_results( $wpdb->prepare(
			"select tt.taxonomy, tt.term_taxonomy_id, tt.term_id, t.name, t.slug
			from `wp_rbTMja_term_taxonomy` tt
			join `wp_rbTMja_terms` t on t.term_id = tt.term_id
			where tt.taxonomy = 'issues'
			order by tt.term_taxonomy_id;"
		), ARRAY_A );
		foreach ( $results as $result ) {
			$issues_taxonomies[] = [
				'term_taxonomy_id' => $result[ 'term_taxonomy_id' ],
				'term_id' => $result[ 'term_id' ],
				'name' => $result[ 'name' ],
				'slug' => $result[ 'slug' ],
			];
		}

		return $issues_taxonomies;
	}

	private function get_posts_in_issue( $term_taxonomy_id ) {
		global $wpdb;

		$posts = [];

		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM `wp_rbTMja_term_relationships` WHERE term_taxonomy_id = %d;", (int) $term_taxonomy_id
		), ARRAY_A );
		foreach ( $results as $result ) {
			$posts[] = $result[ 'object_id' ];
		}

		return $posts;
	}
}
