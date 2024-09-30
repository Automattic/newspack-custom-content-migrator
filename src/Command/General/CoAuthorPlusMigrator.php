<?php

namespace NewspackCustomContentMigrator\Command\General;

use Newspack\MigrationTools\Command\WpCliCommandTrait;
use Newspack\MigrationTools\Logic\CoAuthorsPlusHelper;
use NewspackCustomContentMigrator\Command\RegisterCommandInterface;
use \NewspackCustomContentMigrator\Logic\Posts;
use \NewspackCustomContentMigrator\PluginSetup;
use \WP_CLI;
use \WP_Query;
use WP_User_Query;

/**
 * Class for migrating Co-Authors Plus Guest Authors.
 */
class CoAuthorPlusMigrator implements RegisterCommandInterface {

	use WpCliCommandTrait;

	/**
	 * Prefix of tags which get converted to Guest Authors.
	 *
	 * @var string Prefix of a tag which contains a Guest Author's name.
	 */
	const TAG_AUTHOR_PREFIX = 'author:';

	/**
	 * Co-Authors Plus.
	 *
	 * @var CoAuthorsPlusHelper $coauthorsplus_logic Co-Authors Plus logic.
	 */
	private $coauthorsplus_logic;

	/**
	 * Posts logic.
	 *
	 * @var Posts $posts_logic Posts logic.
	 */
	private $posts_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthorsplus_logic = new CoAuthorsPlusHelper();
		$this->posts_logic         = new Posts();
	}

	/**
	 * {@inheritDoc}
	 */
	public static function register_commands(): void {
		WP_CLI::add_command(
			'newspack-content-migrator co-authors-tags-with-prefix-to-guest-authors',
			self::get_command_closure( 'cmd_tags_with_prefix_to_guest_authors' ),
			[
				'shortdesc' => sprintf( 'Converts tags with a specific prefix to Guest Authors, in the following way -- runs through all public Posts, and converts tags beginning with %s prefix (this prefix is currently hardcoded) to Co-Authors Plus Guest Authors, and also assigns them to the post as (co-)authors. It completely overwrites the existing list of authors for these Posts.', self::TAG_AUTHOR_PREFIX ),
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'unset-author-tags',
						'description' => 'If used, will unset these author tags from the posts.',
						'optional'    => true,
						'repeating'   => false,
					),
				),
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator co-authors-tags-with-taxonomy-to-guest-authors',
			self::get_command_closure( 'cmd_tags_with_taxonomy_to_guest_authors' ),
			[
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
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator co-authors-cpt-to-guest-authors',
			self::get_command_closure( 'cmd_cpt_to_guest_authors' ),
			[
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
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator co-authors-link-guest-author-to-existing-user',
			self::get_command_closure( 'cmd_link_ga_to_user' ),
			[
				'shortdesc' => 'Links a Guest Author to an existing WP User.',
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
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator co-authors-create-guest-author-and-add-to-post',
			self::get_command_closure( 'cmd_cap_create_guest_author_and_add_to_post' ),
			[
				'shortdesc' => 'Create a Co-Authors Plus Guest Author and add it to a post.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'post-id',
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
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator co-authors-fix-non-unique-guest-slugs',
			self::get_command_closure( 'cmd_cap_fix_non_unique_guest_slugs' ),
			[
				'shortdesc' => "Make unique any Guest Author Slug which matches a User's slug.",
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator co-authors-split-to-multiple-coauthors',
			self::get_command_closure( 'cmd_split_guest_author_to_multiple_authors' ),
			[
				'shortdesc' => 'Reassigns all posts currently associated to a specific Guest Author to different multiple (or single) Guest Author(s). Example use: `wp newspack-content-migrator co-authors-split-to-multiple-coauthors "Chris Christofersson of NewsSource, with Adam Black of 100 Days in Appalachia" --new-guest-author-names="Chris Christofersson; Adam Black of 100 Days in Appalachia" --delimiter="; "',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'guest-author',
						'description' => 'Target Guest Author (Guest Author Display Name e.g. Edward Carrasco with Newspack, and Ingrid Miller with HR)',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'new-guest-author-names',
						'description' => "The new guest author's to create (e.g. Edward Carrasco,Ingrid Miller [delimited by comma]). The original GA will be disassociated.",
						'optional'    => false,
						'repeating'   => true,
					],
					[
						'type'        => 'assoc',
						'name'        => 'delimiter',
						'description' => 'Delimiter to use to split new-guest-authors values.',
						'optional'    => true,
						'repeating'   => false,
						'default'     => ',',
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator co-authors-fix-gas-post-counts',
			self::get_command_closure( 'cmd_fix_gas_post_counts' ),
			[
				'shortdesc' => 'Fixes/updates CAP post counts. However, the GA list in Dashboard only shows counts for Posts. A GA could own Pages too, and counts for pages will not be displayed there. This script is technically correct, it will update the counts to the correct number, but CAP Dashboard will still show counts just for Posts.',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator co-authors-delete-all-co-authors',
			self::get_command_closure( 'cmd_delete_all_co_authors' ),
			[
				'shortdesc' => 'Delete all Guest Authors on the site.',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Do a dry run without deleting any Guest Authors.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator co-authors-delete-authors-with-zero-posts',
			self::get_command_closure( 'cmd_delete_authors_with_zero_posts' ),
			[
				'shortdesc' => 'Delete all Guest Authors having 0 posts.',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Do a dry run without deleting any Guest Authors.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			],
		);
		WP_CLI::add_command(
			'newspack-content-migrator co-authors-meta-to-guest-authors',
			self::get_command_closure( 'cmd_meta_to_guest_authors' ),
			[
				'shortdesc' => "Converts meta value with specified key to Guest Authors, in the following way -- Checks each post with that meta key and determines whether the meta value is different than the current author. If so, it creates or finds the guest author and assigns it to the post. It completely overwrites the existing list of authors for these Posts.",
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'meta_key',
						'description' => 'The meta key to use for guest author assignment.',
						'optional'    => false,
						'repeating'   => false,
					),
				),
			]
		);
    WP_CLI::add_command(
			'newspack-content-migrator co-authors-export-posts-and-gas',
			self::get_command_closure( 'cmd_export_posts_gas' ),
			[
				'shortdesc' => 'Export all posts and their associated Guest Authors to a .php file. The command exports just the GAs names associated to post IDs, not WP Users -- if a post has a WP User author but no GAs, that ID will have a null value.',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'post-ids-csv',
						'description' => 'Export Guest Author names for these Post IDs only.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			],
		);
		WP_CLI::add_command(
			'newspack-content-migrator co-authors-set-ga-as-author-of-all-posts-in-category',
			self::get_command_closure( 'cmd_set_ga_as_author_of_all_posts_in_category' ),
			[
				'shortdesc' => 'Sets a GA as author for all posts in category. Does not append GA, sets as only author.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'category-id',
						'description' => 'Category ID.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'ga-id',
						'description' => 'GA ID.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			],
		);
		WP_CLI::add_command(
			'newspack-content-migrator co-authors-convert-wpuser-to-guestauthor',
			self::get_command_closure( 'cmd_convert_wpuser_to_ga' ),
			[
				'shortdesc' => "Converts a WP_User to GA. If --ga-id is provided, the command will transfer WP_User's posts to that GA, otherwise it will create a new GA.",
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'wpuser-id',
						'description' => 'WP User ID.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'ga-id',
						'description' => 'Optional GA ID -- if provided will use this GA ID, otherwise will create a new one.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'default-post-author-wpuser-id',
						'description' => "This command will try and use adminnewspack, but if adminnewspack is not found, it will be required to give any default WP_User ID to become the new 'placeholder' wp_posts.post_author once GA is assigned. The inner workings of CAP require an actual existing WP_User to be used as post's wp_post.post_author (even though that doesn't matter any longer once a GA is assigned because CAP's taxonomy takes over and wp_post.post_author is no longer used to represent post's author).",
						'optional'    => true,
						'repeating'   => false,
					],
				],
			],
		);
	}

	/**
	 * Callable for `newspack-content-migrator co-authors-set-ga-as-author-of-all-posts-in-category`.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_convert_wpuser_to_ga( array $pos_args, array $assoc_args ) {
		global $wpdb;

		$wpuser_id = $assoc_args['wpuser-id'];
		$ga_id     = isset( $assoc_args['ga-id'] ) ? $assoc_args['ga-id'] : null;

		// Get the WP_User.
		$wpuser = get_user_by( 'ID', $wpuser_id );
		if ( ! $wpuser ) {
			WP_CLI::error( sprintf( 'WP User ID %d not found.', $wpuser_id ) );
		}

		// Get a default WP_User ID to replace this WP_User as current wp_posts.post_author. Try and use adminnewspack as it won't matter who wp_posts.post_author is once GA is assigned, because at that point CAP's term relationships take over as determinants of post autorship and post_author stops representing the author. If adminnewspack is not found, exit and require to provide an ID.
		$adminnewspack_user = get_user_by( 'login', 'adminnewspack' );
		$default_wpuser_id  = $adminnewspack_user ? $adminnewspack_user->ID : null;
		if ( ! $default_wpuser_id ) {
			WP_CLI::error( sprint( 'adminnewspack WP_User not found. Please provide a default WP_User ID to use as placeholder for wp_posts.post_author by using the --default-post-author-wpuser-id argument.' ) );
		}

		// Check if WP_User is linked to a GA and don't proceed if it is. Must be unlinked first.
		$existing_ga_with_linked_wpuser = $this->coauthorsplus_logic->get_guest_author_by_linked_wpusers_user_login( $wpuser->user_login );
		if ( $existing_ga_with_linked_wpuser ) {
			WP_CLI::error( sprintf( "WP_user ID %d (user_login '%s') is presently linked/mapped to GA %d (user_login '%s'). Please unlink WP_User from GA before proceeding. WARNING -- at the time of writing this (CAP version 3.5.10) there is a bug in CAP when simply by unlinking a WP_User from GA unsets the GA as post author from some (not all) of its posts. This command can't take responsibility for that, which is why you should unlink the WP_User before proceeding.", $wpuser->ID, $wpuser->user_login, $existing_ga_with_linked_wpuser->ID, $existing_ga_with_linked_wpuser->user_login ) );
		}

		// If GA ID was given, transfer all posts by WP_User to that one.
		if ( $ga_id ) {

			// Validate GA ID.
			$ga = $this->coauthorsplus_logic->get_guest_author_by_id( $ga_id );
			if ( ! $ga ) {
				WP_CLI::error( sprintf( 'Guest Author ID %d not found.', $ga_id ) );
			}
		} else {
			// Create new GA.
			// But first validate whether a new GA can be created -- check if one already exists with same email or user_login.

			// Check 1. -- GA with same email.
			$ga = $this->coauthorsplus_logic->get_guest_author_by_email( $wpuser->user_email );
			if ( $ga ) {
				WP_CLI::warning( sprintf( "Guest Author ID %d with same email '%s' already exists. Your options are:\n  1.) transfer all content to this GA,\n  2.) stop and rerun this command with --ga-id param to transfer all content to a different existing GA,\n  3.) delete this GA and rerun so that a new GA can be created by the command.", $ga->ID, $wpuser->user_email ) );
				WP_CLI::confirm( sprintf( 'Use existing GA ID %d and transfer all content by WP_User ID %d to this GA?', $ga->ID, $wpuser_id ) );

			}

			/**
			 * Check 2. -- GA with same user_login -- if one exists, it will prevent creation of a new GA.
			 *
			 * @see \CoAuthors_Guest_Authors::create, says "The user login field shouldn't collide with any existing users".
			 */
			if ( ! $ga ) {
				$ga = $this->coauthorsplus_logic->get_guest_author_by_user_login( $wpuser->user_login );
				if ( $ga ) {
					WP_CLI::warning( sprintf( "Guest Author ID %d with same user_login '%s' already exists. Your options are:\n  1.) transfer all content to this GA,\n  2.) stop and rerun this command with --ga-id param to transfer all content to a different existing GA,\n  3.) delete this GA and rerun so that a new GA can be created by the command.", $ga->ID, $wpuser->user_login ) );
					WP_CLI::confirm( sprintf( 'Use existing GA ID %d and transfer all content by WP_User ID %d to this GA?', $ga->ID, $wpuser_id ) );
				}
			}

			// Create GA.
			if ( ! $ga ) {

				// Create GA from WP_User -- note: using this function will automatically link WP_User to the new GA, so we'll have to unlink it.
				$ga_id = $this->coauthorsplus_logic->create_guest_author_from_wp_user( $wpuser->ID );
				if ( ! $ga_id || is_wp_error( $ga_id ) ) {
					WP_CLI::error( sprintf( 'Error when attempting to create create Guest Author from WP User.%s', is_wp_error( $ga_id ) ? "\nError message: " . $ga_id->get_error_message() : '' ) );
				}
				WP_CLI::success( sprintf( 'Created GA ID %d', $ga_id ) );

				// Get created GA object.
				$ga = $this->coauthorsplus_logic->get_guest_author_by_id( $ga_id );

				// Unlink WP_User from the GA.
				$existing_ga_with_linked_wpuser = $this->coauthorsplus_logic->get_guest_author_by_linked_wpusers_user_login( $wpuser->user_login );
				if ( $existing_ga_with_linked_wpuser ) {
					$this->coauthorsplus_logic->unlink_wp_user_from_guest_author( $ga_id, $wpuser );
				}
			}
		}

		// Get posts by WP_User.
		$post_ids = $this->coauthorsplus_logic->get_all_posts_by_wp_user( $wpuser_id, 'post' );

		// This here is a key step!
		// We must change this WP_User's login before proceeding, because CAP will still be linking the GA internally if they share the same username, even though they're not "linked" via Edit GA interface.
		$new_userlogin = $wpuser->user_login . '__reassigned';
		wp_update_user(
			[
				'ID'         => $wpuser_id,
				'user_login' => $new_userlogin,
			]
		);
		WP_CLI::success( sprintf( "WP_User's user_login updated to '%s'.", $new_userlogin ) );

		// Reassign posts from $wpuser to $ga.
		$reassigned_post_ids = [];
		foreach ( $post_ids as $key_post_id => $post_id ) {
			WP_CLI::line( sprintf( '(%d)/(%d) post ID %d', $key_post_id + 1, count( $post_ids ), $post_id ) );

			// Get all authors for post. Our WP_User may be a CAP co-author, or a classic WP single WP_User post author.
			$post_authors = $this->coauthorsplus_logic->get_all_authors_for_post( $post_id );

			// Update WP_User author to GA.
			$post_authors_updated = $post_authors;
			foreach ( $post_authors_updated as $key_author => $author ) {
				if ( is_object( $author ) && 'WP_User' === $author::class && $author->ID == $wpuser_id ) {
					$post_authors_updated[ $key_author ] = $ga;
				}
			}

			// If $wpuser_id is set as wp_posts.post_author, change post_author value.
			$post_author = $wpdb->get_var( $wpdb->prepare( "SELECT post_author FROM $wpdb->posts WHERE ID = %d", $post_id ) );
			if ( $wpuser_id == $post_author ) {
				$wpdb->update( $wpdb->posts, [ 'post_author' => $default_wpuser_id ], [ 'ID' => $post_id ] );
			}

			// Update authors.
			$this->coauthorsplus_logic->assign_authors_to_post( $post_authors_updated, $post_id );

			// Log.
			$reassigned_post_ids[] = $post_id;

			clean_post_cache( $post_id );
			WP_CLI::success( 'Post author updated.' );
		}

		// Log and finish.
		$log_file = sprintf( 'wpuser_%d_to_ga_%d.log', $wpuser_id, $ga_id );
		if ( ! empty( $reassigned_post_ids ) ) {
			file_put_contents( $log_file, implode( "\n", $reassigned_post_ids ) );
		}

		WP_CLI::success(
			sprintf(
				'Done 👍 Reassigned %d posts to GA ID %d'
				. "\n --> feel free to delete the WP_User -- $ wp user delete %d"
				. '%s',
				count( $reassigned_post_ids ),
				$ga_id,
				$wpuser_id,
				(
					empty( $reassigned_post_ids )
					? ''
					: "\n --> See '{$log_file}' list of updated post IDs."
				)
			)
		);

		wp_cache_flush();
	}

	/**
	 * Callable for `newspack-content-migrator co-authors-set-ga-as-author-of-all-posts-in-category`.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_set_ga_as_author_of_all_posts_in_category( array $pos_args, array $assoc_args ) {
		$cat_id = $assoc_args['category-id'];
		$ga_id  = $assoc_args['ga-id'];

		$category = get_category( $cat_id );
		if ( is_wp_error( $category ) || ! $category ) {
			WP_CLI::error( sprintf( 'Category with ID %d not found.', $cat_id ) );
		}
		$ga = $this->coauthorsplus_logic->get_guest_author_by_id( $ga_id );
		if ( false === $ga || ! $ga ) {
			WP_CLI::error( sprintf( 'Guest Author with ID %d not found.', $ga_id ) );
		}

		// Get all Post IDs in category and set GA.
		$post_ids = $this->posts_logic->get_all_posts_ids_in_category( $cat_id, 'post', [ 'publish', 'future', 'draft', 'pending', 'private', 'inherit' ] );
		foreach ( $post_ids as $post_id ) {
			$this->coauthorsplus_logic->assign_guest_authors_to_post( [ $ga_id ], $post_id, false );
			WP_CLI::success( sprintf( 'Updated Post ID %d.', $post_id ) );
		}
	}

	/**
	 * Saves a list of all posts and their GAs to an array file. Does not export WP Users authors.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_export_posts_gas( array $args, array $assoc_args ) {
		$post_ids = isset( $assoc_args['post-ids-csv'] ) ? explode( ',', $assoc_args['post-ids-csv'] ) : null;

		$guest_author_names = [];

		if ( ! $post_ids ) {
			$post_ids = $this->posts_logic->get_all_posts_ids();
		}
		foreach ( $post_ids as $key_post_id => $post_id ) {
			WP_CLI::log( sprintf( '(%d)/(%d) %d', $key_post_id + 1, count( $post_ids ), $post_id ) );
			$guest_authors = $this->coauthorsplus_logic->get_guest_authors_for_post( $post_id );
			foreach ( $guest_authors as $guest_author ) {
				$guest_author_names[ $post_id ][] = $guest_author->display_name;
			}
			if ( empty( $guest_authors ) ) {
				$guest_author_names[ $post_id ] = null;
			}
		}

		$php_file = 'post_ids_ga_display_names.php';
		// phpcs:ignore
		file_put_contents( $php_file, '<?php' . "\n" . 'return ' . var_export( $guest_author_names, true ) . ';' );

		WP_CLI::success( sprintf( 'Done. Post IDs with GA names saved to %s. Just use `$posts_gas = include( %s );`', $php_file, $php_file ) );
	}

	/**
	 * Installs the CAP plugin, and reinitializes the Newspack\MigrationTools\Logic\CoAuthorsPlusHelper dependency.
	 */
	public function require_cap_plugin() {
		if ( false === $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
			WP_CLI::warning( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );

			// Install and activate the CAP plugin.
			PluginSetup::setup_coauthors_plus();

			// reinitialize the CAP dependency.
			$this->coauthorsplus_logic = new CoAuthorsPlusHelper();
		}
	}

	/**
	 * Create a guest author and assign to a post.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_cap_create_guest_author_and_add_to_post( $pos_args, $assoc_args ) {
		$this->require_cap_plugin();

		$post_id     = $assoc_args['post-id'];
		$full_name   = sanitize_text_field( $assoc_args['full_name'] );
		$email       = isset( $assoc_args['email'] ) ? sanitize_email( $assoc_args['email'] ) : null;
		$first_name  = isset( $assoc_args['first_name'] ) ? sanitize_text_field( $assoc_args['first_name'] ) : null;
		$last_name   = isset( $assoc_args['last_name'] ) ? sanitize_text_field( $assoc_args['last_name'] ) : null;
		$description = isset( $assoc_args['description'] ) ? wp_filter_post_kses( $assoc_args['description'] ) : null;
		$user_login  = sanitize_title( $full_name );

		// Get GA creation data array.
		$data = [
			'display_name' => $full_name,
			'user_login'   => $user_login,
		];
		if ( $email ) {
			// When creating a new GA, use lowercase email to avoid duplicates.
			$data['user_email'] = strtolower( $email );
		}
		if ( $first_name ) {
			$data['first_name'] = $first_name;
		}
		if ( $last_name ) {
			$data['last_name'] = $last_name;
		}
		if ( $description ) {
			$data['description'] = $description;
		}

		// To get existing GA, use email first if available.
		$guest_author = null;
		if ( $email ) {
			$guest_author = $this->coauthorsplus_logic->get_guest_author_by_email( $email );

			// Also try lowercase email.
			if ( ! $guest_author ) {
				$guest_author = $this->coauthorsplus_logic->get_guest_author_by_email( strtolower( $email ) );
			}
		}
		// If GA wasn't found by email, try user_login.
		if ( ! $guest_author ) {
			$guest_author = $this->coauthorsplus_logic->get_guest_author_by_user_login( $user_login );
		}
		// Get GA ID.
		if ( $guest_author ) {
			$author_id = $guest_author->ID;
		} else {
			$author_id = $this->coauthorsplus_logic->create_guest_author( $data );
		}

		// Assign GA to post.
		$this->coauthorsplus_logic->assign_guest_authors_to_post( [ $author_id ], $post_id );

		WP_CLI::success( sprintf( 'Done postID %d', $post_id ) );
	}

	/**
	 * Callable for the `newspack-content-migrator co-authors-tags-with-prefix-to-guest-authors` command.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_tags_with_prefix_to_guest_authors( $args, $assoc_args ) {
		$this->require_cap_plugin();

		// Associative parameter.
		$unset_author_tags = isset( $assoc_args['unset-author-tags'] ) ? true : false;

		// Get all published posts.
		$args        = array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			// The WordPress.VIP.PostsPerPage.posts_per_page_posts_per_page coding standard doesn't like '-1' (all posts) for
			// posts_per_page value, so we'll set it to something really high.
			'posts_per_page' => 1000000,
		);
		$the_query   = new WP_Query( $args );
		$total_posts = $the_query->found_posts;

		WP_CLI::line( sprintf( "Converting tags beginning with '%s' to Guest Authors for %d total Posts...", self::TAG_AUTHOR_PREFIX, $total_posts ) );

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
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_tags_with_taxonomy_to_guest_authors( $args, $assoc_args ) {
		$this->require_cap_plugin();

		// Positional parameter.
		if ( ! isset( $args[0] ) || empty( $args[0] ) ) {
			WP_CLI::error( 'Invalid tag taxonomy name.' );
		}
		$tag_taxonomy = $args[0];

		$post_ids    = $this->posts_logic->get_posts_with_tag_with_taxonomy( $tag_taxonomy );
		$total_posts = count( $post_ids );

		WP_CLI::line( sprintf( 'Converting author tags beginning with %s and with taxonomy %s to Guest Authors for %d posts.', self::TAG_AUTHOR_PREFIX, $tag_taxonomy, $total_posts ) );

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
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_cpt_to_guest_authors( $args, $assoc_args ) {
		$this->require_cap_plugin();

		// Get input args.
		$cpt_from = isset( $args[0] ) ? $args[0] : null;
		if ( null === $cpt_from ) {
			WP_CLI::error( 'Missing CPT slug.' );
		}
		$display_name_from = isset( $assoc_args['display_name'] ) ? $assoc_args['display_name'] : null;
		if ( null === $display_name_from ) {
			WP_CLI::error( "Missing Guest Author's display_name." );
		}
		$first_name_from  = isset( $assoc_args['first_name'] ) && ! empty( $assoc_args['first_name'] ) ? $assoc_args['first_name'] : null;
		$last_name_from   = isset( $assoc_args['last_name'] ) && ! empty( $assoc_args['last_name'] ) ? $assoc_args['last_name'] : null;
		$email_from       = isset( $assoc_args['email'] ) && ! empty( $assoc_args['email'] ) ? $assoc_args['email'] : null;
		$website_from     = isset( $assoc_args['website'] ) && ! empty( $assoc_args['website'] ) ? $assoc_args['website'] : null;
		$description_from = isset( $assoc_args['description'] ) && ! empty( $assoc_args['description'] ) ? $assoc_args['description'] : null;
		$dry_run          = isset( $assoc_args['dry-run'] ) ? true : false;

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

				$args['display_name'] = $this->get_cpt_2_ga_cmd_param_value( $cpt, $display_name_from );
				if ( $first_name_from ) {
					$args['first_name'] = $this->get_cpt_2_ga_cmd_param_value( $cpt, $first_name_from );
				}
				if ( $last_name_from ) {
					$args['last_name'] = $this->get_cpt_2_ga_cmd_param_value( $cpt, $last_name_from );
				}
				if ( $email_from ) {
					$args['user_email'] = $this->get_cpt_2_ga_cmd_param_value( $cpt, $email_from );
				}
				if ( $website_from ) {
					$args['website'] = $this->get_cpt_2_ga_cmd_param_value( $cpt, $website_from );
				}
				if ( $description_from ) {
					$args['description'] = $this->get_cpt_2_ga_cmd_param_value( $cpt, $description_from );
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

		WP_CLI::success(
			sprintf(
				'Created %d GAs from total %d CTPs, and had %d errors.',
				count( $guest_author_ids ),
				count( $cpts ),
				count( $errors )
			)
		);
	}

	/**
	 * Callable for the 'co-authors-link-guest-author-to-existing-user' command.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_link_ga_to_user( $args, $assoc_args ) {
		$this->require_cap_plugin();

		$ga_id   = isset( $assoc_args['ga_id'] ) ? (int) $assoc_args['ga_id'] : null;
		$user_id = isset( $assoc_args['user_id'] ) ? (int) $assoc_args['user_id'] : null;

		$guest_author = $this->coauthorsplus_logic->get_guest_author_by_id( $ga_id );
		$user         = get_user_by( 'id', $user_id );
		if ( ! $guest_author ) {
			WP_CLI::error( sprintf( 'Guest Author by ID %d not found.', $ga_id ) );
		}
		if ( ! $user ) {
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

	public function cmd_cap_fix_non_unique_guest_slugs( array $pos_args, array $assoc_args ): void {

		$authors = ( new WP_User_Query(
			array(
				'who'    => 'authors',
				'fields' => array(
					'user_nicename',
				),
			)
		) )->get_results();

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

		$updated_slugs         = array();
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
							'meta_key'   => 'cap-user_login',
							'meta_value' => $guest_author,
						)
					);

					$updated_slugs[] = "Updated: `{$guest_author}` -> `{$new_guest_author_slug}`";

					/*
					 * Break out of do-while and go to next for-each iteration
					 * */
					continue 2;
				}
			} while ( $attempts > 0 );

			/*
			 * 3 Attempts failed to generate unique slug. Will report back to console.
			 * */
			$unable_to_make_unique[] = $guest_author;
		}
		$progress->finish();

		if ( ! empty( $unable_to_make_unique ) ) {
			array_unshift( $unable_to_make_unique, 'Unable to make the following Guest Author Slugs unique...' );
			WP_CLI::error_multi_line( $unable_to_make_unique );
		}

		WP_CLI::success( 'Done!' );
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
		$old_guest_author       = $args[0];
		$delimiter              = $assoc_args['delimiter'];
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
		$results                = $wpdb->get_results( $wpdb->prepare( $guest_author_posts_sql ) );

		if ( empty( $results ) ) {
			WP_CLI::success( 'Target guest author not found.' );
			WP_CLI::halt( 1 );
		}

		WP_CLI::line( "Found result for '$old_guest_author'" );

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

			$guest_author    = $this->coauthorsplus_logic->coauthors_guest_authors->get_guest_author_by( 'id', $author_id );
			$guest_authors[] = $guest_author->user_nicename;
		}

		foreach ( $results as $relationship ) {
			WP_CLI::line( "Adding Guest Authors to Post ID: $relationship->object_id " );
			$coauthors_plus->add_coauthors( $relationship->object_id, $guest_authors, true );

			$wpdb->delete(
				$wpdb->term_relationships,
				[
					'term_taxonomy_id' => $relationship->term_taxonomy_id,
					'object_id'        => $relationship->object_id,
				]
			);
		}

		foreach ( $guest_authors as $guest_author ) {
			$guest_author_term_taxonomy = $wpdb->get_row(
				"SELECT * FROM $wpdb->term_taxonomy 
				WHERE taxonomy = 'author' AND description LIKE '%$guest_author%'"
			);

						$wpdb->query(
							"UPDATE $wpdb->term_taxonomy SET count = (
    			SELECT COUNT(tr.object_id) FROM $wpdb->term_relationships AS tr
    			INNER JOIN $wpdb->posts AS p ON tr.object_id = p.ID
    			WHERE tr.term_taxonomy_id = $guest_author_term_taxonomy->term_taxonomy_id
    			AND p.post_type = 'post'
			) WHERE term_taxonomy_id = $guest_author_term_taxonomy->term_taxonomy_id"
						);
		}

		WP_CLI::success( 'Done' );
	}

	/**
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_fix_gas_post_counts( $args, $assoc_args ) {

		$update_taxonomy = 'author';
		$get_terms_args  = array(
			'taxonomy'   => $update_taxonomy,
			'fields'     => 'ids',
			'hide_empty' => false,
		);

		$update_term_ids = get_terms( $get_terms_args );
		foreach ( $update_term_ids as $key_term_id => $term_id ) {
			WP_CLI::log( sprintf( '(%d)/(%d) updating term_id %d', $key_term_id, count( $update_term_ids ), $term_id ) );
			wp_update_term_count_now( [ $term_id ], $update_taxonomy );
		}
	}

	public function cmd_delete_all_co_authors( array $positional_args, array $assoc_args ): void {
		$dry_run = $assoc_args['dry-run'] ?? false;
		if ( $dry_run ) {
			WP_CLI::log( 'Dry run is enabled, so this command will only show what would be deleted.' );
		}

		global $coauthors_plus;
		global $wpdb;

		// This query was taken directly from \CoAuthors_Guest_Authors::get_guest_author_by.
		$post_ids = $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_type = 'guest-author'" );

		$total_gas = count( $post_ids );
		$counter   = 0;
		WP_CLI::confirm( sprintf( 'About to delete %d guest authors. Is that OK?', $total_gas ) );

		foreach ( $post_ids as $post_id ) {
			$ga = $coauthors_plus->guest_authors->get_guest_author_by( 'ID', $post_id );

			WP_CLI::log( sprintf( '(%d)/(%d) Guest Author #%d %s.', ++ $counter, $total_gas, $ga->ID, $ga->display_name ) );
			if ( ! $dry_run ) {
				$coauthors_plus->guest_authors->delete( $ga->ID );
				WP_CLI::log( sprintf( 'Deleted #%d.', $ga->ID ) );
			}
		}

		wp_cache_flush();
		WP_CLI::success( 'Done' );
	}

	/**
	 * Callable for the `newspack-content-migrator co-authors-delete-authors-with-zero-posts` command.
	 */
	public function cmd_delete_authors_with_zero_posts( $args, $assoc_args ) {
		$dry_run = isset( $assoc_args['dry-run'] );

		// Get all GAs.
		$all_gas = $this->coauthorsplus_logic->get_all_gas();

		// Get GAs with zero posts.
		$gas_to_delete = [];
		foreach ( $all_gas as $ga ) {
			if ( 0 === count( $this->coauthorsplus_logic->get_all_posts_by_guest_author( $ga->ID ) ) ) {
				$gas_to_delete[] = $ga;
			}
		}

		// Delete GAs.
		foreach ( $gas_to_delete as $key_ga_to_delete => $ga_to_delete ) {
			WP_CLI::log( sprintf( '(%d)/(%d) Deleting Guest Author #%d %s.', $key_ga_to_delete, count( $gas_to_delete ), $ga_to_delete->ID, $ga_to_delete->display_name ) );

			if ( ! $dry_run ) {
				$this->coauthorsplus_logic->delete_ga( $ga_to_delete->ID );
			}
		}

		wp_cache_flush();
		WP_CLI::success( 'Done.' );
	}

	/**
	 * Callable for the 'co-authors-meta-to-guest-authors' command.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_meta_to_guest_authors( $args, $assoc_args ) {
		$this->require_cap_plugin();

		$meta_key = $assoc_args['meta_key'];
		
		$posts = get_posts( [
			'posts_per_page' => -1,
			'meta_key'       => $meta_key,
			'meta_value'     => '',
			'meta_compare'   => '!='
		] );

		if ( empty( $posts ) ) {
			WP_CLI::error( sprintf( 'No posts found with meta_key "%s%', $meta_key ) );
		}

		foreach ( $posts as $post ) {
			$coauthors      = get_coauthors( $post->ID );
			$current_author = get_the_author_meta( 'display_name', $post->post_author );
			$byline_author  = get_post_meta( $post->ID, $meta_key, true );

			if ( empty( $byline_author ) ) {
				WP_CLI::warning( sprintf(
					'No byline author found in meta for post: %s (%d). Skipping.',
					$post->post_title,
					$post->ID
				) );
				continue;
			}

			if ( strtolower( $current_author ) === strtolower( $byline_author ) ) {
				WP_CLI::warning( sprintf(
					'Author %s is already assigned to post: %s (%d)',
					$byline_author,
					$post->post_title,
					$post->ID
				) );
				continue;
			}

			// Check if any guest authors are already the byline author.
			$author_already_set = false;
			foreach ( $coauthors as $coauthor ) {
				if ( strtolower( $byline_author ) === strtolower( $coauthor->display_name ) ) {
					WP_CLI::warning( sprintf(
						'Author %s is already assigned to post: %s (%d)',
						$byline_author,
						$post->post_title,
						$post->ID
					) );
					$author_already_set = true;
					break;
				}
			}
			if ( $author_already_set ) {
				continue;
			}

			WP_CLI::warning( sprintf(
				'Assigning %s for post %s (%d) instead of %s',
				$byline_author,
				$post->post_title,
				$post->ID,
				$current_author
			) );

			$guest_author = false;

			// Get co-author author if exists.
			$possible_guest_authors = $this->coauthorsplus_logic->coauthors_plus->search_authors( $byline_author );
			foreach ( $possible_guest_authors as $possible_guest_author ) {
				if ( strtolower( $possible_guest_author->display_name ) === strtolower( $byline_author ) ) {
					WP_CLI::warning( sprintf(
						'Found existing guest author %s',
						$possible_guest_author->display_name
					) );
					$guest_author = $possible_guest_author;
					break;
				}
			}

			// Check by username.
			if ( ! $guest_author ) {
				$author_username = sanitize_title( $byline_author );
				$potential_author = $this->coauthorsplus_logic->coauthors_guest_authors->get_guest_author_by( 'user_login', $author_username, true );
				if ( $potential_author ) {
					$guest_author = $potential_author;
				}
			}

			if ( ! $guest_author ) {
				WP_CLI::warning( 'Creating guest author: ' . $byline_author );
				$author_data = [ 
					'display_name' => $byline_author, 
					'user_login'   => sanitize_title( $byline_author ) 
				];
				$author_id    = $this->coauthorsplus_logic->create_guest_author( $author_data );
				if ( ! $author_id ) {
					WP_CLI::error( sprintf(
						'Failed to create guest author: %s',
						$byline_author
					) );
				}
				$guest_author = $this->coauthorsplus_logic->get_guest_author_by_id(  $author_id );
			}

			$this->coauthorsplus_logic->coauthors_plus->add_coauthors( $post->ID, [ $guest_author->user_nicename ] );
			WP_CLI::warning( sprintf(
				'Added guest author to post %d',
				$post->ID
			) );
		}
		
		WP_CLI::success( 'Done!' );
	}

	/**
	 * Taken from https://gist.github.com/sepehr/3371339.
	 *
	 * @param int $length Length of string.
	 * @return string
	 */
	private function readable_random_string( int $length = 8 ) {
		$string     = '';
		$vowels     = array(
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
			$string .= $consonants[ rand( 0, 19 ) ];
			$string .= $vowels[ rand( 0, 4 ) ];
		}

		return $string;
	}

	/**
	 * Takes an array of tags, and returns those which begin with the self::TAG_AUTHOR_PREFIX prefix, stripping the result
	 * of this prefix before returning.
	 *
	 * Example, if this tag is present in the input $tags array:
	 *      '{self::TAG_AUTHOR_PREFIX}Some Name' is present in the $tagas array
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
			if ( substr( $tag->name, 0, strlen( self::TAG_AUTHOR_PREFIX ) ) == self::TAG_AUTHOR_PREFIX ) {
				$author_tags[] = [
					'tag'         => $tag,
					'author_name' => substr( $tag->name, strlen( self::TAG_AUTHOR_PREFIX ) ),
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
	 * @param string $post_type Post type.
	 *
	 * @return array WP_Post
	 */
	private function get_posts( $post_type ) {

		$posts = get_posts(
			[
				'post_type'      => $post_type,
				'posts_per_page' => -1,
				'post_status'    => 'any',
			]
		);

		if ( empty( $posts ) ) {
			WP_CLI::error( sprintf( "No '%s' post types found!", $post_type ) );
		}

		return $posts;

	}

}
