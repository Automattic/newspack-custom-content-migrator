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

			$plugin_path = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ABSPATH . 'wp-content/plugins';

			$file_1 = $plugin_path . '/co-authors-plus/co-authors-plus.php';
			$file_2 = $plugin_path . '/co-authors-plus/php/class-coauthors-guest-authors.php';
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
			'shortdesc' => "Converts a CPT used for describing authors to a CAP Guest Authors. The associative arguments tell the command where to find info needed to create the Guest Author objects. For example, the GA's 'display name' could be located in the CPT's 'post_title', the GA's 'description' (bio) could be in the CPT's 'post_content', and the email address in a CPT's meta field called 'author_email' -- and these could all be provided like this: `--display_name=post_title --description=post_content --email=meta:author_email`.",
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
					'description' => 'Where the CPT stores the author\'s display name. This is the only mandatory field for GAs.',
					'optional'    => false,
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
					'name'        => 'description',
					'description' => 'Where the CPT stores the author\'s biographical information.',
					'optional'    => true,
					'repeating'   => false,
				],
				[
					'type'        => 'flag',
					'name'        => 'dry-run',
					'description' => 'Do a dry run simulation and don\'t actually create any Guest Authors.',
					'optional'    => true,
					'repeating'   => false,
				],
			],
		] );
		WP_CLI::add_command( 'newspack-content-migrator co-authors-link-guest-author-to-existing-user', array( $this, 'cmd_link_ga_to_user' ), [
			'shortdesc' => "Links a Guest Author to an existing WP User.",
			'synopsis'  => [
				[
					'type'        => 'assoc',
					'name'        => 'ga_id',
					'description' => 'Guest Author ID which will be linked to the existing WP User.',
					'optional'    => false,
					'repeating'   => false,
				],
				[
					'type'        => 'assoc',
					'name'        => 'user_id',
					'description' => 'WP User ID to which to link the Guest Author to.',
					'optional'    => false,
					'repeating'   => false,
				],
			],
		] );
		WP_CLI::add_command( 'newspack-content-migrator co-authors-create-guest-author', array( $this, 'cmd_cap_create_guest_author' ), [
	      'shortdesc' => "Create a Co-Authors Plus Guest Author",
	      'synopsis'  => array(
	        'type'        => 'positional',
	        'name'        => 'postID',
	        'description' => 'Post ID to add guest author to.',
	        'optional'    => false,
	        'repeating'   => false,
	      ),
	    ] );
	}

	/**
	   * Create a guest author and assign to a post.
	   *
	   * @param $args
	   * @param $assoc_args
	   */
	  public function cmd_cap_create_guest_author( $args, $assoc_args ) {
	    if ( false === $this->validate_co_authors_plus_dependencies() ) {
	      WP_CLI::warning( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );
	      exit;
	    }
	    $post_id = $args[0];
	    $email = isset( $assoc_args['email'] ) ? $assoc_args['email'] : null;
	    $description = isset( $assoc_args['description'] ) ? $assoc_args['description'] : null;
	    $full_name = $assoc_args['full_name'];
	    $data = array(
	      'display_name' => sanitize_text_field( $full_name ),
	      'first_name' => sanitize_text_field( $assoc_args['first_name'] ),
	      'last_name' => sanitize_text_field( $assoc_args['last_name'] ),
	    );
	    if ( $email ) {
	      $data['user_email'] = sanitize_email( $email );
	    }
	    if ( $description ) {
	      $data['description'] = wp_filter_post_kses( $description );
	    }
	    $user_login = sanitize_title( $full_name );
	    $data['user_login'] = $user_login;
	    $guest_author = $this->coauthors_guest_authors->get_guest_author_by( 'user_login', $user_login );
	    if ( $guest_author ) {
	      $author_id = $guest_author->ID;
	    } else {
	      $author_id = $this->coauthors_guest_authors->create( $data ) ;
	      $guest_author = $this->coauthors_guest_authors->get_guest_author_by( 'id', $author_id );
	    }
	    $this->coauthors_plus->add_coauthors( $post_id, [ $guest_author->user_nicename ], true );
	    WP_CLI::success( 'Guest author successfully added.' );
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
				$errors = $this->convert_tags_to_guest_authors( get_the_ID(), $unset_author_tags );
			}
		}
		$progress_bar->finish();

		wp_reset_postdata();

		if ( isset( $errors ) && ! empty( $errors ) ) {
			WP_CLI::warning( 'Errors occurred:' );
			foreach ( $errors as $error ) {
				WP_CLI::warning( $error );
			}

			WP_CLI::error( 'Done with errors.' );
		}

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

			$guest_author_ids = [];
			$errors           = [];
			$author_names     = $this->get_post_tags_with_taxonomy( $post_id, $tag_taxonomy );
			foreach ( $author_names as $author_name ) {
				try {
					$guest_author_ids[] = $this->create_guest_author( [ 'display_name' => $author_name ] );
				} catch ( \Exception $e ) {
					$errors[] = sprintf( "Error creating '%s', %s", $author_name, $e->getMessage() );
				}
			}

			$this->assign_guest_authors_to_post( $guest_author_ids, $post_id );
		}
		$progress_bar->finish();

		wp_reset_postdata();

		if ( isset( $errors ) && ! empty( $errors ) ) {
			WP_CLI::warning( 'Errors occurred:' );
			foreach ( $errors as $error ) {
				WP_CLI::warning( $error );
			}

			WP_CLI::error( 'Done with errors.' );
		}

		WP_CLI::success( 'Done.' );
	}

	/**
	 * Callable for the 'newspack-content-migrator co-authors-cpt-to-guest-authors' command.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_cpt_to_guest_authors( $args, $assoc_args ) {
		if ( false === $this->validate_co_authors_plus_dependencies() ) {
			WP_CLI::warning( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );
			exit;
		}

		// Get input args.
		$cpt_from = isset( $args[0] ) ? $args[0] : null;
		if ( null === $cpt_from ) {
			WP_CLI::error( 'Missing CPT slug.' );
		}
		$display_name_from = isset( $assoc_args[ 'display_name' ] ) ? $assoc_args[ 'display_name' ] : null;
		if ( null === $display_name_from ) {
			WP_CLI::error( "Missing Guest Author's display_name." );
		}
		$first_name_from  = isset( $assoc_args[ 'first_name' ] ) && ! empty( $assoc_args[ 'first_name' ] ) ? $assoc_args[ 'first_name' ] : null;
		$last_name_from   = isset( $assoc_args[ 'last_name' ] ) && ! empty( $assoc_args[ 'last_name' ] ) ? $assoc_args[ 'last_name' ] : null;
		$email_from       = isset( $assoc_args[ 'email' ] ) && ! empty( $assoc_args[ 'email' ] ) ? $assoc_args[ 'email' ] : null;
		$website_from     = isset( $assoc_args[ 'website' ] ) && ! empty( $assoc_args[ 'website' ] ) ? $assoc_args[ 'website' ] : null;
		$description_from = isset( $assoc_args[ 'description' ] ) && ! empty( $assoc_args[ 'description' ] ) ? $assoc_args[ 'description' ] : null;
		$dry_run          = isset( $assoc_args[ 'dry-run' ] ) ? true : false;

		// Register the post type temporarily.
		if ( ! in_array( $cpt_from, get_post_types() ) ) {
			register_post_type( $cpt_from );
		}

		// Create the Guest Authors.
		$guest_author_ids = [];
		$errors           = [];
		$cpts             = $this->get_posts( $cpt_from );
		foreach ( $cpts as $cpt ) {
			WP_CLI::line( sprintf( 'Migrating author %s (Post ID %d)', get_the_title( $cpt->ID ), $cpt->ID ) );

			try {
				$args = [];

				$args[ 'display_name' ] = $this->get_cpt_2_ga_cmd_param_value( $cpt, $display_name_from );
				if ( $first_name_from ) {
					$args[ 'first_name' ] = $this->get_cpt_2_ga_cmd_param_value( $cpt, $first_name_from );
				}
				if ( $last_name_from ) {
					$args[ 'last_name' ] = $this->get_cpt_2_ga_cmd_param_value( $cpt, $last_name_from );
				}
				if ( $email_from ) {
					$args[ 'user_email' ] = $this->get_cpt_2_ga_cmd_param_value( $cpt, $email_from );
				}
				if ( $website_from ) {
					$args[ 'website' ] = $this->get_cpt_2_ga_cmd_param_value( $cpt, $website_from );
				}
				if ( $description_from ) {
					$args[ 'description' ] = $this->get_cpt_2_ga_cmd_param_value( $cpt, $description_from );
				}

				if ( true === $dry_run ) {
					continue;
				}

				$new_guest_author   = $this->create_guest_author( $args );
				$guest_author_ids[] = $new_guest_author;

				// Record the original post ID that we migrated from.
				add_post_meta( $new_guest_author, '_post_migrated_from', $cpt->ID );

			} catch ( \Exception $e ) {
				$errors[] = sprintf( 'ID %d -- %s', $cpt->ID, $e->getMessage() );
			}
		}

		if ( isset( $errors ) && ! empty( $errors ) ) {
			WP_CLI::warning( 'Errors occurred:' );
			foreach ( $errors as $error ) {
				WP_CLI::warning( $error );
			}

			WP_CLI::error( 'Done with errors.' );
		}

		WP_CLI::success( sprintf(
			'Created %d GAs from total %d CTPs, and had %d errors.',
			count( $guest_author_ids ),
			count( $cpts ),
			count( $errors )
		) );
	}

	/**
	 * Callable for the 'co-authors-link-guest-author-to-existing-user' command.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_link_ga_to_user( $args, $assoc_args ) {
		if ( false === $this->validate_co_authors_plus_dependencies() ) {
			WP_CLI::warning( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );
			exit;
		}

		$ga_id   = isset( $assoc_args[ 'ga_id' ] ) ? (int) $assoc_args[ 'ga_id' ] : null;
		$user_id = isset( $assoc_args[ 'user_id' ] ) ? (int) $assoc_args[ 'user_id' ] : null;

		$guest_author = $this->coauthors_guest_authors->get_guest_author_by( 'ID', $ga_id );
		$user         = get_user_by( 'id', $user_id );
		if (  ! $guest_author ) {
			WP_CLI::error( sprintf( 'Guest Author by ID %d not found.', $ga_id ) );
		}
		if (  ! $user ) {
			WP_CLI::error( sprintf( 'WP User by ID %d not found.', $user_id ) );
		}

		WP_CLI::line( sprintf( "Linking Guest Author '%s' (ID %d) to WP User '%s' (ID %d)", $guest_author->user_login, $ga_id, $user->user_login, $user_id ) );

		// Since GAs and WP Users can't have the same login, update it if they're the same.
		$cap_user_login = get_post_meta( $ga_id, 'cap-user_login', true );
		if ( $cap_user_login == $user->user_nicename ) {
			update_post_meta( $ga_id, 'cap-user_login', 'cap-' . $cap_user_login );
		}

		// WP User mapping.
		update_post_meta( $ga_id, 'cap-linked_account', $user->user_login );
	}

	/**
	 * Takes one of the positional argument which describe a Guest Author object that's about to be created by the
	 * `co-authors-cpt-to-guest-authors` command, and returns it's value.
	 *
	 * These positional arguments may specify one of these:
	 *  - a column/property of the \WP_Post object (`wp_posts` table), e.g. `post_title`
	 *  - its meta key, prefixed with 'meta:', e.g. `meta:author_email`.
	 *
	 * And this function returns the actual value.
	 *
	 * @param \WP_Post $cpt            CPT from which a Guest Author is getting created.
	 * @param string   $get_value_from The positional argument which describes a new Guest Author to create from the CPTs.
	 *
	 * @return string The actual value specified by the $get_value_from.
	 */
	private function get_cpt_2_ga_cmd_param_value( $cpt, $get_value_from ) {
		// Get the value from meta.
		if ( 0 === strpos( $get_value_from, 'meta:' ) ) {
			$meta_key = substr( $get_value_from, 5 );

			return get_post_meta( $cpt->ID, $meta_key, true );
		}

		return $cpt->$get_value_from;
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
	 *
	 * @return array Descriptive error messages, if any errors occurred.
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

		$guest_author_ids = [];
		$errors           = [];
		foreach ( $author_names as $author_name ) {
			try {
				$guest_author_ids[] = $this->create_guest_author( [ 'display_name' => $author_name ] );
			} catch ( \Exception $e ) {
				$errors[] = sprintf( "Error creating '%s', %s", $author_name, $e->getMessage() );
			}
		}

		$this->assign_guest_authors_to_post( $guest_author_ids, $post_id );

		if ( $unset_author_tags ) {
			$new_tags      = $this->get_tags_diff( $all_tags, $author_tags );
			$new_tag_names = [];
			foreach ( $new_tags as $new_tag ) {
				$new_tag_names[] = $new_tag->name;
			}

			wp_set_post_terms( $post_id, implode( ',', $new_tag_names ), 'post_tag' );
		}

		return $errors;
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
	 * Takes the same $args param as the \CoAuthors_Guest_Authors::create does, which is an array with the following keys:
	 *      - 'display_name' -> this is the only required param
	 *      - 'user_login'   -> this param is optional, because this function automatically creates it from the 'display_name'
	 *      - 'first_name'
	 *      - 'last_name'
	 *      - 'user_email'
	 *      - 'website'
	 *      - 'description'
	 *
	 * @param array $args The $args param for the \CoAuthors_Guest_Authors::create method.
	 *
	 * @return array An array of Guest Author IDs.
	 *
	 * @throws \UnexpectedValueException In case mandatory argument values aren't provided.
	 */
	public function create_guest_author( array $args ) {
		if ( ! isset( $args[ 'display_name' ] ) ) {
			throw new \UnexpectedValueException( 'The `display_name` param is mandatory for Guest Author creation.' );
		}

		// If not provided, automatically set `user_login` from display_name.
		if ( ! isset( $args[ 'user_login' ] ) ) {
			$args[ 'user_login' ] = sanitize_title( $args[ 'display_name' ] );
		}

		$guest_author = $this->coauthors_guest_authors->get_guest_author_by( 'user_login', $args[ 'user_login' ] );
		if ( false === $guest_author ) {
			$coauthor_id = $this->coauthors_guest_authors->create( $args );
		} else {
			$coauthor_id = $guest_author->ID;
		}

		return $coauthor_id;
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
	 * Grab the Custom Post Types.
	 *
	 * @return array WP_Post
	 */
	private function get_posts( $post_type ) {

		$posts = get_posts( [
			'post_type'      => $post_type,
			'posts_per_page' => -1,
			'post_status'    => 'any',
		] );

		if ( empty( $posts ) ) {
			WP_CLI::error( sprintf( "No '%s' post types found!", $post_type ) );
		}

		return $posts;

	}

}
