<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\MigrationLogic\CoAuthorPlus as CoAuthorPlusLogic;
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
	 * @var CoAuthorPlusLogic.
	 */
	private $coauthorsplus_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthorsplus_logic = new CoAuthorPlusLogic();
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
			'newspack-content-migrator washingtonmonthly-transform-custom-taxonomies',
			[ $this, 'cmd_transform_taxonomies' ],
			[
				'shortdesc' => 'Transform custom taxonomies to Categories.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator washingtonmonthly-migrate-acf-authors-to-cap',
			[ $this, 'cmd_migrate_acf_authors_to_cap' ],
			[
				'shortdesc' => 'Migrates authors custom made with Advanced Custom Fields to Co-Authors Plus.',
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
	 * Callable for `newspack-content-migrator washingtonmonthly-migrate-acf-authors-to-cap`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_migrate_acf_authors_to_cap( $args, $assoc_args )
	{
		// Create all CAP GAs.
		$errors = [];
		$acf_authors = $this->get_all_acf_authors();
		$acf_authors_to_gas = [];
		$progress = \WP_CLI\Utils\make_progress_bar( 'CAP GAs created', count( $acf_authors ) );
		WP_CLI::log( 'Converting ACP Authors to CAP GAs...' );
		foreach ( $acf_authors as $acf_author_post_id => $acf_author ) {
			$progress->tick();
			$twitter_byline = ! empty( $acf_author[ 'twitter_username' ] )
				? sprintf ( 'Follow %s on Twitter @%s. ', $acf_author[ 'first_name' ], $acf_author[ 'twitter_username' ] )
				: '';
			$guest_author_id = $this->coauthorsplus_logic->create_guest_author( [
				'display_name' => $acf_author[ 'first_name' ] . ( ! empty( $acf_author[ 'last_name' ] ) ? ' '. $acf_author[ 'last_name' ] : '' ),
				'first_name' => $acf_author[ 'first_name' ],
				'last_name' => $acf_author[ 'last_name' ],
				'description' => $twitter_byline . ( $acf_author[ 'short_bio' ] ?? '' ),
				'avatar' => ( $acf_author[ 'headshot' ] ?? null ),
			] );
			if ( is_wp_error( $guest_author_id ) ) {
				$errors[] = $guest_author_id->get_error_message();
			}
			$acf_authors_to_gas[ $acf_author_post_id ] = $guest_author_id;
		}
		$progress->finish();
		if ( empty( $errors ) ) {
			WP_CLI::success( 'Done creating CAP GAs.' );
		} else {
			WP_CLI::error( sprintf( 'Errors while creating CAP GAs: %s', implode( "\n", $errors ) ) );
		}

		// Assign GAs to their posts.
		$errors = [];
		$posts_with_acf_authors = $this->get_posts_acf_authors();
		WP_CLI::log( 'Assigning CAP GAs to Posts...' );
		$i = 0;
		foreach ( $posts_with_acf_authors as $post_id => $acf_ids ) {
			$i++;
			$ga_ids = [];
			foreach ( $acf_ids as $acf_id ) {
				$ga_ids[] = $acf_authors_to_gas[ $acf_id ] ?? null;
			}
			if ( is_null( $ga_ids ) ) {
				$errors[] = sprintf( 'Could not locate GA for acf_id %d', $acf_id );
			}
			$this->coauthorsplus_logic->assign_guest_authors_to_post( $ga_ids, $post_id );
			WP_CLI::success( sprintf( '(%d/%d) Post ID %d got GA(s) %s', $i, count( $posts_with_acf_authors ), $post_id, implode( ',', $ga_ids ) ) );
		}
		if ( empty( $errors ) ) {
			WP_CLI::success( 'Done assigning GAs to Posts.' );
		} else {
			$log_file = 'wm_err_authors.log';
			$msg = sprintf( 'Errors while assigning GAs to posts and saved to log %s', $log_file );
			WP_CLI::error( $msg );
			file_put_contents( $log_file, $msg );
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

	/**
	 * Gets all existng ACF Authors.
	 *
	 * @return array ACF Authors data. Key is original ACF Author's post ID, and subarray are meta fields which make up their data
	 *               (e.g. first_name, etc.)
	 */
	private function get_all_acf_authors() {
		global $wpdb;

		$acf_authors = [];

		$results = $wpdb->get_results( $wpdb->prepare(
			"select p.ID, pm.meta_key, pm.meta_value
			from wp_rbTMja_posts p
			join wp_rbTMja_postmeta pm on pm.post_id = p.ID
			where post_type = 'people'
			and meta_key in ( 'first_name', 'last_name', 'headshot', 'short_bio', 'twitter_username' );"
		), ARRAY_A );
		foreach ( $results as $result ) {
			$acf_authors[ $result[ 'ID' ] ] = array_merge(
				$acf_authors[ $result[ 'ID' ] ] ?? [],
				[ $result[ 'meta_key' ] => $result[ 'meta_value' ] ]
			);
		}

		return $acf_authors;
	}

	/**
	 * Gets a list of all the Posts and their Authors.
	 *
	 * @return array Keys are Post IDs, value is a sub array of one or more ACF Author Post IDs.
	 */
	private function get_posts_acf_authors() {
		global $wpdb;

		$posts_with_acf_authors = [];

		$results = $wpdb->get_results(
			"select p.ID, pm.meta_key, pm.meta_value
			from `wp_rbTMja_posts` p
			join `wp_rbTMja_postmeta` pm on pm.post_id = p.ID
			where p.post_type = 'post'
			and pm.meta_key = 'author'
			and pm.meta_value <> '';",
			ARRAY_A
		);
		foreach ( $results as $result ) {
			$posts_with_acf_authors[ $result[ 'ID' ] ] = unserialize( $result[ 'meta_value' ] );
		}

		return $posts_with_acf_authors;
	}
}
