<?php

namespace NewspackCustomContentMigrator;

use \NewspackCustomContentMigrator\InterfaceMigrator;
use \WP_CLI;
use \WP_Error;

/**
 * Custom migration scripts for Sahan Journal.
 */
class SahanJournalMigrator implements InterfaceMigrator {

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

		// Migrate Author CPT to WP User accounts.
		WP_CLI::add_command(
			'newspack-live-migrate sahanjournal-authors',
			[ $this, 'cmd_sahanjournal_authors' ],
			[
				'shortdesc' => 'Migrates the Sahan Journal "Authors" CPT to native WP users.',
				'synopsis'  => [],
			]
		);

	}

	/**
	 * Create users where needed from the 'authors' CPT and assign them to posts.
	 */
	public function cmd_sahanjournal_authors() {

		// Temporarily register the CPT if it's not already registered.
		if ( ! post_type_exists( 'authors' ) ) {
			register_post_type( 'authors' );
		}

		$authors = $this->get_authors();

		// Let us count the ways in which we fail.
		$error_count = 0;

		foreach ( $authors as $author ) {

			WP_CLI::line( sprintf( 'Migrating author %s (%d)', get_the_title( $author->ID ), $author->ID ) );

			// Check if there is already a WP user.
			$user = get_user_by( 'email', get_post_meta( $author->ID, 'contributor_email', true ) );
			if ( ! $user ) {
				$user = $this->create_new_user( $author );
				if ( is_wp_error( $user ) ) {
					WP_CLI::warning( sprintf(
						esc_html( '-- Failed to create new user with message: %s (%s)' ),
						$user->get_error_message(),
						$user->get_error_code()
					) );
					$error_count++;
					continue; // Skip to next author.
				}
			}

			// Update the Author post to record the alternative user ID.
			update_post_meta( $author->ID, 'user_id', $user->ID );

			// Verify the migrated user looks good.
			$verified = $this->verify_migrated_author( $author, $user );
			if ( is_wp_error( $verified ) ) {
				WP_CLI::warning( sprintf(
					esc_html__( '-- Verification failed for author %d with the following issue: %s (%s)' ),
					$author->ID,
					$verified->get_error_message(),
					$verified->get_error_code()
				) );
				$error_count++;
				continue; // Skip to next author.
			}

		}

		WP_CLI::success( sprintf(
			esc_html__( 'Completed CPT to Users migration with %d issues.' ),
			$error_count
		) );

	}

	/**
	 * Grab the author posts.
	 *
	 * @return array List of authors as an array of WP_Post objects
	 */
	private function get_authors() {

		// Grab an array of WP_Post objects for the authors.
		$authors = get_posts( [
			'post_type'      => 'authors',
			'posts_per_page' => -1,
			'post_status'    => 'any',
		] );

		if ( empty( $authors ) ) {
			WP_CLI::error( sprintf( 'No authors found!' ) );
		}

		return $authors;

	}

	/**
	 * Extract the relevant info about an author from the post.
	 *
	 * @param  WP_Post $author A WP_Post object of the author post.
	 * @return array           The critical data in an associative array.
	 */
	private function get_author_info( $author ) {

		$author_info = [
			'ID'           => $author->ID,
			'display_name' => get_the_title( $author->ID ),
			'description'  => sprintf(
				'<p>%s</p>%s',
				esc_html( get_post_meta( $author->ID, 'contributor_title', true ) ),
				get_post_meta( $author->ID, 'contributor_bio', true )
			), // title + bio
			'user_email'   => get_post_meta( $author->ID, 'contributor_email', true ),
			'facebook'     => get_post_meta( $author->ID, 'social_facebook', true ),
			'twitter'      => get_post_meta( $author->ID, 'social_twitter', true ),
			'instagram'    => get_post_meta( $author->ID, 'social_instagram', true ),
			'youtube'      => get_post_meta( $author->ID, 'social_youtube', true ),
			'thumbnail_id' => get_post_meta( $author->ID, '_thumbnail_id', true ),
		];

		return $author_info;

	}

	/**
	 * Creates a new WP user from info extracted from an authors CPT post.
	 *
	 * @param  array $author    The WP_Post describing an author.
	 * @return WP_User|WP_Error A standard WP user account, or WP_Error if it failed.
	 */
	private function create_new_user( $author ) {

		// Get just the crucial information from the WP_Post.
		$author_info = $this->get_author_info( $author );

		// Make sure to stop any emails going out that could be confusing.
		add_filter( 'send_password_change_email', '__return_false');
		add_filter( 'send_email_change_email', '__return_false');
		add_filter( 'wp_new_user_notification_email_admin', '__return_false' );
		add_filter( 'wp_new_user_notification_email', '__return_false' );

		// Create the new user account.
		$user = wp_insert_user( [
			'user_login'    => sanitize_title( $author_info['display_name'] ),
			'user_pass'     => '', // Included to avoid PHP Notice.
			'user_email'    => $author_info['user_email'],
			'display_name'  => $author_info['display_name'],
			'description'   => $author_info['description'],
			'role'          => 'contributor',
		] );

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		// Add the other meta data.
		foreach ( [ 'facebook', 'twitter', 'instagram', 'youtube' ] as $meta_key ) {
			add_user_meta( $user, $meta_key, $author_info[ $meta_key ], true );
		}

		// For posterity, add the ID of the author post this author originated from.
		add_user_meta( $user, '_migrated_authors_cpt_id', $author_info['ID'], true );

		// Add the user avatar to be used by WP User Avatar plugin.
		add_user_meta( $user, 'wp_user_avatar', $author_info['thumbnail_id'], true );

		// Flush the cache given we've made data changes.
		wp_cache_flush();

		return get_user_by( 'ID', $user );
	}

	/**
	 * Check that the new WP User account has everything from the original post.
	 *
	 * @param  WP_Post $author The original authors post.
	 * @param  WP_User $user   The new WP user object.
	 * @return bool|WP_error   True if everything checks out. WP_Error with message otherwise.
	 */
	private function verify_migrated_author( $author, $user ) {

		// Verify the username.
		if (
			sanitize_title( get_the_title( $author->ID ) ) !==
			$user->user_login
		) {
			return new WP_Error( 'mismatched_username', sprintf(
				esc_html__( 'The username "%s" does not match the expected "%s".' ),
				sanitize_title( get_the_title( $author->ID ) ),
				$user->user_login
			) );
		}

		// Verify the email address.
		if ( get_post_meta( $author->ID, 'contributor_email', true ) !== $user->user_email ) {
			return new WP_Error( 'mismatched_email', sprintf(
				esc_html__( 'The email "%s" does not match the expected "%s".' ),
				get_post_meta( $author->ID, 'contributor_email', true ),
				$user->user_email
			) );
		}

		// Verify display name.
		if ( get_the_title( $author->ID ) !== $user->display_name ) {
			return new WP_Error( 'mismatched_displayname', sprintf(
				esc_html__( 'The display name "%s" does not match the expected "%s".' ),
				get_the_title( $author->ID ),
				$user->display_name
			) );
		}

		// Verify Facebook.
		if ( get_post_meta( $author->ID, 'social_facebook', true ) !== get_user_meta( $user->ID, 'facebook', true ) ) {
			return new WP_Error( 'mismatched_facebook', esc_html__( 'Facebook details do not match: %s vs %s' ) );
		}

		// Verify Twitter.
		if ( get_post_meta( $author->ID, 'social_twitter', true ) !== get_user_meta( $user->ID, 'twitter', true ) ) {
			return new WP_Error( 'mismatched_twitter', esc_html__( 'Twitter details do not match' ) );
		}

		// Verify Instagram.
		if ( get_post_meta( $author->ID, 'social_instagram', true ) !== get_user_meta( $user->ID, 'instagram', true ) ) {
			return new WP_Error( 'mismatched_instagram', esc_html__( 'Instagram details do not match' ) );
		}

		// Verify YouTube.
		if ( get_post_meta( $author->ID, 'social_youtube', true ) !== get_user_meta( $user->ID, 'youtube', true ) ) {
			return new WP_Error( 'mismatched_youtube', esc_html__( 'YouTube details do not match' ) );
		}

		// Verify Thumbnail.
		if ( get_post_meta( $author->ID, '_thumbnail_id', true ) !== get_user_meta( $user->ID, 'wp_user_avatar', true ) ) {
			return new WP_Error( 'mismatched_thumbnail', esc_html__( 'Thumbnail ID does not match' ) );
		}

		return true;
	}

}
