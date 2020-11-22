<?php

namespace NewspackCustomContentMigrator\MigrationLogic;

class Posts {
	/**
	 * Gets IDs of all the Pages.
	 *
	 * @return array Pages IDs.
	 */
	public function get_all_posts_ids( $post_type = 'post', $post_status = [ 'publish', 'future', 'draft', 'pending', 'private', 'inherit' ], $nopaging = true ) {
		$ids = array();

		wp_reset_postdata();

		// Arguments in \WP_Query::parse_query .
		$args = array(
			'nopaging' => $nopaging,
			'post_type' => $post_type,
			'post_status' => $post_status,
		);
		$query = new \WP_Query( $args );
		$posts = $query->get_posts();
		if ( ! empty( $posts ) ) {
			foreach ( $posts as $post ) {
				$ids[] = $post->ID;
			}
		}

		wp_reset_postdata();

		return $ids;
	}

	/**
	 * Gets posts which have tags with taxonomy.
	 *
	 * @param string $tag_taxonomy Tag taxonomy.
	 *
	 * @return array Array of post IDs found.
	 */
	public function get_posts_with_tag_with_taxonomy( $tag_taxonomy ) {
		global $wpdb;
		$post_ids = [];

		// TODO: switch to WP_Query instead of raw SQL ( e.g. if ( ! taxonomy_exists( $tag ) ) register_taxonomy( $tag, $object_type ) ).
		$sql_get_post_ids_with_taxonomy = <<<SQL
			SELECT DISTINCT wp.ID
			FROM {$wpdb->prefix}posts wp
			JOIN {$wpdb->prefix}term_relationships wtr ON wtr.object_id = wp.ID
			JOIN {$wpdb->prefix}term_taxonomy wtt ON wtt.term_taxonomy_id = wtr.term_taxonomy_id AND wtt.taxonomy = %s
			JOIN {$wpdb->prefix}terms wt ON wt.term_id = wtt.term_id
			WHERE wp.post_type = 'post'
			AND wp.post_status = 'publish'
			ORDER BY wp.ID;
SQL;
		// phpcs:ignore -- false positive, all params are fully sanitized.
		$results_post_ids               = $wpdb->get_results( $wpdb->prepare( $sql_get_post_ids_with_taxonomy, $tag_taxonomy ), ARRAY_A );

		if ( ! empty( $results_post_ids ) ) {
			foreach ( $results_post_ids as $result_post_id ) {
				$post_ids[] = $result_post_id['ID'];
			}
		}

		return $post_ids;
	}

	/**
	 * For a post ID, gets tags which have the given taxonomy.
	 *
	 * @param int    $post_id         Post ID.
	 * @param string $tag_taxonomy Tag tagxonomy.
	 *
	 * @return array Tag names with given taxonomy which this post has.
	 */
	public function get_post_tags_with_taxonomy( $post_id, $tag_taxonomy ) {
		global $wpdb;
		$names = [];

		// TODO: switch to WP_Query instead of raw SQL ( e.g. if ( ! taxonomy_exists( $tag ) ) register_taxonomy( $tag, $object_type ) ).
		$sql_get_post_ids_with_taxonomy = <<<SQL
			SELECT DISTINCT wt.name
			FROM {$wpdb->prefix}terms wt
			JOIN {$wpdb->prefix}term_taxonomy wtt ON wtt.taxonomy = %s AND wtt.term_id = wt.term_id
			JOIN {$wpdb->prefix}term_relationships wtr ON wtt.term_taxonomy_id = wtr.term_taxonomy_id
			JOIN {$wpdb->prefix}posts wp ON wp.ID = wtr.object_id AND wp.ID = %d
			WHERE wp.post_type = 'post'
			AND wp.post_status = 'publish'
SQL;
		// phpcs:ignore -- false positive, all params are fully sanitized.
		$results_names                  = $wpdb->get_results( $wpdb->prepare( $sql_get_post_ids_with_taxonomy, $tag_taxonomy, $post_id ), ARRAY_A );
		if ( ! empty( $results_names ) ) {
			foreach ( $results_names as $results_name ) {
				$names[] = $results_name['name'];

			}
		}

		return $names;
	}

	/**
	 * Returns a list of all `post_type`s defined in the posts DB table.
	 *
	 * @return array Post types.
	 */
	public function get_all_post_types() {
		global $wpdb;

		$post_types = [];
		$results = $wpdb->get_results( "SELECT DISTINCT post_type FROM {$wpdb->posts}" );
		foreach ( $results as $result ) {
			$post_types[] = $result->post_type;
		}

		return $post_types;
	}

	/**
	 * Gets all post objects with taxonomy and term.
	 * In order for this function to work, the Taxonomy must be registered on all post types, e.g. like this:
	 * ```
	 *      if ( ! taxonomy_exists( $taxonomy ) ) {
	 *          register_taxonomy( $taxonomy, 'any' );
	 *      }
	 * ```
	 *
	 * @param array $post_types Post types.
	 * @param string $taxonomy Taxonomy.
	 * @param int $term_id term_id.
	 *
	 * @return \WP_Post[]
	 */
	public function get_post_objects_with_taxonomy_and_term( $taxonomy, $term_id, $post_types = array( 'post', 'page' ) ) {
		return get_posts( [
			'posts_per_page' => -1,
			// Target all post_types.
			'post_type'      => $post_types,
			'tax_query'      => [
				[
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $term_id,
				]
			],
		] );
	}
}
