<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;
use \WP_Query;
use \CoAuthors_Plus;
use \CoAuthors_Guest_Authors;

class CoAuthorPlusMigrator implements InterfaceMigrator {

	/**
	 * Prefix of tags which get converted to Guest Authors.
	 *
	 * @var string Prefix of a tag which contains a Guest Author's name.
	 */
	private $tag_author_prefix = 'author:';

	/**
	 * @var null|CoAuthors_Plus $coauthors_plus
	 */
	private $coauthors_plus;

	/**
	 * @var null|CoAuthors_Guest_Authors
	 */
	private $coauthors_guest_authors;

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
	 * Validates whether Co-Author Plus plugin's dependencies were successfully set.
	 *
	 * @return bool Is everything set up OK.
	 */
	private function validate_co_authors_plus_dependencies() {
		if ( ( ! $this->coauthors_plus instanceof CoAuthors_Plus ) || ( ! $this->coauthors_guest_authors instanceof CoAuthors_Guest_Authors ) ) {
			return false;
		}

		if ( false === $this->is_coauthors_active() ) {
			return false;
		}

		return true;
	}

	/**
	 * Sets up Co-Authors Plus plugin dependencies.
	 *
	 * @return InterfaceMigrator|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class;

			// Set Co-Authors Plus dependencies.
			global $coauthors_plus;

			$file_1 = ABSPATH . 'wp-content/plugins/co-authors-plus/co-authors-plus.php';
			$file_2 = ABSPATH . 'wp-content/plugins/co-authors-plus/php/class-coauthors-guest-authors.php';
			$included_1 = is_file( $file_1 ) && include_once $file_1;
			$included_2 = is_file( $file_2 ) && include_once $file_2;

			if ( is_null( $coauthors_plus ) || ( false === $included_1 ) || ( false === $included_2 ) || ( ! $coauthors_plus instanceof CoAuthors_Plus ) ) {
				return self::$instance;
			}

			self::$instance->coauthors_plus          = $coauthors_plus;
			self::$instance->coauthors_guest_authors = new CoAuthors_Guest_Authors();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command( 'newspack-content-migrator co-authors-tags-with-prefix-to-guest-authors', array( $this, 'cmd_tags_with_prefix_to_guest_authors' ), [
			'shortdesc' => sprintf( "Converts tags with a specific prefix to Guest Authors, in the following way -- runs through all public Posts, and converts tags beginning with %s prefix (this prefix is currently hardcoded) to Co-Authors Plus Guest Authors, and also assigns them to the post as (co-)authors. It completely overwrites the existing list of authors for these Posts.", $this->tag_author_prefix ),
			'synopsis'  => array(
				array(
					'type'        => 'assoc',
					'name'        => 'unset-author-tags',
					'description' => 'If used, will unset these author tags from the posts.',
					'optional'    => true,
					'repeating'   => false,
				),
			),
		] );
		WP_CLI::add_command( 'newspack-content-migrator co-authors-tags-with-taxonomy-to-guest-authors', array( $this, 'cmd_tags_with_taxonomy_to_guest_authors' ), [
			'shortdesc' => "Converts tags with specified taxonomy to Guest Authors, in the following way -- runs through all the public Posts, gets tags which have a specified taxonomy assigned to them (this taxonomy given here as a positional argument, e.g. 'writer'), and converts these tags to Co-Authors Plus Guest Authors, and also assigns them to the posts as co-authors. It completely overwrites the existing list of authors for these Posts.",
			'synopsis'  => array(
				array(
					'type'        => 'positional',
					'name'        => 'tag-taxonomy',
					'description' => 'The tag taxonomy to filter posts by.',
					'optional'    => false,
					'repeating'   => false,
				),
			),
		] );
		WP_CLI::add_command( 'newspack-content-migrator co-authors-cpt-to-guest-authors', array( $this, 'cmd_cpt_to_guest_authors' ), [
			'shortdesc' => "Converts a CPT used for describing authors to a CAP Guest Authors.",
			'synopsis'  => [
				[
					'type'        => 'positional',
					'name'        => 'cpt',
					'description' => 'The slug of the custom post type we want to convert.',
					'optional'    => false,
					'repeating'   => false,
				],
				[
					'type'        => 'assoc',
					'name'        => 'display_name',
					'description' => 'Where the CPT stores the author\'s display name.',
					'optional'    => true,
					'repeating'   => false,
				],
				[
					'type'        => 'assoc',
					'name'        => 'first_name',
					'description' => 'Where the CPT stores the author\'s first name.',
					'optional'    => true,
					'repeating'   => false,
				],
				[
					'type'        => 'assoc',
					'name'        => 'last_name',
					'description' => 'Where the CPT stores the author\'s last name.',
					'optional'    => true,
					'repeating'   => false,
				],
				[
					'type'        => 'assoc',
					'name'        => 'email',
					'description' => 'Where the CPT stores the author\'s email address.',
					'optional'    => true,
					'repeating'   => false,
				],
				[
					'type'        => 'assoc',
					'name'        => 'website',
					'description' => 'Where the CPT stores the author\'s website address.',
					'optional'    => true,
					'repeating'   => false,
				],
				[
					'type'        => 'assoc',
					'name'        => 'bio',
					'description' => 'Where the CPT stores the author\'s biographical information.',
					'optional'    => true,
					'repeating'   => false,
				],
			],
		] );
	}

	/**
	 * Callable for the `newspack-content-migrator co-authors-tags-with-prefix-to-guest-authors` command.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_tags_with_prefix_to_guest_authors( $args, $assoc_args ) {
		if ( false === $this->validate_co_authors_plus_dependencies() ) {
			WP_CLI::warning( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );
			exit;
		}

		// Associative parameter.
		$unset_author_tags = isset( $assoc_args[ 'unset-author-tags' ] ) ? true : false;

		// Get all published posts.
		$args      = array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			// The WordPress.VIP.PostsPerPage.posts_per_page_posts_per_page coding standard doesn't like '-1' (all posts) for
			// posts_per_page value, so we'll set it to something really high.
			'posts_per_page' => 1000000,
		);
		$the_query = new WP_Query( $args );
		$total_posts = $the_query->found_posts;

		WP_CLI::line( sprintf( "Converting tags beginning with '%s' to Guest Authors for %d total Posts...", $this->tag_author_prefix, $total_posts ) );

		$progress_bar = \WP_CLI\Utils\make_progress_bar( 'Converting', $total_posts );
		if ( $the_query->have_posts() ) {
			while ( $the_query->have_posts() ) {
				$progress_bar->tick();
				$the_query->the_post();
				$this->convert_tags_to_guest_authors( get_the_ID(), $unset_author_tags );
			}
		}
		$progress_bar->finish();

		wp_reset_postdata();

		WP_CLI::success( 'Done.' );
	}

	/**
	 * Callable for the `newspack-content-migrator co-authors-tags-with-taxonomy-to-guest-authors` command.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_tags_with_taxonomy_to_guest_authors( $args, $assoc_args ) {
		if ( false === $this->validate_co_authors_plus_dependencies() ) {
			WP_CLI::warning( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );
			exit;
		}

		// Positional parameter.
		if ( ! isset( $args[0] ) || empty( $args[0] ) ) {
			WP_CLI::error( 'Invalid tag taxonomy name.' );
		}
		$tag_taxonomy = $args[0];

		$post_ids = $this->get_posts_with_tag_with_taxonomy( $tag_taxonomy );
		$total_posts  = count( $post_ids );

		WP_CLI::line( sprintf( 'Converting author tags beginning with %s and with taxonomy %s to Guest Authors for %d posts.', $this->tag_author_prefix, $tag_taxonomy, $total_posts ) );

		$progress_bar = \WP_CLI\Utils\make_progress_bar( 'Converting', $total_posts );
		foreach ( $post_ids as $post_id ) {
			$progress_bar->tick();

			$author_names     = $this->get_post_tags_with_taxonomy( $post_id, $tag_taxonomy );
			$guest_author_ids = $this->create_guest_authors( $author_names );
			$this->assign_guest_authors_to_post( $guest_author_ids, $post_id );
		}
		$progress_bar->finish();

		wp_reset_postdata();

		WP_CLI::success( 'Done.' );
	}

	/**
	 * Callable for the 'newspack-content-migrator co-authors-cpt-to-guest-authors' command.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_cpt_to_guest_authors( $args, $assoc_args ) {

		$cpt_from = $args[0];

		// Make sure we have a valid post type.
		if ( ! \in_array( $cpt_from, get_post_types() ) ) {
			// Register the post type temporarily.
			register_post_type( $cpt_from );
		}

		$authors = $this->get_cpt_authors( $cpt_from );

		// Let us count the ways in which we fail.
		$error_count = 0;

		foreach ( $authors as $author ) {

			WP_CLI::line( sprintf( 'Migrating author %s (%d)', get_the_title( $author->ID ), $author->ID ) );

			

		}

		WP_CLI::success( sprintf(
			esc_html__( 'Completed CPT to Users migration with %d issues.' ),
			$error_count
		) );

	}

	/**
	 * Checks whether Co-authors Plus is installed and active.
	 *
	 * @return bool Is active.
	 */
	public function is_coauthors_active() {
		$active = false;
		foreach ( wp_get_active_and_valid_plugins() as $plugin ) {
			if ( false !== strrpos( $plugin, 'co-authors-plus.php' ) ) {
				$active = true;
			}
		}

		return $active;
	}

	/**
	 * Gets posts which have tags with taxonomy.
	 *
	 * @param string $tag_taxonomy Tag taxonomy.
	 *
	 * @return array Array of post IDs found.
	 */
	public function get_posts_with_tag_with_taxonomy( $tag_taxonomy ) {
		global $wpdb;
		$post_ids = [];

		// TODO: switch to WP_Query instead of raw SQL ( e.g. if ( ! taxonomy_exists( $tag ) ) register_taxonomy( $tag, $object_type ) ).
		$sql_get_post_ids_with_taxonomy = <<<SQL
			SELECT DISTINCT wp.ID
			FROM {$wpdb->prefix}posts wp
			JOIN {$wpdb->prefix}term_relationships wtr ON wtr.object_id = wp.ID
			JOIN {$wpdb->prefix}term_taxonomy wtt ON wtt.term_taxonomy_id = wtr.term_taxonomy_id AND wtt.taxonomy = %s
			JOIN {$wpdb->prefix}terms wt ON wt.term_id = wtt.term_id
			WHERE wp.post_type = 'post'
			AND wp.post_status = 'publish'
			ORDER BY wp.ID;
SQL;
		// phpcs:ignore -- false positive, all params are fully sanitized.
		$results_post_ids               = $wpdb->get_results( $wpdb->prepare( $sql_get_post_ids_with_taxonomy, $tag_taxonomy ), ARRAY_A );

		if ( ! empty( $results_post_ids ) ) {
			foreach ( $results_post_ids as $result_post_id ) {
				$post_ids[] = $result_post_id['ID'];
			}
		}

		return $post_ids;
	}

	/**
	 * For a post ID, gets tags which have the given taxonomy.
	 *
	 * @param int    $post_id         Post ID.
	 * @param string $tag_taxonomy Tag tagxonomy.
	 *
	 * @return array Tag names with given taxonomy which this post has.
	 */
	public function get_post_tags_with_taxonomy( $post_id, $tag_taxonomy ) {
		global $wpdb;
		$names = [];

		// TODO: switch to WP_Query instead of raw SQL ( e.g. if ( ! taxonomy_exists( $tag ) ) register_taxonomy( $tag, $object_type ) ).
		$sql_get_post_ids_with_taxonomy = <<<SQL
			SELECT DISTINCT wt.name
			FROM {$wpdb->prefix}terms wt
			JOIN {$wpdb->prefix}term_taxonomy wtt ON wtt.taxonomy = %s AND wtt.term_id = wt.term_id
			JOIN {$wpdb->prefix}term_relationships wtr ON wtt.term_taxonomy_id = wtr.term_taxonomy_id
			JOIN {$wpdb->prefix}posts wp ON wp.ID = wtr.object_id AND wp.ID = %d
			WHERE wp.post_type = 'post'
			AND wp.post_status = 'publish'
SQL;
		// phpcs:ignore -- false positive, all params are fully sanitized.
		$results_names                  = $wpdb->get_results( $wpdb->prepare( $sql_get_post_ids_with_taxonomy, $tag_taxonomy, $post_id ), ARRAY_A );
		if ( ! empty( $results_names ) ) {
			foreach ( $results_names as $results_name ) {
				$names[] = $results_name['name'];

			}
		}

		return $names;
	}

	/**
	 * Converts tags starting with $tag_author_prefix to Guest Authors, and assigns them to the Post.
	 *
	 * @param int  $post_id           Post ID.
	 * @param bool $unset_author_tags Should the "author tags" be unset from the post once they've been converted to Guest Users.
	 */
	public function convert_tags_to_guest_authors( $post_id, $unset_author_tags = true ) {
		$all_tags = get_the_tags( $post_id );
		if ( false === $all_tags ) {
			return;
		}

		$author_tags_with_names = $this->get_tags_with_author_names( $all_tags );
		if ( empty( $author_tags_with_names ) ) {
			return;
		}

		$author_tags  = [];
		$author_names = [];
		foreach ( $author_tags_with_names as $author_tag_with_name ) {
			$author_tags[]  = $author_tag_with_name['tag'];
			$author_names[] = $author_tag_with_name['author_name'];
		}

		$guest_author_ids = $this->create_guest_authors( $author_names );
		$this->assign_guest_authors_to_post( $guest_author_ids, $post_id );

		if ( $unset_author_tags ) {
			$new_tags      = $this->get_tags_diff( $all_tags, $author_tags );
			$new_tag_names = [];
			foreach ( $new_tags as $new_tag ) {
				$new_tag_names[] = $new_tag->name;
			}

			wp_set_post_terms( $post_id, implode( ',', $new_tag_names ), 'post_tag' );
		}
	}

	/**
	 * Takes an array of tags, and returns those which begin with the $this->tag_author_prefix prefix, stripping the result
	 * of this prefix before returning.
	 *
	 * Example, if this tag is present in the input $tags array:
	 *      '{$this->tag_author_prefix}Some Name' is present in the $tagas array
	 * it will be detected, the prefix stripped, and the rest of the tag returned as an element of the array:
	 *      'Some Name'.
	 *
	 * @param array $tags An array of tags.
	 *
	 * @return array An array with elements containing two keys:
	 *      'tag' holding the full WP_Term object (tag),
	 *      and 'author_name' with the extracted author name.
	 */
	private function get_tags_with_author_names( array $tags ) {
		$author_tags = [];
		if ( empty( $tags ) ) {
			return $author_tags;
		}

		foreach ( $tags as $tag ) {
			if ( substr( $tag->name, 0, strlen( $this->tag_author_prefix ) ) == $this->tag_author_prefix ) {
				$author_tags[] = [
					'tag'         => $tag,
					'author_name' => substr( $tag->name, strlen( $this->tag_author_prefix ) ),
				];
			}
		}

		return $author_tags;
	}

	/**
	 * Creates Guest Authors from their full names.
	 *
	 * @param array $authors_names Authors' names.
	 *
	 * @return array An array of Guest Author IDs.
	 */
	public function create_guest_authors( array $authors_names ) {
		$guest_author_ids = [];

		foreach ( $authors_names as $author_name ) {
			$author_login = sanitize_title( $author_name );
			$guest_author = $this->coauthors_guest_authors->get_guest_author_by( 'user_login', $author_login );

			// If the Guest author doesn't exist, creates it first.
			if ( false === $guest_author ) {
				$coauthor_id = $this->coauthors_guest_authors->create(
					array(
						'display_name' => $author_name,
						'user_login'   => $author_login,
					)
				);
			} else {
				$coauthor_id = $guest_author->ID;
			}

			$guest_author_ids[] = $coauthor_id;
		}

		return $guest_author_ids;
	}

	/**
	 * Assigns Guest Authors to the Post. Completely overwrites the existing list of authors.
	 *
	 * @param array $guest_author_ids Guest Author IDs.
	 * @param int   $post_id          Post IDs.
	 */
	public function assign_guest_authors_to_post( array $guest_author_ids, $post_id ) {
		$coauthors = [];
		foreach ( $guest_author_ids as $guest_author_id ) {
			$guest_author = $this->coauthors_guest_authors->get_guest_author_by( 'id', $guest_author_id );
			$coauthors[]  = $guest_author->user_nicename;
		}
		$this->coauthors_plus->add_coauthors( $post_id, $coauthors, $append_to_existing_users = false );
	}

	/**
	 * A helper function, returns a diff of $tags_a - $tags_b (filters out $tags_b from $tags_a).
	 *
	 * @param array $tags_a Array of WP_Term objects (tags).
	 * @param array $tags_b Array of WP_Term objects (tags).
	 *
	 * @return array An array of resulting WP_Term objects.
	 */
	private function get_tags_diff( $tags_a, $tags_b ) {
		$tags_diff = [];

		foreach ( $tags_a as $tag ) {
			$tag_found_in_tags_b = false;
			foreach ( $tags_b as $author_tag ) {
				if ( $author_tag->term_id === $tag->term_id ) {
					$tag_found_in_tags_b = true;
					break;
				}
			}

			if ( ! $tag_found_in_tags_b ) {
				$tags_diff[] = $tag;
			}
		}

		return $tags_diff;
	}

	/**
	 * Grab the author posts.
	 *
	 * @return array List of authors as an array of WP_Post objects
	 */
	private function get_cpt_authors( $cpt ) {

		// Grab an array of WP_Post objects for the authors.
		$authors = get_posts( [
			'post_type'      => $cpt,
			'posts_per_page' => -1,
			'post_status'    => 'any',
		] );

		if ( empty( $authors ) ) {
			WP_CLI::error( sprintf( 'No authors found!' ) );
		}

		return $authors;

	}

}
