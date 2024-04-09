<?php

namespace NewspackCustomContentMigrator\Command\General;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\Posts;
use \NewspackCustomContentMigrator\Utils\Logger;
use \WP_CLI;

class PostsMigrator implements InterfaceCommand {

	/**
	 * `meta_key` which gets assigned to exported posts, contains the original post ID.
	 */
	const META_KEY_ORIGINAL_ID = 'newspack_custom_content_migrator-original_post_id';

	/**
	 * @var string Staging site pages export file name.
	 */
	const STAGING_PAGES_EXPORT_FILE = 'newspack-staging_pages_all.xml';

	/**
	 * @var string Log file for shortcodes manipulation.
	 */
	const SHORTCODES_LOGS = 'posts_shorcodes_migrator.log';

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * @var Posts.
	 */
	private $posts_logic = null;

	/**
	 * @var Logger.
	 */
	private $logger;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts_logic = new Posts();
		$this->logger      = new Logger();
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
			'newspack-content-migrator export-posts',
			array( $this, 'cmd_export_posts' ),
			array(
				'shortdesc' => 'Exports posts/pages by post-IDs to an XML file using `wp export`.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'output-dir',
						'description' => 'Output directory, no ending slash.',
						'optional'    => false,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'output-xml-file',
						'description' => 'Output XML file name.',
						'optional'    => false,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'post-ids',
						'description' => 'CSV post/page IDs to migrate.',
						'optional'    => true,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator import-posts',
			array( $this, 'cmd_import_posts' ),
			array(
				'shortdesc' => 'Imports custom posts from the XML file generated by `wp export`.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'file',
						'description' => 'XML file full path.',
						'optional'    => false,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator export-all-staging-pages',
			array( $this, 'cmd_export_all_staging_site_pages' ),
			array(
				'shortdesc' => 'Exports all Pages from the Staging site.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'output-dir',
						'description' => 'Output directory, no ending slash.',
						'optional'    => false,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator import-staging-site-pages',
			array( $this, 'cmd_import_staging_site_pages' ),
			array(
				'shortdesc' => 'Imports pages which were exported from the Staging site',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'input-dir',
						'description' => 'Full path to the location of the XML file containing staging files.',
						'optional'    => false,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator delete-export-postmeta',
			array( $this, 'cmd_delete_export_postmeta' ),
			array(
				'shortdesc' => 'Removes the postmeta with original ID which gets set on all exported posts/pages.',
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator find-posts-by-number-of-revisions',
			array( $this, 'cmd_find_posts_by_number_of_revisions' ),
			array(
				'shortdesc' => 'Finds post IDs that have at least the informed number of revisisions. Used to find posts with too many revisions that need to be cleaned up.',
				'synopsis'  => array(
					array(
						'type'        => 'positional',
						'name'        => 'number-of-revisions',
						'description' => 'The minimum number of revisions a post must have to be returned in the search.',
						'optional'    => true,
						'repeating'   => false,
						'default'     => 500,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'format',
						'description' => 'The output format. Default table',
						'optional'    => true,
						'repeating'   => false,
						'default'     => 'table',
					),
				),
			)
		);

		$clear_revisions_arguments = [
			array(
				'type'        => 'assoc',
				'name'        => 'keep',
				'description' => 'The minimum number of revisions that should be kept and that a post must have to have its revisions cleared.',
				'optional'    => true,
				'repeating'   => false,
				'default'     => 500,
			),
			array(
				'type'        => 'assoc',
				'name'        => 'chunk-size',
				'description' => 'The number of revisions to be deleted in each step. Default 30.',
				'optional'    => true,
				'repeating'   => false,
				'default'     => 1000,
			),
			array(
				'type'        => 'assoc',
				'name'        => 'sleep',
				'description' => 'The time in miliseconds to sleep between each chunk. Default 100.',
				'optional'    => true,
				'repeating'   => false,
				'default'     => 100,
			),
			array(
				'type'        => 'flag',
				'name'        => 'dry-run',
				'description' => 'Just output debugging info but do not delete anything.',
				'optional'    => true,
				'repeating'   => false,
			),
		];

		WP_CLI::add_command(
			'newspack-content-migrator clear-revisions',
			array( $this, 'cmd_clear_revisions' ),
			array(
				'shortdesc' => 'Clear post revisions of posts that have more than a certain number of revisions',
				'synopsis'  => $clear_revisions_arguments,
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator clear-post-revisions',
			array( $this, 'cmd_clear_post_revisions' ),
			array(
				'shortdesc' => 'Clear post revisions of a specific post, if it exceeds a certain number of revisions',
				'synopsis'  => array_merge(
					[
						array(
							'type'        => 'assoc',
							'name'        => 'post-id',
							'description' => 'The ID of the post you want to clear the revisions of.',
							'optional'    => false,
							'repeating'   => false,
						),
					],
					$clear_revisions_arguments
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator fix-dupe-slugs',
			[ $this, 'cmd_fix_dupe_slugs' ],
			[
				'shortdesc' => 'Fixes duplicate slugs used by Posts and Pages.',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator hide-featured-images',
			[ $this, 'cmd_hide_featured_images' ],
			[
				'shortdesc' => 'Hide featured image per posts or per categories.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'batch',
						'description' => 'Bath to start from.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'posts-per-batch',
						'description' => 'Posts to import per batch',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'      => 'assoc',
						'name'      => 'post-ids',
						'optional'  => true,
						'repeating' => false,
					],
					[
						'type'      => 'assoc',
						'name'      => 'category-id',
						'optional'  => true,
						'repeating' => false,
					],
				],
			]
		);
	}

	/**
	 * Callable for export-posts command.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function cmd_export_posts( $args, $assoc_args ) {
		$output_dir   = isset( $assoc_args['output-dir'] ) ? $assoc_args['output-dir'] : null;
		$output_file  = isset( $assoc_args['output-xml-file'] ) ? $assoc_args['output-xml-file'] : null;
		$post_ids_csv = isset( $assoc_args['post-ids'] ) ? $assoc_args['post-ids'] : null;
		if ( is_null( $output_dir ) ) {
			WP_CLI::error( 'Invalid output dir.' );
		}
		if ( is_null( $output_file ) ) {
			WP_CLI::error( 'Invalid output file.' );
		}
		if ( is_null( $post_ids_csv ) ) {
			WP_CLI::error( 'One of these is mandatory: post-ids or created-from' );
		}

		$post_ids = explode( ',', $post_ids_csv );

		WP_CLI::line( sprintf( 'Exporting post IDs %s to %s...', implode( ',', $post_ids ), $output_dir . '/' . $output_file ) );

		$this->migrator_export_posts( $post_ids, $output_dir, $output_file );

		WP_CLI::success( 'Done.' );
	}

	/**
	 * Exports posts and sets a meta with the original ID when it was exported.
	 *
	 * @param array  $post_ids    Post IDs.
	 * @param string $output_dir  Output dir.
	 * @param string $output_file Output file.
	 *
	 * @return bool
	 */
	public function migrator_export_posts( $post_ids, $output_dir, $output_file ) {
		if ( empty( $post_ids ) ) {
			WP_CLI::warning( 'No posts to export.' );
			return false;
		}

		wp_cache_flush();
		foreach ( $post_ids as $key => $post_id ) {
			update_post_meta( $post_id, self::META_KEY_ORIGINAL_ID, $post_id );
		}

		wp_cache_flush();
		$post_ids = array_values( $post_ids );
		$this->export_posts( $post_ids, $output_dir, $output_file );

		return true;
	}

	/**
	 * Actual exporting of posts to file.
	 * NOTE: this function doesn't set the self::META_KEY_ORIGINAL_ID meta on exported posts, so be sure that's what you want to do.
	 *       Otherwise, use the self::migrator_export_posts() function to set the meta, too.
	 *
	 * @param array  $post_ids    Post IDs.
	 * @param string $output_dir  Output dir.
	 * @param string $output_file Output file.
	 */
	private function export_posts( $post_ids, $output_dir, $output_file ) {
		$post_ids_csv = implode( ',', $post_ids );
		WP_CLI::runcommand( "export --post__in=$post_ids_csv --dir=$output_dir --filename_format=$output_file --with_attachments" );
	}

	/**
	 * Callable for import-posts command.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function cmd_import_posts( $args, $assoc_args ) {
		$file = isset( $assoc_args['file'] ) ? $assoc_args['file'] : null;
		if ( is_null( $file ) || ! file_exists( $file ) ) {
			WP_CLI::error( 'Invalid file provided.' );
		}

		WP_CLI::line( 'Importing posts...' );

		$this->import_posts( $file );
		wp_cache_flush();

		WP_CLI::success( 'Done.' );
	}

	/**
	 * @param string $file File for Import.
	 *
	 * @return mixed
	 */
	public function import_posts( $file ) {
		$options = array(
			'return' => true,
		);
		$output  = WP_CLI::runcommand( "import $file --authors=create", $options );

		return $output;
	}

	/**
	 * Exports all Pages from the Staging site.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_export_all_staging_site_pages( $args, $assoc_args ) {
		$output_dir = isset( $assoc_args['output-dir'] ) ? $assoc_args['output-dir'] : null;
		if ( is_null( $output_dir ) ) {
			WP_CLI::error( 'Invalid output dir.' );
		}

		WP_CLI::line( sprintf( 'Exporting all Staging site Pages to %s ...', $output_dir . '/' . self::STAGING_PAGES_EXPORT_FILE ) );

		wp_reset_postdata();
		$post_ids = $this->posts_logic->get_all_posts_ids( 'page' );
		$this->migrator_export_posts( $post_ids, $output_dir, self::STAGING_PAGES_EXPORT_FILE );

		WP_CLI::success( 'Done.' );
	}

	/**
	 * Updates titles of given pages with a prefix, and sets their statuses to drafts.
	 *
	 * @param array $page_ids IDs of pages.
	 */
	public function preserve_unique_pages_from_live_as_drafts( $page_ids ) {
		foreach ( $page_ids as $id ) {
			$page              = get_post( $id );
			$page->post_title  = '[Live] ' . $page->post_title;
			$page->post_name  .= '-live_migrated';
			$page->post_status = 'draft';
			wp_update_post( $page );
		}
	}

	/**
	 * Callable for import-posts command.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function cmd_import_staging_site_pages( $args, $assoc_args ) {
		$input_dir = isset( $assoc_args['input-dir'] ) ? $assoc_args['input-dir'] : null;
		if ( is_null( $input_dir ) || ! file_exists( $input_dir ) ) {
			WP_CLI::error( 'Invalid input dir.' );
		}

		// The following Page migration strategy aims to achieve two things:
		// - to keep all the Pages from the Staging site,
		// - to keep only the unique (new, different) pages from Live, but import them as Drafts, with their titles and permalinks updated.

		WP_CLI::line( 'Importing all Pages from Staging site and new pages from the Live site...' );

		// First delete those Live Pages which we're not keeping.
		WP_CLI::line( 'First clearing all Live site Pages which will be imported from Staging site to prevent duplicates...' );
		$this->delete_duplicate_live_site_pages();

		// Get IDs of the unique Live Pages which we are keeping.
		wp_reset_postdata();
		$pages_live_ids = $this->posts_logic->get_all_posts_ids( 'page' );

		// Update the remaining Live Pages which we are keeping: save them as drafts, and change their permalinks and titles.
		if ( count( $pages_live_ids ) > 0 ) {
			$this->preserve_unique_pages_from_live_as_drafts( $pages_live_ids );
			wp_cache_flush();
		} else {
			WP_CLI::warning( 'No unique Live site Pages found, continuing.' );
		}

		// Import Pages from Staging site.
		$file = $input_dir . '/' . self::STAGING_PAGES_EXPORT_FILE;
		WP_CLI::line( 'Importing Staging site Pages from  ' . $file . ' (uses `wp import` and might take a bit longer) ...' );
		$this->import_posts( $file );

		wp_cache_flush();

		WP_CLI::success( 'Done.' );
	}

	/**
	 * Deletes all Live site Pages which will not be preserved, since they'll be imported from the Staging site anyway.
	 */
	public function delete_duplicate_live_site_pages() {
		$post_ids = $this->get_all_pages_duplicates_on_staging();
		if ( empty( $post_ids ) ) {
			WP_CLI::success( 'No Pages found.' );
			return;
		}

		$progress = \WP_CLI\Utils\make_progress_bar( 'Deleting ' . count( $post_ids ) . ' pages...', count( $post_ids ) );
		foreach ( $post_ids as $id ) {
			$progress->tick();
			wp_delete_post( $id, true );
		}
		$progress->finish();

		wp_reset_postdata();
		wp_cache_flush();
	}

	/**
	 * Gets all pages which were exported from Staging and that are also found in current wp_posts.
	 *
	 * @return array|void Array of page IDs.
	 */
	public function get_all_pages_duplicates_on_staging() {
		global $wpdb;

		$ids = array();
		wp_reset_postdata();

		$staging_posts_table = 'staging_' . $wpdb->prefix . 'posts';
		$posts_table         = $wpdb->prefix . 'posts';
		// Notes on joining: post_content will have different hostnames; guid is misleading (new page on live would get the same guid).
		$sql = "SELECT wp.ID FROM {$posts_table} wp
			JOIN {$staging_posts_table} swp
				ON swp.post_name = wp.post_name
				AND swp.post_title = wp.post_title
				AND swp.post_status = wp.post_status
			WHERE wp.post_type = 'page';";
		// phpcs:ignore -- false positive, all params are fully sanitized.
		$results = $wpdb->get_results( $sql );

		if ( empty( $results ) ) {
			return;
		}

		foreach ( $results as $result ) {
			$ids[] = $result->ID;
		}
		return $ids;
	}

	/**
	 * Callable for remove-export-postmeta command.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function cmd_delete_export_postmeta() {
		WP_CLI::line( sprintf( 'Deleting %s postmeta from all ther posts and pages...', self::META_KEY_ORIGINAL_ID ) );

		$args  = array(
			'post_type'   => array( 'post', 'page' ),
			'post_status' => 'publish',
			'meta_query'  => array(
				array(
					'key' => self::META_KEY_ORIGINAL_ID,
				),
			),
		);
		$query = new \WP_Query( $args );
		$posts = $query->posts;

		foreach ( $posts as $post ) {
			delete_post_meta( $post->ID, self::META_KEY_ORIGINAL_ID );
		}

		WP_CLI::success( 'Done.' );
	}

	/**
	 * When exporting objects, the PostsMigrator sets PostsMigrator::META_KEY_ORIGINAL_ID meta key with the ID they had at the
	 * time. This function gets the new/current ID which changed when they were imported.
	 *
	 * @param $original_post_id ID.
	 *
	 * @return |null
	 */
	public function get_current_post_id_from_original_post_id( $original_post_id ) {
		global $wpdb;

		$new_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID
			FROM {$wpdb->prefix}posts p
			JOIN {$wpdb->prefix}postmeta pm ON pm.post_id = p.ID
			AND pm.meta_key = '%s'
			AND pm.meta_value = %d ; ",
				self::META_KEY_ORIGINAL_ID,
				$original_post_id
			)
		);

		return isset( $new_id ) ? $new_id : null;
	}

	/**
	 * Gets a list of posts that have more than a certain number of revisions
	 *
	 * @param array $args The positional arguments passed to the command.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function cmd_find_posts_by_number_of_revisions( $args, $assoc_args ) {
		$number = intval( $args[0] );
		if ( ! $number ) {
			WP_CLI::error( 'Invalid argument for Number of revisions. Integer expected.' );
		}
		$format  = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		$results = $this->posts_logic->get_posts_by_number_of_revisions( $number );
		WP_CLI\Utils\format_items( $format, $results, [ 'post_ID', 'num_of_revisions' ] );
	}


	/**
	 * Callback for clear-post-revisions command
	 *
	 * @param array $positional_args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function cmd_clear_post_revisions( $positional_args, $assoc_args ) {
		$post_id = intval( $assoc_args['post-id'] );
		$number  = intval( $assoc_args['keep'] );
		if ( ! $number || ! $post_id ) {
			WP_CLI::error( 'Invalid argument for Number of revisions or Post ID. Integer are expected in both cases.' );
		}
		$chunk_size = intval( $assoc_args['chunk-size'] );
		if ( ! $chunk_size ) {
			WP_CLI::error( 'Invalid argument for Chunk size. Integer is expected.' );
		}
		$sleep = intval( $assoc_args['sleep'] );
		if ( ! $sleep ) {
			WP_CLI::error( 'Invalid argument for Sleep. Integer is expected.' );
		}
		$slepp_micro = $sleep * 1000;
		$dry_run     = isset( $assoc_args['dry-run'] );

		$current_revisions = $this->posts_logic->get_post_number_of_revisions( $post_id );

		if ( $dry_run ) {
			WP_CLI::log( "Post $post_id currently has $current_revisions revisions" );
			$steps         = ceil( ( $current_revisions - $number ) / $chunk_size );
			$seconds_slept = ( $steps * $sleep ) / 1000;
			WP_CLI::log( "In steps of $chunk_size, it would take $steps operations to finish" );
			WP_CLI::log( "Since we are sleeping $sleep miliseconds per operation, this would take at least $seconds_slept seconds to finish" );
			WP_CLI::halt( 1 );
		}

		WP_CLI::debug( "Post $post_id currently has $current_revisions revisions" );
		$total_deleted = 0;

		while ( $current_revisions > $number ) {
			$chunk = min( $chunk_size, $current_revisions - $number );
			WP_CLI::debug( "Preparing to delete $chunk revisions" );
			$deleted = $this->posts_logic->delete_post_revisions( $post_id, $chunk );
			if ( $deleted !== $chunk ) {
				WP_CLI::error( "An error has occurred when trying to delete $chunk revisions", false );
				if ( false === $deleted ) {
					WP_CLI::error( 'Database error' );
				} else {
					WP_CLI::error( "$deleted revisions deleted" );
				}
			}
			$total_deleted += $deleted;
			WP_CLI::log( "Deleted $chunk revisions" );
			$current_revisions = $this->posts_logic->get_post_number_of_revisions( $post_id );
			WP_CLI::log( "Post $post_id currently has $current_revisions revisions" );
			if ( $sleep > 0 ) {
				WP_CLI::debug( "Sleeping for $sleep miliseconds" );
				usleep( $slepp_micro );
			}
		}

		WP_CLI::success( "Process finished! $total_deleted revisions deleted!" );
	}

	/**
	 * Callback for clear-revisions command
	 *
	 * @param array $positional_args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function cmd_clear_revisions( $positional_args, $assoc_args ) {
		$number = intval( $assoc_args['keep'] );
		if ( ! $number ) {
			WP_CLI::error( 'Invalid argument for Number of revisions. Integer expected.' );
		}
		$posts_result = WP_CLI::launch_self( 'newspack-content-migrator find-posts-by-number-of-revisions', [ $number ], [ 'format' => 'json' ], true, true );
		$posts        = json_decode( $posts_result->stdout );
		foreach ( $posts as $post ) {
			WP_CLI::log( '' );
			WP_CLI::log( "Processing post {$post->post_ID}" );
			$assoc_args['post-id'] = $post->post_ID;
			$this->cmd_clear_post_revisions( $positional_args, $assoc_args );
		}
	}

	/**
	 * Callable for `newspack-content-migrator fix-dupe-slugs`.
	 *
	 * @param array $positional_args Positional arguments.
	 * @param array $assoc_args      Associative arguments.
	 * @return void
	 */
	public function cmd_fix_dupe_slugs( $positional_args, $assoc_args ) {
		$log_file = 'fixed_dupe_slugs.log';

		WP_CLI::log( 'Fixing duplicate slugs for posts and pages...' );
		$updated = $this->posts_logic->fix_duplicate_slugs( [ 'post', 'page' ] );

		if ( ! empty( $updated ) ) {
			// Save to log.
			foreach ( $updated as $update ) {
				$this->logger->log(
					$log_file,
					json_encode( $update ),
					$to_cli = false
				);
			}

			WP_CLI::success( sprintf( 'List of post IDs that had their post_name updated was saved to %s .', $log_file ) );
		}

		WP_CLI::success( 'Done 👍' );
	}

	/**
	 * Callable for `newspack-content-migrator hide-featured-images`.
	 *
	 * @param array $positional_args Positional arguments.
	 * @param array $assoc_args      Associative arguments.
	 * @return void
	 */
	public function cmd_hide_featured_images( $positional_args, $assoc_args ) {
		$log_file = 'hide_featured_images.log';

		$posts_per_batch = isset( $assoc_args['posts-per-batch'] ) ? intval( $assoc_args['posts-per-batch'] ) : 10000;
		$batch           = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : 1;
		$post_ids        = isset( $assoc_args['post-ids'] ) ? explode( ',', $assoc_args['post-ids'] ) : null;
		$category_id     = isset( $assoc_args['category-id'] ) ? intval( $assoc_args['category-id'] ) : null;

		if ( ! $post_ids && ! $category_id ) {
			WP_CLI::error( 'Please set at least one of the two parameters (post_ids, category_id) to not run this command on all the posts.' );
		}

		$meta_query = [
			[
				'key'     => '_newspack_featured_image_is_hidden',
				'compare' => 'NOT EXISTS',
			],
		];

		$query_base_params = [
			'post_type'   => 'post',
			'post_status' => 'any',
			'fields'      => 'ids',
		];

		if ( $post_ids ) {
			$query_base_params['post__in'] = $post_ids;
		}

		if ( $category_id ) {
			$query_base_params['cat'] = $category_id;
		}

		$total_query = new \WP_Query(
			array_merge(
				$query_base_params,
				[
					'posts_per_page' => -1,
					'no_found_rows'  => true,
					'meta_query'     => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				]
			)
		);

		WP_CLI::warning( sprintf( 'Total posts: %d', count( $total_query->posts ) ) );

		$query = new \WP_Query(
			array_merge(
				$query_base_params,
				[
					'orderby'        => 'ID',
					'paged'          => $batch,
					'posts_per_page' => $posts_per_batch,
					'meta_query'     => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				]
			)
		);

		$posts = $query->get_posts();

		foreach ( $posts as $post_id ) {
			update_post_meta( $post_id, 'newspack_featured_image_position', 'hidden' );
			update_post_meta( $post_id, '_newspack_featured_image_is_hidden', true );
			$this->logger->log(
				$log_file,
				sprintf( 'Featured image hidden for the post %d', $post_id ),
				true
			);
		}

		wp_cache_flush();
	}

	/**
	 * Generate Newspack Iframe Block code from URL.
	 *
	 * @param string $src Iframe source URL.
	 * @return string Iframe block code to be add to the post content.
	 */
	public function embed_iframe_block_from_src( $src ) {
		return '<!-- wp:newspack-blocks/iframe {"src":"' . $src . '"} /-->';
	}
}
