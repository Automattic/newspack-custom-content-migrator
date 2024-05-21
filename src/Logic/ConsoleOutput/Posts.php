<?php

namespace NewspackCustomContentMigrator\Logic\ConsoleOutput;

use NewspackCustomContentMigrator\Utils\ConsoleColor;
use NewspackCustomContentMigrator\Utils\ConsoleTable;

/**
 * Logic mixed with Console Output for Posts.
 */
class Posts {

	/**
	 * This function will output a table with the given post IDs.
	 *
	 * @param array  $post_ids The post IDs to output.
	 * @param array  $columns The specific columns to output.
	 * @param string $title The title of the table.
	 *
	 * @return void
	 */
	public function output_table( array $post_ids, array $columns = [], string $title = 'Post\'s Table' ): void {
		if ( empty( $post_ids ) ) {
			return;
		}

		if ( empty( $columns ) ) {
			$columns = [
				'ID',
				'post_type',
				'post_title',
				'post_name',
				'post_status',
				'post_author',
				'post_date',
				'post_modified',
				'post_parent',
			];
		}

		$post_ids_placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$columns_escaped       = esc_sql( implode( ',', $columns ) );

		global $wpdb;

		ConsoleTable::output_data(
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->get_results(
			// phpcs:disable -- Placeholders used and query sanitized
				$wpdb->prepare(
					"SELECT $columns_escaped
					FROM $wpdb->posts
					WHERE ID IN ( $post_ids_placeholders );",
					...$post_ids
				)
			// phpcs:enable
			),
			$columns,
			$title
		);
	}

	/**
	 * This function facilitates obtaining and outputting the postmeta data for a given
	 * post ID to the console as a table.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $title Table title.
	 *
	 * @return array
	 */
	public function get_and_output_postmeta_table( int $post_id, string $title = 'Postmeta Table' ): array {
		if ( ! empty( $title ) ) {
			if ( ! ConsoleColor::has_color( $title ) ) {
				$title = ConsoleColor::title( $title )->get();
			}

			$title .= ' ' . ConsoleColor::bright_blue( '(' )
										->bright_white( $post_id )
										->bright_blue( ')' )
										->get();
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$postmeta_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $wpdb->postmeta WHERE post_id = %d ORDER BY meta_key ASC",
				$post_id
			)
		);

		ConsoleTable::output_data(
			$postmeta_rows,
			[
				'meta_id',
				'meta_key',
				'meta_value',
			],
			$title
		);

		return $postmeta_rows;
	}


	/**
	 * This function will facilitate obtaining and outputting the postmeta data for any given
	 * pairs of meta_key => meta_value combos. This is useful for determining if a
	 * meta_key => meta_value combo is being used for more than one post.
	 *
	 * @param array $identifiers {
	 *      Array of meta_key => meta_value pairs.
	 *
	 * @type string $meta_key Meta key.
	 * @type string $meta_value Meta value.
	 * }
	 *
	 * @return array|object[]
	 */
	public function get_and_output_matching_postmeta_datapoints_tables( array $identifiers ): array {
		global $wpdb;

		$base_query_escaped = esc_sql( "SELECT * FROM $wpdb->postmeta WHERE " );

		$all_postmeta_rows = [];

		foreach ( $identifiers as $meta_key => $meta_value ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$postmeta_rows = $wpdb->get_results(
			// phpcs:disable -- Placeholders used and query sanitized
				$wpdb->prepare(
					$base_query_escaped . 'meta_key = %s AND meta_value = %s ORDER BY meta_id ASC',
					$meta_key,
					$meta_value
				)
			// phpcs:enable
			);

			if ( empty( $postmeta_rows ) ) {
				ConsoleColor::yellow( 'No postmeta rows found for meta_key:' )
							->bright_white( $meta_key )
							->yellow( 'with meta_value:' )
							->bright_white( $meta_value )
							->output();
				continue;
			}

			$table_title = ConsoleColor::title( 'Postmeta Data' )
										->bright_blue( '(' )
										->bright_white( $meta_key )
										->bright_blue( ')' )
										->get();

			ConsoleTable::output_data(
				$postmeta_rows,
				[
					'meta_id',
					'post_id',
					'meta_key',
					'meta_value',
				],
				$table_title
			);

			$all_postmeta_rows = array_merge( $all_postmeta_rows, $postmeta_rows );
		}

		return $all_postmeta_rows;
	}
}
