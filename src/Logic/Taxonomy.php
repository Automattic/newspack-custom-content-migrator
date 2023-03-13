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
			COUNT( DISTINCT tt.term_id ) as term_id_count, 
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
			          . implode(',', array_fill( 0, count( $taxonomies ), '%s' ) )
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
}
