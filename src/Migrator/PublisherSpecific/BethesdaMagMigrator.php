<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;

/**
 * Custom migration scripts for Bethesda Mag.
 */
class BethesdaMagMigrator implements InterfaceMigrator {
	const DELETE_LOGS = 'bethesda_duplicate_posts_delete.log';

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

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
			'newspack-content-migrator bethesda-remove-duplicated-posts',
			array( $this, 'bethesda_remove_duplicated_posts' ),
			array(
				'shortdesc' => 'Remove duplicated posts.',
				'synopsis'  => array(),
			)
		);
	}

	/**
	 * Callable for `newspack-content-migrator bethesda-remove-duplicated-posts`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function bethesda_remove_duplicated_posts( $args, $assoc_args ) {
		global $wpdb;

		$post_ids_to_delete = array();

		$posts_table = $wpdb->prefix . 'posts';

		$sql = "SELECT post_title, post_date, GROUP_CONCAT(ID ORDER BY ID) AS duplicate_ids, COUNT(*)
		FROM {$posts_table}
		where post_status = 'publish' and post_type in ('post', 'page')
		GROUP BY post_title, post_content, post_date
		HAVING COUNT(*) > 1 ;";
		// phpcs:ignore -- false positive, all params are fully sanitized.
		$results = $wpdb->get_results( $sql );

		foreach ( $results as $result ) {
			$ids = explode( ',', $result->duplicate_ids );
			if ( 2 === count( $ids ) ) {
				$post_ids_to_delete[ $ids[0] ] = array( $ids[1] ); // Deleting the last one imported.
			} else {
				// Some posts are duplicated more than once.
				// We need to make sure that we're deleting the right duplicate.
				$original_post = get_post( $ids[0] );
				foreach ( $ids as $index => $id ) {
					// skip original post.
					if ( 0 === $index ) {
						continue;
					}

					$post = get_post( $id );
					if ( $original_post->post_content === $post->post_content ) {
						if ( ! isset( $post_ids_to_delete[ $ids[0] ] ) ) {
							$post_ids_to_delete[ $ids[0] ] = array();
						}

						$post_ids_to_delete[ $ids[0] ][] = $id;
					}
				}
			}
		}

		foreach ( $post_ids_to_delete as $original_id => $ids ) {
			foreach ( $ids as $post_id_to_delete ) {
				$this->log( self::DELETE_LOGS, sprintf( "Deleting post #%d as it's a duplicate of #%d", $post_id_to_delete, $original_id ) );
				wp_delete_post( $post_id_to_delete );
			}
		}

		wp_cache_flush();
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
