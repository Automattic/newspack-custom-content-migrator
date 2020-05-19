<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;
use \WP_Error;
use \CoAuthors_Guest_Authors;
use \CoAuthors_Plus;

/**
 * Custom migration scripts for Local News Matters.
 */
class LocalNewsMattersMigrator implements InterfaceMigrator {

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

		// Assign posts to new users.
		WP_CLI::add_command(
			'newspack-content-migrator lnm-posts-to-users',
			[ $this, 'cmd_lnm_posts_to_users' ],
			[
				'shortdesc' => 'Assigns posts to WP users based on the old author meta.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'post_id',
						'description' => __('ID of a specific post to process'),
						'optional'    => true,
					],
					[
						'type'        => 'assoc',
						'name'        => 'dry_run',
						'description' => __('Perform a dry run, making no changes.'),
						'optional'    => true,
					],
				],
			]
		);

	}

	/**
	 * Move author meta to CAP Guest authors.
	 */
	public function cmd_lnm_posts_to_users( $args, $assoc_args ) {

		list( $post_id ) = $args;
		$dry_run = ( isset( $assoc_args['dry_run'] ) );

		WP_CLI::line( sprintf( 'Post ID is %d', $post_id ) );
		WP_CLI::line( sprintf( 'Dry run is %s', $dry_run ) );
		wp_die();

		// Let us count the ways in which we fail.
		$error_count = 0;

		$posts = get_posts( [
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_query'     => [ [
				'key'     => 'sfly_guest_author_names',
				'compare' => 'EXISTS',
			] ],
		] );

		if ( empty( $posts ) ) {
			WP_CLI::error( __('No posts found.') );
		} else {
			WP_CLI::line( sprintf(
				__('Found %d posts to check co-authors on.'),
				count( $posts )
			) );
		}

		$coauthors_guest_authors = new CoAuthors_Guest_Authors();

		foreach ( $posts as $post ) {

			WP_CLI::line( sprintf( __('Checking post %d'), $post->ID ) );

			// Get the guest author data out.
			$original_guest_authors = get_post_meta( $post->ID, 'sfly_guest_author_names', true );
			$original_guest_authors = explode( ',', $original_guest_authors );

			WP_CLI::line( sprintf( __('Checking %d guest authors'), count( $original_guest_authors ) ) );

			$new_guest_authors = [];

			// Find Guest Authors from the names in the meta field.
			foreach ( $original_guest_authors as $author_name ) {

				WP_CLI::line( sprintf( __('Handling guest author called %s'), $author_name ) );

				// Find an existing guest author.
				$cap_guest_author = $coauthors_guest_authors->get_guest_author_by( 'user_nicename', $author_name );
				if ( ! $cap_guest_author ) {
					$coauthors_guest_authors->get_guest_author_by( 'login', $author_name );
				}

				if ( ! $cap_guest_author ) { // No guest author was found.
					WP_CLI::line( __('No CAP Guest Author found.') );
				} else {
					$new_guest_authors[] = $cap_guest_author;
				}

			}

			if ( empty( $new_guest_authors ) ) {
				WP_CLI::warning( __('No guest authors to add to this post.') );
				continue;
			}

			WP_CLI::line( sprintf(
				__('Adding these guest authors to post %d: %s'),
				$post->ID,
				implode( ',', $new_guest_authors )
			) );

			if ( ! $dry_run ) {
				global $coauthors_plus;
				$this->assign_guest_authors_to_post( $new_guest_authors, $post->ID, $coauthors_plus, $coauthors_guest_authors );
			}

		}

		WP_CLI::success( esc_html__( 'Completed guest authors migration.' ) );

	}

	/**
	 * Assigns Guest Authors to the Post. Completely overwrites the existing list of authors.
	 *
	 * @param array $guest_author_ids Guest Author IDs.
	 * @param int   $post_id          Post IDs.
	 */
	public function assign_guest_authors_to_post( array $guest_author_ids, $post_id, $coauthors_plus, $coauthors_guest_authors ) {
		$coauthors = [];
		foreach ( $guest_author_ids as $guest_author_id ) {
			$guest_author = $coauthors_guest_authors->get_guest_author_by( 'id', $guest_author_id );
			$coauthors[]  = $guest_author->user_nicename;
		}
		$coauthors_plus->add_coauthors( $post_id, $coauthors, $append_to_existing_users = false );
	}

}
