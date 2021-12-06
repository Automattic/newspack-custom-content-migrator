<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\MigrationLogic\CoAuthorPlus as CoAuthorPlusLogic;
use \WP_CLI;

/**
 * Custom migration scripts for Washington Monthly.
 */
class PublicIntegrityMigrator implements InterfaceMigrator {

	const PARENT_ISSUES_CATEGORY = 'Issues';

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
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator publicintegrity-migrate-acf-authors-to-cap',
			[ $this, 'cmd_migrate_acf_authors_to_cap' ],
			[
				'shortdesc' => 'Migrates authors custom made with Advanced Custom Fields to Co-Authors Plus.',
				'synopsis'  => [],
			]
		);
	}

	/**
	 * Callable for `newspack-content-migrator publicintegrity-migrate-acf-authors-to-cap`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_migrate_acf_authors_to_cap( $args, $assoc_args ) {
		// Create all CAP GAs.
		$errors      = [];
		$acf_authors = $this->get_all_acf_authors();

		$acf_authors_to_gas = [];
		$progress           = \WP_CLI\Utils\make_progress_bar( 'CAP GAs created', count( $acf_authors ) );
		WP_CLI::log( 'Converting ACP Authors to CAP GAs...' );
		foreach ( $acf_authors as $acf_author_post_id => $acf_author ) {
			$progress->tick();
			$twitter_byline  = ! empty( $acf_author['twitter_username'] )
				? sprintf( 'Follow %s on Twitter @%s. ', $acf_author['first_name'], $acf_author['twitter'] )
				: '';
			$guest_author_id = $this->coauthorsplus_logic->create_guest_author(
				[
					'display_name' => $acf_author['first_name'] . ( ! empty( $acf_author['last_name'] ) ? ' ' . $acf_author['last_name'] : '' ),
					'first_name'   => $acf_author['first_name'],
					'last_name'    => $acf_author['last_name'],
					'description'  => $twitter_byline . ( $acf_author['bio'] ?? '' ),
					'avatar'       => ( $acf_author['picture'] ?? null ),
				]
			);
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
		$errors                 = [];
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
				WP_CLI::success( sprintf( '(%d/%d) Possible error with assigning Post ID %d, check log file when finished.', $i, count( $posts_with_acf_authors ), $post_id ) );
				continue;
			}
			$this->coauthorsplus_logic->assign_guest_authors_to_post( $ga_ids, $post_id );
			WP_CLI::success( sprintf( '(%d/%d) Post ID %d got GA(s) %s', $i, count( $posts_with_acf_authors ), $post_id, implode( ',', $ga_ids ) ) );
		}
		if ( empty( $errors ) ) {
			WP_CLI::success( 'Done assigning GAs to Posts.' );
		} else {
			$log_file = 'wm_err_authors.log';
			$msg      = sprintf( 'Errors while assigning GAs to posts and saved to log %s', $log_file );
			WP_CLI::error( $msg );
			file_put_contents( $log_file, $msg );
		}
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

		$results = $wpdb->get_results(
			"select {$wpdb->prefix}terms.term_id as ID, {$wpdb->prefix}termmeta.meta_key, {$wpdb->prefix}termmeta.meta_value from {$wpdb->prefix}posts
			left join {$wpdb->prefix}users on post_author = {$wpdb->prefix}users.ID
			left join {$wpdb->prefix}terms on {$wpdb->prefix}terms.term_id = post_author
			left join {$wpdb->prefix}termmeta on {$wpdb->prefix}termmeta.term_id = {$wpdb->prefix}terms.term_id
			where {$wpdb->prefix}termmeta.meta_key is not null
			and meta_key in ( 'first_name', 'last_name', 'picture', 'bio', 'twitter' )",
			ARRAY_A
		);

		foreach ( $results as $result ) {
			$acf_authors[ $result['ID'] ] = array_merge(
				$acf_authors[ $result['ID'] ] ?? [],
				[ $result['meta_key'] => $result['meta_value'] ]
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
			from `{$wpdb->prefix}posts` p
			join `{$wpdb->prefix}postmeta` pm on pm.post_id = p.ID
			where p.post_type = 'post'
			and pm.meta_key = 'author'
			and pm.meta_value <> '';",
			ARRAY_A
		);
		foreach ( $results as $result ) {
			$posts_with_acf_authors[ $result['ID'] ] = unserialize( $result['meta_value'] );
		}

		return $posts_with_acf_authors;
	}
}
