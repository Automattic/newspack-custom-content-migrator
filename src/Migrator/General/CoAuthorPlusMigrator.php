<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use \CoAuthors_Guest_Authors;
use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\MigrationLogic\CoAuthorPlus;
use \NewspackCustomContentMigrator\MigrationLogic\Posts;
use \NewspackCustomContentMigrator\PluginSetup;
use \WP_CLI;
use \WP_Query;
use WP_User_Query;

class CoAuthorPlusMigrator implements InterfaceMigrator {

	/**
	 * Prefix of tags which get converted to Guest Authors.
	 *
	 * @var string Prefix of a tag which contains a Guest Author's name.
	 */
	private $tag_author_prefix = 'author:';

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * @var CoAuthorPlus $coauthorsplus_logic
	 */
	private $coauthorsplus_logic;

	/**
	 * @var Posts $posts_logic
	 */
	private $posts_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthorsplus_logic = new CoAuthorPlus();
		$this->posts_logic = new Posts();
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
		WP_CLI::add_command( 'newspack-content-migrator co-authors-create-guest-author-and-add-to-post', array( $this, 'cmd_cap_create_guest_author_and_add_to_post' ), [
			'shortdesc' => "Create a Co-Authors Plus Guest Author and add it to a post.",
			'synopsis'  => [
				[
					'type'        => 'positional',
					'name'        => 'postID',
					'description' => 'Post ID to add guest author to.',
					'optional'    => false,
					'repeating'   => false,
				],
				[
					'type'        => 'assoc',
					'name'        => 'full_name',
					'description' => 'Guest author\' full name.',
					'optional'    => false,
					'repeating'   => false,
				],
				[
					'type'        => 'assoc',
					'name'        => 'first_name',
					'description' => 'Guest author\' first name.',
					'optional'    => true,
					'repeating'   => false,
				],
				[
					'type'        => 'assoc',
					'name'        => 'last_name',
					'description' => 'Guest author\' last name.',
					'optional'    => true,
					'repeating'   => false,
				],
				[
					'type'        => 'assoc',
					'name'        => 'email',
					'description' => 'Guest author\' email.',
					'optional'    => true,
					'repeating'   => false,
				],
				[
					'type'        => 'assoc',
					'name'        => 'description',
					'description' => 'Guest author\' description.',
					'optional'    => true,
					'repeating'   => false,
				],
			],
		] );
		WP_CLI::add_command( 'newspack-content-migrator co-authors-fix-non-unique-guest-slugs', array( $this, 'cmd_cap_fix_non_unique_guest_slugs'), [
		    'shortdesc' => "Make unique any Guest Author Slug which matches a User's slug."
        ] );
		WP_CLI::add_command(
			'newspack-content-migrator co-authors-split-to-multiple-coauthors',
			[ $this, 'cmd_split_guest_author_to_multiple_authors' ],
			[
				'shortdesc' => 'Reassigns all posts currently associated to a specific Guest Author to different multiple (or single) Guest Author(s). Example use: `wp newspack-content-migrator co-authors-split-to-multiple-coauthors "Chris Christofersson of NewsSource, with Adam Black of 100 Days in Appalachia" --new-guest-author-names="Chris Christofersson; Adam Black of 100 Days in Appalachia" --delimiter="; "',
				'synopsis' => [
					[
						'type' => 'positional',
						'name' => 'guest-author',
						'description' => 'Target Guest Author (Guest Author Display Name e.g. Edward Carrasco with Newspack, and Ingrid Miller with HR)',
						'optional' => false,
						'repeating' => false,
					],
					[
						'type' => 'assoc',
						'name' => 'new-guest-author-names',
						'description' => "The new guest author's to create (e.g. Edward Carrasco,Ingrid Miller [delimited by comma]). The original GA will be disassociated.",
						'optional' => false,
						'repeating' => true,
					],
					[
						'type' => 'assoc',
						'name' => 'delimiter',
						'description' => 'Delimiter to use to split new-guest-authors values.',
						'optional' => true,
						'repeating' => false,
						'default' => ',',
					],
				],
			]
		);
	}

	/**
	 * Installs the CAP plugin, and reinitializes the \NewspackCustomContentMigrator\MigrationLogic\CoAuthorPlus dependency.
	 */
	public function require_cap_plugin() {
		if ( false === $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
			WP_CLI::warning( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );

			// Install and activate the CAP plugin.
			PluginSetup::setup_coauthors_plus();

			// reinitialize the CAP dependency.
			$this->coauthorsplus_logic = new CoAuthorPlus();
		}
	}

	/**
	 * Create a guest author and assign to a post.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_cap_create_guest_author_and_add_to_post( $args, $assoc_args ) {
		$this->require_cap_plugin();

		$post_id            = $args[0];
		$email              = isset( $assoc_args['email'] ) ? $assoc_args['email'] : null;
		$description        = isset( $assoc_args['description'] ) ? $assoc_args['description'] : null;
		$full_name          = $assoc_args['full_name'];
		$user_login         = sanitize_title( $full_name );
		$data['user_login'] = $user_login;
		$data               = [
			'display_name' => sanitize_text_field( $full_name ),
			'first_name'   => sanitize_text_field( $assoc_args['first_name'] ),
			'last_name'    => sanitize_text_field( $assoc_args['last_name'] ),
		];
		if ( $email ) {
			$data['user_email'] = sanitize_email( $email );
		}
		if ( $description ) {
		$data['description'] = wp_filter_post_kses( $description );
		}

		$guest_author = $this->coauthorsplus_logic->get_guest_author_by_user_login( $user_login );
		if ( $guest_author ) {
			$author_id = $guest_author->ID;
		} else {
			$author_id = $this->coauthorsplus_logic->create_guest_author( $data ) ;
		}

		$this->coauthorsplus_logic->assign_guest_authors_to_post( [ $author_id ], $post_id );

		WP_CLI::success( 'Guest author successfully added.' );
	}

	/**
	 * Callable for the `newspack-content-migrator co-authors-tags-with-prefix-to-guest-authors` command.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_tags_with_prefix_to_guest_authors( $args, $assoc_args ) {
		$this->require_cap_plugin();

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
		$this->require_cap_plugin();

		// Positional parameter.
		if ( ! isset( $args[0] ) || empty( $args[0] ) ) {
			WP_CLI::error( 'Invalid tag taxonomy name.' );
		}
		$tag_taxonomy = $args[0];

		$post_ids = $this->posts_logic->get_posts_with_tag_with_taxonomy( $tag_taxonomy );
		$total_posts  = count( $post_ids );

		WP_CLI::line( sprintf( 'Converting author tags beginning with %s and with taxonomy %s to Guest Authors for %d posts.', $this->tag_author_prefix, $tag_taxonomy, $total_posts ) );

		$progress_bar = \WP_CLI\Utils\make_progress_bar( 'Converting', $total_posts );
		foreach ( $post_ids as $post_id ) {
			$progress_bar->tick();

			$guest_author_ids = [];
			$errors           = [];
			$author_names     = $this->posts_logic->get_post_tags_with_taxonomy( $post_id, $tag_taxonomy );
			foreach ( $author_names as $author_name ) {
				try {
					$guest_author_ids[] = $this->coauthorsplus_logic->create_guest_author( [ 'display_name' => $author_name ] );
				} catch ( \Exception $e ) {
					$errors[] = sprintf( "Error creating '%s', %s", $author_name, $e->getMessage() );
				}
			}

			$this->coauthorsplus_logic->assign_guest_authors_to_post( $guest_author_ids, $post_id );
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
		$this->require_cap_plugin();

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

				$new_guest_author   = $this->coauthorsplus_logic->create_guest_author( $args );
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
		$this->require_cap_plugin();

		$ga_id   = isset( $assoc_args[ 'ga_id' ] ) ? (int) $assoc_args[ 'ga_id' ] : null;
		$user_id = isset( $assoc_args[ 'user_id' ] ) ? (int) $assoc_args[ 'user_id' ] : null;

		$guest_author = $this->coauthorsplus_logic->get_guest_author_by( 'ID', $ga_id );
		$user         = get_user_by( 'id', $user_id );
		if (  ! $guest_author ) {
			WP_CLI::error( sprintf( 'Guest Author by ID %d not found.', $ga_id ) );
		}
		if (  ! $user ) {
			WP_CLI::error( sprintf( 'WP User by ID %d not found.', $user_id ) );
		}

		WP_CLI::line( sprintf( "Linking Guest Author '%s' (ID %d) to WP User '%s' (ID %d)", $guest_author->user_login, $ga_id, $user->user_login, $user_id ) );

		$this->coauthorsplus_logic->link_guest_author_to_wp_user( $ga_id, $user );
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
	 * Converts tags starting with $tag_author_prefix to Guest Authors, and assigns them to the Post.
	 *
	 * @param int  $post_id           Post ID.
	 * @param bool $unset_author_tags Should the "author tags" be unset from the post once they've been converted to Guest Users.
	 *
	 * @return array Descriptive error messages, if any errors occurred.
	 */
	public function convert_tags_to_guest_authors( $post_id, $unset_author_tags = true ) {
		$this->require_cap_plugin();

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
				$guest_author_ids[] = $this->coauthorsplus_logic->create_guest_author( [ 'display_name' => $author_name ] );
			} catch ( \Exception $e ) {
				$errors[] = sprintf( "Error creating '%s', %s", $author_name, $e->getMessage() );
			}
		}

		$this->coauthorsplus_logic->assign_guest_authors_to_post( $guest_author_ids, $post_id );

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

    public function cmd_cap_fix_non_unique_guest_slugs() {

        $authors = ( new WP_User_Query( array(
            'who'       => 'authors',
            'fields'    => array(
                    'user_nicename',
                ),
        ) ) )->get_results();

        $author_slugs_string = "'" . implode( "', '", wp_list_pluck( $authors, 'user_nicename' ) ) . "'";

        /*
         * Convert from stdClass to indexed array.
         * */

        foreach ( $authors as $key => $author ) {
            $authors[ $author->user_nicename ] = $key;
            unset( $authors[ $key ] );
        }

        global $wpdb;

        $post_meta_table = "{$wpdb->prefix}postmeta";

        $sql = "SELECT meta_value FROM {$post_meta_table} 
                WHERE meta_key = 'cap-user_login'
                AND meta_value IN ({$author_slugs_string})";

        $non_unique_guest_authors = $wpdb->get_col( $sql );

        $updated_slugs = array();
        $unable_to_make_unique = array();

        $progress = WP_CLI\Utils\make_progress_bar( 'Updating Guest Author Slugs', count( $non_unique_guest_authors ) );
        foreach ( $non_unique_guest_authors as $guest_author ) {
            $attempts = 3;

            $progress->tick();

            do {
                $new_guest_author_slug = "{$guest_author}_{$this->readable_random_string()}";

                if ( array_key_exists( $new_guest_author_slug, $authors ) ) {
                    $attempts--;
                } else {
                    $wpdb->update(
                        $post_meta_table,
                        array(
                            'meta_value' => $new_guest_author_slug,
                        ),
                        array(
                            'meta_key' => 'cap-user_login',
                            'meta_value' => $guest_author,
                        )
                    );

                    $updated_slugs[] = "Updated: `{$guest_author}` -> `{$new_guest_author_slug}`";

                    /*
                     * Break out of do-while and go to next for-each iteration
                     * */
                    continue 2;
                }
            } while ($attempts > 0);

            /*
             * 3 Attempts failed to generate unique slug. Will report back to console.
             * */
            $unable_to_make_unique[] = $guest_author;
        }
        $progress->finish();

        if ( ! empty($unable_to_make_unique) ) {
            array_unshift( $unable_to_make_unique, "Unable to make the following Guest Author Slugs unique..." );
            WP_CLI::error_multi_line( $unable_to_make_unique );
        }

        WP_CLI::success( "Done!" );
        foreach ( $updated_slugs as $slug ) {
            WP_CLI::line( $slug );
        }
	}

	/**
	 * This command facilitates the creation of new guest author's in place of another.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function cmd_split_guest_author_to_multiple_authors( $args, $assoc_args ) {
		$old_guest_author = $args[0];
		$delimiter = $assoc_args['delimiter'];
		$new_guest_author_names = explode( $delimiter, $assoc_args['new-guest-author-names'] );

		global $wpdb;
		global $coauthors_plus;

		$guest_author_posts_sql = "SELECT tr.* FROM $wpdb->term_relationships tr 
			INNER JOIN $wpdb->posts p ON tr.object_id = p.ID
			WHERE tr.term_taxonomy_id IN (
	            SELECT wtt.term_taxonomy_id FROM $wpdb->term_taxonomy wtt
	            INNER JOIN $wpdb->term_relationships wtr ON wtt.term_taxonomy_id = wtr.term_taxonomy_id
	            WHERE wtr.object_id = (
	                SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'cap-display_name' AND meta_value = '$old_guest_author'
	            )
			) AND p.post_type = 'post'";
		$results = $wpdb->get_results( $wpdb->prepare( $guest_author_posts_sql ) );

		if ( empty( $results ) ) {
			WP_CLI::success('Target guest author not found.');
			WP_CLI::halt(1);
		}

		WP_CLI::line("Found result for '$old_guest_author'");

		$guest_authors = [];
		foreach ( $new_guest_author_names as $guest_author_name ) {
			$sanitized_guest_author_name = sanitize_title( $guest_author_name );

			$guest_author = $this->coauthorsplus_logic->get_guest_author_by_user_login( $sanitized_guest_author_name );

			if ( $guest_author ) {
				$author_id = $guest_author->ID;
			} else {
				$user = get_user_by( 'login', $sanitized_guest_author_name );

				if ( false === $user ) {
					$author_id = $this->coauthorsplus_logic->create_guest_author(
						[
							'user_login'   => $sanitized_guest_author_name,
							'display_name' => sanitize_text_field( $guest_author_name ),
						]
					);
				} else {
					$author_id = $user->ID;
				}
			}

			$guest_author = $this->coauthorsplus_logic->coauthors_guest_authors->get_guest_author_by( 'id', $author_id );
			$guest_authors[] = $guest_author->user_nicename;
		}

		foreach ( $results as $relationship ) {
			WP_CLI::line("Adding Guest Authors to Post ID: $relationship->object_id ");
			$coauthors_plus->add_coauthors( $relationship->object_id, $guest_authors, true );

			$wpdb->delete(
				$wpdb->term_relationships,
				[
					'term_taxonomy_id' => $relationship->term_taxonomy_id,
					'object_id' => $relationship->object_id,
				]
			);
		}

		foreach ( $guest_authors as $guest_author ) {
			$guest_author_term_taxonomy = $wpdb->get_row( "SELECT * FROM $wpdb->term_taxonomy 
				WHERE taxonomy = 'author' AND description LIKE '%$guest_author%'" );

			$wpdb->query("UPDATE $wpdb->term_taxonomy SET count = (
    			SELECT COUNT(tr.object_id) FROM $wpdb->term_relationships AS tr
    			INNER JOIN $wpdb->posts AS p ON tr.object_id = p.ID
    			WHERE tr.term_taxonomy_id = $guest_author_term_taxonomy->term_taxonomy_id
    			AND p.post_type = 'post'
			) WHERE term_taxonomy_id = $guest_author_term_taxonomy->term_taxonomy_id");
		}

		WP_CLI::success('Done');
	}

    /**
     * https://gist.github.com/sepehr/3371339
     *
     * @param int $length
     * @return string
     */
	private function readable_random_string( int $length = 8 ) {
        $string = '';
        $vowels = array(
            'a',
            'e',
            'i',
            'o',
            'u',
        );
        $consonants = array(
            'b',
            'c',
            'd',
            'f',
            'g',
            'h',
            'j',
            'k',
            'l',
            'm',
            'n',
            'p',
            'r',
            's',
            't',
            'v',
            'w',
            'x',
            'y',
            'z',
        );

        $max = $length / 2;
        for ( $i = 1; $i <= $max; $i++ ) {
            $string .= $consonants[rand(0,19)];
            $string .= $vowels[rand(0,4)];
        }

        return $string;
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
