<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;

class TaxonomyMigrator implements InterfaceMigrator {

	/**
	 * @var null|InterfaceMigrator Instance.
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

			],
		] );
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

		$all_post_types = $this->get_all_db_post_types();

		// Register the Taxonomy on all post types if it's not registered, otherwise Term functions won't work.
		if ( ! taxonomy_exists( $taxonomy ) ) {
			register_taxonomy( $taxonomy, 'any' );
		}

		// Get all Terms with this Taxonomy.
		$terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false ] );
		if ( is_wp_error( $terms ) ) {
			$err_msg = is_wp_error( $terms ) ? $terms->get_error_message() : 'empty';
			WP_CLI::error( sprintf( 'Error retrieving terms: %s', $err_msg ) );
		}
		if ( empty( $terms ) ) {
			WP_CLI::error( sprintf( "No Terms found with Taxonomy '%s'.", $taxonomy ) );
			exit;
		}

		foreach ( $terms as $term ) {
			// Get post objects.
			$posts = $this->get_post_objects_with_taxonomy_and_term( $taxonomy, $term->term_id, $all_post_types );
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

		WP_CLI::line( sprintf( 'Converting Terms with Taxonomy %s to Tags...', $taxonomy ) );

		$all_post_types = $this->get_all_db_post_types();

		// Register the Taxonomy on all post types if it's not registered, otherwise Term functions won't work.
		if ( ! taxonomy_exists( $taxonomy ) ) {
			register_taxonomy( $taxonomy, 'any' );
		}

		// Get all Terms with this Taxonomy.
		$terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false ] );
		if ( is_wp_error( $terms ) ) {
			$err_msg = is_wp_error( $terms ) ? $terms->get_error_message() : 'empty';
			WP_CLI::error( sprintf( 'Error retrieving terms: %s', $err_msg ) );
		}
		if ( empty( $terms ) ) {
			WP_CLI::error( sprintf( 'No Terms found with Taxonomy %s.', $taxonomy ) );
			exit;
		}

		foreach ( $terms as $term ) {
			// Get post objects.
			$posts = $this->get_post_objects_with_taxonomy_and_term( $taxonomy, $term->term_id, $all_post_types );
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

	/**
	 * Gets all post objects with taxonomy and term.
	 *
	 * @param array $post_types Post types.
	 * @param string $taxonomy Taxonomy.
	 * @param int $term_id term_id.
	 *
	 * @return \WP_Post[]
	 */
	private function get_post_objects_with_taxonomy_and_term( $taxonomy, $term_id, $post_types = array( 'post', 'page' ) ) {
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

	/**
	 * Returns a list of all distinct `post_type`s in the posts DB table.
	 *
	 * @return array Post types.
	 */
	private function get_all_db_post_types() {
		global $wpdb;

		$post_types = [];
		$results = $wpdb->get_results( "SELECT DISTINCT post_type FROM {$wpdb->posts}" );
		foreach ( $results as $result ) {
			$post_types[] = $result->post_type;
		}

		return $post_types;
	}
}
