<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Utils\Logger;
use \NewspackCustomContentMigrator\Logic\Taxonomy as TaxonomyLogic;
use \NewspackCustomContentMigrator\Logic\Posts as PostLogic;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus as CoAuthorPlusLogic;
use \WP_CLI;

/**
 * Custom migration scripts for VTDigger.
 */
class VTDiggerMigrator implements InterfaceCommand {

	// VTD CPTs.
	const OBITUARY_CPT = 'obituary';
	const LETTERS_TO_EDITOR_CPT = 'letters_to_editor';
	const LIVEBLOG_CPT = 'liveblog';
	const OLYMPICS_BLOG_CPT = 'olympics';
	const NEWSBRIEF_CPT = 'news-brief';
	const ELECTION_CPT = 'election_brief';
	const CARTOONS_CPT = 'cartoons';
	const BUSINESSBRIEFS_CPT = 'business_briefs';

	// GAs for CPTs.
	const OBITUARIES_GA_NAME = 'VTD Obituaries';
	const LETTERS_TO_EDITOR_GA_NAME = 'Opinion';
	const NEWS_BRIEFS_GA_NAME = 'VTD staff';
	const LIVEBLOG_GA_NAME = 'Liveblogs';
	const ELECTION_GA_NAME = 'Election Briefs';
	const OLYMPICS_GA_NAME = 'Olympics Blog';

	// VTD Taxonomies.
	const COUNTIES_TAXONOMY = 'counties';
	const SERIES_TAXONOMY = 'series';

	// WP tag names.
	const ALL_LIVEBLOGS_TAG_NAME = 'news in brief';
	const LETTERSTOTHEEDITOR_TAG_NAME = 'letters to the editor';
	const SERIES_TAG_NAME = 'series';

	// WP Category names.
	const LIVEBLOGS_CAT_NAME = 'Liveblogs';
	const OLYMPICS_BLOG_CAT_NAME = 'Olympics Blog';
	const OBITUARIES_CAT_NAME = 'Obituaries';
	const ELECTION_BLOG_CAT_NAME = 'Election Blog';
	const CARTOONS_CAT_NAME = 'Cartoons';
	const BUSINESSBRIEFS_CAT_NAME = 'Business Briefs';

	// This postmeta will tell us which CPT this post was originally, e.g. 'liveblog'.
	const META_VTD_CPT = 'newspack_vtd_cpt';

	// This postmeta will tell us if authors have already been migrated for this post.
	const META_AUTHORS_MIGRATED = 'newspack_vtd_authors_migrated';

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * @var Logger
	 */
	private $logger;

	/**
	 * @var TaxonomyLogic
	 */
	private $taxonomy_logic;

	/**
	 * @var PostLogic.
	 */
	private $posts_logic;

	/**
	 * @var CoAuthorPlusLogic.
	 */
	private $cap_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->logger = new Logger();
		$this->taxonomy_logic = new TaxonomyLogic();
		$this->posts_logic = new PostLogic();
		$this->cap_logic = new CoAuthorPlusLogic();
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
			'newspack-content-migrator vtdigger-migrate-liveblogs',
			[ $this, 'cmd_liveblogs' ],
			[
				'shortdesc' => 'Migrates the Liveblog CPT to regular posts with category.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-migrate-letterstotheeditor',
			[ $this, 'cmd_letterstotheeditor' ],
			[
				'shortdesc' => 'Migrates the Letters to the Editor CPT to regular posts with category.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-migrate-obituaries',
			[ $this, 'cmd_obituaries' ],
			[
				'shortdesc' => 'Migrates the Obituaries CPT to regular posts with category.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-migrate-counties',
			[ $this, 'cmd_counties' ],
			[
				'shortdesc' => 'Migrates Counties taxonomy to Categories.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-migrate-series',
			[ $this, 'cmd_series' ],
			[
				'shortdesc' => 'Migrates Series taxonomy to Categories.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-migrate-authors',
			[ $this, 'cmd_authors' ],
			[
				'shortdesc' => 'Migrates ACF Authors to GAs.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-helper-remove-subcategories',
			[ $this, 'cmd_helper_remove_subcategories' ],
			[
				'shortdesc' => 'Removes subcategories of given parent category if post count is 0.',
				'synopsis'  => [
					[
						'type'      => 'assoc',
						'name'      => 'parent-cat-id',
						'optional'  => false,
						'repeating' => false,
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-helper-get-nonobituaries-post-ids',
			[ $this, 'cmd_helper_get_nonobituaries_post_ids' ],
			[
				'shortdesc' => 'Gets post IDs of all posts that were not obituaries.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-import-posts-gas',
			[ $this, 'cmd_import_posts_gas' ],
			[
				'shortdesc' => "Imports all posts' associated Guest Authors from the file generated by `newspack-content-migrator co-authors-export-posts-and-gas`.",
				'synopsis'  => [
					[
						'type'      => 'assoc',
						'name'      => 'php-file',
						'optional'  => false,
						'repeating' => false,
					],
					[
						'type'      => 'assoc',
						'name'      => 'imported-post-ids-file',
						'optional'  => false,
						'repeating' => false,
					],
					[
						'type'      => 'assoc',
						'name'      => 'dry-run',
						'optional'  => true,
						'repeating' => false,
					],
				],
			],
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-cartoons-cpt',
			[ $this, 'cmd_cartoons' ],
			[
				'shortdesc' => 'Convert Cartoons CTP to posts.',
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-businessbriefs-cpt',
			[ $this, 'cmd_businessbriefs' ],
			[
				'shortdesc' => 'Convert Business Briefs CTP to posts.',
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-restore-reusable-blocks-in-local-posts-from-live-table',
			[ $this, 'cmd_restore_reusable_blocks_in_local_posts_from_live_table' ],
			[
				'shortdesc' => "In order to restore usage of reusable blocks (which have been removed from local posts' post_content), runs through all local published posts, finds these records in live posts table (table name hardcoded) and sets local posts' post_content to those in live table.",
			],
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-delete-pressrelease-content',
			[ $this, 'cmd_delete_pressrelease_content' ],
		);
	}

	public function cmd_delete_pressrelease_content( array $pos_args, array $assoc_args ) {
		$author = 'Press Release';
		$ga_existing = $this->cap_logic->get_guest_author_by_display_name( $author );
		if ( ! $ga_existing ) {
			WP_CLI::error( "Guest Author $author does not exist." );
		}
		$wpuser_existing = $this->get_wpuser_by_display_name( $author );
		if ( ! $wpuser_existing ) {
			WP_CLI::error( "WP User $author does not exist." );
		}

		$post_ids_with_multiple_coauthors = [];
		$post_ids = $this->posts_logic->get_all_posts_ids();
		foreach ( $post_ids as $key_post_id => $post_id ) {
			WP_CLI::line( sprintf( "(%d)/(%d) %d", $key_post_id + 1, count( $post_ids ), $post_id ) );

			$authors = $this->cap_logic->get_all_authors_for_post( $post_id );

			$matched = false;
			foreach ( $authors as $author ) {
				if ( 'stdClass' === $author::class && $ga_existing->ID == $author->ID ) {
					$matched = true;
				} elseif ( 'WP_User' === $author::class && $wpuser_existing->ID == $author->ID ) {
					$matched = true;
				}
			}

			if ( false === $matched ) {
				continue;
			}

			if ( true === $matched && count( $authors ) > 1 ) {
				$post_ids_with_multiple_coauthors[] = $post_id;
				WP_CLI::warning( sprintf( 'Multiple coauthors' ) );
				continue;
			}

			$deleted = wp_delete_post( $post_id, true );
			if ( false === $deleted || is_null( $deleted ) || empty( $deleted ) ) {
				// log err deleting post
				$this->logger->log( 'vtdigger-delete-pressrelease-content__errDeletingPost.log', sprintf( "Error deleting postID %d", $post_id ), $this->logger::WARNING );
				continue;
			}

			// log deleted post
			$this->logger->log( 'vtdigger-delete-pressrelease-content__deletedPost.log', sprintf( "Deleted postID %d", $post_id ), $this->logger::SUCCESS );
		}

		if ( empty( $post_ids_with_multiple_coauthors ) ) {
			WP_CLI::success( 'No $post_ids_with_multiple_coauthors' );
		} else {
			WP_CLI::warning( 'See $post_ids_with_multiple_coauthors' );
			$this->logger->log( 'vtdigger-delete-pressrelease-content__postsWMultipleAuthors.log', implode( "\n", $post_ids_with_multiple_coauthors ), false );
		}

		$debug = 1;
	}

	private function get_wpuser_by_display_name( $display_name ) {
		global $wpdb;

		$user_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE display_name = %s", $display_name ) );
		$user = get_user_by( 'ID', $user_id );

		return $user;
	}

	/**
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_restore_reusable_blocks_in_local_posts_from_live_table( array $pos_args, array $assoc_args ) {
		$live_posts_table_name = 'livevtdWP_posts';
		$qa_path                  = getcwd() . '/' . 'log_QAUpdatedPostContent';
		if ( ! file_exists( $qa_path ) ) {
			mkdir( $qa_path, 0777, true );
		}

		global $wpdb;

		// Insert Reusable blocks which keep the same IDs.
		$reusable_blocks_ids = [ 331411,333919,287702,292966,294143,294383,301399,306744,307109,310386,310997,313107,313203,315093,315566,316739,316908,319091,319446,319930,319934,320680,320764,322846,325577,326130,326131,326286,326405,326484,327395,328038,328824,328966,329459,329747,330401,331412,333921,335042,336393,339931,340591,340707,340755,341483,344897,344939,345130,349010,349835,350925,352401,352683,355836,356288,362916,370675,371526,372498,375812,375816,375948,376333,376463,382204,386426,390259,401522,408146,408494,410046,410893 ];
		foreach ( $reusable_blocks_ids as $id ) {
			$live_reusable_block = $wpdb->get_row( $wpdb->prepare( "select * from {$live_posts_table_name} where ID = %d", $id ), ARRAY_A );
			if ( ! $live_reusable_block ) {
				WP_CLI::error( "Live reusable block ID {$id} not found." );
			}
			$inserted = $wpdb->insert(
				$wpdb->posts,
				[
					// Keeps the same ID.
					"ID" => $live_reusable_block["ID"],
					// Hardcoded adminnewspack for simplicity.
					"post_author" => 1788,
					"post_date" => $live_reusable_block["post_date"],
					"post_date_gmt" => $live_reusable_block["post_date_gmt"],
					"post_content" => $live_reusable_block["post_content"],
					"post_title" => $live_reusable_block["post_title"],
					"post_excerpt" => $live_reusable_block["post_excerpt"],
					"post_status" => $live_reusable_block["post_status"],
					"comment_status" => $live_reusable_block["comment_status"],
					"ping_status" => $live_reusable_block["ping_status"],
					"post_password" => $live_reusable_block["post_password"],
					"post_name" => $live_reusable_block["post_name"],
					"to_ping" => $live_reusable_block["to_ping"],
					"pinged" => $live_reusable_block["pinged"],
					"post_modified" => $live_reusable_block["post_modified"],
					"post_modified_gmt" => $live_reusable_block["post_modified_gmt"],
					"post_content_filtered" => $live_reusable_block["post_content_filtered"],
					"post_parent" => $live_reusable_block["post_parent"],
					"guid" => $live_reusable_block["guid"],
					"menu_order" => $live_reusable_block["menu_order"],
					"post_type" => $live_reusable_block["post_type"],
					"post_mime_type" => $live_reusable_block["post_mime_type"],
					"comment_count" => $live_reusable_block["comment_count"],
				]
			);
			if ( ! $inserted ) {
				WP_CLI::error( "Failed to insert reusable block ID {$id}." );
			} else {
				WP_CLI::log( "Inserted reusable block with same ID {$id}." );
			}
		}

		// Insert Reusable blocks which will change their IDs.
		$reusable_blocks_ids = [ 412947,412949,412951,413143,413146,413157,413158,413162,413163,414673,418408 ];
		foreach ( $reusable_blocks_ids as $id ) {
			$live_reusable_block = $wpdb->get_row( $wpdb->prepare( "select * from {$live_posts_table_name} where ID = %d", $id ), ARRAY_A );
			if ( ! $live_reusable_block ) {
				WP_CLI::error( "Live reusable block ID {$id} not found." );
			}
			$inserted = $wpdb->insert(
				$wpdb->posts,
				[
					// Hardcoded adminnewspack for simplicity.
					"post_author" => 1788,
					"post_date" => $live_reusable_block["post_date"],
					"post_date_gmt" => $live_reusable_block["post_date_gmt"],
					"post_content" => $live_reusable_block["post_content"],
					"post_title" => $live_reusable_block["post_title"],
					"post_excerpt" => $live_reusable_block["post_excerpt"],
					"post_status" => $live_reusable_block["post_status"],
					"comment_status" => $live_reusable_block["comment_status"],
					"ping_status" => $live_reusable_block["ping_status"],
					"post_password" => $live_reusable_block["post_password"],
					"post_name" => $live_reusable_block["post_name"],
					"to_ping" => $live_reusable_block["to_ping"],
					"pinged" => $live_reusable_block["pinged"],
					"post_modified" => $live_reusable_block["post_modified"],
					"post_modified_gmt" => $live_reusable_block["post_modified_gmt"],
					"post_content_filtered" => $live_reusable_block["post_content_filtered"],
					"post_parent" => $live_reusable_block["post_parent"],
					"guid" => $live_reusable_block["guid"],
					"menu_order" => $live_reusable_block["menu_order"],
					"post_type" => $live_reusable_block["post_type"],
					"post_mime_type" => $live_reusable_block["post_mime_type"],
					"comment_count" => $live_reusable_block["comment_count"],
				]
			);
			if ( ! $inserted ) {
				WP_CLI::error( "Failed to insert reusable block ID {$id}." );
			} else {
				$this->logger->log( 'vtdigger-restore-reusable-blocks-in-local-posts-from-live-table__newReusableBlockIds.log', "inserted Reusable Block live:{$id} local:{$wpdb->insert_id}" );
			}
		}

		// Update post_content.
		$post_ids = $this->posts_logic->get_all_posts_ids( 'post', [ 'publish', 'future' ] );
		foreach ( $post_ids as $key_post_id => $post_id ) {
			WP_CLI::log( sprintf( "(%d)/(%d) %d", $key_post_id + 1, count( $post_ids ), $post_id ) );
			// Match that post in local and live table.
			$local_post = $wpdb->get_row( $wpdb->prepare( "select * from {$wpdb->posts} where ID = %d", $post_id ), ARRAY_A );
			$live_post  = $wpdb->get_row(
				$wpdb->prepare(
					"select * from {$live_posts_table_name}
	                where post_name = %s
					and post_title = %s
					and post_status = %s
					and post_date = %s
					and post_type <> 'revision' ; ",
					$local_post['post_name'],
					$local_post['post_title'],
					$local_post['post_status'],
					$local_post['post_date']
				),
				ARRAY_A
			);
			if ( ! $live_post ) {
				$this->logger->log( 'vtdigger-restore-reusable-blocks-in-local-posts-from-live-table__postNotFoundInLive.log', "Could not find post in live table: {$post_id}", $this->logger::WARNING );
				continue;
			}

			// Update local post's post_content with live post's post_content.
			if ( ! empty( $live_post['post_content'] ) && ( $live_post['post_content'] !== $local_post['post_content'] ) ) {
				$updated = $wpdb->update(
					$wpdb->posts,
					[ 'post_content' => $live_post['post_content'] ],
					[ 'ID' => $post_id ],
				);
				if ( ( false !== $updated ) && ( $updated > 0 ) ) {
					$this->logger->log( 'vtdigger-restore-reusable-blocks-in-local-posts-from-live-table__updatedPostId.log', "Updated Post ID: {$post_id}" );
					file_put_contents( $qa_path . '/' . $post_id . '_1before.txt', $local_post['post_content'] );
					file_put_contents( $qa_path . '/' . $post_id . '_2after.txt', $live_post['post_content'] );
				}
			}
		}

		wp_cache_flush();
	}

	public function cmd_businessbriefs( array $pos_args, array $assoc_args ) {
		global $wpdb;
		$log = 'vtd_businessbriefs.log';

		$cat_id = get_cat_ID( self::BUSINESSBRIEFS_CAT_NAME );
		if ( 0 == $cat_id ) {
			$cat_id = wp_insert_category( [ 'cat_name' => self::BUSINESSBRIEFS_CAT_NAME ] );
		}

		$businessbriefs_ids = $wpdb->get_col( $wpdb->prepare( "select ID from {$wpdb->posts} where post_type = %s;", self::BUSINESSBRIEFS_CPT ) );

		foreach ( $businessbriefs_ids as $key_businessbriefs_id => $businessbrief_id ) {
			WP_CLI::log( sprintf( "(%d)/(%d) %d", $key_businessbriefs_id + 1, count( $businessbriefs_ids ), $businessbrief_id ) );

			// Convert to 'post' type.
			$wpdb->query( $wpdb->prepare( "update {$wpdb->posts} set post_type='post' where ID=%d;", $businessbrief_id ) );

			update_post_meta( $businessbrief_id, self::META_VTD_CPT, self::BUSINESSBRIEFS_CPT );

			wp_set_post_categories( $businessbrief_id, [ $cat_id ], false );
		}

		$this->logger->log( $log, implode( ',', $businessbriefs_ids ), false );

		wp_cache_flush();
		WP_CLI::log( sprintf( "Done; see %s", $log ) );
	}

	public function cmd_cartoons( array $pos_args, array $assoc_args ) {
		global $wpdb;
		$log = 'vtd_cartoons.log';

		$cat_id = get_cat_ID( self::CARTOONS_CAT_NAME );
		if ( 0 == $cat_id ) {
			$cat_id = wp_insert_category( [ 'cat_name' => self::CARTOONS_CAT_NAME ] );
		}

		$cartoons_ids = $wpdb->get_col( $wpdb->prepare( "select ID from {$wpdb->posts} where post_type = %s;", self::CARTOONS_CPT ) );

		foreach ( $cartoons_ids as $key_cartoon_id => $cartoon_id ) {
			WP_CLI::log( sprintf( "(%d)/(%d) %d", $key_cartoon_id + 1, count( $cartoons_ids ), $cartoon_id ) );

			// Convert to 'post' type.
			$wpdb->query( $wpdb->prepare( "update {$wpdb->posts} set post_type='post' where ID=%d;", $cartoon_id ) );

			update_post_meta( $cartoon_id, self::META_VTD_CPT, self::CARTOONS_CPT );

			wp_set_post_categories( $cartoon_id, [ $cat_id ], false );
		}

		$this->logger->log( $log, implode( ',', $cartoons_ids ), false );

		wp_cache_flush();
		WP_CLI::log( sprintf( "Done; see %s", $log ) );
	}

	/**
	 * Works with:
	 *  - the .php file saved by \NewspackCustomContentMigrator\Command\General\CoAuthorPlusMigrator::cmd_import_posts_gas
	 *  - and the content-diff__imported-post-ids.log file created by Content Diff
	 * Assigns the Guest Authors to the posts and creates a log of GAs it created anew.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_import_posts_gas( array $args, array $assoc_args ) {
		$php_file               = $assoc_args['php-file'];
		$imported_post_ids_file = $assoc_args['imported-post-ids-file'];
		$dry_run                = isset( $assoc_args['dry-run'] ) ? true : false;
		if ( ! file_exists( $php_file ) || ! file_exists( $imported_post_ids_file ) ) {
			WP_CLI::error( 'Wrong files provided.' );
		}

		$log_created_gas = 'log_created_gas.txt';

		// Get mapping old post ID => new post ID.
		$post_ids_old_new_map = [];
		foreach ( explode( "\n", file_get_contents( $imported_post_ids_file ) ) as $line ) {
			$line_decoded = json_decode( $line, true );
			if ( ! $line_decoded ) {
				continue;
			}
			if ( 'post' == $line_decoded['post_type'] ) {
				$post_ids_old_new_map[ $line_decoded['id_old'] ] = $line_decoded['id_new'];
			}
		}

		$posts_gas = include $php_file;
		foreach ( $posts_gas as $post_id => $ga_display_names ) {
			$ga_ids = [];
			foreach ( $ga_display_names as $ga_display_name ) {

				// Get or create GA by display name.
				$guest_author = $this->cap_logic->get_guest_author_by_display_name( $ga_display_name );
				if ( ! $guest_author ) {
					if ( ! $dry_run ) {
						$ga_id = $this->cap_logic->create_guest_author( [ 'display_name' => $ga_display_name ] );
						$this->logger->log( $log_created_gas, sprintf( "Created Guest Author %s ID %s", $ga_display_name, $ga_id ) );
					} else {
						WP_CLI::line( sprintf( "Created Guest Author %s ID %s", $ga_display_name, "n/a" ) );
					}
				} else {
					$ga_id = $guest_author->ID;
				}
				$ga_ids[] = $ga_id;
			}

			// Get new ID and assign.
			if ( ! $dry_run ) {
				$new_post_id = isset( $post_ids_old_new_map[ $post_id ] ) ? $post_ids_old_new_map[ $post_id ] : $post_id;
				$this->cap_logic->assign_guest_authors_to_post( $ga_ids, $new_post_id );
			}
		}

		WP_CLI::success( sprintf( 'Done. See %s', $log_created_gas ) );
	}

	/**
	 * Outputs all Post IDs that were not obituaries.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_helper_get_nonobituaries_post_ids( array $pos_args, array $assoc_args ) {
		global $wpdb;
		$log_csv = 'post_ids_not_obituaries.csv';

		// all posts 71462
		"select count(ID) from vtdWP_posts where post_type = 'post';";
		// obituaries 455
		"select count(distinct post_id) as ID from vtdWP_postmeta where meta_key = 'newspack_vtd_cpt' and meta_value = 'obituary';";

		// -- posts that weren't obituaries 71007
		$ids = $wpdb->get_col(
			"select distinct ID from vtdWP_posts
			where post_type = 'post' and ID not in (
				select distinct post_id as ID from vtdWP_postmeta where meta_key = 'newspack_vtd_cpt' and meta_value = 'obituary'
			);"
		);

		$this->logger->log( $log_csv, implode( ',', $ids ), false );
		WP_CLI::success( sprintf( "Done. See %s", $log_csv ) );
	}

	/**
	 * Takes parent-cat-id from $assoc_args and removes subcategories if post count is 0.
	 * This is a helper command to clean up categories after migration.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_helper_remove_subcategories( array $pos_args, array $assoc_args ) {
		global $wpdb;

		$parent_cat_id = $assoc_args['parent-cat-id'];
		$log = 'vtd_helper_remove_subcategories.log';
		$log_error = 'vtd_helper_remove_subcategories_err.log';

		$children_cat_term_taxonomy_ids = $wpdb->get_col( $wpdb->prepare( "SELECT term_taxonomy_id FROM $wpdb->term_taxonomy WHERE parent = %d", $parent_cat_id ) );
		foreach ( $children_cat_term_taxonomy_ids as $key_children_cat_term_taxonomy_id => $children_cat_term_taxonomy_id ) {
			WP_CLI::log( sprintf( '(%d)/(%d) %d', $key_children_cat_term_taxonomy_id + 1, count( $children_cat_term_taxonomy_ids ), $children_cat_term_taxonomy_id ) );
			$children_cat_post_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->term_relationships WHERE term_taxonomy_id = %d", $children_cat_term_taxonomy_id ) );
			if ( 0 == $children_cat_post_count ) {
				WP_CLI::log( sprintf( 'Removing category %d', $children_cat_term_taxonomy_id ) );
				$deleted = wp_delete_term( $children_cat_term_taxonomy_id, 'category' );
				if ( is_wp_error( $deleted ) || false === $deleted || 0 === $deleted ) {
					WP_CLI::warning( sprintf( 'Error removing category %d: %s', $children_cat_term_taxonomy_id, is_object( $deleted ) ? $deleted->get_error_message() : '' ) );
					$this->logger->log( $log_error, sprintf( 'Error removing category %d: %s', $children_cat_term_taxonomy_id, is_object( $deleted ) ? $deleted->get_error_message() : '' ) );
				}
			} else {
				$this->logger->log( $log, sprintf( 'Category %d has %d posts, not removing.', $children_cat_term_taxonomy_id, $children_cat_post_count ) );
			}
		}
	}

	/**
	 * Callable for `newspack-content-migrator vtdigger-migrate-liveblogs`.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_liveblogs( array $pos_args, array $assoc_args ) {
		global $wpdb;

		/**
		 * Move Liveblogs>Uncategorized to Liveblogs.
		 * Move
		 */

		/**
		 * Newsbriefs.
		 */
		WP_CLI::log( sprintf( 'Migrating %s ...', self::NEWSBRIEF_CPT ) );
		$log = 'vtd_cpt_newsbriefs.log';
		$ga_id = $this->get_or_create_ga_id_by_display_name( self::NEWS_BRIEFS_GA_NAME );
		$post_ids = $wpdb->get_col( $wpdb->prepare( "select ID from {$wpdb->posts} where post_type=%s ;", self::NEWSBRIEF_CPT ) );
		// NewsBriefs posts remain uncategorized.
		$this->migrate_liveblog( self::NEWSBRIEF_CPT, false, $ga_id, $post_ids, self::ALL_LIVEBLOGS_TAG_NAME );
		$this->logger->log( $log, implode( ',', $post_ids ), false );
		WP_CLI::log( sprintf( "Done with %s; see %s", self::NEWSBRIEF_CPT, $log ) );

		/**
		 * Liveblogs.
		 */
		WP_CLI::log( sprintf( 'Migrating %s ...', self::LIVEBLOG_CPT ) );
		$log = 'vtd_cpt_liveblog.log';
		$ga_id = $this->get_or_create_ga_id_by_display_name( self::LIVEBLOG_GA_NAME );
		$parent_cat_id = get_cat_ID( self::LIVEBLOGS_CAT_NAME );
		if ( 0 == $parent_cat_id ) {
			$parent_cat_id = wp_insert_category( [ 'cat_name' => self::LIVEBLOGS_CAT_NAME ] );
		}
		$post_ids = $wpdb->get_col( $wpdb->prepare( "select ID from {$wpdb->posts} where post_type=%s;", self::LIVEBLOG_CPT ) );
		$this->migrate_liveblog( self::LIVEBLOG_CPT, $parent_cat_id, $ga_id, $post_ids, self::ALL_LIVEBLOGS_TAG_NAME );
		$this->logger->log( $log, implode( ',', $post_ids ), false );
		WP_CLI::log( sprintf( "Done with %s; see %s", self::LIVEBLOGS_CAT_NAME, $log ) );

		/**
		 * Olympics Blog.
		 */
		WP_CLI::log( sprintf( 'Migrating %s ...', self::OLYMPICS_BLOG_CPT ) );
		$log = 'vtd_cpt_olympicsblog.log';
		$parent_cat_id = get_cat_ID( self::OLYMPICS_BLOG_CAT_NAME );
		if ( 0 == $parent_cat_id ) {
			$parent_cat_id = wp_insert_category( [ 'cat_name' => self::OLYMPICS_BLOG_CAT_NAME ] );
		}
		$ga_id = $this->get_or_create_ga_id_by_display_name( self::OLYMPICS_GA_NAME );
		$post_ids = $wpdb->get_col( $wpdb->prepare( "select ID from {$wpdb->posts} where post_type=%s;", self::OLYMPICS_BLOG_CPT ) );
		$this->migrate_liveblog( self::OLYMPICS_BLOG_CPT, $parent_cat_id, $ga_id, $post_ids, self::ALL_LIVEBLOGS_TAG_NAME );
		$this->logger->log( $log, implode( ',', $post_ids ), false );
		WP_CLI::log( sprintf( "Done with %s; see %s", self::OLYMPICS_BLOG_CPT, $log ) );

		/**
		 * Election Liveblogs.
		 */
		WP_CLI::log( sprintf( 'Migrating %s ...', self::ELECTION_CPT ) );
		$log = 'vtd_cpt_election.log';
		$parent_cat_id = get_cat_ID( self::ELECTION_BLOG_CAT_NAME );
		if ( 0 == $parent_cat_id ) {
			$parent_cat_id = wp_insert_category( [ 'cat_name' => self::ELECTION_BLOG_CAT_NAME ] );
		}
		$ga_id = $this->get_or_create_ga_id_by_display_name( self::ELECTION_GA_NAME );
		$post_ids = $wpdb->get_col( $wpdb->prepare( "select ID from {$wpdb->posts} where post_type=%s ;", self::ELECTION_CPT ) );
		$this->migrate_liveblog( self::ELECTION_CPT, $parent_cat_id, $ga_id, $post_ids, self::ALL_LIVEBLOGS_TAG_NAME );
		$this->logger->log( $log, implode( ',', $post_ids ), false );
		WP_CLI::log( sprintf( "Done with %s; see %s", self::ELECTION_CPT, $log ) );


		WP_CLI::log( 'Done.' );
		wp_cache_flush();
	}

	/**
	 * Gets or creates a GA by display name.
	 *
	 * @param string $display_name GA display name.
	 *
	 * @throws \RuntimeException If GA could not be created.
	 *
	 * @return int GA ID.
	 */
	private function get_or_create_ga_id_by_display_name( $display_name ) {
		$ga = $this->cap_logic->get_guest_author_by_display_name( $display_name );
		$ga_id = $ga->ID ?? null;
		if ( is_null( $ga_id ) ) {
			$ga_id = $this->cap_logic->create_guest_author( ['display_name' => $display_name] );
		}
		if ( ! $ga_id ) {
			throw new \RuntimeException( sprintf( 'Could not get/create Guest Author %s.', $display_name ) );
		}

		return $ga_id;
	}

	/**
	 * @param string   $liveblog_cpt  post_type of the liveblog.
	 * @param bool|int $parent_cat_id ID of the parent category for all liveblogs, or false if content should become uncategorized.
	 * @param int      $ga_id         GA ID to assign to all posts.
	 * @param array    $post_ids      Post IDs to migrate.
	 * @param string   $tag           Tag to append to all posts.
	 *
	 * @return void
	 */
	public function migrate_liveblog( string $liveblog_cpt, bool|int $parent_cat_id, int $ga_id, array $post_ids, string $tag ) {
		global $wpdb;

		// Convert to 'post' type.
		foreach ( $post_ids as $key_post_id => $post_id ) {
			WP_CLI::log( sprintf( "(%d)/(%d) %d", $key_post_id + 1, count( $post_ids ), $post_id ) );

			// Update to type post.
			$wpdb->query( $wpdb->prepare( "update {$wpdb->posts} set post_type='post' where ID=%d;", $post_id ) );

			// Tag, append.
			wp_set_post_tags( $post_id, $tag, true );

			// GA, not append, just this one GA.
			if ( $ga_id ) {
				$this->cap_logic->assign_guest_authors_to_post( [ $ga_id ], $post_id, false );
			}

			// Update post Categories.
			if ( false == $parent_cat_id ) {
				/**
				 * Uncategorized.
				 */

				wp_set_post_categories( $post_id, [], false );
			} else {
				/**
				 * Migrate categories to this new post category.
				 */

				$category_ids = wp_get_post_categories( $post_id );
				foreach ( $category_ids as $category_id ) {
					$category = get_category( $category_id );

					// Get or recreate this category under $parent_cat_id parent.
					$new_category_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( $category->name, $parent_cat_id );

					// Assign
					wp_set_post_categories( $post_id, [ $new_category_id ], false );
				}
				// Or if no category, set $parent_cat_id.
				if ( empty( $category_ids ) ) {
					wp_set_post_categories( $post_id, [ $parent_cat_id ], false );
				}
			}

			// Set meta 'newspack_vtd_cpt' = $liveblog_cpt;
			update_post_meta( $post_id, self::META_VTD_CPT, $liveblog_cpt );
		}
	}

	/**
	 * Callable for `newspack-content-migrator vtdigger-migrate-letterstotheeditor`.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_letterstotheeditor( array $pos_args, array $assoc_args ) {
		global $wpdb;
		$log = 'vtd_letterstotheeditor.log';

		$letters_ids = $wpdb->get_col( $wpdb->prepare( "select ID from {$wpdb->posts} where post_type = %s;", self::LETTERS_TO_EDITOR_CPT ) );

		// Convert to 'post' type.
		foreach ( $letters_ids as $key_letter_id => $letter_id ) {
			WP_CLI::log( sprintf( "(%d)/(%d) %d", $key_letter_id + 1, count( $letters_ids ), $letter_id ) );

			// Update to type post.
			$wpdb->query( $wpdb->prepare( "update {$wpdb->posts} set post_type='post' where ID=%d;", $letter_id ) );

			// Set meta 'newspack_vtd_cpt' = 'letters_to_editor';
			update_post_meta( $letter_id, self::META_VTD_CPT, self::LETTERS_TO_EDITOR_CPT );

			// Tag, append.
			wp_set_post_tags( $letter_id, [ self::LETTERSTOTHEEDITOR_TAG_NAME ], true );
		}

		$this->logger->log( $log, implode( ',', $letters_ids ), false );

		wp_cache_flush();
		WP_CLI::log( sprintf( "Done; see %s", $log ) );
	}

	/**
	 * Callable for `newspack-content-migrator vtdigger-migrate-obituaries`.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_obituaries( array $pos_args, array $assoc_args ) {
		global $wpdb;
		$log = 'vtd_obituaries.log';
		$log_error = 'vtd_obituaries_error.log';

		// Get Obituaries category ID.
		$obituaries_cat_id = get_cat_ID( self::OBITUARIES_CAT_NAME );
		if ( ! $obituaries_cat_id ) {
			$obituaries_cat_id = wp_insert_category( [ 'cat_name' => self::OBITUARIES_CAT_NAME ] );
		}

		$obituaries_ids = $wpdb->get_col( $wpdb->prepare( "select ID from {$wpdb->posts} where post_type='%s';", self::OBITUARY_CPT ) );
		$obituaries_ids_dev = [
			// _thumbnail_id IDs w/ & wo/
			// 409943,394799,
			// name_of_deceased IDs w/ & wo/
			// 402320,402256,
			// date_of_birth IDs w/ & wo/
			// 402256,401553,
			// city_of_birth IDs w/ & wo/
			// 402256,401553,
			// state_of_birth IDs w/ & wo/
			// 402497, 402320,
			// date_of_death IDs w/ & wo/
			// 384051,384020,
			// city_of_death IDs w/ & wo/
			// 402256,401553,
			// state_of_death IDs w/ & wo/
			// 402497,402320,
			// details_of_services IDs w/ & wo/
			// 402320,402256,
			// obitbiography IDs w/ & wo/
			// 394221,394199,
			// obitfamily_information IDs w/ & wo/
			// 394221,394199,
		];

		// Convert to 'post' type.
		foreach ( $obituaries_ids as $key_obituary_id => $obituary_id ) {
			WP_CLI::log( sprintf( "(%d)/(%d) %d", $key_obituary_id + 1, count( $obituaries_ids ), $obituary_id ) );

			// Get all ACF.
			/*
			 * @var $_thumbnail_id E.g. has _thumbnail_id ID 409943, no _thumbnail_id ID 394799.
			 */
			$thumbnail_id = get_post_meta( $obituary_id, '_thumbnail_id', true ) != '' ? get_post_meta( $obituary_id, '_thumbnail_id', true ) : null;
			/*
			 * @var $name_of_deceased E.g. has name_of_deceased ID 402320, no name_of_deceased ID 402256.
			 */
			$name_of_deceased = get_post_meta( $obituary_id, 'name_of_deceased', true ) != '' ? get_post_meta( $obituary_id, 'name_of_deceased', true ) : null;
			/*
			 * @var string|null $date_of_birth E.g. has date_of_birth ID 402256, no date_of_birth ID 401553
			 */
			$date_of_birth = get_post_meta( $obituary_id, 'date_of_birth', true ) != '' ? get_post_meta( $obituary_id, 'date_of_birth', true ) : null;
			/*
			 * @var string|null $city_of_birth E.g. has city_of_birth ID 402256, no city_of_birth ID 401553.
			 */
			$city_of_birth = get_post_meta( $obituary_id, 'city_of_birth', true ) != '' ? get_post_meta( $obituary_id, 'city_of_birth', true ) : null;
			/*
			 * @var string|null $state_of_birth E.g. has state_of_birth ID 402497, no state_of_birth ID 402320.
			 */
			$state_of_birth = get_post_meta( $obituary_id, 'state_of_birth', true ) != '' ? get_post_meta( $obituary_id, 'state_of_birth', true ) : null;
			/*
			 * @var string|null $date_of_death E.g. has date_of_death ID 384051, no date_of_death ID 384020.
			 */
			$date_of_death = get_post_meta( $obituary_id, 'date_of_death', true ) != '' ? get_post_meta( $obituary_id, 'date_of_death', true ) : null;
			/*
			 * @var string|null $city_of_death E.g. has city_of_death ID 402256, no city_of_death ID 401553.
			 */
			$city_of_death = get_post_meta( $obituary_id, 'city_of_death', true ) != '' ? get_post_meta( $obituary_id, 'city_of_death', true ) : null;
			/*
			 * @var string|null $state_of_death E.g. has state_of_death ID 402497, no state_of_death ID 402320.
			 */
			$state_of_death = get_post_meta( $obituary_id, 'state_of_death', true ) != '' ? get_post_meta( $obituary_id, 'state_of_death', true ) : null;
			/*
			 * @var string|null $details_of_services E.g. has details_of_services ID 402320, no details_of_services ID 402256.
			 */
			$details_of_services = get_post_meta( $obituary_id, 'details_of_services', true ) != '' ? get_post_meta( $obituary_id, 'details_of_services', true ) : null;
			/*
			 * @var string|null $obitbiography E.g. has obitbiography ID 394221, no obitbiography ID 394199.
			 */
			$obitbiography = get_post_meta( $obituary_id, 'obitbiography', true ) != '' ? get_post_meta( $obituary_id, 'obitbiography', true ) : null;
			/*
			 * @var string|null $obitfamily_information E.g. has obitfamily_information ID 394221, no obitfamily_information ID 394199.
			 */
			$obitfamily_information = get_post_meta( $obituary_id, 'obitfamily_information', true ) != '' ? get_post_meta( $obituary_id, 'obitfamily_information', true ) : null;

			// Possible characters for replacing for other types of content.
			$not_used_dev = [
				' ' => '',
			];

			$details_of_services = trim( apply_filters( 'the_content', trim( $details_of_services ) ) );
			$details_of_services = str_replace( "\r\n", "\n", $details_of_services );
			$details_of_services = str_replace( "\n", "", $details_of_services );
			$obitbiography = trim( apply_filters( 'the_content', trim( $obitbiography ) ) );
			$obitbiography = str_replace( "\r\n", "\n", $obitbiography );
			$obitbiography = str_replace( "\n", "", $obitbiography );
			$obitfamily_information = trim( apply_filters( 'the_content', trim( $obitfamily_information ) ) );
			$obitfamily_information = str_replace( "\r\n", "\n", $obitfamily_information );
			$obitfamily_information = str_replace( "\n", "", $obitfamily_information );

			$acf_args = [
				'_thumbnail_id' => $thumbnail_id,
				'name_of_deceased' => $name_of_deceased,
				'date_of_birth' => $date_of_birth,
				'city_of_birth' => $city_of_birth,
				'state_of_birth' => $state_of_birth,
				'date_of_death' => $date_of_death,
				'city_of_death' => $city_of_death,
				'state_of_death' => $state_of_death,
				'details_of_services' => $details_of_services,
				'obitbiography' => $obitbiography,
				'obitfamily_information' => $obitfamily_information,
			];
			$acf_additional_args = [
				'submitter_firstname' => get_post_meta( $obituary_id, 'submitter_firstname' ),
				'submitter_lastname' => get_post_meta( $obituary_id, 'submitter_lastname' ),
				'submitter_email' => get_post_meta( $obituary_id, 'submitter_email' ),
				'display_submitter_info' => get_post_meta( $obituary_id, 'display_submitter_info' ),
				'submitter_phone' => get_post_meta( $obituary_id, 'submitter_phone' ),
			];

			// New values.
			$post_content = $this->get_obituary_content( $acf_args );

			// Update to type post, set title and content.
			$wpdb->query( $wpdb->prepare( "update {$wpdb->posts} set post_type='post', post_content='%s' where ID=%d;", $post_content, $obituary_id ) );

			// Set meta 'newspack_vtd_cpt' = self::OBITUARY_CPT;
			update_post_meta( $obituary_id, self::META_VTD_CPT, self::OBITUARY_CPT );

			// Assign category for Obituaries.
			wp_set_post_categories( $obituary_id, [ $obituaries_cat_id ], true );
		}

		$this->logger->log( $log, implode( ',', $obituaries_ids ), false );

		wp_cache_flush();
		WP_CLI::log( sprintf( "Done; see %s", $log ) );
	}

	/**
	 * @param array $replacements {
	 *     Keys are search strings, values are replacements. Expected and mandatory keys:
	 *
	 *     @type int|null    $thumbnail_id           Thumbnail ID.
	 *     @type string|null $name_of_deceased       Value for "{{name_of_deceased}}".
	 *     @type string|null $date_of_birth          Value for "{{date_of_birth}}".
	 *     @type string|null $city_of_birth          Value for "{{city_of_birth}}".
	 *     @type string|null $state_of_birth         Value for "{{state_of_birth}}".
	 *     @type string|null $date_of_death          Value for "{{date_of_death}}".
	 *     @type string|null $city_of_death          Value for "{{city_of_death}}".
	 *     @type string|null $state_of_death         Value for "{{state_of_death}}".
	 *     @type string|null $details_of_services    Value for "{{details_of_services}}".
	 *     @type string|null $obitbiography          Value for "{{obitbiography}}".
	 *     @type string|null $obitfamily_information Value for "{{obitfamily_information}}".
	 *
	 * @return void
	 */
	public function get_obituary_content( $replacements ) {
		$log_error = 'vtd_obituaries_template_error.log';

		$post_content = '';

		// Image.
		if ( ! is_null( $replacements['_thumbnail_id'] ) ) {
			$img_template = <<<HTML
<!-- wp:image {"align":"right","id":%d,"width":353,"sizeSlug":"large","linkDestination":"none","className":"is-resized"} -->
<figure class="wp-block-image alignright size-large is-resized"><img src="%s" alt="" class="wp-image-%d" width="353"/></figure>
<!-- /wp:image -->
HTML;
			$src = wp_get_attachment_url( $replacements['_thumbnail_id'] );
			if ( false == $src || empty( $src ) || ! $src ) {
				$this->logger->log( $log_error, sprintf( "not found src for _thumbnail_id %d", $replacements['_thumbnail_id'] ) );
			}

			$wp_image = sprintf( $img_template, $replacements['_thumbnail_id'], $src, $replacements['_thumbnail_id'] );
			$post_content .= $wp_image;
		}

		// name_of_deceased.
		if ( ! is_null( $replacements['name_of_deceased'] ) ) {
			$spaces = <<<HTML


HTML;
			if ( ! empty( $post_content ) ) {
				$post_content .= $spaces;
			}

			$wp_paragraph_template = <<<HTML
<!-- wp:paragraph -->
<p>{{name_of_deceased}}</p>
<!-- /wp:paragraph -->
HTML;
			$wp_paragraph = str_replace( '{{name_of_deceased}}', $replacements['name_of_deceased'], $wp_paragraph_template );
			$post_content .= $wp_paragraph;
		}

		// date_of_birth, city_of_birth, state_of_birth
		if ( ! is_null( $replacements['date_of_birth'] ) || ! is_null( $replacements['city_of_birth'] ) || ! is_null( $replacements['state_of_birth'] ) ) {

			// The first paragraph goes with or without date of birth, if any of the birth info is present.
			$wp_paragraph_1_template = <<<HTML


<!-- wp:paragraph -->
<p><strong>Born </strong>{{date_of_birth}}</p>
<!-- /wp:paragraph -->
HTML;
			$wp_paragraph_1 = str_replace( '{{date_of_birth}}', ! is_null( $replacements['date_of_birth'] ) ? $replacements['date_of_birth'] : '', $wp_paragraph_1_template );
			$post_content .= $wp_paragraph_1;

			// Second paragraph goes only if either city_of_birth or state_of_birth is present.
			$wp_paragraph_2_template = <<<HTML


<!-- wp:paragraph -->
<p>%s</p>
<!-- /wp:paragraph -->
HTML;
			$wp_paragraph_2_values = $replacements['city_of_birth'] ?? '';
			$wp_paragraph_2_values .= ( ! empty( $wp_paragraph_2_values ) && ! empty( $replacements['state_of_birth'] ) ) ? ', ' : '';
			$wp_paragraph_2_values .= ! empty( $replacements['state_of_birth'] ) ? $replacements['state_of_birth'] : '';
			if ( ! empty( $wp_paragraph_2_values ) ) {
				$wp_paragraph_2 = sprintf( $wp_paragraph_2_template, $wp_paragraph_2_values );

				$post_content .= $wp_paragraph_2;
			}
		}

		// date_of_death, city_of_death, state_of_death
		if ( ! is_null( $replacements['date_of_death'] ) || ! is_null( $replacements['city_of_death'] ) || ! is_null( $replacements['state_of_death'] ) ) {

			// The first paragraph goes with or without date of death, if any of the death info is present.
			$wp_paragraph_1_template = <<<HTML


<!-- wp:paragraph -->
<p><strong>Died </strong>{{date_of_death}}</p>
<!-- /wp:paragraph -->
HTML;
			$wp_paragraph_1 = str_replace( '{{date_of_death}}', ! is_null( $replacements['date_of_death'] ) ? $replacements['date_of_death'] : '', $wp_paragraph_1_template );
			$post_content .= $wp_paragraph_1;

			// Second paragraph goes only if either city_of_death or state_of_death is present.
			$wp_paragraph_2_template = <<<HTML


<!-- wp:paragraph -->
<p>%s</p>
<!-- /wp:paragraph -->
HTML;
			$wp_paragraph_2_values = $replacements['city_of_death'] ?? '';
			$wp_paragraph_2_values .= ( ! empty( $wp_paragraph_2_values ) && ! empty( $replacements['state_of_death'] ) ) ? ', ' : '';
			$wp_paragraph_2_values .= ! empty( $replacements['state_of_death'] ) ? $replacements['state_of_death'] : '';
			if ( ! empty( $wp_paragraph_2_values ) ) {
				$wp_paragraph_2 = sprintf( $wp_paragraph_2_template, $wp_paragraph_2_values );

				$post_content .= $wp_paragraph_2;
			}
		}

		// details_of_services
		if ( ! empty( $replacements['details_of_services'] ) ) {
			$wp_paragraph_template = <<<HTML


<!-- wp:paragraph -->
<p><strong>Details of services</strong></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
{{details_of_services}}
<!-- /wp:paragraph -->
HTML;
			$wp_paragraph = str_replace( '{{details_of_services}}', $replacements['details_of_services'], $wp_paragraph_template );
			$post_content .= $wp_paragraph;
		}

		// wp:separator
		$wp_paragraph_template = <<<HTML


<!-- wp:separator -->
<hr class="wp-block-separator has-alpha-channel-opacity"/>
<!-- /wp:separator -->
HTML;
		$post_content .= $wp_paragraph_template;

		// obitbiography
		if ( ! empty( $replacements['obitbiography'] ) ) {
			$wp_paragraph_template = <<<HTML


<!-- wp:paragraph -->
{{obitbiography}}
<!-- /wp:paragraph -->
HTML;
			$wp_paragraph = str_replace( '{{obitbiography}}', $replacements['obitbiography'], $wp_paragraph_template );
			$post_content .= $wp_paragraph;
		}

		// obitfamily_information
		if ( ! empty( $replacements['obitfamily_information'] ) ) {
			$wp_paragraph_template = <<<HTML


<!-- wp:paragraph -->
<p><strong>Family information</strong></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
{{obitfamily_information}}
<!-- /wp:paragraph -->
HTML;
			$wp_paragraph = str_replace( '{{obitfamily_information}}', $replacements['obitfamily_information'], $wp_paragraph_template );
			$post_content .= $wp_paragraph;
		}

		return $post_content;
	}

	/**
	 * Callable for `newspack-content-migrator vtdigger-migrate-counties`.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_counties( array $pos_args, array $assoc_args ) {
		global $wpdb;

		$log = 'vtd_counties.log';

		WP_CLI::log( "Getting or creating category tree..." );
		/**
		 * Fetch or create the destination category tree:
		 *	Regional
		 *		Champlain Valley
		 *			Chittenden County
		 *				Burlington
		 *			Grand Isle County
		 *			Franklin County
		 *			Addison County
		 *		Northeast Kingdom
		 *			Orleans County
		 *			Essex County
		 *			Caledonia County
		 *		Central Vermont
		 *			Washington County
		 *			Lamoille County
		 *			Orange County
		 *		Southern Vermont
		 *			Windsor County
		 *			Rutland County
		 *			Bennington County
		 *			Windham County
		 **/
		// phpcs:disable -- leave this indentation for clear hierarchical overview.
		$regional_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Regional', 0 );
			$champlain_valley_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Champlain Valley', $regional_id );
				$chittenden_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Chittenden County', $champlain_valley_id );
					$burlington_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Burlington', $chittenden_county_id );
				$grand_isle_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Grand Isle County', $champlain_valley_id );
				$franklin_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Franklin County', $champlain_valley_id );
				$addison_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Addison County', $champlain_valley_id );
			$northeast_kingdom_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Northeast Kingdom', $regional_id );
				$orleans_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Orleans County', $northeast_kingdom_id );
				$essex_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Essex County', $northeast_kingdom_id );
				$caledonia_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Caledonia County', $northeast_kingdom_id );
			$central_vermont_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Central Vermont', $regional_id );
				$washington_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Washington County', $central_vermont_id );
				$lamoille_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Lamoille County', $central_vermont_id );
				$orange_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Orange County', $central_vermont_id );
			$southern_vermontt_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Southern Vermont', $regional_id );
				$windsor_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Windsor County', $southern_vermontt_id );
				$rutland_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Rutland County', $southern_vermontt_id );
				$bennington_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Bennington County', $southern_vermontt_id );
				$windham_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Windham County', $southern_vermontt_id );
		// phpcs:enable

		$county_id_to_cat_id = [
			'Addison' => $addison_county_id,
			'Bennington' => $bennington_county_id,
			'Caledonia' => $caledonia_county_id,
			'Chittenden' => $chittenden_county_id,
			'Essex' => $essex_county_id,
			'Franklin' => $franklin_county_id,
			'Grand Isle' => $grand_isle_county_id,
			'Lamoille' => $lamoille_county_id,
			'Orange' => $orange_county_id,
			'Orleans' => $orleans_county_id,
			'Rutland' => $rutland_county_id,
			'Washington' => $washington_county_id,
			'Windham' => $windham_county_id,
			'Windsor' => $windsor_county_id,
		];

		// Get all term_ids, term_taxonomy_ids and term names with 'counties' taxonomy.
		$counties_terms = $wpdb->get_results(
			$wpdb->prepare(
				"select tt.term_id as term_id, tt.term_taxonomy_id as term_taxonomy_id, t.name as name 
				from {$wpdb->term_taxonomy} tt
				join {$wpdb->terms} t on t.term_id = tt.term_id 
				where tt.taxonomy = '%s';",
				self::COUNTIES_TAXONOMY
			),
			ARRAY_A
		);

		// Loop through all 'counties' terms.
		foreach ( $counties_terms as $key_county_term => $county_term ) {
			$term_id = $county_term['term_id'];
			$term_taxonomy_id = $county_term['term_taxonomy_id'];
			$term_name = $county_term['name'];

			$this->logger->log( $log, sprintf( "(%d)/(%d) %d %d %s", $key_county_term + 1, count( $counties_terms ), $term_id, $term_taxonomy_id, $term_name ), true );

			// Get all objects for this 'county' term's term_taxonomy_id.
			$object_ids = $wpdb->get_col(
				$wpdb->prepare(
					"select object_id from {$wpdb->term_relationships} vwtr where term_taxonomy_id = %d;",
					$term_taxonomy_id
				)
			);

			// Get the destination category.
			$destination_cat_id = $county_id_to_cat_id[$term_name] ?? null;
			// We should have all 'counties' on record. Double check.
			if ( is_null( $destination_cat_id ) ) {
				throw new \RuntimeException( sprintf( "County term_id=%d term_taxonomy_id=%d name=%s is not mapped by the migrator script.", $term_id, $term_taxonomy_id, $term_name ) );
			}

			// Assign the destination category to all objects.
			foreach ( $object_ids as $object_id ) {
				$this->logger->log( $log, sprintf( "post_id=%d to category_id=%d", $object_id, $destination_cat_id ), true );
				wp_set_post_categories( $object_id, [ $destination_cat_id ], true );
			}

			// Remove the custom taxonomy from objects, leaving just the newly assigned category.
			$wpdb->query(
				$wpdb->prepare(
					"delete from {$wpdb->term_relationships} where term_taxonomy_id = %d;",
					$term_taxonomy_id
				)
			);
		}

		WP_CLI::success( "Done. See {$log}." );
	}

	/**
	 * Callable for `newspack-content-migrator vtdigger-migrate-series`.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_series( array $pos_args, array $assoc_args ) {
		global $wpdb;

		$log = 'vtd_series.log';

		// Get all term_ids, term_taxonomy_ids and term names with 'series' taxonomy.
		$seriess_terms = $wpdb->get_results(
			$wpdb->prepare(
				"select tt.term_id as term_id, tt.term_taxonomy_id as term_taxonomy_id, t.name as name 
				from {$wpdb->term_taxonomy} tt
				join {$wpdb->terms} t on t.term_id = tt.term_id 
				where tt.taxonomy = '%s';",
				self::SERIES_TAXONOMY
			),
			ARRAY_A
		);

		// Loop through all 'series' terms.
		foreach ( $seriess_terms as $key_series_term => $series_term ) {
			$term_id = $series_term['term_id'];
			$term_taxonomy_id = $series_term['term_taxonomy_id'];
			$term_name = $series_term['name'];

			// Get all objects for this 'series' term's term_taxonomy_id.
			$object_ids = $wpdb->get_col(
				$wpdb->prepare(
					"select object_id from {$wpdb->term_relationships} vwtr where term_taxonomy_id = %d;",
					$term_taxonomy_id
				)
			);

			$this->logger->log( $log, sprintf( "(%d)/(%d) %d %d %s count=%d", $key_series_term + 1, count( $seriess_terms ), $term_id, $term_taxonomy_id, $term_name, count( $object_ids ) ), true );
			if ( 0 == count( $object_ids ) ) {
				WP_CLI::log( "0 posts, skipping." );
				continue;
			}

			// Assign the tag to posts/objects.
			foreach ( $object_ids as $object_id ) {
				$this->logger->log( $log, sprintf( "post_id=%d tag='%s'", $object_id, self::SERIES_TAG_NAME ), true );

				// Tag, append.
				wp_set_post_tags( $object_id, [ self::SERIES_TAG_NAME ], true );
			}

			// Remove this term from objects, leaving just the newly assigned category.
			$wpdb->query(
				$wpdb->prepare(
					"delete from {$wpdb->term_relationships} where term_taxonomy_id = %d;",
					$term_taxonomy_id
				)
			);
		}

		WP_CLI::success( "Done. See {$log}." );
	}

	/**
	 * Callable for `newspack-content-migrator vtdigger-migrate-authors`.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_authors( array $pos_args, array $assoc_args ) {
		global $wpdb;

		$logs = [
			'previously_migrated_skipping'                  => 'vtd_authors__previously_migrated_skipping.log',
			'created_gas_from_acf'                          => 'vtd_authors__created_gas_from_acf.log',
			'created_gas_from_wpusers'                      => 'vtd_authors__created_gas_from_wpusers.log',
			'post_ids_obituaries'                           => 'vtd_authors__post_ids_obituaries.log',
			'post_ids_letters_to_editor'                    => 'vtd_authors__post_ids_letters_to_editor.log',
			'assigned_gas_post_ids'                         => 'vtd_authors__assigned_gas_post_ids.log',
			'already_assigned_gas_post_ids_pre_authornames' => 'vtd_authors__already_assigned_gas_post_ids_pre_authornames.log',
			'already_assigned_gas_post_ids'                 => 'vtd_authors__already_assigned_gas_post_ids.log',
			'post_ids_failed_author'                        => 'vtd_authors__post_ids_failed_author.log',
			'post_has_no_authors_at_all'                    => 'vtd_authors__post_has_no_authors_at_all.log',
			// DEV helper, things not yet done, just log and skip these:
			'post_ids_was_newsbrief_not_assigned'           => 'vtd_authors__post_id_was_newsbrief_not_assigned.log',
			'post_ids_was_liveblog_not_assigned'            => 'vtd_authors__post_id_was_liveblog_not_assigned.log',
		];

		// Local caching var.
		$cached_authors_meta = [];

		// Get/create GA for CPTs.
		$obituaries_ga = $this->cap_logic->get_guest_author_by_display_name( self::OBITUARIES_GA_NAME );
		$obituaries_ga_id = $obituaries_ga->ID ?? null;
		if ( ! $obituaries_ga_id ) {
			$obituaries_ga_id = $this->cap_logic->create_guest_author( [ 'display_name' => self::OBITUARIES_GA_NAME ] );
		}
		$letters_to_editor_ga = $this->cap_logic->get_guest_author_by_display_name( self::LETTERS_TO_EDITOR_GA_NAME );
		$letters_to_editor_ga_id = $letters_to_editor_ga->ID ?? null;
		if ( ! $letters_to_editor_ga_id ) {
			$letters_to_editor_ga_id = $this->cap_logic->create_guest_author( [ 'display_name' => self::LETTERS_TO_EDITOR_GA_NAME ] );
		}


		WP_CLI::log( "Fetching Post IDs..." );
		$post_ids = $this->posts_logic->get_all_posts_ids( 'post', [ 'publish', 'future', 'draft', 'pending', 'private' ] );
		// $post_ids = [386951,]; // DEV test.

		// Loop through all posts and create&assign GAs.
		foreach ( $post_ids as $key_post_id => $post_id ) {
			WP_CLI::log( sprintf( "(%d)/(%d) %d", $key_post_id + 1, count( $post_ids ), $post_id ) );

			// Skip some specific dev IDs.
			if ( in_array( $post_id, [ 410385 ] ) ) {
				WP_CLI::log( "skipping DEV ID" );
				continue;
			}

			// Skip if already imported.
			if ( get_post_meta( $post_id, self::META_AUTHORS_MIGRATED, true ) ) {
				$this->logger->log( $logs[ 'previously_migrated_skipping' ], sprintf( "PREVIOUSLY_MIGRATED_SKIPPING post_id=%d", $post_id ), true );
				continue;
			}

			// Get if this post used to be a CPT.
			$was_cpt = get_post_meta( $post_id, self::META_VTD_CPT, true );

			// Assign specific GAs to ex CPTs.
			if ( $was_cpt && ( self::OBITUARY_CPT == $was_cpt ) ) {
				$this->cap_logic->assign_guest_authors_to_post( [ $obituaries_ga_id ], $post_id);
				update_post_meta( $post_id, self::META_AUTHORS_MIGRATED, true );
				$this->logger->log( $logs[ 'post_ids_obituaries' ], sprintf( "OBITUARY post_id=%d", $post_id ), true );
				continue;
			} elseif ( $was_cpt && ( self::LETTERS_TO_EDITOR_CPT == $was_cpt ) ) {
				$this->cap_logic->assign_guest_authors_to_post( [ $letters_to_editor_ga_id ], $post_id );
				update_post_meta( $post_id, self::META_AUTHORS_MIGRATED, true );
				$this->logger->log( $logs['post_ids_letters_to_editor'], sprintf( "LETTERS_TO_EDITOR post_id=%d", $post_id ), true );
				continue;
			}
			//
			// WIP -- for now just log and skip those CPTs we don't know how to handle yet. Answers will come soon and this part will be refined.
			//
			elseif ( $was_cpt && ( self::NEWSBRIEF_CPT == $was_cpt ) ) {
				$this->logger->log( $logs['post_ids_was_newsbrief_not_assigned'], sprintf( "NEWSBRIEF post_id=%d", $post_id ), true );
				WP_CLI::log( 'not sure how to handle this CPT, skipping for now' );
				continue;
			} elseif ( $was_cpt && ( self::LIVEBLOG_CPT == $was_cpt ) ) {
				$this->logger->log( $logs['post_ids_was_liveblog_not_assigned'], sprintf( "LIVEBLOG post_id=%d", $post_id ), true );
				WP_CLI::log( 'not sure how to handle this CPT, skipping for now' );
				continue;
			}

			// Skip if it already has GAs assigned.
			$existing_ga_ids = $this->cap_logic->get_posts_existing_ga_ids( $post_id );
			if ( ! empty( $existing_ga_ids ) ) {
				$this->logger->log( $logs['already_assigned_gas_post_ids_pre_authornames'], sprintf( "HAS_GAs post_id=%d", $post_id ), true );
				update_post_meta( $post_id, self::META_AUTHORS_MIGRATED, true );
				continue;
			}

			/**
			 * Get author names for this post.
			 * Author names are terms with 'author' taxonomy. And the actual data for these authors can either be located in ACF 'vtd_team' Post objects,
			 * or can be regular WP Users.
			 */
			$author_names = $wpdb->get_col(
				$wpdb->prepare(
					"select name
					from {$wpdb->terms} where term_id in (
						select term_id
						from {$wpdb->term_taxonomy} vwtt
						where taxonomy = 'author' and term_taxonomy_id in (
							select term_taxonomy_id
							from {$wpdb->term_relationships} vwtr
							where object_id = %d
						)
					);",
					$post_id
				)
			);

			// GA IDs for this Post.
			$ga_ids = [];

			/**
			 * First try and get or create GAs based on $author_names.
			 */
			if ( ! empty( $author_names ) ) {

				foreach ( $author_names as $author_name ) {
					WP_CLI::log( "author_name=" . $author_name );

					// Get existing GA.
					$ga = $this->cap_logic->get_guest_author_by_display_name( $author_name );
					$ga_id = $ga->ID ?? null;

					// Create GA if it doesn't exist.
					if ( is_null( $ga_id ) ) {

						/**
						 * 1/2 First try and create GA from ACF author Post object with this name.
						 */
						$acf_author_meta = isset( $cached_authors_meta[ $author_name ] )
							? $cached_authors_meta[ $author_name ]
							: $this->get_acf_author_meta( $author_name );
						if ( ! is_null( $acf_author_meta ) ) {
							// Cache entry.
							if ( ! isset( $cached_authors_meta[ $author_name ] ) ) {
								$cached_authors_meta[ $author_name ] = $acf_author_meta;
							}
							// Create GA.
							$ga_id = $this->create_ga_from_acf_author( $author_name, $acf_author_meta );
							$this->logger->log( $logs['created_gas_from_acf'], sprintf( "CREATED_FROM_ACF post_id=%d name='%s' ga_id=%d", $post_id, $author_name, $ga_id ), true );
						}

						/**
						 * 2/2 Next try and create GA from WP User with this display_name.
						 */
						if ( is_null( $ga_id ) ) {
							$ga_id = $this->create_ga_from_wp_user( $post_id, $author_name, $logs['created_gas_from_wpusers'] );

						}
					}

					// Add new or existing.
					if ( $ga_id ) {
						$ga_ids[] = $ga_id;
					}
				} // Done foreach $author_names.

			} else {

				/**
				 * Next try and create GAs just from pure WP User author.
				 */
				// Get WP User author's display_name as $author_name.
				$author_name = $wpdb->get_var(
					$wpdb->prepare(
						"select u.display_name
						from {$wpdb->users} u
						join {$wpdb->posts} p on p.post_author = u.ID 
						where p.ID = %d ; ",
						$post_id
					)
				);
				if ( $author_name ) {
					$ga_id = $this->create_ga_from_wp_user( $post_id, $author_name, $logs['created_gas_from_wpusers'] );
					if ( $ga_id ) {
						$ga_ids[] = $ga_id;
					}
				}
			}


			// This is where author creation/assignment failed completely, GA was not created or fetched from known sources.
			if ( empty( $ga_ids ) ) {
				$this->logger->log( $logs['post_ids_failed_author'], sprintf( "FAILED_AUTHOR___SKIPPED post_id=%d author_name='%s'", $post_id, $author_name ), true );
				// Continue to next $post_id.
				continue;
			}

			// Assign GAs to post and log all post_ids.
			$existing_ga_ids = $this->cap_logic->get_posts_existing_ga_ids( $post_id );
			$new_ga_ids = array_unique( array_merge( $existing_ga_ids, $ga_ids ) );
			if ( $existing_ga_ids != $new_ga_ids ) {
				$this->cap_logic->assign_guest_authors_to_post( $new_ga_ids, $post_id );
				update_post_meta( $post_id, self::META_AUTHORS_MIGRATED, true );
				$this->logger->log( $logs['assigned_gas_post_ids'], sprintf( "NEWLY_ASSIGNED_GAS post_id=%d ga_ids=%s", $post_id, implode( ',', $new_ga_ids ) ), true );
			} elseif ( ! empty( $existing_ga_ids ) ) {
				$this->logger->log( $logs['already_assigned_gas_post_ids'], sprintf( "ALREADY_ASSIGNED post_id=%d ga_ids=%s", $post_id, implode( ',', $new_ga_ids ) ), true );
			} else {
				$this->logger->log( $logs['post_has_no_authors_at_all'], sprintf( "NO_AUTHORS_AT_ALL post_id=%d", $post_id ), true );
			}

		}

		wp_cache_flush();
		WP_CLI::log( sprintf( "Done, see %s ", implode( ', ', array_keys( $logs ) ) ) );
	}

	/**
	 * Creates GA from WP User with display_name or user_nicename same as $author_name.
	 *
	 * @param int    $post_id     Post ID being processed (for logging).
	 * @param string $author_name Display name.
	 * @param string $log         Log file name.
	 *
	 * @return int|null GA ID or null.
	 */
	public function create_ga_from_wp_user( int $post_id, string $author_name, string $log ) {
		global $wpdb;

		// Try and get WP user with display_name.
		$wp_user_row = $wpdb->get_row(
			$wpdb->prepare(
				"select * from {$wpdb->users} where display_name = %s; ",
				$author_name
			),
			ARRAY_A
		);

		// Next, try and get a WP user with that user_nicename.
		if ( is_null( $wp_user_row ) ) {
			$wp_user_row = $wpdb->get_row(
				$wpdb->prepare(
					"select * from {$wpdb->users} where user_nicename = %s; ",
					$author_name
				),
				ARRAY_A
			);

			// Get $author_name from display_name.
			if ( $wp_user_row ) {

				/**
				 * Handle exceptions manually.
				 */
				// This user has 'Commentary' for display_name, let's not use that.
				if ( 'opinion' == $wp_user_row['user_nicename'] ) {
					$author_name = 'Opinion';
				} elseif ( 'stacey1' == $wp_user_row['user_nicename'] ) {
					// This is a weird one. This user has 'stacey 2' for display_name, let's not use that.
					$author_name = 'stacey1';
				} elseif ( 'ben-heintz' == $wp_user_row['user_nicename'] ) {
					// This user has 'Underground Workshop' for display_name, let's not use that.
					$author_name = 'Ben Heintz';
				} else {
					$author_name = $wp_user_row['display_name'];
				}
			}
		}

		// Next, try and get a WP user with that user_login.
		if ( is_null( $wp_user_row ) ) {
			$wp_user_row = $wpdb->get_row(
				$wpdb->prepare(
					"select * from {$wpdb->users} where user_login = %s; ",
					$author_name
				),
				ARRAY_A
			);

			// Get $author_name from display_name.
			if ( $wp_user_row ) {
				/**
				 * Handle exceptions manually.
				 */
				// This user has 'Underground Workshop' for display_name, let's not use that.
				if ( 'Ben Heintz' == $wp_user_row['user_login'] ) {
					$author_name = 'Ben Heintz';
				} elseif ( 'Ben Opinion' == $wp_user_row['user_login'] ) {
					// This user has 'Commentary' for display_name, let's not use that.
					$author_name = 'Opinion';
				} else {
					$author_name = $wp_user_row['display_name'];
				}
			}
		}

		// Still nothing. Exit.
		if ( is_null( $wp_user_row ) ) {
			return null;
		}

		$social_sources = '';
		$social_sources .= ! empty( get_user_meta( $wp_user_row['ID'], 'aim', true ) )        ? 'AIM: ' .                  get_user_meta( $wp_user_row['ID'], 'aim', true ) . '. ' : null;
		$social_sources .= ! empty( get_user_meta( $wp_user_row['ID'], 'yim', true ) )        ? 'Yahoo IM: ' .             get_user_meta( $wp_user_row['ID'], 'yim', true ) . '. ' : null;
		$social_sources .= ! empty( get_user_meta( $wp_user_row['ID'], 'jabber', true ) )     ? 'Jabber / Google Talk: ' . get_user_meta( $wp_user_row['ID'], 'jabber', true ) . '. ' : null;
		$social_sources .= ! empty( get_user_meta( $wp_user_row['ID'], 'facebook', true ) )   ? 'Facebook: ' .             get_user_meta( $wp_user_row['ID'], 'facebook', true ) . '. ' : null;
		$social_sources .= ! empty( get_user_meta( $wp_user_row['ID'], 'instagram', true ) )  ? 'Instagram: ' .            get_user_meta( $wp_user_row['ID'], 'instagram', true ) . '. ' : null;
		$social_sources .= ! empty( get_user_meta( $wp_user_row['ID'], 'linkedin', true ) )   ? 'LinkedIn: ' .             get_user_meta( $wp_user_row['ID'], 'linkedin', true ) . '. ' : null;
		$social_sources .= ! empty( get_user_meta( $wp_user_row['ID'], 'myspace', true ) )    ? 'MySpace: ' .              get_user_meta( $wp_user_row['ID'], 'myspace', true ) . '. ' : null;
		$social_sources .= ! empty( get_user_meta( $wp_user_row['ID'], 'pinterest', true ) )  ? 'Pinterest: ' .            get_user_meta( $wp_user_row['ID'], 'pinterest', true ) . '. ' : null;
		$social_sources .= ! empty( get_user_meta( $wp_user_row['ID'], 'soundcloud', true ) ) ? 'SoundCloud: ' .           get_user_meta( $wp_user_row['ID'], 'soundcloud', true ) . '. ' : null;
		$social_sources .= ! empty( get_user_meta( $wp_user_row['ID'], 'twitter', true ) )    ? 'Twitter: @' .             get_user_meta( $wp_user_row['ID'], 'twitter', true ) . '. ' : null;
		$social_sources .= ! empty( get_user_meta( $wp_user_row['ID'], 'youtube', true ) )    ? 'YouTube: ' .              get_user_meta( $wp_user_row['ID'], 'youtube', true ) . '. ' : null;
		$social_sources .= ! empty( get_user_meta( $wp_user_row['ID'], 'wikipedia', true ) )  ? 'Wikipedia: ' .            get_user_meta( $wp_user_row['ID'], 'wikipedia', true ) . '. ' : null;

		$bio = ! empty( get_user_meta( $wp_user_row['ID'], 'description', true ) ) ? get_user_meta( $wp_user_row['ID'], 'description', true ) : null;

		$description = $social_sources
		               . ( ( ! empty( $social_sources ) && ! empty( $bio ) ) ? ' ' : '' )
		               . $bio;

		$ga_args = [
			'display_name' => $author_name,
			'user_email'   => $wp_user_row['user_email'] ?? null,
			'website'      => $wp_user_row['user_url'] ?? null,
			'description'  => ! empty( $description ) ? $description : null,
			// Their WP Users have an external plugin which extends avatar abilities, but these are not used.
			// 'avatar'       => null,
		];

		// Create GA
		$ga_id = $this->cap_logic->create_guest_author( $ga_args );

		// Link to WP User.
		$wp_user = get_user_by( 'ID', $wp_user_row['ID'] );
		$this->cap_logic->link_guest_author_to_wp_user( $ga_id, $wp_user );

		$this->logger->log( $log, sprintf( "CREATED_FROM_WPUSER ga_id=%d name='%s' post_id=%d linked_wp_user_id=%d", $ga_id, $author_name, $post_id, $wp_user_row['ID'] ), true );

		return $ga_id;
	}

	/**
	 * @param string $author_name
	 * @param array  $acf_author_meta
	 *
	 * @return int GA ID.
	 */
	public function create_ga_from_acf_author( string $author_name, array $acf_author_meta ) {
		// Compose $media_link for bio.
		$media_link = '';
		if ( isset( $acf_author_meta['vtd_social_media_handle'] ) && ! empty( $acf_author_meta['vtd_social_media_handle'] ) && isset( $acf_author_meta['vtd_social_media_link'] ) && ! empty( $acf_author_meta['vtd_social_media_link'] ) ) {
			// $media_link is a <a> element if both handle and link given.
			$media_link = sprintf( "<a href=\"%s\" target=\"_blank\">%s</a>", $acf_author_meta['vtd_social_media_link'], $acf_author_meta['vtd_social_media_handle'] );
		} elseif ( isset( $acf_author_meta['vtd_social_media_link'] ) && ! empty( $acf_author_meta['vtd_social_media_link'] ) ) {
			// $media_link is a <a> element if just link given.
			$media_link = sprintf( "<a href=\"%s\" target=\"_blank\">%s</a>", $acf_author_meta['vtd_social_media_link'], $acf_author_meta['vtd_social_media_link'] );
		} elseif ( isset( $acf_author_meta['vtd_social_media_handle'] ) && ! empty( $acf_author_meta['vtd_social_media_handle'] ) ) {
			// $media_link text.
			$media_link = $acf_author_meta['vtd_social_media_handle'];
		}

		// Compose GA description.
		// Start with the title.
		$description = ( isset( $acf_author_meta['vtd_title'] ) && ! empty( $acf_author_meta['vtd_title'] ) ) ? $acf_author_meta['vtd_title'] . '. ' : '';
		// Add media link.
		$description .= $media_link ? $media_link . ' ' : '';
		// Add bio.
		$description .= ( isset( $acf_author_meta['vtd_bio'] ) && ! empty( $acf_author_meta['vtd_bio'] ) ) ? $acf_author_meta['vtd_bio'] : '';

		// Leaving out:
		// 'office_phone', 'cell_phone', 'google_phone', 'vtd_department' (e.g. vtd_department e.g. a:1:{i:0;s:8:"newsroom";})

		$ga_args = [
			'display_name' => $author_name,
			'user_email'   => $acf_author_meta['vtd_email'] ?? null,
			'website'      => $acf_author_meta['vtd_social_media_link'] ?? null,
			'description'  => $description,
			'avatar'       => $acf_author_meta['_thumbnail_id'] ?? null,
		];

		// Create GA
		$ga_id = $this->cap_logic->create_guest_author( $ga_args );

		return $ga_id;
	}

	/**
	 * Gets ACF vtd_team meta from author name.
	 *
	 * @param string $author_name
	 *
	 * @return array|null
	 */
	public function get_acf_author_meta( string $author_name ) : array|null {
		global $wpdb;

		$post_id = $wpdb->get_var(
			$wpdb->prepare(
				"select ID
				from {$wpdb->posts}
				where post_title = %s and post_type = 'vtd_team'; ",
				$author_name
			)
		);
		if ( ! $post_id ) {
			return null;
		}

		$post_meta = $wpdb->get_row(
			$wpdb->prepare(
				"select meta_key, meta_value
				from {$wpdb->postmeta}
				where post_id = %d 
				and meta_key in ( '_thumbnail_id', 'vtd_email', 'vtd_title', 'vtd_bio', 'vtd_social_media_handle', 'vtd_social_media_link', 'office_phone', 'cell_phone', 'google_phone', 'vtd_department' ) ; ",
				$post_id
			),
			ARRAY_A
		);

		return $post_meta;
	}
}
