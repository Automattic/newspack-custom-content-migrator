<?php

namespace NewspackCustomContentMigrator\Logic;

use WP_Error;

use NewspackCustomContentMigrator\Logic\ConsoleOutput\Taxonomy as TaxonomyConsoleOutputLogic;

/**
 * Class to handle fixing CoAuthors Plus data.
 */
class CoAuthorPlusDataFixer {

	/**
	 * Legacy Custom CAP logic.
	 *
	 * @var CoAuthorPlus $co_authors_plus CoAuthors Plus class instance.
	 */
	private CoAuthorPlus $co_authors_plus;

	/**
	 * Taxonomy and console output logic.
	 *
	 * @var TaxonomyConsoleOutputLogic $taxonomy_console_output_logic Taxonomy Console Output Logic class instance.
	 */
	private TaxonomyConsoleOutputLogic $taxonomy_console_output_logic;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->co_authors_plus               = new CoAuthorPlus();
		$this->taxonomy_console_output_logic = new TaxonomyConsoleOutputLogic();
	}

	/**
	 * This function is meant to determine if a Guest Author record has a linked Author taxonomy.
	 *
	 * @param object|int $guest_author Guest Author object or ID.
	 *
	 * @return object|null
	 */
	public function get_guest_author_related_taxonomy( $guest_author ): ?object {
		$guest_author_id = null;

		if ( is_object( $guest_author ) ) {
			$guest_author_id = $guest_author->ID;
		}

		if ( is_int( $guest_author ) ) {
			$guest_author_id = $guest_author;
		}

		if ( is_null( $guest_author_id ) ) {
			return null;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT 
					tt.term_taxonomy_id, 
					tt.term_id, 
					tt.taxonomy 
				FROM $wpdb->term_relationships tr 
    				INNER JOIN $wpdb->term_taxonomy tt 
    				    ON tr.term_taxonomy_id = tt.term_taxonomy_id
				    INNER JOIN $wpdb->terms t 
				        ON tt.term_id = t.term_id
         			INNER JOIN $wpdb->posts p 
         			    ON tr.object_id = p.ID
         		WHERE tt.taxonomy = 'author' 
         		  AND p.post_type = 'guest-author' 
         		  AND tr.object_id = %d",
				$guest_author_id
			)
		);
	}

	/**
	 * This function will return an author term-taxonomy record for a given ID. If the given ID does not belong to
	 * a term which is an Author term, an empty array will be returned.
	 *
	 * @param int    $by_id ID of the term or term_taxonomy record.
	 * @param string $id_type Type of ID being passed. Either TaxonomyConsoleOutputLogic::TERM_ID or TaxonomyConsoleOutputLogic::TAX_ID.
	 *
	 * @return array
	 */
	public function get_guest_author_taxonomy( int $by_id, string $id_type = TaxonomyConsoleOutputLogic::TERM_ID ): array {
		$constraint_column = 't.term_id';

		if ( TaxonomyConsoleOutputLogic::TAX_ID === $id_type ) {
			$constraint_column = 'tt.term_taxonomy_id';
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
		// phpcs:disable
			$wpdb->prepare(
				"SELECT
	                t.term_id,
	                t.name,
	                t.slug,
	                tt.term_taxonomy_id,
	                tt.description
	            FROM $wpdb->terms t 
	            LEFT JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id 
	                WHERE tt.taxonomy = 'author' AND $constraint_column = %d",
				$by_id
			)
		// phpcs:enable
		);
	}


	/**
	 * This function will handle all the logistics involved in ensuring that a given Guest Author ID and Term ID
	 * can be "linked" together. This means that the Guest Author ID will be related to a term_taxonomy_id
	 * which in turn will be related to the given term_id.
	 *
	 * @param int $guest_author_id Guest Author ID.
	 * @param int $term_id Term ID.
	 *
	 * @return int|WP_Error
	 */
	public function attempt_to_relate_standalone_guest_author_and_term( int $guest_author_id, int $term_id ): int|object {
		wp_cache_flush();

		return $this->insert_guest_author_taxonomy(
			$this->co_authors_plus->get_guest_author_by_id( $guest_author_id ),
			$term_id
		);
	}

	/**
	 * This function will insert a new author term-taxonomy record for a given Guest Author.
	 *
	 * @param object $author Guest Author object. {
	 *      This object should be a Guest Author Object obtained from the CoAuthors_Guest_Authors class.
	 *
	 * @type int    $ID
	 * @type string $display_name
	 * @type string $first_name
	 * @type string $last_name
	 * @type string $user_login
	 * @type string $user_email
	 * @type string $user_nicename
	 * }
	 *
	 * @param int    $term_id Term ID.
	 *
	 * @return int|WP_Error
	 */
	public function insert_guest_author_taxonomy( object $author, int $term_id ): int|object {
		$coauthor_slug = $author->user_nicename;

		if ( ! str_starts_with( $coauthor_slug, 'cap-' ) ) {
			$coauthor_slug = 'cap-' . $coauthor_slug;
		}

		$existing_slugs = $this->taxonomy_console_output_logic->get_term_by_slug( $coauthor_slug, $term_id );

		if ( ! empty( $existing_slugs ) ) {
			return new WP_Error(
				'existing-slugs',
				'This slug is already being used by other terms.',
				[
					'slug'           => $coauthor_slug,
					'existing_slugs' => $existing_slugs,
				]
			);
		}

		$author_taxonomy = $this->get_guest_author_taxonomy( $term_id );

		if ( ! empty( $author_taxonomy ) ) {
			if ( 1 === count( $author_taxonomy ) ) {
				// This term already seems to have the appropriate author taxonomy connected to it.

				$this->taxonomy_console_output_logic->insert_relationship_if_not_exists(
					$author->ID,
					$author_taxonomy[0]->term_taxonomy_id
				);

				return $author_taxonomy[0]->term_taxonomy_id;
			}

			return new WP_Error(
				'multiple-author-taxonomies',
				'This term already has multiple author taxonomies connected to it.',
				[
					'term_id'             => $term_id,
					'author_taxonomy_ids' => array_map( fn( $row ) => $row->term_taxonomy_id, $author_taxonomy ),
				]
			);
		}

		global $wpdb;

		$insert_data = [
			'term_id'     => $term_id,
			'taxonomy'    => 'author',
			'description' => $this->get_author_term_description( $author ),
			'parent'      => 0,
			'count'       => 0,
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$insert = $wpdb->insert( $wpdb->term_taxonomy, $insert_data );

		if ( false === $insert ) {
			return new WP_Error(
				'insert-failed',
				'Failed to insert author term taxonomy.',
				[
					'insert_data' => $insert_data,
				]
			);
		}

		$this->taxonomy_console_output_logic->insert_relationship_if_not_exists( $author->ID, $insert );

		return $this->get_guest_author_taxonomy( $term_id )[0]->term_taxonomy_id;
	}

	/**
	 * Generates a description for an author term.
	 *
	 * @param object $author Guest Author object. {
	 *      This object should be a Guest Author Object obtained from the CoAuthors_Guest_Authors class.
	 *
	 * @type int    $ID
	 * @type string $display_name
	 * @type string $first_name
	 * @type string $last_name
	 * @type string $user_login
	 * @type string $user_email
	 * @type string $user_nicename
	 *  }
	 *
	 * @return string
	 */
	public function get_author_term_description( object $author ): string {
		// @see https://github.com/Automattic/Co-Authors-Plus/blob/e9e76afa767bc325123c137df3ad7af169401b1f/php/class-coauthors-plus.php#L1623
		$fields = [
			'display_name',
			'first_name',
			'last_name',
			'user_login',
			'ID',
			'user_email',
		];

		$values = [];
		foreach ( $fields as $field ) {
			$values[] = $author->$field;
		}

		return implode( ' ', $values );
	}

	/**
	 * This function will obtain the CAP description format for an author term and
	 * update the wp_term_taxonomy.description column with it.
	 *
	 * @param object $author The WP_User or Guest Author Object.
	 * @param object $term The term object.
	 *
	 * @return bool|null
	 */
	public function update_author_term_description( object $author, object $term ): ?bool {
		$description = $this->get_author_term_description( $author );

		global $wpdb;

		if ( ! isset( $term->description ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$term->description = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT description FROM $wpdb->term_taxonomy WHERE term_taxonomy_id = %d",
					$term->term_taxonomy_id
				)
			);
		}

		if ( $description !== $term->description ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$update = (bool) $wpdb->update(
				$wpdb->term_taxonomy,
				[
					'description' => $description,
				],
				[
					'term_taxonomy_id' => $term->term_taxonomy_id,
				]
			);

			if ( $update ) {
				$term->description = $description;
			}

			return $update;
		}

		return null;
	}

	/**
	 * This function will check a Guest Author's user_email, user_login, and linked_account CAP related fields
	 * against all WP_User's to see if there are any matches. If there are, false is returned because
	 * this means that the Guest Author is not standalone.
	 *
	 * @param object|int $guest_author Guest Author object or ID.
	 *
	 * @return bool
	 */
	public function is_guest_author_standalone( $guest_author ): bool {
		if ( is_numeric( $guest_author ) ) {
			$guest_author = $this->co_authors_plus->get_guest_author_by_id( $guest_author );
		}

		// user_email, user_login, user_nicename.
		$filtered_cap_fields = $this->get_filtered_cap_fields(
			$guest_author->ID,
			[
				'cap-user_email',
				'cap-user_login',
				'cap-linked_account',
			]
		);

		foreach ( $filtered_cap_fields as $cap_field => $values ) {
			if ( is_array( $values ) ) {
				$values = array_unique( $values );

				if ( 1 !== count( $values ) ) {
					return false;
				}

				$values = $values[0];
			}

			if ( empty( $values ) ) {
				return false;
			}

			if ( 'cap-linked_account' === $cap_field ) {
				return false;
			}

			if ( 'cap-user_email' === $cap_field ) {
				$user_by_email = get_user_by( 'email', $values );

				if ( ! empty( $user_by_email ) ) {
					return false;
				}

				$user_login_by_email = get_user_by( 'login', $values );

				if ( ! empty( $user_login_by_email ) ) {
					return false;
				}
			}

			if ( 'cap-user_login' === $cap_field ) {
				$values = str_replace( 'cap-', '', $values );

				$user_by_login = get_user_by( 'login', $values );

				if ( ! empty( $user_by_login ) ) {
					return false;
				}

				$user_nicename_by_login = get_user_by( 'slug', $values );

				if ( ! empty( $user_nicename_by_login ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Get CAP related postmeta fields for a given Guest Author ID.
	 *
	 * @param int $guest_author_post_id Guest Author ID (Post ID).
	 *
	 * @return array|array[]
	 */
	public function get_cap_fields( int $guest_author_post_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
    				meta_id, 
    				meta_key, 
    				meta_value 
				FROM $wpdb->postmeta 
				WHERE post_id = %d 
				  AND meta_key LIKE %s",
				$guest_author_post_id,
				$wpdb->esc_like( 'cap-' ) . '%'
			),
		);

		return array_map(
			function ( $result ) {
				// phpcs:disable
				return [
					'meta_id'    => $result->meta_id,
					'meta_key'   => $result->meta_key,
					'meta_value' => $result->meta_value,
				];
				//phpcs:enable
			},
			$results
		);
	}

	/**
	 * Get only the CAP related postmeta fields you want for a given Guest Author ID.
	 *
	 * @param int   $guest_author_post_id Guest Author ID (Post ID).
	 * @param array $keys Array of meta_keys to filter by.
	 *
	 * @return array|array[]
	 */
	public function get_filtered_cap_fields( int $guest_author_post_id, array $keys ): array {
		$filtered_author_cap_fields = [];

		foreach ( $this->get_cap_fields( $guest_author_post_id ) as $author_cap_field ) {
			if ( in_array( $author_cap_field['meta_key'], $keys, true ) ) {
				if ( array_key_exists( $author_cap_field['meta_key'], $filtered_author_cap_fields ) ) {
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					$filtered_author_cap_fields[ $author_cap_field['meta_key'] ] = [
						$filtered_author_cap_fields[ $author_cap_field['meta_key'] ],
						$author_cap_field['meta_value'],
					];
				} else {
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					$filtered_author_cap_fields[ $author_cap_field['meta_key'] ] = $author_cap_field['meta_value'];
				}
			}
		}

		foreach ( $filtered_author_cap_fields as $key => $filtered_author_cap_field ) {
			if ( is_array( $filtered_author_cap_field ) ) {

				unset( $filtered_author_cap_fields[ $key ] );

				foreach ( $this->get_cap_fields( $guest_author_post_id ) as $author_cap_field ) {
					if ( $author_cap_field['meta_key'] === $key ) {
						$filtered_author_cap_fields[ $author_cap_field['meta_key'] ][ $author_cap_field['meta_id'] ] = $author_cap_field['meta_value'];
					}
				}
			}
		}

		return $filtered_author_cap_fields;
	}
}
