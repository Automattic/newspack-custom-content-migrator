<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\Posts as PostsLogic;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus as CoAuthorPlusLogic;
use \NewspackCustomContentMigrator\Logic\Attachments as AttachmentsLogic;
use \WP_CLI;

/**
 * Custom migration scripts for Baristanet.
 */
class BaristanetMigrator implements InterfaceCommand {

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceCommand|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator baristanet-update-duplicate-term-slugs',
			[ $this, 'cmd_update_duplicate_term_slugs' ],
			[
				'shortdesc' => 'Updates duplicate term slugs in given terms table by appending -1 type suffix.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'terms-table-name',
						'description' => 'Terms table name to scan and update.',
						'optional'    => false,
					],
				],
			]

		);
	}

	public function cmd_update_duplicate_term_slugs( $args, $assoc_args ) {
		global $wpdb;

		$terms_table_name = esc_sql( $assoc_args['terms-table-name'] );

		// Cache terms results.
		$terms_cache = $wpdb->get_results( "select * from $terms_table_name order by term_id asc; ", ARRAY_A );

		// Get dupe slugs.
		$dupe_slugs_results = $wpdb->get_results( "select slug, count(slug) from $terms_table_name group by slug having count(slug) > 1; ", ARRAY_A );
		$dupe_slugs = [];
		foreach ( $dupe_slugs_results as $dupe_slugs_result ) {
			$dupe_slugs[] = $dupe_slugs_result['slug'];
		}

		foreach ( $dupe_slugs as $key_dupe_slug => $dupe_slug ) {

			// Get terms with dupe slug.
			$dupe_terms = $this->get_duplicate_slugs_term_rows( $dupe_slug, $terms_cache );

			// Nothing to update.
			if ( 1 === count( $dupe_terms ) ) {
				continue;
			}

			// All dupe slugs will be updated except the first one.
			$dupe_terms_for_update = $dupe_terms;
			unset( $dupe_terms_for_update[0] );
			$dupe_terms_for_update = array_values( $dupe_terms_for_update );
			foreach ( $dupe_terms_for_update as $dupe_term_for_update ) {

				// Get next available "-1" type slug.
				$new_slug = $this->get_next_available_slug( $dupe_slug, $terms_cache );

				// Persist.
				$wpdb->update(
					$terms_table_name,
					[ 'slug' => $new_slug ],
					[ 'term_id' => $dupe_term_for_update['term_id'] ]
				);

				// Update cache.
				$terms_cache = $this->update_terms_cache( $terms_cache, $dupe_term_for_update['term_id'], $new_slug );

				WP_CLI::log( sprintf( "%d/%d", $key_dupe_slug + 1, count( $dupe_slugs ) ) );
				WP_CLI::success( sprintf( "Updated term_id %d from slug '%s' to '%s'.", $dupe_term_for_update['term_id'], $dupe_term_for_update['slug'], $new_slug ) );
			}
		}
	}

	private function update_terms_cache( $results, $term_id, $new_slug ) {
		foreach ( $results as $key_result => $result ) {
			if ( $result['term_id'] === $term_id ) {
				$results[$key_result]['slug'] = $new_slug;

				return $results;
			}
		}

		$debug = 1;
	}

	private function get_next_available_slug( $slug, $results ) {
		$is_available = false;
		$i = 1;
		while ( true ) {
			$next_slug = $slug . '-' . $i;
			$dupe_results = $this->get_duplicate_slugs_term_rows( $next_slug, $results );
			if ( empty( $dupe_results ) ) {
				return $next_slug;
			}
			$i++;
		}
	}

	private function get_duplicate_slugs_term_rows( $slug, $terms_results ) {
		$duplicate_results = [];

		foreach ( $terms_results as $terms_result ) {
			if ( $slug == $terms_result['slug'] ) {
				$duplicate_results[] = $terms_result;
			}
		}

		return $duplicate_results;
	}
}
