<?php

namespace NewspackCustomContentMigrator\Logic\ConsoleOutput;

use NewspackCustomContentMigrator\Utils\ConsoleColor;
use NewspackCustomContentMigrator\Utils\ConsoleTable;
use stdClass;

/**
 * Logic mixed with Console Output for Taxonomy.
 */
class Taxonomy {

	const TERM_ID = 'term_id';
	const TAX_ID  = 'term_taxonomy_id';


	/**
	 * This function will return any wp_term records that match the given slug.
	 *
	 * @param string $slug The slug.
	 * @param int    $ignore_term_id A specific term_id to ignore. Use this if you want to ensure no other terms with the same slug exist, except for this one.
	 *
	 * @return stdClass[]
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
	public function get_term_record( int $term_id ): ?object {
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