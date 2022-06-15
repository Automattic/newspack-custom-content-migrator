<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\MigrationLogic\Posts as PostsLogic;
use \NewspackCustomContentMigrator\MigrationLogic\CoAuthorPlus as CoAuthorPlusLogic;
use \WP_CLI;

/**
 * Custom migration scripts for Bethesda Mag.
 */
class BethesdaMagMigrator implements InterfaceMigrator {
	const DELETE_LOGS = 'bethesda_duplicate_posts_delete.log';

	/**
	 * @var PostsLogic.
	 */
	private $posts_migrator_logic;

	/**
	 * @var CoAuthorPlusLogic $coauthorsplus_logic
	 */
	private $coauthorsplus_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts_migrator_logic = new PostsLogic();
		$this->coauthorsplus_logic  = new CoAuthorPlusLogic();
	}

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

		WP_CLI::add_command(
			'newspack-content-migrator bethesda-migrate-co-authors-from-meta',
			array( $this, 'bethesda_migrate_co_authors_from_meta' ),
			array(
				'shortdesc' => 'Remove duplicated posts.',
				'synopsis'  => array(),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator bethesda-update-categories',
			[ $this, 'bethesda_update_categories' ],
			[
				'shortdesc' => 'Updating categories according to a CSV list provided by the Pub.',
				'synopsis' => [],
			]
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
	 * Callable for `newspack-content-migrator bethesda-migrate-co-authors-from-meta`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function bethesda_migrate_co_authors_from_meta( $args, $assoc_args ) {
		$this->posts_migrator_logic->throttled_posts_loop(
			array(
				'post_type'   => 'post',
				'post_status' => array( 'publish' ),
			),
			function( $post ) {
				$co_authors_ids       = array();
				$author_meta          = get_post_meta( $post->ID, 'bm_author', true );
				$authors_to_not_split = array(
					'By Alicia Klaffky, Kensington',
					'By Robert Karn, Boyds',
					'By Carole Sugarman, @CaroleSugarman',
				);

				$co_authors_to_add = array();

				if ( ! empty( $author_meta ) ) {
					$cleaned_author_name = $this->clean_author_name( trim( wp_strip_all_tags( $author_meta ) ) );

					// Skip splitting authors with 'School' as they contain only one author name and the name of the high school.
					// Skip splitting specific author names.
					// Skip splitting author names that starts with or ends with given words.
					if (
						! $this->str_contains( $cleaned_author_name, 'school' )
						&& ! $this->str_contains( $cleaned_author_name, 'Adult Short Story Winner' )
						&& ! $this->str_contains( $cleaned_author_name, 'Washington, D.C.' )
						&& ! $this->str_contains( $cleaned_author_name, 'Academie de Cuisine' )
						&& ! $this->str_ends_with( $cleaned_author_name, ', Potomac' )
						&& ! $this->str_ends_with( $cleaned_author_name, ', Rockville' )
						&& ! $this->str_ends_with( $cleaned_author_name, ', Chevy Chase' )
						&& ! $this->str_ends_with( $cleaned_author_name, ', MD' )
						&& ! $this->str_ends_with( $cleaned_author_name, ', Gaithersburg' )
						&& ! $this->str_ends_with( $cleaned_author_name, ', Bethesda' )
						&& ! $this->str_ends_with( $cleaned_author_name, ', Arlington, VA' )
						&& ! $this->str_starts_with( 'Story and photos by', $author_meta )
						&& ! $this->str_starts_with( 'Text and photos by', $author_meta )
						&& ! in_array( $author_meta, $authors_to_not_split, true )
						&& ( $this->str_contains( $cleaned_author_name, ' and ' ) || $this->str_contains( $cleaned_author_name, ', ' ) || $this->str_contains( $cleaned_author_name, 'Follow @' ) )
					) {
						$co_authors_names = preg_split( '/(, | and | & |Follow @[^\s]+)/', $cleaned_author_name );
						foreach ( $co_authors_names as $ca ) {
							$co_authors_to_add[] = $ca;
						}
					} else {
						$co_authors_to_add = array( $cleaned_author_name );
					}
				}

				if ( ! empty( $co_authors_to_add ) ) {
					// add co-authors and link them to the post.
					foreach ( $co_authors_to_add as $co_author_to_add ) {
						$co_authors_ids[] = $this->coauthorsplus_logic->create_guest_author( array( 'display_name' => $co_author_to_add ) );
					}

					// Assign co-atuhors to the post in question.
					$this->coauthorsplus_logic->assign_guest_authors_to_post( $co_authors_ids, $post->ID );
					WP_CLI::line( sprintf( 'Adding co-authors to the post %d: %s', $post->ID, join( ', ', $co_authors_to_add ) ) );
				}
			}
		);

		wp_cache_flush();
	}

	/**
	 * This function takes the Publisher provied CSV and processes the changes to categories.
	 *
	 * @param array $args WP CLI positional args.
	 * @param array $assoc_args WP CLI optional args.
	 */
	public function bethesda_update_categories( $args, $assoc_args ) {
		global $wpdb;
		$handle = fopen( get_home_path() . 'bethesda_categories.csv', 'r' );
		$header = fgetcsv( $handle, 0 );

		$merger = new ElLiberoCustomCategoriesMigrator();
		$erase_taxonomies = [];
		$affected_categories = [];
		while ( ! feof( $handle ) ) {
			$row = array_combine( $header, fgetcsv( $handle, 0 ) );

			WP_CLI::log( "Slug: {$row['slug']}" );
			$affected_category = [
				'slug' => $row['slug'],
				'action' => $row['action'],
				'target' => $row['target'],
				'tag' => $row['tag'],
				'term_id' => null,
				'term_taxonomy_id' => null,
				'rel_count' => 0,
				'dup_cat_count' => 0,
				'already_exist_cat' => 0,
				'dup_tag_count' => 0,
				'already_exist_tag' => 0,
			];

			if ( ! empty( $row['action'] ) ) {
				$current_term_and_term_taxonomy_id_sql = "SELECT t.term_id, tt.term_taxonomy_id FROM $wpdb->term_taxonomy tt 
    				INNER JOIN $wpdb->terms t ON tt.term_id = t.term_id WHERE t.slug = '{$row['slug']}' AND tt.taxonomy = 'category'";
				$current_term_and_term_taxonomy_id = $wpdb->get_row( $current_term_and_term_taxonomy_id_sql );
				$relationship_count_sql = "SELECT COUNT(object_id) as counter FROM $wpdb->term_relationships WHERE term_taxonomy_id = $current_term_and_term_taxonomy_id->term_taxonomy_id";
				$relationship_count = $wpdb->get_row( $relationship_count_sql );
				$affected_category['term_id'] = $current_term_and_term_taxonomy_id->term_id;
				$affected_category['term_taxonomy_id'] = $current_term_and_term_taxonomy_id->term_taxonomy_id;
				$affected_category['rel_count'] = $relationship_count->counter;

				$erase_taxonomies[] = [ $current_term_and_term_taxonomy_id->term_id, $current_term_and_term_taxonomy_id->term_taxonomy_id ];

				switch ( strtolower( trim( $row['action'] ) ) ) {
					case 'remove':
						if ( ! empty( $row['target'] ) ) {
							$term_id = wp_create_category( $row['target'] );
							$result = $this->duplicate_relationships( $term_id, $current_term_and_term_taxonomy_id->term_taxonomy_id );
							$affected_category['dup_cat_count'] = $result['successful_inserts'];
							$affected_category['already_exist_cat'] = $result['already_exist'];
						}

						if ( ! empty( $row['tag'] ) ) {
							$term_id = wp_create_tag( $row['tag'] )['term_id'];
							$result = $this->duplicate_relationships( $term_id, $current_term_and_term_taxonomy_id->term_taxonomy_id, 'post_tag' );
							$affected_category['dup_tag_count'] = $result['successful_inserts'];
							$affected_category['already_exist_tag'] = $result['already_exist'];
						}
						break;
					case 'rename':
						if ( ! empty( $row['target'] ) ) {

							$category_exists = category_exists( $row['target'] );

							if ( is_null( $category_exists ) ) {
								$category_id = wp_create_category( $row['target'] );
								$result = $this->duplicate_relationships( $category_id, $current_term_and_term_taxonomy_id->term_taxonomy_id );
								$affected_category['dup_cat_count'] = $result['successful_inserts'];
								$affected_category['already_exist_cat'] = $result['already_exist'];
							} else {
								// Category already exists, and a merge is required instead.
								$category_exists = (int) $category_exists;

								$merger->merge_terms( $category_exists, [ $current_term_and_term_taxonomy_id->term_id ] );
							}
						}

						if ( ! empty( $row['tag'] ) ) {
							$tag_id = wp_create_tag( $row['tag'] )['term_id'];
							$result = $this->duplicate_relationships( $tag_id, $current_term_and_term_taxonomy_id->term_taxonomy_id, 'post_tag' );
							$affected_category['dup_tag_count'] = $result['successful_inserts'];
							$affected_category['already_exist_tag'] = $result['already_exist'];
						}
						break;
					case 'change to tag':
						if ( ! empty( $row['tag'] ) ) {
							$tag_id = wp_create_tag( $row['tag'] )['term_id'];

							$result = $this->duplicate_relationships( $tag_id, $current_term_and_term_taxonomy_id->term_taxonomy_id, 'post_tag' );
							$affected_category['dup_tag_count'] = $result['successful_inserts'];
							$affected_category['already_exist_tag'] = $result['already_exist'];
						} else {
							$tag_name_without_dashes = str_replace( '-', ' ', $row['slug'] );
							$tag_name_without_dashes = ucwords( $tag_name_without_dashes );
							$tag_id = wp_create_tag( $tag_name_without_dashes )['term_id'];
							$result = $this->duplicate_relationships( $tag_id, $current_term_and_term_taxonomy_id->term_taxonomy_id, 'post_tag' );
							$affected_category['dup_tag_count'] = $result['successful_inserts'];
							$affected_category['already_exist_tag'] = $result['already_exist'];
						}
						break;
					case 'merge':
						$category_id = wp_create_category( $row['target'] );

						$merger->merge_terms( $category_id, [ $current_term_and_term_taxonomy_id->term_id ] );
						break;
				}
			}

			$affected_categories[] = $affected_category;
		}

		foreach ( $erase_taxonomies as $taxonomy ) {
			$this->erase_category( $taxonomy[0], $taxonomy[1] );
		}

		$counts_which_need_updating_sql = "SELECT 
       		tt.term_taxonomy_id,
       		tt.count,
       		sub.counter 
		FROM $wpdb->term_taxonomy tt LEFT JOIN (
    		SELECT 
    		       term_taxonomy_id, 
    		       COUNT(object_id) as counter 
    		FROM $wpdb->term_relationships GROUP BY term_taxonomy_id
		) as sub ON 
		    tt.term_taxonomy_id = sub.term_taxonomy_id 
		WHERE sub.counter IS NOT NULL 
		  AND tt.count <> sub.counter 
		  AND tt.taxonomy IN ('category', 'post_tag')";
		$counts_which_need_updating = $wpdb->get_results( $counts_which_need_updating_sql );

		foreach ( $counts_which_need_updating as $item ) {
			$wpdb->update(
				$wpdb->term_taxonomy,
				[
					'count' => $item->counter,
				],
				[
					'term_taxonomy_id' => $item->term_taxonomy_id,
				]
			);
		}
		$result = [];
		$print_post_ids = [];
		foreach ( $result as $post ) { if ( ! array_key_exists( $post->object_id, $print_post_ids ) ) { $wpdb->insert( $wpdb->term_relationships, [ 'object_id' => $post->object_id, 'term_taxonomy_id' => 58398 ] ); } }
		WP_CLI\Utils\format_items(
			'table',
			$affected_categories,
			[
				'slug',
				'action',
				'target',
				'tag',
				'term_id',
				'term_taxonomy_id',
				'rel_count',
				'dup_cat_count',
				'already_exist_cat',
				'dup_tag_count',
				'already_exist_tag',
			]
		);
	}

	/**
	 * Convenience function to duplicate wp_term_relationships rows.
	 *
	 * @param int    $term_id wp_term.ID.
	 * @param int    $current_term_taxonomy_id wp_term_taxonomy.term_taxonomy_id.
	 * @param string $taxonomy wp_term.taxonomy.
	 *
	 * @return array
	 */
	private function duplicate_relationships( int $term_id, int $current_term_taxonomy_id, string $taxonomy = 'category' ) {
		global $wpdb;

		$term_taxonomy_id_sql = "SELECT term_taxonomy_id FROM $wpdb->term_taxonomy WHERE term_id = $term_id AND taxonomy = '$taxonomy'";
		$term_taxonomy_id = $wpdb->get_row( $term_taxonomy_id_sql );
		$term_taxonomy_id = $term_taxonomy_id->term_taxonomy_id;
		$existing_relationships_sql = "SELECT object_id FROM $wpdb->term_relationships WHERE term_taxonomy_id = $term_taxonomy_id";
		$existing_relationships = $wpdb->get_results( $existing_relationships_sql );
		$existing_relationships = array_map( fn($rel) => $rel->object_id, $existing_relationships );
		$existing_relationships = array_flip( $existing_relationships );
		$current_relationships_sql = "SELECT object_id FROM $wpdb->term_relationships WHERE term_taxonomy_id = $current_term_taxonomy_id";
		$current_relationships = $wpdb->get_results( $current_relationships_sql );

		$successful_inserts = 0;
		$already_exist = count( $existing_relationships );
		foreach ( $current_relationships as $object ) {
			if ( ! array_key_exists( $object->object_id, $existing_relationships ) ) {
				$success = $wpdb->insert(
					$wpdb->term_relationships,
					[
						'object_id'        => $object->object_id,
						'term_taxonomy_id' => $term_taxonomy_id,
					]
				);

				if ( false !== $success ) {
					$successful_inserts += $success;
				}
			}
		}

		return [
			'successful_inserts' => $successful_inserts,
			'already_exist' => $already_exist,
		];
	}

	/**
	 * Deletes any rows from wp_terms, wp_term_taxonomy, wp_term_relationships.
	 *
	 * @param string|int $term_id wp_terms.ID.
	 * @param int        $term_taxonomy_id wp_term_taxonomy.term_taxonomy_id.
	 *
	 * @throws Exception Throws exception if both term_id and term_taxonomy_id = 0.
	 */
	private function erase_category( $term_id = 0, int $term_taxonomy_id = 0 ) {
		global $wpdb;

		if ( is_string( $term_id ) && ! is_numeric( $term_id ) ) {
			// $term_id should be slug.
			$term_id_sql = "SELECT term_id FROM $wpdb->terms WHERE slug = '$term_id'";
			$term_id = $wpdb->get_row( $term_id_sql );
			$term_id = $term_id->term_id;
		}

		$term_id = (int) $term_id;
		if ( 0 === $term_id && 0 === $term_taxonomy_id ) {
			throw new Exception( 'Both $term_id and $term_taxonomy_id cannot be 0.' );
		}

		if ( 0 === $term_id ) {
			$term_id_sql = "SELECT term_id FROM $wpdb->term_taxonomy WHERE term_taxonomy_id = $term_taxonomy_id";
			$term_id = $wpdb->get_row( $term_id_sql );
			$term_id = $term_id->term_id;
		}

		if ( 0 === $term_taxonomy_id ) {
			$term_taxonomy_id_sql = "SELECT term_taxonomy_id FROM $wpdb->term_taxonomy WHERE term_id = $term_id AND taxonomy = 'category'";
			$term_taxonomy_id = $wpdb->get_row( $term_taxonomy_id_sql );
			$term_taxonomy_id = $term_taxonomy_id->term_taxonomy_id;
		}

		$wpdb->delete(
			$wpdb->term_relationships,
			[
				'term_taxonomy_id' => $term_taxonomy_id,
			]
		);

		$wpdb->delete(
			$wpdb->term_taxonomy,
			[
				'term_taxonomy_id' => $term_taxonomy_id,
			]
		);

		$wpdb->delete(
			$wpdb->terms,
			[
				'term_id' => $term_id,
			]
		);
	}

	/**
	 * Clean author name from prefixes.
	 *
	 * @param string $author Author name to clean.
	 * @return string
	 */
	private function clean_author_name( $author ) {
		$prefixes = array(
			'By ',
			'By: ',
			'From Bethesda Now - By ',
			'Compiled By ',
		);

		foreach ( $prefixes as $prefix ) {
			if ( $this->str_starts_with( $author, $prefix ) ) {
				return preg_replace( '/^' . preg_quote( $prefix, '/' ) . '/i', '', $author );
			}
		}

		return $author;
	}

	/**
	 * Checks if a string starts with a given substring
	 *
	 * @param string $haystack The string to search in.
	 * @param string $needle The substring to search for in the haystack.
	 * @return boolean
	 */
	private function str_starts_with( $haystack, $needle ) {
		return substr( strtolower( $haystack ), 0, strlen( strtolower( $needle ) ) ) === strtolower( $needle );
	}

	/**
	 * Checks if a string ends with a given substring
	 *
	 * @param string $haystack The string to search in.
	 * @param string $needle The substring to search for in the haystack.
	 * @return boolean
	 */
	private function str_ends_with( $haystack, $needle ) {
		$length = strlen( $needle );
		if ( ! $length ) {
			return true;
		}
		return substr( strtolower( $haystack ), -$length ) === strtolower( $needle );
	}

	/**
	 * Determine if a string contains a given substring
	 *
	 * @param string $haystack The string to search in.
	 * @param string $needle The substring to search for in the haystack.
	 * @return boolean
	 */
	private function str_contains( $haystack, $needle ) {
		return strpos( strtolower( $haystack ), strtolower( $needle ) ) !== false;
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
