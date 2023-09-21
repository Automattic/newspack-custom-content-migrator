<?php

namespace NewspackCustomContentMigrator\Command\General;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use \WP_CLI;

class LedeMigrator implements InterfaceCommand {

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * CoAuthorPlus instance.
	 *
	 * @var CoAuthorPlus CoAuthorPlus instance.
	 */
	private $cap;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->cap = new CoAuthorPlus();
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceCommand|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command( 'newspack-content-migrator lede-migrate-authors-to-gas',
			[ $this, 'cmd_migrate_authors_to_gas' ],
			[
				'shortdesc' => 'Migrates that custom Lede Authors plugin data to GA authors.',
			]
		);
	}

	/**
	 * Callable for `newspack-content-migrator lede-migrate-authors-to-gas` command.
	 *
	 * @param array $pos_args
	 * @param array $assoc_args
	 */
	public function cmd_migrate_authors_to_gas( $pos_args, $assoc_args ) {

		// Create all GAs.
		global $wpdb;
		// Live https://atlanta.capitalbnews.org/cop-city-unrest/
		// Staging ID 3395
		//      Madeline Thigpen
		//      Adam Mahoney
		// Get all post_type = 'profile'.
		$profile_posts_rows = $wpdb->get_results( "SELECT * FROM {$wpdb->posts} WHERE post_type = 'profile';", ARRAY_A );
		if ( empty( $profile_posts_rows ) ) {
			WP_CLI::error( "No objects of post_type = 'profile' found. Looks like the 'custom Lede authors plugin' was not used on this site." );
		}
		foreach ( $profile_posts_rows as $profile_post_row ) {
			$ga_args = [];

			if ( empty( $profile_post_row['post_title'] ) ) {
				// Log warning
				$d=1;
			}

			// Get author data available from wp_posts.
			$ga_args['display_name'] = $profile_post_row['post_title'];
			if ( ! empty( $profile_post_row['post_content'] ) ) {
				$ga_args['description'] = $profile_post_row['post_content'];
			}
			if ( ! empty( $profile_post_row['post_name'] ) ) {
				$ga_args['user_login'] = $profile_post_row['post_name'];
			}

			// Get author data available from wp_postmeta.
			$profile_postmeta_rows = $wpdb->get_results( "SELECT * FROM {$wpdb->postmeta} WHERE post_id = {$profile_post_row['ID']};", ARRAY_A );
			$social_links = [];
			foreach ( $profile_postmeta_rows as $profile_postmeta_row ) {

				// user_login might also be stored as postmeta. Override if exists.
				if ( 'user_login' == $profile_postmeta_row['meta_key'] && ! empty( $profile_postmeta_row['meta_value'] ) ) {
					$ga_args['user_login'] = $profile_postmeta_row['meta_value'];
				}

				// Email might be stored as two different metas.
				if ( 'user_email' == $profile_postmeta_row['meta_key'] && ! empty( $profile_postmeta_row['meta_value'] ) ) {
					$ga_args['user_email'] = $profile_postmeta_row['meta_value'];
				}
				if ( 'email' == $profile_postmeta_row['meta_key'] && ! empty( $profile_postmeta_row['meta_value'] ) ) {
					$ga_args['user_email'] = $profile_postmeta_row['meta_value'];
				}

				// Short bio might also be stored as short_bio meta_key. Append it to description after line break.
				if ( 'short_bio' == $profile_postmeta_row['meta_key'] && ! empty( $profile_postmeta_row['meta_value'] ) ) {
					$ga_args['description'] .= ! empty( $ga_args['description'] ) ? "\n" : '';
					$ga_args['description'] .= $profile_postmeta_row['meta_value'];
				}

				if ( 'first_name' == $profile_postmeta_row['meta_key'] && ! empty( $profile_postmeta_row['meta_value'] ) ) {
					$ga_args['first_name'] = $profile_postmeta_row['meta_value'];
				}
				if ( 'last_name' == $profile_postmeta_row['meta_key'] && ! empty( $profile_postmeta_row['meta_value'] ) ) {
					$ga_args['last_name'] = $profile_postmeta_row['meta_value'];
				}

				// Avatar.
				if ( '_thumbnail_id' == $profile_postmeta_row['meta_key'] && ! empty( $profile_postmeta_row['meta_value'] ) ) {
					$ga_args['avatar'] = $profile_postmeta_row['meta_value'];
				}

				// There could also be a linked WP_User ID.
				$linked_wp_user_id = null;
				if ( 'user_id' == $profile_postmeta_row['meta_key'] && ! empty( $profile_postmeta_row['meta_value'] ) ) {
					// Confirm that WP_User exists.
					$user_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID = %d;", $profile_postmeta_row['meta_value'] ) );
					if ( $user_id ) {
						$linked_wp_user_id = $user_id;
					}
				}

				// Get additional social links.
				if ( 'twitter' == $profile_postmeta_row['meta_key'] && ! empty( $profile_postmeta_row['meta_value'] ) ) {
					$social_links[] = '<a href="' . $profile_postmeta_row['meta_value'] . '" $social_links>Twitter @' . $profile_postmeta_row['meta_value'] . '</a>';
				}
			}

			// Simply append social links to the description.
			if ( ! empty( $social_links ) ) {
				$ga_args['description'] .= ! empty( $ga_args['description'] ) ? ' ' : '';
				$ga_args['description'] .= implode( ' ', $social_links );
			}


			// Before creating, check if GA already exists.


			$ga_id = $this->cap->create_guest_author( $ga_args );
			// Handle error.

			// Link WP_User.
			if ( $linked_wp_user_id ) {
				$wp_user = get_user_by( 'ID', $linked_wp_user_id );
				$this->cap->link_guest_author_to_wp_user( $ga_id, $wp_user );
			}
		}


		// Assign GAs to posts.
	}
}
