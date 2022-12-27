<?php

namespace NewspackCustomContentMigrator\Command\General;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\Posts;
use \NewspackCustomContentMigrator\Logic\Taxonomy;
use stdClass;
use \WP_CLI;

class TaxonomyMigrator implements InterfaceCommand {

	/**
	 * Instance.
	 *
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * @var Posts $posts_logic
	 */
	private $posts_logic;

	/**
	 * Taxonomy logic class.
	 *
	 * @var Taxonomy $taxonomy_logic Taxonomy logic class.
	 */
	private $taxonomy_logic;

	/**
	 * List of taxonomy values recognized by WordPress.
	 *
	 * @var string[] WordPress recognized taxonomies.
	 */
	private $taxonomies = [
		'category',
		'post_tag',
		'hashtags',
	];

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts_logic    = new Posts();
		$this->taxonomy_logic = new Taxonomy();
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceCommand|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator terms-with-taxonomy-to-categories',
			array( $this, 'cmd_terms_with_taxonomy_to_categories' ),
			[
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
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator terms-with-taxonomy-to-tags',
			array( $this, 'cmd_terms_with_taxonomy_to_tags' ),
			[
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
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator fix-taxonomy-count',
			[ $this, 'cmd_fix_taxonomy_count' ],
			[
				'shortdesc' => 'This command will fix wp_term_taxonomy.count for given taxonomies.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'taxonomies-csv',
						'description' => 'CSV of Taxonomies to fix, e.g. --taxonomies-csv=category,tag,author .',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Optional, skips actual updating.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator fix-category-and-tag-count',
			[ $this, 'cmd_fix_category_and_tag_count' ],
			[
				'shortdesc' => 'This command will fix wp_term_taxonomy.count for categories and tags.',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Optional, skips actual updating.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator cull-low-value-tags',
			[ $this, 'cmd_cull_low_value_tags' ],
			[
				'shortdesc' => 'This command will delete any tags which are below a certain threshold.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'threshold',
						'description' => 'This is the upper threshold limit. Any tags below and equal to this value will be deleted.',
						'optional'    => true,
						'default'     => 3,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'sync-counts-first',
						'description' => 'Tells the command to update the wp_term_taxonomy.count column first before proceeding.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator merge-terms',
			[ $this, 'merge_terms_driver' ],
			[
				'shortdesc' => 'Will merge any two terms into one record.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'main-term-id',
						'description' => 'The Term which the other specified terms should be merged into.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'other-term-ids',
						'description' => 'Other terms that should be merged into the main term.',
						'optional'    => false,
						'repeating'   => true,
					],
					[
						'type'        => 'assoc',
						'name'        => 'include-taxonomies',
						'description' => 'Limit to these taxonomies for a given term.',
						'optional'    => true,
						'repeating'   => true,
					],
					[
						'type'        => 'assoc',
						'name'        => 'exclude-taxonomies',
						'description' => 'Do not include these taxonomies if they appear for a given term.',
						'optional'    => true,
						'repeating'   => true,
					],
					[
						'type'        => 'assoc',
						'name'        => 'new-taxonomy',
						'description' => 'The new taxonomy to use for the merged terms and taxonomies.',
						'default'     => 'category',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'parent-term-id',
						'description' => 'Parent Term ID if the finalized term/taxonomy should be a child.',
						'default'     => 0,
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator replant-category-tree',
			[ $this, 'cmd_replant_category_tree' ],
			[
				'shortdesc' => 'Will take a category tree (any Category, either root category or some child category, together with its child categories) and completely move it under a different parent. Any content belonging to categories in that tree get updated.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'category-id',
						'description' => 'Category which together with all its children gets replanted to a different parent category.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'destination-parent-category-id',
						'description' => 'Parent category under which the category tree will be replanted. If `0` is given, category tree will become a root category.',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator move-content-from-one-category-to-another',
			[ $this, 'cmd_move_content_from_one_category_to_another' ],
			[
				'shortdesc' => 'Moves all content from one category to a different one.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'source-term-id',
						'description' => 'term_id of source category.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'destination-term-id',
						'description' => 'term_id of destination category.',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Callable for `newspack-content-migrator replant-category-tree`.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_replant_category_tree( $pos_args, $assoc_args ) {
		$category_id                    = $assoc_args['category-id'];
		$destination_parent_category_id = $assoc_args['destination-parent-category-id'];

		// Get and validate the category parent.
		$category_data = $this->taxonomy_logic->get_categories_data( [ 'term_id' => $category_id ], true );
		if ( is_null( $category_data ) || empty( $category_data ) ) {
			WP_CLI::error( sprintf( 'Category ID %d can not be found.', $category_id ) );
		}
		if ( $destination_parent_category_id == $category_data['parent'] ) {
			WP_CLI::error( sprintf( 'Category %s already has parent %s. No changes made.', $category_id, $destination_parent_category_id ) );
		}
		if ( '0' != $destination_parent_category_id ) {
			$destination_parent_category_data = $this->taxonomy_logic->get_categories_data( [ 'term_id' => $destination_parent_category_id ], true );
			if ( is_null( $destination_parent_category_id ) || empty( $destination_parent_category_id ) ) {
				WP_CLI::error( sprintf( 'Destination category ID %d can not be found.', $destination_parent_category_id ) );
			}
		}

		// Get this category tree array data.
		$category_tree_data = $this->taxonomy_logic->get_category_tree_data( $category_data );

		// Relocate categories and content to a different parent/location.
		$replanted_category_tree_data = $this->taxonomy_logic->replant_category_tree( $category_tree_data, $destination_parent_category_data['term_id'] );

		// Delete the original category tree.
		$this->taxonomy_logic->delete_category_tree( $category_tree_data );

		// Update category count.
		$this->taxonomy_logic->fix_taxonomy_term_counts( 'category' );

		wp_cache_flush();
		WP_CLI::success( 'Done.' );
	}

	/**
	 * Callable for `newspack-content-migrator move-content-from-one-category-to-another`.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_move_content_from_one_category_to_another( $pos_args, $assoc_args ) {
		$source_term_id      = $assoc_args['source-term-id'];
		$destination_term_id = $assoc_args['destination-term-id'];

		$this->taxonomy_logic->reassign_all_content_from_one_category_to_another( $source_term_id, $destination_term_id );

		// Update category count.
		$this->update_counts_for_taxonomies( $this->get_unsynced_taxonomy_rows() );

		wp_cache_flush();
		WP_CLI::success( 'Done.' );
	}

	/**
	 * Callable for terms-with-taxonomy-to-categories command.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_terms_with_taxonomy_to_categories( $args, $assoc_args ) {
		$taxonomy = isset( $assoc_args['taxonomy'] ) ? $assoc_args['taxonomy'] : null;
		if ( is_null( $taxonomy ) ) {
			WP_CLI::error( 'Invalid Taxonomy.' );
		}

		$create_parent_category = isset( $assoc_args['create-parent-category'] ) ? true : false;

		$term_ids_for_conversion = isset( $assoc_args['term_ids'] ) ? explode( ',', $assoc_args['term_ids'] ) : [];

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
			if ( ! empty( $term_ids_for_conversion ) && ! in_array( $term->term_id, $term_ids_for_conversion ) ) {
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
		$taxonomy = isset( $assoc_args['taxonomy'] ) ? $assoc_args['taxonomy'] : null;
		if ( is_null( $taxonomy ) ) {
			WP_CLI::error( 'Invalid Taxonomy.' );
		}

		$term_ids_for_conversion = isset( $assoc_args['term_ids'] ) ? explode( ',', $assoc_args['term_ids'] ) : [];

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
			if ( ! empty( $term_ids_for_conversion ) && ! in_array( $term->term_id, $term_ids_for_conversion ) ) {
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
	public function cmd_fix_category_and_tag_count( $args, $assoc_args ) {

		$dry_run = $assoc_args['dry-run'] ?? null;

		if ( ! $dry_run ) {
			$this->taxonomy_logic->fix_taxonomy_term_counts( 'category' );
			$this->taxonomy_logic->fix_taxonomy_term_counts( 'post_tag' );
		}

		wp_cache_flush();
		WP_CLI::success( 'Done.' );
	}

	/**
	 * Callable for newspack-content-migrator fix-taxonomy-count.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_fix_taxonomy_count( $pos_args, $assoc_args ) {

		$dry_run    = $assoc_args['dry-run'] ?? null;
		$taxonomies = explode( ',', $assoc_args['taxonomies-csv'] ) ?? null;

		foreach ( $taxonomies as $taxonomy ) {
			if ( ! $dry_run ) {
				$this->taxonomy_logic->fix_taxonomy_term_counts( $taxonomy );
			}
		}

		wp_cache_flush();
		WP_CLI::success( 'Done.' );
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
				$this->cmd_fix_taxonomy_count( [], [] );
			}
		} elseif ( $sync_counts_first ) {
			$this->cmd_fix_taxonomy_count( [], [] );
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
		$term_taxonomy_rows_deleted     = 0;
		$term_rows_deleted              = 0;
		if ( ! empty( $results ) ) {
			$term_taxonomy_ids              = implode( ',', $term_taxonomy_ids );
			$term_relationship_rows_deleted = $wpdb->query( "DELETE FROM $wpdb->term_relationships WHERE term_taxonomy_id IN ($term_taxonomy_ids)" );

			if ( ! is_numeric( $term_relationship_rows_deleted ) ) {
				$term_relationship_rows_deleted = 0;
			}

			$term_taxonomy_rows_deleted = $wpdb->query( "DELETE FROM $wpdb->term_taxonomy WHERE term_taxonomy_id IN ($term_taxonomy_ids)" );

			$term_ids           = implode( ',', $term_ids );
			$affected_term_rows = $wpdb->get_results(
				"SELECT 
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
	 * WARNING -- this method does not fetch rows where counts are zero, which might cause errors if updating all records is needed.
	 *
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
			$args     = [
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
				$catarr['category_parent'] = $parent_category->term_id;
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
		$taxonomy_name           = ucfirst( $taxonomy );
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

	/**
	 * Function to merge wp_term_relationships records.
	 *
	 * @param int $main_term_taxonomy_id Main term_taxonomy_id to merge relationship records into.
	 * @param int $term_taxonomy_id term_taxonomy_id which relationships should be merged from.
	 */
	public function merge_relationships( int $main_term_taxonomy_id, int $term_taxonomy_id ) {
		WP_CLI::line( 'Merging relationships...' );
		$this->output( "Term Taxonomy ID: $term_taxonomy_id => Main Term Taxonomy ID: ($main_term_taxonomy_id)", '%B' );

		global $wpdb;

		$duplicate_sql = "SELECT object_id, COUNT(object_id) as counter FROM $wpdb->term_relationships WHERE term_taxonomy_id IN ($term_taxonomy_id, $main_term_taxonomy_id) GROUP BY object_id HAVING counter > 1";

		$this->output_sql( $duplicate_sql );
		$dupes = $wpdb->get_results( $duplicate_sql );

		$dupes_count = count( $dupes );

		$this->output( "There are $dupes_count duplicates in $wpdb->term_relationships", '%B' );

		if ( ! empty( $dupes ) ) {
			$object_ids = array_map(
				function( $dupe ) {
					return $dupe->object_id;
				},
				$dupes
			);

			$delete_sql = "DELETE FROM $wpdb->term_relationships WHERE term_taxonomy_id = $term_taxonomy_id AND object_id IN (" . implode( ',', $object_ids ) . ')';
			$this->output_sql( $delete_sql );
			$deleted = $wpdb->query( $delete_sql );

			$this->output( "$deleted rows were deleted", '%B' );
		}

		$update_sql = "UPDATE $wpdb->term_relationships SET term_taxonomy_id = $main_term_taxonomy_id WHERE term_taxonomy_id = $term_taxonomy_id";
		$this->output_sql( $update_sql );
		$updated = $wpdb->query( $update_sql );

		if ( false === $updated ) {
			$this->output( 'Unable to merge.', '%B' );
		} else {
			$this->output( "Merged $updated rows.", '%B' );
		}
	}

	/**
	 * Handles the merging of wp_term_taxonomy records.
	 *
	 * @param int    $main_term_id Main term_id to use for merged taxonomy.
	 * @param array  $records Array of wp_term_taxonomy records to merge.
	 * @param string $taxonomy Taxonomy to use. Defaults to 'category'.
	 * @param int    $parent_term_id Parent term_id. Defaults to 0.
	 *
	 * @return stdClass
	 */
	protected function merge_taxonomies( int $main_term_id, array $records, string $taxonomy = 'category', int $parent_term_id = 0 ) {
		global $wpdb;

		$taxonomies_record_count = count( $records );

		/*
		 * Will attempt to find the first record in $records matching $main_term_id.
		 * If none is found, then will fall back to simply taking the first record
		 * in $records.
		 */
		$first_taxonomy_record = null;
		foreach ( $records as $key => $record ) {
			if ( $main_term_id == $record->term_id ) {
				$first_taxonomy_record = $record;
				unset( $records[ $key ] );
			}
		}

		if ( is_null( $first_taxonomy_record ) ) {
			$first_taxonomy_record = array_shift( $records );
		}

		$this->output( "Main term_taxonomy_id: $first_taxonomy_record->term_taxonomy_id", '%C' );

		if ( $parent_term_id != $first_taxonomy_record->parent ) {
			$this->output( "Updating parent: $first_taxonomy_record->parent to $parent_term_id for term_taxonomy_id: $first_taxonomy_record->term_taxonomy_id" );
			$wpdb->update(
				$wpdb->term_taxonomy,
				[
					'parent' => $parent_term_id,
				],
				[
					'term_taxonomy_id' => $first_taxonomy_record->term_taxonomy_id,
				]
			);
		}

		if ( $taxonomies_record_count > 1 ) {
			$this->output( "Merging $taxonomies_record_count records into one main taxonomy record.", '%C' );
		}

		foreach ( $records as $taxonomy_record ) {
			$this->merge_relationships(
				$first_taxonomy_record->term_taxonomy_id,
				$taxonomy_record->term_taxonomy_id
			);

			$this->output( "Checking to see if term_taxonomy_id: $taxonomy_record->term_taxonomy_id term_id: $taxonomy_record->term_id is a parent.", '%C' );
			$parent_check_sql = "SELECT * FROM $wpdb->term_taxonomy WHERE parent = $taxonomy_record->term_id";
			$this->output_sql( $parent_check_sql );
			$term_is_a_parent_check = $wpdb->get_results( $parent_check_sql );

			if ( ! empty( $term_is_a_parent_check ) ) {
				$this->output( 'Term is a parent, updating to new parent.', '%C' );

				$update_parent_sql = "UPDATE $wpdb->term_taxonomy SET parent = $first_taxonomy_record->term_id WHERE parent = $taxonomy_record->term_id";
				$this->output_sql( $update_parent_sql );
				$updated = $wpdb->query( $update_parent_sql );

				if ( false === $updated ) {
					$this->output( 'Unable to update to new parent', '%C' );
				} else {
					$this->output( "Updated $updated rows to new parent", '%C' );
				}
			}

			WP_CLI::line( "Adding count: $taxonomy_record->count to main count: $first_taxonomy_record->count" );
			$update_count_sql = "UPDATE $wpdb->term_taxonomy SET count = count + $taxonomy_record->count WHERE term_taxonomy_id = $first_taxonomy_record->term_taxonomy_id";
			$this->output_sql( $update_count_sql );
			$update_count = $wpdb->query( $update_count_sql );

			if ( false !== $update_count ) {
				$this->output( 'Count updated.', '%C' );
			}

			WP_CLI::line( "Say goodbye to term_taxonomy_id: $taxonomy_record->term_taxonomy_id term_id: $taxonomy_record->term_id" );
			$deleted = $wpdb->delete(
				$wpdb->term_taxonomy,
				[
					'term_taxonomy_id' => $taxonomy_record->term_taxonomy_id,
				]
			);

			if ( false !== $deleted ) {
				$this->output( "Deleted term_taxonomy_id: $taxonomy_record->term_taxonomy_id", '%C' );
			} else {
				$this->output( "Unable to delete term_taxonomy_id: $taxonomy_record->term_taxonomy_id", '%C' );
			}
		}

		$final_count_check_sql = "SELECT COUNT(object_id) as counter FROM $wpdb->term_relationships WHERE term_taxonomy_id = $first_taxonomy_record->term_taxonomy_id";
		$this->output_sql( $final_count_check_sql );
		$final_count_check = $wpdb->get_results( $final_count_check_sql );

		if ( ! empty( $final_count_check ) ) {
			$final_count_check = $final_count_check[0];

			if ( $final_count_check->counter != $first_taxonomy_record->count ) {
				$this->output( "Final count must be updated. Current: $first_taxonomy_record->count Actual: $final_count_check->counter" );

				$wpdb->update(
					$wpdb->term_taxonomy,
					[
						'count' => $final_count_check->counter,
					],
					[
						'term_taxonomy_id' => $first_taxonomy_record->term_taxonomy_id,
					]
				);
			}
		}

		$wpdb->update(
			$wpdb->term_taxonomy,
			[
				'taxonomy' => $taxonomy,
			],
			[
				'term_taxonomy_id' => $first_taxonomy_record->term_taxonomy_id,
			]
		);

		return $first_taxonomy_record;
	}

	/**
	 * The $term_ids which are given should be loose term_ids which can safely be deleted from thw wp_terms table.
	 *
	 * @param array $term_ids term_id's which should be deleted, to keep proper table maintenance.
	 */
	public function delete_loose_terms( array $term_ids = [] ) {
		global $wpdb;
		$imploded_term_ids  = implode( ', ', $term_ids );
		$loose_term_ids_sql = "SELECT * FROM $wpdb->terms t 
    		LEFT JOIN $wpdb->term_taxonomy wtt on t.term_id = wtt.term_id 
			WHERE t.term_id IN ($imploded_term_ids) AND wtt.term_taxonomy_id IS NULL";
		$this->output_sql( $imploded_term_ids );
		$loose_term_ids = $wpdb->get_results( $loose_term_ids_sql );

		if ( ! empty( $loose_term_ids ) ) {
			$loose_term_ids = array_map( fn ( $loose_term_id ) => $loose_term_id->term_id, $loose_term_ids );

			$this->output( 'Deleting loose term_ids: ' . implode( ', ', $loose_term_ids ) );
			foreach ( $loose_term_ids as $loose_term_id ) {
				$wpdb->delete(
					$wpdb->terms,
					[
						'term_id' => $loose_term_id,
					]
				);
			}
		}
	}

	/**
	 * Finds $term_ids to merge into $main_term_id.
	 *
	 * @param int      $main_term_id Main term_id to merge terms into.
	 * @param int[]    $term_ids Other term_id's that should be merged into main term_id.
	 * @param string[] $include_taxonomies Capture any terms with these specific taxonomies.
	 * @param string[] $exclude_taxonomies Exclude any terms with these specific taxonomies.
	 * @param string   $taxonomy Taxonomy to use. Default to 'category'.
	 * @param int      $parent_term_id Parent term_id. Default to 0 (i.e. none).
	 *
	 * @return stdClass|WP_Error
	 */
	public function merge_terms( int $main_term_id, array $term_ids = [], array $include_taxonomies = [], array $exclude_taxonomies = [], string $taxonomy = 'category', int $parent_term_id = 0 ) {
		WP_CLI::line( 'Merging Taxonomies...' );

		/*
		 * Some level setting.
		 */
		if ( ! empty( $include_taxonomies ) ) {
			$include_taxonomies = array_unique( $include_taxonomies );
			$include_taxonomies = array_map( fn( $included_taxonomy ) => strtolower( $included_taxonomy ), $include_taxonomies );
		}

		if ( ! empty( $exclude_taxonomies ) ) {
			$exclude_taxonomies = array_unique( $exclude_taxonomies );
			$exclude_taxonomies = array_map( fn( $excluded_taxonomy ) => strtolower( $excluded_taxonomy ), $exclude_taxonomies );
		}

		$taxonomy = strtolower( $taxonomy );

		$term_ids = array_unique( $term_ids, SORT_NUMERIC );

		if ( in_array( $main_term_id, $term_ids ) ) {
			foreach ( $term_ids as $key => $term_id ) {
				if ( $main_term_id === $term_id ) {
					unset( $term_ids[ $key ] );
					break;
				}
			}
		}

		if ( ! in_array( $taxonomy, $include_taxonomies ) ) {
			$include_taxonomies[] = $taxonomy;
		}

		if ( in_array( $taxonomy, $exclude_taxonomies ) ) {
			$exclude_taxonomies = array_filter( $exclude_taxonomies, fn( $excluded_taxonomy ) => $taxonomy !== $excluded_taxonomy );
		}

		/*
		 * Level setting done.
		 */

		global $wpdb;

		$main_taxonomy_sql = "SELECT * FROM $wpdb->term_taxonomy ";

		$constraints     = [];
		$taxonomy_wheres = [];
		if ( ! empty( $include_taxonomies ) ) {
			$taxonomy_wheres[] = "taxonomy IN ('" . implode( "','", $include_taxonomies ) . "')";
		}

		if ( ! empty( $exclude_taxonomies ) ) {
			$constraints[] = "taxonomy NOT IN ('" . implode( "','", $exclude_taxonomies ) . "')";
		}

		$parent_term_id_constraint = "parent = $parent_term_id";
		if ( ! empty( $taxonomy_wheres ) ) {
			$constraints[] = '(' . implode( ' AND ', $taxonomy_wheres ) . " OR $parent_term_id_constraint" . ')';
		} else {
			$constraints[] = $parent_term_id_constraint;
		}

		$temporary_term_id_merge = array_merge( [ $main_term_id ], $term_ids );
		if ( ! empty( $temporary_term_id_merge ) ) {
			$constraints[] = 'term_id IN (' . implode( ',', $temporary_term_id_merge ) . ')';
		} else {
			WP_CLI::confirm( 'The script is about to run on a ton of records, because no term_ids were provided.' );
		}

		if ( ! empty( $constraints ) ) {
			$main_taxonomy_sql = "$main_taxonomy_sql WHERE " . implode( ' AND ', $constraints );
		}

		$this->output( "Main term_id: $main_term_id", '%C' );

		$this->output_sql( $main_taxonomy_sql );
		// var_dump($main_taxonomy_sql);die();
		$main_taxonomy_records = $wpdb->get_results( $main_taxonomy_sql );

		// If one or more $taxonomy records, need to merge all records into one.
		if ( ! empty( $main_taxonomy_records ) ) {
			$result = $this->merge_taxonomies( $main_term_id, $main_taxonomy_records, $taxonomy, $parent_term_id );

			$this->delete_loose_terms( array_map( fn ( $main_taxonomy_record ) => $main_taxonomy_record->term_id, $main_taxonomy_records ) );

			return $result;
		} else {
			// There is no main taxonomy record. Need to create one.
			WP_CLI::line( "No proper $taxonomy taxonomy record exists for term, creating a new one..." );

			// Before creating a new taxonomy row, need to check the unique key constraint. If it exists, need to create a new term.
			$unique_taxonomy_constraint_sql  = "SELECT * FROM $wpdb->term_taxonomy ";
			$unique_taxonomy_constraint_sql .= "INNER JOIN $wpdb->terms ON $wpdb->term_taxonomy.term_id = $wpdb->terms.term_id ";
			$unique_taxonomy_constraint_sql .= "WHERE $wpdb->term_taxonomy.term_id = $main_term_id ";
			$unique_taxonomy_constraint_sql .= "AND $wpdb->term_taxonomy.taxonomy = '$taxonomy'";
			$this->output_sql( $unique_taxonomy_constraint_sql );
			$result = $wpdb->get_results( $unique_taxonomy_constraint_sql );

			// Means a row already exists in $wpdb->term_taxonomy for $main_term_id-$taxonomy key.
			if ( ! empty( $result ) ) {
				// Must get a new term ID.
				$this->output( "$main_term_id-$taxonomy key already exists in $wpdb->term_taxonomy. Need to create a new one...", '%C' );
				$wpdb->insert(
					$wpdb->terms,
					[
						'name' => $result[0]->name,
						'slug' => $result[0]->slug,
					]
				);

				$new_term_sql = "SELECT * FROM $wpdb->terms WHERE name = '{$result[0]->name}' AND slug = '{$result[0]->slug}' AND term_id != $main_term_id";
				$this->output_sql( $new_term_sql );
				$new_term = $wpdb->get_results( $new_term_sql );

				if ( ! empty( $new_term ) ) {
					$this->output( "New term created successfully, term_id: {$new_term[0]->term_id}" );
					$main_term_id = $new_term[0]->term_id;
				}
			}

			$inserted = $wpdb->insert(
				$wpdb->term_taxonomy,
				[
					'term_id'  => $main_term_id,
					'taxonomy' => $taxonomy,
					'parent'   => $parent_term_id,
				]
			);

			if ( false !== $inserted ) {
				$get_newly_created_taxonomy_sql = "SELECT * FROM $wpdb->term_taxonomy WHERE term_id = $main_term_id AND taxonomy = '$taxonomy' AND parent = $parent_term_id";
				$this->output_sql( $get_newly_created_taxonomy_sql );
				$result = $wpdb->get_results( $get_newly_created_taxonomy_sql );

				$other_taxonomies = [];
				if ( ! empty( $term_ids ) ) {
					$other_taxonomies_sql = "SELECT * FROM $wpdb->term_taxonomy WHERE term_id IN (" . implode( ',', $term_ids ) . ')';
					$this->output_sql( $other_taxonomies_sql );
					$other_taxonomies = $wpdb->get_results( $other_taxonomies_sql );
				}

				$this->output( "New term_taxonomy_id: {$result[0]->term_taxonomy_id}", '%C' );

				$this->merge_taxonomies(
					$main_term_id,
					array_merge( $result, $other_taxonomies ),
					$taxonomy
				);

				$this->delete_loose_terms( $term_ids );

				return $result[0];
			} else {
				WP_CLI::error( "Unable to create new taxonomy row for term_id: $main_term_id" );
			}
		}
	}

	/**
	 * Handler for WP CLI execution.
	 *
	 * @param array $args Positional CLI arguments.
	 * @param array $assoc_args Associative CLI arguments.
	 * */
	public function merge_terms_driver( array $args, array $assoc_args ) {
		$this->setup();

		$main_term_id       = intval( $assoc_args['main-term-id'] );
		$other_term_ids     = intval( $assoc_args['other-term-ids'] );
		$include_taxonomies = $assoc_args['include-taxonomies'] ?? [];
		$exclude_taxonomies = $assoc_args['exclude-taxonomies'] ?? [];
		$new_taxonomy       = $assoc_args['new-taxonomy'];
		$parent_term_id     = intval( $assoc_args['parent-term-id'] );

		if ( is_string( $include_taxonomies ) && strlen( $include_taxonomies ) ) {
			$include_taxonomies = explode( ',', $include_taxonomies );
		}

		if ( is_string( $exclude_taxonomies ) && strlen( $exclude_taxonomies ) ) {
			$exclude_taxonomies = explode( ',', $exclude_taxonomies );
		}

		if ( ! is_array( $other_term_ids ) ) {
			$other_term_ids = [ $other_term_ids ];
		}

		$this->merge_terms(
			$main_term_id,
			$other_term_ids,
			$include_taxonomies,
			$exclude_taxonomies,
			$new_taxonomy,
			$parent_term_id
		);
	}

	/**
	 * Using as a sort of constructor, to initialize some class properties.
	 *
	 * @returns void
	 */
	private function setup() {
		global $wpdb;

		$max_term_id_sql = "SELECT * FROM $wpdb->terms ORDER BY term_id DESC LIMIT 1;";
		$this->output_sql( $max_term_id_sql );
		$max_term = $wpdb->get_results( $max_term_id_sql );

		if ( ! empty( $max_term ) ) {
			$this->output( "Maximum term_id: {$max_term[0]->term_id}", '%G' );
			$this->maximum_term_id = $max_term[0]->term_id;
		}
	}

	/**
	 * Convenience function to handle setting a specific color for SQL statements.
	 *
	 * @param string $message MySQL Statement.
	 *
	 * @returns void
	 */
	private function output_sql( string $message ) {
		$this->output( $message, '%w' );
	}

	/**
	 * Output messsage to console with color.
	 *
	 * @param string $message String to output on console.
	 * @param string $color The color to use for console output.
	 *
	 * @returns void
	 */
	private function output( string $message, $color = '%Y' ) {
		echo WP_CLI::colorize( "$color$message%n\n" );
	}
}
