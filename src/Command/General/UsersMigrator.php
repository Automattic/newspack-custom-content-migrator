<?php

namespace NewspackCustomContentMigrator\Command\General;

use NewspackCustomContentMigrator\Command\InterfaceCommand;

use \WP_CLI;

class UsersMigrator implements InterfaceCommand {

	/**
	 * Singleton instance.
	 *
	 * @var null|InterfaceCommand UsersMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * Sets up UsersMigrator class.
	 *
	 * @return InterfaceCommand|null
	 */
	public static function get_instance() {
		$class = get_called_class();

		if ( null === self::$instance ) {
			self::$instance = new $class();

		}

		return self::$instance;
	}

	/**
	 * @inheritDoc
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator export-users-and-guest-authors',
			[ $this, 'cmd_export_users_and_guest_authors' ]
		);

		WP_CLI::add_command(
			'newspack-content-migrator import-users-and-guest-authors',
			[ $this, 'cmd_import_users_and_guest_authors' ],
			[
				'shortdesc' => 'Imports users and guest authors from a JSON file.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'path-to-file',
						'description' => 'The path to the JSON file.',
						'optional'    => false,
					],
				],
			]
		);
	}

	/**
	 * Custom command to export users and guest authors.
	 * 
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_export_users_and_guest_authors( array $args, array $assoc_args ) {
		global $wpdb;

		$guest_author_ids = [];

		$users_query = "SELECT * FROM $wpdb->users";
		$users       = $wpdb->get_results( $users_query );

		foreach ( $users as $user ) {
			$user->import_type = 'user';
			$user->meta_input  = $this->get_user_meta( $user->ID );
			$user->nickname = $user->meta_input['nickname'] ?? '';
			$user->first_name = $user->meta_input['first_name'] ?? '';
			$user->last_name = $user->meta_input['last_name'] ?? '';
			$user->description = $user->meta_input['description'] ?? '';
			$user->role = array_keys( unserialize( $user->meta_input['wp_capabilities'] ) )[0] ?? 'subscriber';
			unset( $user->meta_input['nickname'] );
			unset( $user->meta_input['first_name'] );
			unset( $user->meta_input['last_name'] );
			unset( $user->meta_input['description'] );

			$guest_author_query = "SELECT * FROM $wpdb->posts WHERE post_type = 'guest-author' AND post_name = %s";
			$guest_author       = $wpdb->get_row( $wpdb->prepare( $guest_author_query, 'cap-' . $user->user_login ) );

			if ( ! $guest_author ) {
				$guest_author_query = "SELECT * FROM $wpdb->posts WHERE post_type = 'guest-author' AND post_name = %s";
				$guest_author       = $wpdb->get_row( $wpdb->prepare( $guest_author_query, 'cap-' . sanitize_title( $user->user_login ) ) );
			}

			if ( $guest_author ) {
				$guest_author_ids[]       = $guest_author->ID;
				$guest_author->meta_input = $this->get_post_meta( $guest_author->ID );
				$guest_author             = $this->get_taxonomical_data( $guest_author );
				unset( $guest_author->ID );
				$user->guest_author       = $guest_author;
			}

			unset( $user->ID );
		}

		$guest_authors_query = "SELECT * FROM $wpdb->posts WHERE post_type = 'guest-author'";

		if ( ! empty( $guest_author_ids ) ) {
			$guest_authors_query .= ' AND ID NOT IN (' . implode( ',', $guest_author_ids ) . ')';
		}

		$guest_authors       = $wpdb->get_results( $guest_authors_query );
		$guest_authors       = array_map(
			function( $guest_author ) {
				$guest_author->import_type = 'guest-author';
				$guest_author->meta_input  = $this->get_post_meta( $guest_author->ID );
				$guest_author = $this->get_taxonomical_data( $guest_author );
				unset( $guest_author->ID );
				return $guest_author;
			},
			$guest_authors
		);

		$users = array_merge( $users, $guest_authors );

		$timestamp = gmdate( 'YmdHis' );
		$filename  = "users-and-guest-authors-$timestamp.json";
		file_put_contents( $filename, wp_json_encode( $users ) );
	}

	/**
	 * Handles the import of users and guest authors.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_import_users_and_guest_authors( array $args, array $assoc_args ) {
		$file_path = $args[0];
		$users = wp_json_file_decode( $file_path );

		foreach ( $users as $user ) {
			if ( 'user' === $user->import_type ) {
				if ( email_exists( $user->user_email ) ) {
					WP_CLI::warning( "User with email {$user->user_email} already exists. Skipping." );
					continue;
				}

				if ( isset( $user->guest_author ) ) {
					$guest_author = $user->guest_author;
					unset( $user->guest_author );
					$this->import_guest_author( $guest_author );
				}

				wp_insert_user( $user );
			} else if ( 'guest-author' === $user->import_type ) {
				$this->import_guest_author( $user );
			}
		}
	}

	/**
	 * Handles record creation for guest authors.
	 *
	 * @param $guest_author The guest author object.
	 */
	private function import_guest_author( $guest_author ) {
		global $wpdb;

		if ( post_exists( $guest_author->post_title ) ) {
			WP_CLI::warning( "Guest author with name {$guest_author->post_title} already exists. Skipping." );
			return;
		}

		$term = $guest_author->term;
		$taxonomy = $guest_author->taxonomy;
		unset( $guest_author->term );
		unset( $guest_author->taxonomy );
		unset( $guest_author->import_type );
		$guest_author->post_author = 0;

		$guest_author_id = wp_insert_post( $guest_author );

		if ( $guest_author_id ) {
			if ( ! empty( $term ) ) {
				$term_id = $wpdb->insert(
					$wpdb->terms,
					(array) $term
				);

				if ( $term_id && ! empty( $taxonomy ) ) {
					$term_taxonomy_id = $wpdb->insert(
						$wpdb->term_taxonomy,
						array_merge( (array) $taxonomy, [ 'term_id' => $term_id ] )
					);

					if ( $term_taxonomy_id ) {
						$wpdb->insert(
							$wpdb->term_relationships,
							[
								'object_id'        => $guest_author_id,
								'term_taxonomy_id' => $term_taxonomy_id,
							]
						);
					}
				}
			}
		}
	}

	/**
	 * Get user meta without ID's.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return array|object|\stdClass[]|null
	 */
	private function get_user_meta( int $user_id ) {
		global $wpdb;

		$meta_query = "SELECT meta_key, meta_value FROM $wpdb->usermeta WHERE user_id = %d";
		$results = $wpdb->get_results( $wpdb->prepare( $meta_query, $user_id ) );

		$meta = [];
		foreach ( $results as $result ) {
			$meta[ $result->meta_key ] = $result->meta_value;
		}

		return $meta;
	}

	/**
	 * Get post meta without ID's.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array|object|\stdClass[]|null
	 */
	private function get_post_meta( int $post_id ) {
		global $wpdb;

		$meta_query = "SELECT meta_key, meta_value FROM $wpdb->postmeta WHERE post_id = %d";
		$results = $wpdb->get_results( $wpdb->prepare( $meta_query, $post_id ) );

		$meta = [];
		foreach ( $results as $result ) {
			$meta[ $result->meta_key ] = $result->meta_value;
		}
		return $meta;
	}

	/**
	 * Get taxonomical data for guest authors.
	 *
	 * @param $guest_author The guest author object.
	 *
	 * @return mixed
	 */
	private function get_taxonomical_data( $guest_author ) {
		global $wpdb;

		$taxonomy_query         = "SELECT * FROM $wpdb->term_taxonomy 
					WHERE taxonomy = 'author' 
					  AND term_taxonomy_id = ( 
					      SELECT term_taxonomy_id 
					      FROM $wpdb->term_relationships 
					      WHERE object_id = %d 
					      )";
		$guest_author->taxonomy = $wpdb->get_row( $wpdb->prepare( $taxonomy_query, $guest_author->ID ) );

		if ( $guest_author->taxonomy ) {
			$term_query         = "SELECT * FROM $wpdb->terms WHERE term_id = %d";
			$guest_author->term = $wpdb->get_row( $wpdb->prepare( $term_query, $guest_author->taxonomy->term_id ) );

			unset( $guest_author->term->term_id );
			unset( $guest_author->taxonomy->term_taxonomy_id );
			unset( $guest_author->taxonomy->term_id );
		}

		return $guest_author;
	}
}
