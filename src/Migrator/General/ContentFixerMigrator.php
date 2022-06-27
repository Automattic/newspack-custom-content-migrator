<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\MigrationLogic\Posts as PostsLogic;
use \WP_CLI;

/**
 * Custom migration scripts for Posts' content.
 */
class ContentFixerMigrator implements InterfaceMigrator {
	const POST_CONTENT_SHORTCODES_MIGRATION_LOG = 'POST_CONTENT_SHORTCODES_MIGRATION.log';

    /**
	 * @var PostsLogic.
	 */
	private $posts_logic = null;

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

    /**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts_logic = new PostsLogic();
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceMigrator|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator remove-first-image-from-post-body',
			array( $this, 'remove_first_image_from_post_body' ),
			array(
				'shortdesc' => 'Remove the first image from the post body, usefull to normalize the posts content in case some contains the featured image in their body and others not.',
				'synopsis'  => array(),
			)
		);

        WP_CLI::add_command(
			'newspack-content-migrator remove-shortcodes-from-post-body',
			array( $this, 'remove_shortcodes_from_post_body' ),
			array(
				'shortdesc' => 'Remove shortcodes from post body.',
				'synopsis'  => array(
					array(
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Do a dry run simulation and don\'t actually edit the posts content.',
						'optional'    => true,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'shortcodes',
						'description' => 'List of shortcodes to delete from all the posts content separated by a comma (e.g. shortcode1,shortcode2)',
						'optional'    => false,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'post_ids',
						'description' => 'IDs of posts and pages to remove shortcodes from their content separated by a comma (e.g. 123,456)',
						'optional'    => true,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator remove-duplicate-post-meta-rows',
			array( $this, 'remove_duplicate_post_meta_rows' ),
			array(
				'shortdesc' => 'This will remove any duplicate data that may exist in wp_postmeta table.',
				'synopsis' => array(),
			)
		);
	}

	/**
	 * Callable for `newspack-content-migrator remove-first-image-from-post-body`.
	 */
	public function remove_first_image_from_post_body() {
		WP_CLI::confirm( "This will remove the first image from the post body if it's on a shortcode format ([caption ...]<img src=...>[/caption]), do you want to continue?" );

		$this->posts_logic->throttled_posts_loop(
			array(
				'post_type'   => 'post',
				'post_status' => array( 'publish' ),
			),
			function( $post ) {
				global $wpdb;

				if ( substr( $post->post_content, 0, strlen( '[caption' ) ) === '[caption' ) {
					$start_with_images[] = $post->ID;

					$pattern = get_shortcode_regex();
					if ( preg_match_all( '/' . $pattern . '/s', $post->post_content, $matches ) && array_key_exists( 2, $matches ) && in_array( 'caption', $matches[2] ) ) {
						$index = 0;
						$count = 0;
						// Remove the first image shortcode from the content.
						foreach ( $matches[2] as $match ) {
							if ( 'caption' === $match ) {
								// Found our first image.
								$index = $count;
								break; // We've done enough.
							}
							$count++;
						};
						// Remove first gallery from content.
						$content = str_replace( $matches[0][ $index ], '', $post->post_content );

						if ( $content !== $post->post_content ) {
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery
							$wpdb->update(
								$wpdb->prefix . 'posts',
								array( 'post_content' => $content ),
								array( 'ID' => $post->ID )
							);

							WP_CLI::line( sprintf( 'Updated post: %d', $post->ID ) );
						}
					}
				}
			}
		);
	}

    /**
	 * Callable for `newspack-content-migrator remove-shortcodes-from-post-body`.
	 */
	public function remove_shortcodes_from_post_body( $args, $assoc_args ) {
		$shortcodes = isset( $assoc_args['shortcodes'] ) ? explode( ',', $assoc_args['shortcodes'] ) : null;
		$post_ids   = isset( $assoc_args['post_ids'] ) ? explode( ',', $assoc_args['post_ids'] ) : null;
		$dry_run    = isset( $assoc_args['dry-run'] ) ? true : false;

		if ( is_null( $shortcodes ) || empty( $shortcodes ) ) {
			WP_CLI::error( 'Invalid shortcodes list.' );
		}

		if ( $dry_run ) {
			WP_CLI::warning( 'Dry mode, no changes are going to affect the database' );
		} else {
			WP_CLI::confirm( 'This will remove all the shortcodes with their content from all the posts content, do you want to continue?' );
		}

		$this->posts_logic->throttled_posts_loop(
			array(
				'post_type'   => array( 'post', 'page' ),
				'post_status' => array( 'publish' ),
				'post__in'    => $post_ids,
			),
			function( $post ) use ( $shortcodes, $dry_run ) {
				$post_content_blocks = array();

				foreach ( parse_blocks( $post->post_content ) as $content_block ) {
					// remove shortcodes from Core shortcode, Core HTML, Paragraph, and Classic blocks.
					if (
						'core/shortcode' === $content_block['blockName']
						|| 'core/html' === $content_block['blockName']
						|| ( 'core/paragraph' === $content_block['blockName'] )
						|| ( ! $content_block['blockName'] )
					) {
						$pattern = get_shortcode_regex( $shortcodes );

						if ( preg_match_all( '/' . $pattern . '/s', $content_block['innerHTML'], $matches )
							&& array_key_exists( 2, $matches )
						) {
							$content_without_shortcodes = $this->strip_shortcodes( $shortcodes, $content_block['innerHTML'] );
							// remove resulting empty paragraphs if any.
							$cleaned_content = trim( preg_replace( '/<p[^>]*><\\/p[^>]*>/', '', $content_without_shortcodes ) );

							if ( empty( $cleaned_content ) ) {
								$content_block = null;
								continue;
							}

							$content_block['innerHTML']    = $cleaned_content;
							$content_block['innerContent'] = array_map(
								function( $inner_content ) use ( $shortcodes ) {
									return $this->strip_shortcodes( $shortcodes, $inner_content );
								},
								$content_block['innerContent']
							);
						}
					}

					$post_content_blocks[] = $content_block;
				}

				$post_content_without_shortcodes = serialize_blocks( $post_content_blocks );

				if ( $post_content_without_shortcodes !== $post->post_content ) {
					if ( ! $dry_run ) {
						$update = wp_update_post(
							array(
								'ID'           => $post->ID,
								'post_content' => $post_content_without_shortcodes,
							)
						);

						if ( is_wp_error( $update ) ) {
							$this->log( self::POST_CONTENT_SHORTCODES_MIGRATION_LOG, sprintf( 'Failed to update post %d because %s', $post->ID, $update->get_error_message() ) );
						} else {
							$this->log( self::POST_CONTENT_SHORTCODES_MIGRATION_LOG, sprintf( 'Post %d cleaned from shortcodes.', $post->ID ) );
						}
					} else {
						WP_CLI::line( sprintf( 'Post %d cleaned from shortcodes.', $post->ID ) );
						WP_CLI::line( $post_content_without_shortcodes );
					}
				}
			}
		);
	}

	/**
	 * This function was created to help automate the reduction of a large wp_postmeta table which could adversely
	 * affect performance. It does this by looking at every Post ID in wp_postmeta and seeing if there's a
	 * difference between the total row count and the unique meta_keys. If there is a difference, it
	 * then creates a dynamic key by concatenating meta_key and meta_value, and deleting any
	 * rows where the dynamic key has more than 1 row existing in the table.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function remove_duplicate_post_meta_rows( $args, $assoc_args ) {
		global $wpdb;

		$results         = $wpdb->get_results( "SELECT p.ID as post_id, count(pm.meta_id) as counter, count(distinct pm.meta_key) as unique_keys FROM $wpdb->posts p INNER JOIN $wpdb->postmeta pm ON p.ID = pm.post_id GROUP BY p.ID HAVING counter <> unique_keys ORDER BY counter DESC" );
		$progress_bar    = WP_CLI\Utils\make_progress_bar( "Removing potentially duplicate rows from `$wpdb->postmeta`...", count( $results ) );
		$table_results   = [];
		$total_rows      = 0;
		$eliminated_rows = 0;
		foreach ( $results as $result ) {
			$total_rows         += $result->counter;
			$table_result       = [
				'post_id'     => $result->post_id,
				'total_rows'  => $result->counter,
				'unique_keys' => $result->unique_keys,
			];
			$duplicate_rows     = $wpdb->get_results( "SELECT CONCAT(meta_key, '.', IFNULL(meta_value, 'null')) as concat_key, COUNT(*) as counter, GROUP_CONCAT(meta_id) as meta_ids FROM $wpdb->postmeta WHERE post_id = $result->post_id GROUP BY concat_key HAVING counter > 1" );
			$de_duplicated_rows = 0;
			foreach ( $duplicate_rows as $duplicate_row ) {
				$duplicate_meta_ids = explode( ',', $duplicate_row->meta_ids );
				array_shift( $duplicate_meta_ids );
				$duplicate_meta_ids = implode( ',', $duplicate_meta_ids );
				$deleted_rows       = $wpdb->query( "DELETE FROM $wpdb->postmeta WHERE meta_id IN ( $duplicate_meta_ids )" );

				if ( is_numeric( $deleted_rows ) ) {
					$de_duplicated_rows += $deleted_rows;
				}
			}
			$table_result['deleted_rows'] = $de_duplicated_rows;
			$table_results[] = $table_result;
			$eliminated_rows              += $de_duplicated_rows;
			$progress_bar->tick();
		}
		$progress_bar->finish();

		$results = $wpdb->get_results( "SELECT pm.meta_id, pm.post_id, p.ID FROM $wpdb->postmeta pm LEFT JOIN $wpdb->posts p ON pm.post_id = p.ID WHERE p.ID IS NULL" );
		$loose_post_meta_ids = array_map( fn($result) => $result->meta_id, $results );
		if ( ! empty( $loose_post_meta_ids ) ) {
			$loose_post_meta_ids = implode( ',', $loose_post_meta_ids );
			$deleted_rows = $wpdb->query( "DELETE FROM $wpdb->postmeta WHERE meta_id IN ($loose_post_meta_ids)" );

			if ( is_numeric( $deleted_rows ) ) {
				$total_rows += $deleted_rows;
				$eliminated_rows += $deleted_rows;
			}
		}

		$percent_reduction = ( $eliminated_rows / $total_rows ) * 100;
		WP_CLI\Utils\format_items( 'table', $table_results, [ 'post_id', 'total_rows', 'unique_keys', 'deleted_rows' ] );
		WP_CLI::log( "Total Rows: $total_rows" );
		WP_CLI::log( "Total Eliminated Rows: $eliminated_rows" );
		WP_CLI::log( "% Reduction: $percent_reduction" );
	}

    /**
	 * Strip shortcodes from content.
	 *
	 * @param string[] $shortcodes Shortcodes to strip.
	 * @param string   $text Content to strip the shortcodes from.
	 * @return string
	 */
	private function strip_shortcodes( $shortcodes, $text ) {
		if ( ! ( empty( $shortcodes ) || ! is_array( $shortcodes ) ) ) {
			$tagregexp = join( '|', array_map( 'preg_quote', $shortcodes ) );
			$regex     = '\[(\[?)';
			$regex    .= "($tagregexp)";
			$regex    .= '\b([^\]\/]*(?:\/(?!\])[^\]\/]*)*?)(?:(\/)\]|\](?:([^\[]*+(?:\[(?!\/\2\])[^\[]*+)*+)\[\/\2\])?)(\]?)';

			$text = preg_replace( "/$regex/s", '', $text );
		}

		return $text;
	}

    /**
     * Simple file logging.
     *
     * @param string  $file    File name or path.
     * @param string  $message Log message.
     * @param boolean $to_cli Display the logged message in CLI.
     */
	private function log( $file, $message, $to_cli = true ) {
		$message .= "\n";
		if ( $to_cli ) {
			WP_CLI::line( $message );
		}
		file_put_contents( $file, $message, FILE_APPEND );
	}
}
