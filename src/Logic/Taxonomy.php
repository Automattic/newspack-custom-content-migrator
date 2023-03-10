<?php
/**
 * Logic for working Taxonomies.
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Logic;

use WP_CLI;

/**
 * Taxonomy implements common migration logic that are used to work with the Simple Local Avatars plugin
 */
class Taxonomy {

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
	 * Reassigns all content from one category to a different category.
	 *
	 * @param int $source_term_id      Source term_id.
	 * @param int $destination_term_id Destination term_id.
	 *
	 * @return void
	 */
	public function reassign_all_content_from_one_category_to_another( int $source_term_id, int $destination_term_id ): void {
		$source_term_taxonomy_id      = $this->get_term_taxonomy_id_by_term_id( $source_term_id );
		$destination_term_taxonomy_id = $this->get_term_taxonomy_id_by_term_id( $destination_term_id );

		$this->update_object_relational_mapping_term_taxonomy_id( $source_term_taxonomy_id, $destination_term_taxonomy_id );

		$this->fix_taxonomy_term_counts( 'category' );
	}

	/**
	 * Gets term_taxonomy_id of a term_id.
	 *
	 * @param int $term_id Term_id.
	 *
	 * @return string|null Return from $wpdb::get_var().
	 */
	public function get_term_taxonomy_id_by_term_id( $term_id ) {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d;", $term_id ) );
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

		return $wpdb->get_var( $wpdb->prepare( "UPDATE {$wpdb->term_relationships} SET term_taxonomy_id = %d WHERE term_taxonomy_id = %d ;", $new_term_taxonomy_id, $old_term_taxonomy_id ) );
	}

	/**
	 * Returns the list of term_taxonomy_id's which have count values
	 * that don't match real values in wp_term_relationships.
	 *
	 * @param string[] $taxonomies Taxonomies to check.
	 * @param string   $order_by   Order by column.
	 * @param string   $order_direction Order direction.
	 * @return stdClass[]
	 */
	public function get_unsynced_taxonomy_rows( array $taxonomies, string $order_by = 'real_count', string $order_direction = 'DESC' ) {
		global $wpdb;

		$from_table = "$wpdb->term_taxonomy as tt";

		if ( ! empty( $taxonomies ) ) {
			$from_table = "( SELECT * FROM $wpdb->term_taxonomy WHERE taxonomy IN ( "
			          . implode( ', ', array_fill( 0, count( $taxonomies ), '%s' ) )
			          . ') ) as tt';
		}

		$query =
			"SELECT 
       			* 
			FROM ( 
			    SELECT
					tt.term_taxonomy_id, 
					t.term_id,
					t.name,
					t.slug,
					tt.taxonomy,
					tt.count, 
					IFNULL( sub.real_count, 0 ) as real_count 
				FROM $from_table LEFT JOIN (
					SELECT 
				       term_taxonomy_id, 
				       COUNT(object_id) as real_count 
					FROM $wpdb->term_relationships 
					GROUP BY term_taxonomy_id
				) as sub ON tt.term_taxonomy_id = sub.term_taxonomy_id 
				LEFT JOIN $wpdb->terms t ON t.term_id = tt.term_id
				WHERE tt.count <> IFNULL( sub.real_count, 0 )
            ) as sub2
			ORDER BY sub2.$order_by $order_direction";

		return $wpdb->get_results(
			$wpdb->prepare( $query, ...$taxonomies )
		);
	}
}
