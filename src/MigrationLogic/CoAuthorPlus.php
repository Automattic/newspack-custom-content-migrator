<?php

namespace NewspackCustomContentMigrator\MigrationLogic;

use \CoAuthors_Plus;
use \CoAuthors_Guest_Authors;

class CoAuthorPlus {

	/**
	 * @var null|CoAuthors_Plus $coauthors_plus
	 */
	public $coauthors_plus;

	/**
	 * @var null|CoAuthors_Guest_Authors
	 */
	public $coauthors_guest_authors;

	/**
	 * CoAuthorPlus constructor.
	 */
	public function __construct() {
		// Set Co-Authors Plus dependencies.
		global $coauthors_plus;

		$plugin_path = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ABSPATH . 'wp-content/plugins';

		$file_1 = $plugin_path . '/co-authors-plus/co-authors-plus.php';
		$file_2 = $plugin_path . '/co-authors-plus/php/class-coauthors-guest-authors.php';
		$included_1 = is_file( $file_1 ) && include_once $file_1;
		$included_2 = is_file( $file_2 ) && include_once $file_2;

		if ( is_null( $coauthors_plus ) || ( false === $included_1 ) || ( false === $included_2 ) || ( ! $coauthors_plus instanceof CoAuthors_Plus ) ) {
			throw new \RuntimeException( sprintf( 'CoAuthors Plus dependencies not registered, can not load %s.', __CLASS__ ) );
		}

		$this->coauthors_plus          = $coauthors_plus;
		$this->coauthors_guest_authors = new CoAuthors_Guest_Authors();
	}

	/**
	 * Validates whether Co-Author Plus plugin's dependencies were successfully set.
	 *
	 * @return bool Is everything set up OK.
	 */
	public function validate_co_authors_plus_dependencies() {
		if ( ( ! $this->coauthors_plus instanceof CoAuthors_Plus ) || ( ! $this->coauthors_guest_authors instanceof CoAuthors_Guest_Authors ) ) {
			return false;
		}

		if ( false === $this->is_coauthors_active() ) {
			return false;
		}

		return true;
	}

	/**
	 * Checks whether Co-authors Plus is installed and active.
	 *
	 * @return bool Is active.
	 */
	public function is_coauthors_active() {
		$active = false;
		foreach ( wp_get_active_and_valid_plugins() as $plugin ) {
			if ( false !== strrpos( $plugin, 'co-authors-plus.php' ) ) {
				$active = true;
			}
		}

		return $active;
	}

	/**
	 * Creates Guest Authors from their full names.
	 *
	 * @param array $args {
	 *     The $args param for the \CoAuthors_Guest_Authors::create method.
	 *
	 *     @type string $display_name This is the only required param.
	 *     @type string $user_login   This param is optional, since this function automatically creates it from the $display_name.
	 *     @type string $first_name
	 *     @type string $last_name
	 *     @type string $user_email
	 *     @type string $website
	 *     @type string $description
	 * }
	 *
	 * @return int|array Created Guest Author ID, or an array of created Guest Author IDs.
	 *
	 * @throws \UnexpectedValueException In case mandatory argument values aren't provided.
	 */
	public function create_guest_author( array $args ) {
		if ( ! isset( $args[ 'display_name' ] ) ) {
			throw new \UnexpectedValueException( 'The `display_name` param is mandatory for Guest Author creation.' );
		}

		// If not provided, automatically set `user_login` from display_name.
		if ( ! isset( $args[ 'user_login' ] ) ) {
			$args[ 'user_login' ] = sanitize_title( $args[ 'display_name' ] );
		}

		$guest_author = $this->coauthors_guest_authors->get_guest_author_by( 'user_login', $args[ 'user_login' ] );
		if ( false === $guest_author ) {
			$coauthor_id = $this->coauthors_guest_authors->create( $args );
		} else {
			$coauthor_id = $guest_author->ID;
		}

		return $coauthor_id;
	}

	/**
	 * Assigns Guest Authors to the Post. Completely overwrites the existing list of authors.
	 *
	 * @param array $guest_author_ids Guest Author IDs.
	 * @param int   $post_id          Post IDs.
	 */
	public function assign_guest_authors_to_post( array $guest_author_ids, $post_id ) {
		$coauthors = [];
		foreach ( $guest_author_ids as $guest_author_id ) {
			$guest_author = $this->coauthors_guest_authors->get_guest_author_by( 'id', $guest_author_id );
			$coauthors[]  = $guest_author->user_nicename;
		}
		$this->coauthors_plus->add_coauthors( $post_id, $coauthors, $append_to_existing_users = false );
	}

	/**
	 * Links a Guest Author to an existing WP User.
	 *
	 * @param int $ga_id Guest Author ID.
	 * @param \WPUser $user
	 */
	public function link_guest_author_to_wp_user( $ga_id, $user ) {
		// Since GAs and WP Users can't have the same login, update it if they're the same.
		$cap_user_login = get_post_meta( $ga_id, 'cap-user_login', true );
		if ( $cap_user_login == $user->user_nicename ) {
			update_post_meta( $ga_id, 'cap-user_login', 'cap-' . $cap_user_login );
		}

		// WP User mapping.
		update_post_meta( $ga_id, 'cap-linked_account', $user->user_login );
	}

	/**
	 * Returns the Guest Author object (as defined by the CAP plugin.
	 *
	 * @param int $ga_id Guest Author ID.
	 *
	 * @return false|object Guest Author object.
	 */
	public function get_guest_author_by_id( $ga_id ) {
		return $this->coauthors_guest_authors->get_guest_author_by( 'ID', $ga_id );
	}

	/**
	 * Creates a Guest Author user from an existing WP user.
	 *
	 * @param int $user_id WP User ID.
	 *
	 * @return int|\WP_Error Guest Author ID.
	 */
	public function create_guest_author_from_wp_user( $user_id ) {
		return $this->coauthors_guest_authors->create_guest_author_from_user_id( $user_id );
	}
}
