<?php
/**
 * Logic for working Taxonomies.
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Logic;

use NewspackCustomContentMigrator\Utils\ConsoleColor;
use NewspackCustomContentMigrator\Utils\ConsoleTable;
use WP_CLI;

/**
 * Taxonomy implements common migration logic that are used to work with the Simple Local Avatars plugin
 */
class Taxonomy {

	const TERM_ID = 'term_id';
	const TAX_ID  = 'term_taxonomy_id';

	/**
	 * Fixes counts for taxonomy.
	 *
	 * @param string $taxonomy Taxonomy, e.g. 'category'.
	 *
	 * @return void
	 */
	public function fix_taxonomy_term_counts( string $taxonomy ) {
		$get_terms_args = [
			'taxonomy'   => $taxonomy,
			'fields'     => 'ids',
			'hide_empty' => false,
		];

		$update_term_ids = get_terms( $get_terms_args );
		foreach ( $update_term_ids as $key_term_id => $term_id ) {
			wp_update_term_count_now( [ $term_id ], $taxonomy );
		}

		wp_cache_flush();
	}

	/**
	 * Reassigns all content from one taxonomy to a different taxonomy.
	 *
	 * @param string $taxonomy     Source taxonomy, e.g. 'category'.
	 * @param int    $source_term_id      Source term_id.
	 * @param int    $destination_term_id Destination term_id.
	 *
	 * @return void
	 */
	public function reassign_all_content_from_one_taxonomy_to_another( string $taxonomy, int $source_term_id, int $destination_term_id ): void {
		// Get post IDs with both terms.
		$posts_with_both_terms = get_posts(
			[
				'fields'           => 'ids',
				'posts_per_page'   => -1,
				'post_type'        => 'any',
				'post_status'      => 'any',
				'suppress_filters' => true,
				'tax_query'        => [
					'relation' => 'AND',
					[
						'taxonomy' => $taxonomy,
						'field'    => 'term_taxonomy_id',
						'terms'    => [ $source_term_id ],
					],
					[
						'taxonomy' => $taxonomy,
						'field'    => 'term_taxonomy_id',
						'terms'    => [ $destination_term_id ],
					],
				],
			]
		);

		// Delete the source term from these posts.
		if ( ! empty( $posts_with_both_terms ) ) {
			$this->delete_object_relational_mapping_term_taxonomy_id( $source_term_id, $posts_with_both_terms );
		}

		$this->update_object_relational_mapping_term_taxonomy_id( $source_term_id, $destination_term_id );

		$this->fix_taxonomy_term_counts( $taxonomy );
	}

	/**
	 * Runs a direct DB UPDATE on wp_term_relationships table and updates term_taxonomy_id from one value to a different one.
	 *
	 * @param int $old_term_taxonomy_id Old term_taxonomy_id.
	 * @param int $new_term_taxonomy_id New term_taxonomy_id.
	 *
	 * @return string|null Return from $wpdb::get_var().
	 */
	public function update_object_relational_mapping_term_taxonomy_id( $old_term_taxonomy_id, $new_term_taxonomy_id ) {
		global $wpdb;

		return $wpdb->update( $wpdb->term_relationships, [ 'term_taxonomy_id' => $new_term_taxonomy_id ], [ 'term_taxonomy_id' => $old_term_taxonomy_id ] );
	}

	/**
	 * Runs a direct DB DELETE on wp_term_relationships table and deletes all rows with a given term_taxonomy_id and post_ids.
	 *
	 * @param int   $term_taxonomy_id Term_taxonomy_id.
	 * @param array $post_ids          Post IDs.
	 *
	 * @return string|null Return from $wpdb::query().
	 */
	public function delete_object_relational_mapping_term_taxonomy_id( $term_taxonomy_id, $post_ids ) {
		global $wpdb;

		$object_id_placeholders = implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) );

		return $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN( $object_id_placeholders ) and term_taxonomy_id = %d", array_merge( $post_ids, [ $term_taxonomy_id ] ) ) ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Gets a term_id by its taxonomy, name and parent ID.
	 * For example, you can search for a category with a name and optional parent ID.
	 *
	 * @param string $taxonomy       Taxonomy, e.g. 'category'.
	 * @param string $name           Taxonomy name, e.g. 'Some category name'.
	 * @param int    $parent_term_id Parent term_id, e.g. 123 or 0.
	 *
	 * @return string|null Term ID or null if not found.
	 */
	public function get_term_id_by_taxonmy_name_and_parent( string $taxonomy, string $name, int $parent_term_id = 0 ) {
		global $wpdb;

		$query_prepare = "select t.term_id
			from {$wpdb->terms} t
			join {$wpdb->term_taxonomy} tt on tt.term_id = t.term_id
			where tt.taxonomy = %s and t.name = %s and tt.parent = %d;";

		// First try with converting name chars to HTML entities.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing_term_id = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$query_prepare,
				$taxonomy,
				htmlentities( $name ),
				$parent_term_id
			)
		);

		// Try without converting name chars to HTML entities.
		if ( ! $existing_term_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$existing_term_id = $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$query_prepare,
					$taxonomy,
					$name,
					$parent_term_id
				)
			);
		}

		return $existing_term_id;
	}

	/**
	 * Gets or creates a category by its name and parent term_id.
	 *
	 * @param string $cat_name      Category name.
	 * @param int    $cat_parent_id Category's parent term_id.
	 *
	 * @throws \RuntimeException If nonexisting $cat_parent_id is given.
	 *
	 * @return string|null Category term ID.
	 */
	public function get_or_create_category_by_name_and_parent_id( string $cat_name, int $cat_parent_id = 0 ) {
		global $wpdb;

		// Get term_id if it exists.

		$existing_term_id = $this->get_term_id_by_taxonmy_name_and_parent( 'category', $cat_name, $cat_parent_id );
		if ( ! is_null( $existing_term_id ) ) {
			return $existing_term_id;
		}

		// If it doesn't exist, then create it.

		// Double check this parent exists.
		if ( 0 != $cat_parent_id ) {
			$existing_cat_parent_id = $wpdb->get_var(
				$wpdb->prepare(
					"select t.term_id
						from {$wpdb->terms} t
						join {$wpdb->term_taxonomy} tt on tt.term_id = t.term_id
						where tt.taxonomy = 'category' and tt.term_id = %d;",
					$cat_parent_id
				)
			);
			if ( is_null( $existing_cat_parent_id ) ) {
				throw new \RuntimeException( sprintf( 'Wrong parent category term_id=%d given, does not exist.', $cat_parent_id ) );
			}
		}

		// Create cat.
		$cat_id = wp_insert_category(
			[
				'cat_name'        => $cat_name,
				'category_parent' => $cat_parent_id,
			]
		);

		return $cat_id;
	}

	/**
	 * Gets duplicate term slugs.
	 *
	 * @return array
	 */
	public function get_duplicate_term_slugs() {
		global $wpdb;

		return $wpdb->get_results(
			"SELECT
			t.slug,
			GROUP_CONCAT( DISTINCT tt.taxonomy ORDER BY tt.taxonomy SEPARATOR ', ' ) as taxonomies,
			GROUP_CONCAT(
			    CONCAT( tt.term_id, ':', tt.term_taxonomy_id, ':', tt.taxonomy )
			    ORDER BY t.term_id, tt.term_taxonomy_id ASC SEPARATOR '  |  '
			    ) as 'term_id:term_taxonomy_id:taxonomy',
			COUNT( DISTINCT tt.term_taxonomy_id ) as term_taxonomy_id_count
			FROM $wpdb->terms t
			LEFT JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id
			GROUP BY t.slug, tt.term_id
			HAVING term_taxonomy_id_count > 1
			ORDER BY term_taxonomy_id_count DESC"
		);
	}

	/**
	 * Gets terms and taxonomies by slug.
	 *
	 * @param string $slug Slug.
	 * @param array  $taxonomies Taxonomies.
	 *
	 * @return array
	 */
	public function get_terms_and_taxonomies_by_slug( string $slug, array $taxonomies = [ 'category', 'post_tag' ] ) {
		global $wpdb;

		$query = "SELECT
	                t.term_id,
	                t.name, t.slug,
	                tt.term_taxonomy_id,
	                tt.taxonomy,
	                tt.parent,
	                tt.count
				FROM $wpdb->terms t
				INNER JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id
				WHERE t.slug = %s";

		if ( ! empty( $taxonomies ) ) {
			$query .= 'AND tt.taxonomy IN ( '
					  . implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) )
					  . ' )';
		}

		$query .= ' ORDER BY t.term_id, tt.term_taxonomy_id ASC';

		return $wpdb->get_results(
			$wpdb->prepare(
				$query,
				array_merge( [ $slug ], $taxonomies )
			)
		);
	}

	/**
	 * Obtains a new slug that does not exist in the database.
	 *
	 * @param string $slug Slug.
	 * @param int    $offset Offset.
	 *
	 * @return string
	 */
	public function get_new_term_slug( string $slug, int $offset = 1 ) {
		$new_slug = $slug . '-' . $offset;

		do {
			$slug_exists = ! is_null( term_exists( $new_slug ) );

			if ( $slug_exists ) {
				$offset++;
				$new_slug = $slug . '-' . $offset;
			}
		} while ( $slug_exists );

		return $new_slug;
	}

	/**
	 * This function will return any wp_term records that match the given slug.
	 *
	 * @param string $slug The slug.
	 * @param int    $ignore_term_id A specific term_id to ignore. Use this if you want to ensure no other terms with the same slug exist, except for this one.
	 *
	 * @return array
	 */
	public function get_term_by_slug( string $slug, int $ignore_term_id = 0 ): array {
		global $wpdb;

		$query = $wpdb->prepare( "SELECT * FROM $wpdb->terms WHERE slug = %s", $slug );

		if ( $ignore_term_id ) {
			$query .= $wpdb->prepare( ' AND term_id != %d', $ignore_term_id );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $query );
	}

	/**
	 * Obtains a term record by its term_id from the DB. This is necessary when you need to confirm a
	 * term record exists, albeit not one that's necessarily connected to a wp_term_taxonomy record.
	 *
	 * @param int $term_id Term ID.
	 *
	 * @return object|null
	 */
	public function get_term_record( int $term_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT term_id, name, slug FROM $wpdb->terms WHERE term_id = %d",
				$term_id
			)
		);
	}

	/**
	 * Retrieves and outputs a table of terms and taxonomies, based on a given array of term_ids or term_taxonomy_ids.
	 *
	 * @param int[]  $ids Array of term_ids or term_taxonomy_ids.
	 * @param string $type Type of IDs, either self::TERM_ID or self::TAX_ID.
	 * @param string $title Title of the table.
	 *
	 * @return array|null
	 */
	public function output_term_and_term_taxonomy_table( array $ids, string $type = self::TERM_ID, string $title = 'Terms and Taxonomies Table' ): ?array {
		if ( empty( $ids ) ) {
			return null;
		}

		$ids_placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		$constraint_column = 't.term_id';

		if ( self::TAX_ID === $type ) {
			$constraint_column = 'tt.term_taxonomy_id';
		}

		$columns = [
			't.term_id',
			't.name',
			't.slug',
			'tt.term_taxonomy_id',
			'tt.taxonomy',
			'tt.description',
			'tt.parent',
			'tt.count',
		];

		global $wpdb;

		$imploded_columns_escaped = $wpdb->_escape( implode( ', ', $columns ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
		// phpcs:disable
			$wpdb->prepare(
				"SELECT 
					$imploded_columns_escaped 
				FROM $wpdb->terms t 
				    LEFT JOIN $wpdb->term_taxonomy tt
				        ON t.term_id = tt.term_id 
				WHERE $constraint_column IN ( $ids_placeholders )",
				...$ids
			)
		// phpcs:enable
		);

		if ( empty( $rows ) ) {
			ConsoleColor::yellow( "No rows found for $type's: " )
						->bright_white( implode( ', ', $ids ) )
						->output();

			return null;
		}

		$renamed_columns = array_map(
			function ( $column ) {
				return str_replace( [ 'tt.', 't.' ], '', $column );
			},
			$columns
		);

		ConsoleTable::output_data( $rows, $renamed_columns, $title );

		return $rows;
	}

	/**
	 * Retrieves and outputs a table of taxonomies, from a given set of term_taxonomy_id's.
	 *
	 * @param int[]  $term_taxonomy_ids Array of term_taxonomy_id's.
	 * @param string $title Title of the table.
	 *
	 * @return array|null
	 */
	public function output_term_taxonomy_table( array $term_taxonomy_ids, string $title = 'Term Taxonomy Table' ): ?array {
		if ( empty( $term_taxonomy_ids ) ) {
			return null;
		}

		$term_taxonomy_ids_placeholder = implode( ',', array_fill( 0, count( $term_taxonomy_ids ), '%d' ) );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$taxonomies = $wpdb->get_results(
		// phpcs:disable
			$wpdb->prepare(
				"SELECT * FROM $wpdb->term_taxonomy WHERE term_taxonomy_id IN ( $term_taxonomy_ids_placeholder )",
				...$term_taxonomy_ids
			)
		// php:enable
		);

		if ( empty( $taxonomies ) ) {
			ConsoleColor::yellow( 'No taxonomies found for term_taxonomy_ids: ' )
						->bright_white( implode( ', ', $term_taxonomy_ids ) )
						->output();

			return null;
		}

		ConsoleTable::output_data(
			$taxonomies,
			[
				'term_taxonomy_id',
				'term_id',
				'taxonomy',
				'description',
				'parent',
				'count',
			],
			$title
		);

		return $taxonomies;
	}

	/**
	 * Creates a record in wp_term_relationships table for the given object_id and term_taxonomy_id
	 * if it doesn't already exist. Returns true if the record was created, false if there
	 * was an error during insertion, and null if the record already existed.
	 *
	 * @param int $object_id Object ID.
	 * @param int $term_taxonomy_id Term Taxonomy ID.
	 *
	 * @return bool|null
	 */
	public function insert_relationship_if_not_exists( int $object_id, int $term_taxonomy_id ): ?bool {
		global $wpdb;

		$relationship = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $wpdb->term_relationships WHERE object_id = %d AND term_taxonomy_id = %d",
				$object_id,
				$term_taxonomy_id
			)
		);

		if ( empty( $relationship ) ) { // Bummer.
			$insertion = $wpdb->insert(
				$wpdb->term_relationships,
				[
					'object_id'        => $object_id,
					'term_taxonomy_id' => $term_taxonomy_id,
				]
			);

			if ( false !== $insertion ) {
				return true;
			} else {
				return false;
			}
		}

		return null;
	}
}
