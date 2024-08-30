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
	 *  @var CoAuthorPlusLogic $coauthorsplus_logic
	 */
	private $coauthorsplus_logic;

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
			self::$instance->coauthorsplus_logic = new CoAuthorPlusLogic();
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

		WP_CLI::add_command(
			'newspack-content-migrator baristanet-convert-writers-to-guest-authors',
			[ $this, 'cmd_convert_writers_to_guest_authors' ],
			[
				'shortdesc' => 'Converts custom taxonomy of writers to Guest Authors',
				'synopsis' => []
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator baristanet-import-writers-using-old-taxonomy',
			[ $this, 'cmd_import_writers_using_old_taxonomy' ],
			[
				'shortdesc' => 'Imports metadata for writers taxonomy',
				'synopsis' => []
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

	public function cmd_convert_writers_to_guest_authors( $args, $assoc_args ) {
		global $wpdb;

		$writers = $wpdb->get_results( "SELECT * FROM $wpdb->terms t INNER JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id WHERE tt.taxonomy = 'writer'" );

		foreach ( $writers as $writer ) {
			// Create GA using Slug and Name
			$ga_id = $this->coauthorsplus_logic->create_guest_author( [
				'display_name' => $writer->name,
				'user_login' => $writer->slug,
			] );
			// Find all stories they've written
			$post_ids = $wpdb->get_col( "SELECT object_id FROM $wpdb->term_relationships WHERE term_taxonomy_id = $writer->term_taxonomy_id" );
			// Assign GA to all those stories
			foreach ( $post_ids as $post_id ) {
				$this->coauthorsplus_logic->assign_guest_authors_to_post( [ $ga_id ], $post_id );
			}
		}
	}

	public function cmd_import_writers_using_old_taxonomy( $args, $assoc_args ) {
		global $wpdb;

		/*$post_id_mapping = $wpdb->get_results( "SELECT meta_value, post_id FROM $wpdb->postmeta WHERE meta_key = 'newspackcontentdiff_live_id'", OBJECT_K );
		$post_id_mapping = array_map( function( $item ) {
			return $item->post_id;
		}, $post_id_mapping );*/

		/*$barista_relationships = $wpdb->get_results( "SELECT * FROM barista_wp_term_relationships
         WHERE term_taxonomy_id IN (SELECT term_taxonomy_id FROM barista_wp_term_taxonomy WHERE taxonomy = 'writer')");*/

		/*
		 * Get all the old term_relationships for taxonomy = 'writer'
		 * This will give you all the post IDs that need to be updated to ensure that the proper guest author is assigned.
		 * So the object_id's can be grouped, the grouped taxonomies can then be looked up to find the writers.
		 * For each one of those writers, then we need to get or create a GA for them.
		 * Once we have all the GA IDs created, we will assign them to the post.
		 * We need to check that the new post ID is actually correct. We will need to do the query as: where post_id = $new_post_id AND post_type = 'post' AND post_status = 'publish';
		 * If that doesn't match, then we need to look up the new post_id based on post metadata: id, post_name, post_title, post_status, post_type, post_date, post_modified
		 * If still not found, display message so that it can be manually fixed.
		 * */

		$post_ids_with_writers = $wpdb->get_results( "SELECT btr.object_id as old_post_id, GROUP_CONCAT(btr.term_taxonomy_id) as grouped_term_tax_ids, COUNT(btr.term_taxonomy_id) as counter 
			FROM barista_wp_term_relationships btr INNER JOIN barista_wp_posts bp ON btr.object_id = bp.ID WHERE btr.term_taxonomy_id IN (
				SELECT term_taxonomy_id FROM barista_wp_term_taxonomy WHERE taxonomy = 'writer'
			) AND bp.post_status = 'publish' GROUP BY object_id ORDER BY counter DESC");

		foreach ( $post_ids_with_writers as $relationship_record ) {
			WP_CLI::log( 'Processing record, old_post_id: ' . $relationship_record->old_post_id . ' grouped_term_tax_ids: ' . $relationship_record->grouped_term_tax_ids . ' counter: ' . $relationship_record->counter );
			$new_post = $wpdb->get_row( "SELECT p.ID as new_post_id, p.post_type, p.post_status 
				FROM $wpdb->postmeta pm INNER JOIN $wpdb->posts p ON p.ID = pm.post_id 
				WHERE pm.meta_key = 'newspackcontentdiff_live_id' 
				  AND pm.meta_value = {$relationship_record->old_post_id} 
			      AND p.post_type = 'post' 
			      AND p.post_status = 'publish'");

			if ( ! $new_post ) {
				echo WP_CLI::colorize( "%MCould not find new post ID for old post ID. Attempting second query..%n\n");
				$new_post = $wpdb->get_row( "SELECT 
    					p.ID as new_post_id, 
    					p.post_type, 
    					p.post_status, 
    					p.post_name, 
    					p.post_title, 
    					p.post_date, 
    					p.post_modified, 
    					b.ID as old_post_id, 
    					b.post_type as barista_post_type, 
    					b.post_status as barista_post_status, 
    					b.post_name as barista_post_name, 
    					b.post_title as barista_post_title, 
    					b.post_date as barista_post_date, 
    					b.post_modified as barista_post_modified 
					FROM (SELECT * FROM $wpdb->posts WHERE post_status = 'publish' AND post_type = 'post') as p, (SELECT * FROM barista_wp_posts WHERE ID = $relationship_record->old_post_id) as b 
					WHERE p.post_name = b.post_name 
					  AND p.post_status = b.post_status 
					  AND p.post_type = b.post_type 
					  AND p.post_title = b.post_title 
					  AND DATE(p.post_date) = DATE(b.post_date) 
					  AND DATE(p.post_modified) = DATE(b.post_modified)" );

				if ( ! is_null( $new_post ) ) {
					echo WP_CLI::colorize( "%CFound new post ID for old post ID.%n\n");
					$output = [
						[ 'MATCH' => $new_post->new_post_id == $new_post->old_post_id ? 'YES' : 'NO', 'NEW' => $new_post->new_post_id, 'BARISTA' => $new_post->old_post_id ],
						[ 'MATCH' => $new_post->post_type == $new_post->barista_post_type ? 'YES' : 'NO', 'NEW' => $new_post->post_type, 'BARISTA' => $new_post->barista_post_type ],
						[ 'MATCH' => $new_post->post_status == $new_post->barista_post_status ? 'YES' : 'NO', 'NEW' => $new_post->post_status, 'BARISTA' => $new_post->barista_post_status ],
						[ 'MATCH' => $new_post->post_name == $new_post->barista_post_name ? 'YES' : 'NO', 'NEW' => $new_post->post_name, 'BARISTA' => $new_post->barista_post_name ],
						[ 'MATCH' => $new_post->post_title == $new_post->barista_post_title ? 'YES' : 'NO', 'NEW' => $new_post->post_title, 'BARISTA' => $new_post->barista_post_title ],
						[ 'MATCH' => $new_post->post_date == $new_post->barista_post_date ? 'YES' : 'NO', 'NEW' => $new_post->post_date, 'BARISTA' => $new_post->barista_post_date ],
						[ 'MATCH' => $new_post->post_modified == $new_post->barista_post_modified ? 'YES' : 'NO', 'NEW' => $new_post->post_modified, 'BARISTA' => $new_post->barista_post_modified ],
					];
					WP_CLI\Utils\format_items( 'table', $output, [ 'MATCH', 'NEW', 'BARISTA' ] );
				}
			} else {
				// This means new POST ID was found on first try
				echo WP_CLI::colorize( "%CNew Post ID: {$new_post->new_post_id}%n\n");
			}

			if ( ! $new_post ) {
				echo WP_CLI::colorize( "%RCould not find new post ID for old post ID.%n\n");
			}

			// get slugs and names for all the writers
			$writer_slugs_and_names = $wpdb->get_results( "SELECT t.slug, t.name 
				FROM barista_wp_terms t INNER JOIN barista_wp_term_taxonomy tt ON t.term_id = tt.term_id 
				WHERE tt.taxonomy = 'writer' 
				  AND tt.term_taxonomy_id IN ({$relationship_record->grouped_term_tax_ids})" );

			$guest_author_ids = [];
			foreach ( $writer_slugs_and_names as $writer ) {
				echo WP_CLI::colorize( "%WProcessing writer: {$writer->name} slug: {$writer->slug}%n\n");
				$guest_author = $this->coauthorsplus_logic->get_guest_author_by_user_login( $writer->slug );

				if ( false === $guest_author ) {
					echo WP_CLI::colorize( "%YCREATING GUEST AUTHOR: {$writer->name} slug: {$writer->slug}%n");
					$guest_author_id = $this->coauthorsplus_logic->create_guest_author([
						'display_name' => $writer->name,
						'user_login' => $writer->slug,
					]);
					echo WP_CLI::colorize( "%Y\t GA_ID: $guest_author_id %n\n");

					$guest_author = $this->coauthorsplus_logic->get_guest_author_by_id( $guest_author_id );
				}

				$guest_author_ids[] = $guest_author->ID;
			}

			if ( ! $new_post ) {
				echo WP_CLI::colorize( "%RPlease see processed Guest Author's above, SKIPPING DUE TO MISSING NEW POST ID.%n\n");
				continue;
			}

			if ( empty( $guest_author_ids ) ) {
				echo WP_CLI::colorize( "%RNo guest authors found for this post. Skipping.%n\n");
				continue;
			}

			$this->coauthorsplus_logic->assign_guest_authors_to_post( $guest_author_ids, $new_post->new_post_id );
			$post_url = get_site_url( null, '/?p=' . $new_post->new_post_id );
			echo WP_CLI::colorize( "%GAssigned guest authors to post: $post_url.%n\n");
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
