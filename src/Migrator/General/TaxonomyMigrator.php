<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\MigrationLogic\Posts;
use stdClass;
use \WP_CLI;

class TaxonomyMigrator implements InterfaceMigrator {

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * @var Posts $posts_logic
	 */
	private $posts_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts_logic = new Posts();
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceMigrator|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command( 'newspack-content-migrator terms-with-taxonomy-to-categories', array( $this, 'cmd_terms_with_taxonomy_to_categories' ), [
			'shortdesc' => 'Converts Terms with a specified Taxonomy to Categories, and assigns these Categories to belonging post records of all post_types (not just Posts and Pages).',
			'synopsis'  => [
				[
					'type'        => 'assoc',
					'name'        => 'taxonomy',
					'description' => 'Taxonomy name.',
					'optional'    => false,
					'repeating'   => false,
				],
				[
					'type'        => 'flag',
					'name'        => 'create-parent-category',
					'description' => "If this param is used, creates a parent Category named after the Taxonomy, and all the newly created Categories which get created from Tags will become this Parent Category's Subcategories. For example, if the taxonomy is `Regions`, and if the Terms which get converted to Categories are `USA`, `Europe` and `Asia`, the command will first create a Parent Category named `Regions`, and `USA`, `Europe`, `Asia` will be created as its Subcategories.",
					'optional'    => true,
					'repeating'   => false,
				],
				[
					'type'        => 'assoc',
					'name'        => 'term_ids',
					'description' => 'CSV of Terms IDs. If provided, the command will only convert these specific Terms.',
					'optional'    => true,
					'repeating'   => false,
				],
			],
		] );
		WP_CLI::add_command( 'newspack-content-migrator terms-with-taxonomy-to-tags', array( $this, 'cmd_terms_with_taxonomy_to_tags' ), [
			'shortdesc' => 'Converts Terms with a specified Taxonomy to Tags, and assigns these Tags to belonging post records of all post_types (not just Posts and Pages).',
			'synopsis'  => [
				[
					'type'        => 'assoc',
					'name'        => 'taxonomy',
					'description' => 'Taxonomy name.',
					'optional'    => false,
					'repeating'   => false,
				],
				[
					'type'        => 'assoc',
					'name'        => 'term_ids',
					'description' => 'CSV of Terms IDs. If provided, the command will only convert these specific Terms.',
					'optional'    => true,
					'repeating'   => false,
				],
			],
		] );

		WP_CLI::add_command(
			'newspack-content-migrator sync-taxonomy-count-with-real-values',
			[ $this, 'cmd_sync_taxonomy_count_column_with_actual_values' ],
			[
				'shortdesc' => 'This command will compare wp_term_taxonomy.count values with actual row counts in wp_term_relationships table.',
				'synopsis' => [
					[
						'type' => 'flag',
						'name' => 'update',
						'description' => 'Optional flag which tells the command to update the wp_term_taxonomy.count column with the real number of corresponding rows in wp_term_relationships.',
						'optional' => true,
						'repeating' => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator cull-low-value-tags',
			[ $this, 'cmd_cull_low_value_tags' ],
			[
				'shortdesc' => 'This command will delete any tags which are below a certain threshold.',
				'synopsis' => [
					[
						'type' => 'assoc',
						'name' => 'threshold',
						'description' => 'This is the upper threshold limit. Any tags below and equal to this value will be deleted.',
						'optional' => true,
						'default' => 3,
						'repeating' => false,
					],
					[
						'type' => 'flag',
						'name' => 'sync-counts-first',
						'description' => 'Tells the command to update the wp_term_taxonomy.count column first before proceeding.',
						'optional' => true,
						'repeating' => false,
					],
				],
			]
		);
	}

	/**
	 * Callable for terms-with-taxonomy-to-categories command.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_terms_with_taxonomy_to_categories( $args, $assoc_args ) {
		$taxonomy = isset( $assoc_args[ 'taxonomy' ] ) ? $assoc_args[ 'taxonomy' ] : null;
		if ( is_null( $taxonomy ) ) {
			WP_CLI::error( 'Invalid Taxonomy.' );
		}

		$create_parent_category = isset( $assoc_args[ 'create-parent-category' ] ) ? true : false;

		$term_ids_for_conversion = isset( $assoc_args[ 'term_ids' ] ) ? explode( ',', $assoc_args[ 'term_ids' ] ) : [];

		WP_CLI::line( sprintf( 'Converting Terms with Taxonomy %s to Categories...', $taxonomy ) );

		// Create Parent Category if so specified.
		$parent_category = null;
		if ( $create_parent_category ) {
			$parent_category = $this->create_category_from_taxonomy( $taxonomy );
			if ( is_wp_error( $parent_category ) || null === $parent_category ) {
				$err_msg = is_wp_error( $parent_category ) ? $parent_category->get_error_message() : 'null';
				WP_CLI::error( sprintf( 'Error creating Category from Taxonomy %s: %s', $taxonomy, $err_msg ) );
			}
		}

		$all_post_types = $this->posts_logic->get_all_post_types();

		// Register the Taxonomy on all post types if it's not registered, otherwise Term functions won't work.
		if ( ! taxonomy_exists( $taxonomy ) ) {
			register_taxonomy( $taxonomy, 'any' );
		}

		// Get all Terms with this Taxonomy.
		$terms = get_terms( [ 'taxonomy' => $taxonomy ] );
		if ( is_wp_error( $terms ) ) {
			$err_msg = is_wp_error( $terms ) ? $terms->get_error_message() : 'empty';
			WP_CLI::error( sprintf( 'Error retrieving terms: %s', $err_msg ) );
		}
		if ( empty( $terms ) ) {
			WP_CLI::error( sprintf( "No used Terms found with Taxonomy '%s'.", $taxonomy ) );
			exit;
		}

		foreach ( $terms as $term ) {
			// If `term_ids` argument is provided, only convert those Terms.
			if ( ! empty( $term_ids_for_conversion ) && ! in_array( $term->term_id , $term_ids_for_conversion ) ) {
				continue;
			}

			// Get post objects.
			$posts = $this->posts_logic->get_post_objects_with_taxonomy_and_term( $taxonomy, $term->term_id, $all_post_types );
			if ( empty( $posts ) ) {
				WP_CLI::line( sprintf( "No post objects found for term '%s'.", $term->slug ) );
				continue;
			}

			// Create Category from Term.
			$category = $this->convert_term_to_category( $term, $parent_category );
			if ( is_wp_error( $category ) ) {
				WP_CLI::error( sprintf( "Error creating Category from Term '%s': %s.", $term->slug, $category->get_error_message() ) );
			}

			// Add Category to post objects.
			WP_CLI::line( sprintf( "Adding Category '%s' to all post objects...", $category->name ) );
			foreach ( $posts as $post ) {
				wp_set_post_terms( $post->ID, [ $category->term_id ], 'category', true );
				WP_CLI::line( sprintf( "Updated ID %d with Category '%s.'", $post->ID, $category->name ) );
			}
		}

		WP_CLI::success( 'Done.' );
	}

	/**
	 * Callable for terms-with-taxonomy-to-tags command.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_terms_with_taxonomy_to_tags( $args, $assoc_args ) {
		$taxonomy = isset( $assoc_args[ 'taxonomy' ] ) ? $assoc_args[ 'taxonomy' ] : null;
		if ( is_null( $taxonomy ) ) {
			WP_CLI::error( 'Invalid Taxonomy.' );
		}

		$term_ids_for_conversion = isset( $assoc_args[ 'term_ids' ] ) ? explode( ',', $assoc_args[ 'term_ids' ] ) : [];

		WP_CLI::line( sprintf( 'Converting Terms with Taxonomy %s to Tags...', $taxonomy ) );

		$all_post_types = $this->posts_logic->get_all_post_types();

		// Register the Taxonomy on all post types if it's not registered, otherwise Term functions won't work.
		if ( ! taxonomy_exists( $taxonomy ) ) {
			register_taxonomy( $taxonomy, 'any' );
		}

		// Get all Terms with this Taxonomy.
		$terms = get_terms( [ 'taxonomy' => $taxonomy ] );
		if ( is_wp_error( $terms ) ) {
			$err_msg = is_wp_error( $terms ) ? $terms->get_error_message() : 'empty';
			WP_CLI::error( sprintf( 'Error retrieving terms: %s', $err_msg ) );
		}
		if ( empty( $terms ) ) {
			WP_CLI::error( sprintf( 'No used Terms found with Taxonomy %s.', $taxonomy ) );
			exit;
		}

		foreach ( $terms as $term ) {
			// If `term_ids` argument is provided, only convert those Terms.
			if ( ! empty( $term_ids_for_conversion ) && ! in_array( $term->term_id , $term_ids_for_conversion ) ) {
				continue;
			}

			// Get post objects.
			$posts = $this->posts_logic->get_post_objects_with_taxonomy_and_term( $taxonomy, $term->term_id, $all_post_types );
			if ( empty( $posts ) ) {
				WP_CLI::line( sprintf( "No post objects found for term '%s'.", $term->slug ) );
				continue;
			}

			// Create Tag from Term.
			$tag = $this->convert_term_to_tag( $term );
			if ( is_wp_error( $tag ) ) {
				WP_CLI::error( sprintf( "Error creating Tag from Term '%s': %s", $term->slug, $tag->get_error_message() ) );
			}

			// Add Tag to post objects.
			WP_CLI::line( sprintf( "Adding Tag '%s' to all post objects...", $tag->name ) );
			foreach ( $posts as $post ) {
				wp_set_post_terms( $post->ID, $tag->slug, 'post_tag', true );
				WP_CLI::line( sprintf( "Updated post %d with Tag '%s'.", $post->ID, $tag->name ) );
			}
		}

		WP_CLI::success( 'Done.' );
	}

	/**
	 * This function will display the comparison between the wp_term_taxonomy.count column and
	 * the actual, real count of rows contained with the wp_term_relationships table
	 * for that particular term_taxonomy_id.
	 *
	 * @param array $args       WP CLI Positional arguments.
	 * @param array $assoc_args WP CLI Optional arguments.
	 */
	public function cmd_sync_taxonomy_count_column_with_actual_values( $args, $assoc_args ) {
		$update = $assoc_args['update'] ?? null;

		$results = $this->get_unsynced_taxonomy_rows();

		$table = [];
		foreach ( $results as $row ) {
			$table[] = [
				'term_taxonomy_id' => $row->term_taxonomy_id,
				'term_id' => $row->term_id,
				'name' => $row->name,
				'slug' => $row->slug,
				'taxonomy' => $row->taxonomy,
				'current_count' => $row->count,
				'actual_count' => $row->counter,
			];
		}

		if ( ! empty( $results ) ) {
			WP_CLI\Utils\format_items(
				'table',
				$table,
				[
					'term_taxonomy_id',
					'term_id',
					'name',
					'slug',
					'taxonomy',
					'current_count',
					'actual_count',
				]
			);

			if ( $update ) {
				$this->update_counts_for_taxonomies( $results );
			}
		} else {
			WP_CLI::line( 'All counts are accurate!' );
		}
	}

	/**
	 * This function will execute the updates required to make the wp_term_taxonomy.count column
	 * match the actual, real number of rows in wp_term_relationships table.
	 *
	 * @param array $rows Should be the results which show actual taxonomy counts (from wp_term_relationships)vs what is stored.
	 */
	protected function update_counts_for_taxonomies( array $rows ) {
		global $wpdb;

		$progress_bar = WP_CLI\Utils\make_progress_bar( 'Updating counts for taxonomies...', count( $rows ) );

		foreach ( $rows as $row ) {
			$wpdb->update( $wpdb->term_taxonomy, [ 'count' => $row->counter ], [ 'term_taxonomy_id' => $row->term_taxonomy_id ] );
			$progress_bar->tick();
		}

		$progress_bar->finish();
	}

	/**
	 * Command which drives the elimination of Tags and Tag related data from the database.
	 * Tables affected:
	 * wp_term_taxonomy
	 * wp_term_relationships
	 * wp_terms
	 *
	 * The wp_term_taxonomy.count column is updated and synced with real
	 * count values from wp_term_relationships table.
	 *
	 * @param string[] $args       WP CLI Positional Arguments.
	 * @param string[] $assoc_args WP CLI Optional Arguments.
	 */
	public function cmd_cull_low_value_tags( $args, $assoc_args ) {
		$sync_counts_first = $assoc_args['sync-counts-first'] ?? null;

		if ( is_null( $sync_counts_first ) && ! empty( $this->get_unsynced_taxonomy_rows() ) ) {
			$response = $this->ask_prompt( 'Proceed without first updating the wp_term_taxonomy.count column with actual row counts from wp_term_relationships table? [(y)es/(n)o/(e)xit]' );

			if ( 'exit' === $response || 'e' === $response || ! in_array( $response, [ 'yes', 'y', 'no', 'n' ] ) ) {
				WP_CLI::line( 'Exiting...' );
				die();
			}

			if ( 'no' === $response || 'n' === $response ) {
				$this->cmd_sync_taxonomy_count_column_with_actual_values( [], [ 'update' => true ] );
			}
		} else if ( $sync_counts_first ) {
			$this->cmd_sync_taxonomy_count_column_with_actual_values( [], [ 'update' => true ] );
		}

		global $wpdb;
		$tag_limit = $assoc_args['threshold'];

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
       				term_taxonomy_id, 
       				term_id 
				FROM $wpdb->term_taxonomy 
				WHERE taxonomy = 'post_tag' 
				  AND count <= %d",
				$tag_limit
			)
		);

		$term_taxonomy_ids = [];
		$term_ids          = [];

		$term_taxonomy_count = 0;
		foreach ( $results as $row ) {
			$term_taxonomy_count ++;
			$term_taxonomy_ids[] = $row->term_taxonomy_id;
			$term_ids[]          = $row->term_id;
		}

		$term_relationship_rows_deleted = 0;
		$term_taxonomy_rows_deleted = 0;
		$term_rows_deleted = 0;
		if ( ! empty( $results ) ) {
			$term_taxonomy_ids              = implode( ',', $term_taxonomy_ids );
			$term_relationship_rows_deleted = $wpdb->query( "DELETE FROM $wpdb->term_relationships WHERE term_taxonomy_id IN ($term_taxonomy_ids)" );

			if ( ! is_numeric( $term_relationship_rows_deleted ) ) {
				$term_relationship_rows_deleted = 0;
			}

			$term_taxonomy_rows_deleted = $wpdb->query( "DELETE FROM $wpdb->term_taxonomy WHERE term_taxonomy_id IN ($term_taxonomy_ids)" );

			$term_ids           = implode( ',', $term_ids );
			$affected_term_rows = $wpdb->get_results( "SELECT 
                    t.term_id, 
                    COUNT(tt.term_taxonomy_id) as counter
				FROM $wpdb->terms t 
				    LEFT JOIN $wpdb->term_taxonomy tt 
				        ON t.term_id = tt.term_id 
				WHERE t.term_id IN ($term_ids) 
				GROUP BY t.term_id 
				HAVING counter = 0"
			);

			$affected_term_ids = array_map( fn( $row ) => $row->term_id, $affected_term_rows );

			$term_rows_deleted = 0;
			if ( ! empty( $affected_term_ids ) ) {
				$affected_term_ids = implode( ',', $affected_term_ids );

				$term_rows_deleted = $wpdb->query( "DELETE FROM $wpdb->terms WHERE term_id IN ($affected_term_ids)" );

				if ( ! is_numeric( $term_rows_deleted ) ) {
					$term_rows_deleted = 0;
				}
			}
		}

		WP_CLI::line( "$term_taxonomy_count wp_term_taxonomy records <= $tag_limit." );
		WP_CLI::line( "Deleted wp_term_relationships rows: $term_relationship_rows_deleted" );
		WP_CLI::line( "Deleted wp_term_taxonomy rows: $term_taxonomy_rows_deleted" );
		WP_CLI::line( "Deleted wp_terms rows: $term_rows_deleted" );
	}

	/**
	 * Returns the list of term_taxonomy_id's which have count values
	 * that don't match real values in wp_term_relationships.
	 *
	 * @return stdClass[]
	 */
	protected function get_unsynced_taxonomy_rows() {
		global $wpdb;

		return $wpdb->get_results(
			"SELECT 
	            tt.term_taxonomy_id, 
       			t.term_id,
       			t.name,
       			t.slug,
       			tt.taxonomy,
	            tt.count, 
	            sub.counter 
			FROM $wpdb->term_taxonomy tt LEFT JOIN (
			    SELECT 
			           term_taxonomy_id, 
			           COUNT(object_id) as counter 
			    FROM $wpdb->term_relationships 
			    GROUP BY term_taxonomy_id
			    ) as sub 
			ON tt.term_taxonomy_id = sub.term_taxonomy_id 
			LEFT JOIN $wpdb->terms t ON t.term_id = tt.term_id
			WHERE sub.counter IS NOT NULL 
			  AND tt.count <> sub.counter 
			  AND tt.taxonomy IN ('category', 'post_tag')"
		);
	}
	/**
	 * Custom interactive prompt.
	 *
	 * @param string $question The question to present to the user.
	 *
	 * @return string
	 */
	private function ask_prompt( string $question ) {
		fwrite( STDOUT, "$question: " );

		return strtolower( trim( fgets( STDIN ) ) );
	}

	/**
	 * Converts Term to Tag.
	 *
	 * @param \WP_Term $term
	 *
	 * @return \WP_Term|\WP_Error|null
	 */
	private function convert_term_to_tag( $term ) {
		$tag = get_term_by( 'slug', $term->slug, 'post_tag' );
		if ( ! $tag ) {
			$args = [
				'slug'        => $term->slug,
				'description' => $term->description,
			];
			$tag_info = wp_insert_term( $term->name, 'post_tag', $args );

			if ( is_wp_error( $tag_info ) ) {
				return $tag_info;
			}

			$tag = get_term( $tag_info['term_id'], 'post_tag' );
		}

		return $tag;
	}

	/**
	 * Converts Term to Category, with an option to set a Parent Category.
	 *
	 * @param \WP_Term $term Term to convert to a Category
	 * @param \WP_Term $parent_category If provided, will use this as the Parent Category.
	 *
	 * @return \WP_Term|\WP_Error|null
	 */
	private function convert_term_to_category( $term, $parent_category = null ) {
		$category = get_term_by( 'slug', $term->slug, 'category' );
		if ( ! $category ) {
			$catarr = array(
				'cat_name'             => $term->name,
				'category_nicename'    => $term->slug,
				'category_description' => $term->description,
			);
			if ( $parent_category ) {
				$catarr[ 'category_parent' ] = $parent_category->term_id;
			}
			$category_id = wp_insert_category( $catarr );
			if ( is_wp_error( $category_id ) ) {
				WP_CLI::error( sprintf( 'Error creating Category from Term %s: %s', $term->name, $category_id->get_error_message() ) );
			}

			$category = get_term( $category_id, 'category' );
		}

		return $category;
	}

	/**
	 * Creates Category from Taxonomy.
	 *
	 * @param string $taxonomy
	 *
	 * @return WP_Term|\WP_Error|null
	 */
	private function create_category_from_taxonomy( $taxonomy ) {
		$taxonomy_name = ucfirst( $taxonomy );
		$parent_category_term_id = category_exists( $taxonomy_name );
		if ( ! $parent_category_term_id ) {
			$parent_category_term_id = wp_create_category( $taxonomy_name );

			// On error, early return the \WP_Error object.
			if ( ! $parent_category_term_id || is_wp_error( $parent_category_term_id ) ) {
				return $parent_category_term_id;
			}
		}
		$parent_category = get_category( $parent_category_term_id );

		return $parent_category;
	}
}
