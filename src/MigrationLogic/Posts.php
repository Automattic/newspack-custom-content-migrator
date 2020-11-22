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
}
