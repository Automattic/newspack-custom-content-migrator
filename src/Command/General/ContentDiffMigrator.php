<?php
/**
 * Content Diff migrator exports and imports the content differential from one site to the local site.
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Command\General;

use Newspack\MigrationTools\Command\WpCliCommandTrait;
use NewspackCustomContentMigrator\Command\RegisterCommandInterface;
use NewspackCustomContentMigrator\Logic\ContentDiffMigrator as ContentDiffMigratorLogic;
use NewspackCustomContentMigrator\Utils\PHP as PHPUtil;
use WP_CLI;

/**
 * Content Diff Migrator CLI commands class.
 *
 * @package NewspackCustomContentMigrator\Command\General
 */
class ContentDiffMigrator implements RegisterCommandInterface {

	use WpCliCommandTrait;

	const LOG_IDS_CSV                           = 'content-diff__new-ids-csv.log';
	const LOG_IDS_MODIFIED                      = 'content-diff__modified-ids.log';
	const LOG_IMPORTED_POST_IDS                 = 'content-diff__imported-post-ids.log';
	const LOG_UPDATED_PARENT_IDS                = 'content-diff__updated-parent-ids.log';
	const LOG_DELETED_MODIFIED_IDS              = 'content-diff__deleted-modified-ids.log';
	const LOG_UPDATED_FEATURED_IMAGES_IDS       = 'content-diff__updated-feat-imgs-ids.log';
	const LOG_UPDATED_BLOCKS_IDS                = 'content-diff__wp-blocks-ids-updates.log';
	const LOG_ERROR                             = 'content-diff__err.log';
	const LOG_RECREATED_HIERARCHICAL_TAXONOMIES = 'content-diff__recreated_hierarchical_taxonomies.log';
	const LOG_INSERTED_WP_USERS                 = 'content-diff__inserted_wp_users.log';

	/**
	 * Content Diff logic class.
	 *
	 * @var ContentDiffMigratorLogic Logic.
	 */
	private static ContentDiffMigratorLogic $logic;

	/**
	 * Prefix of tables from the live DB, which are imported next to local WP tables.
	 *
	 * @var null|string Live DB tables prefix.
	 */
	private $live_table_prefix;

	/**
	 * General error log file.
	 *
	 * @var null|string Full path to file.
	 */
	private $log_error;

	/**
	 * Log containing recreated categories term_ids.
	 *
	 * @var null|string Full path to file.
	 */
	private $log_recreated_hierarchical_taxonomies;
	
	/**
	 * Log containing inserted WP_User IDs.
	 *
	 * @var null|string Full path to file.
	 */
	private $log_inserted_wp_users;

	/**
	 * Log containing imported post IDs.
	 *
	 * @var null|string Full path to file.
	 */
	private $log_imported_post_ids;

	/**
	 * Log containing posts ID which had their post_parent IDs updated.
	 *
	 * @var null|string Full path to file.
	 */
	private $log_updated_posts_parent_ids;

	/**
	 * Log containing post IDs which were deleted and reimported.
	 *
	 * @var null|string Full path to file.
	 */
	private $log_deleted_modified_ids;

	/**
	 * Log containing attachment IDs which were updated to new IDs if used as attachment images.
	 *
	 * @var null|string Full path to file.
	 */
	private $log_updated_featured_imgs_ids;

	/**
	 * Log containing post IDs which had their content updated with new IDs in blocks syntax.
	 *
	 * @var null|string Full path to file.
	 */
	private $log_updated_blocks_ids;


	/**
	 * {@inheritDoc}
	 */
	public static function register_commands(): void {
		WP_CLI::add_command(
			'newspack-content-migrator content-diff-search-new-content-on-live',
			self::get_command_closure('cmd_search_new_content_on_live' ),
			[
				'shortdesc' => 'Searches for new posts existing in the Live site tables and not in the local site tables, and exports the IDs to a file.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'export-dir',
						'description' => 'Folder to export the IDs to.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'live-table-prefix',
						'description' => 'Live site table prefix.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-types-csv',
						'description' => 'CSV of all the post types to scan, no extra spaces. E.g. --post-types-csv=post,page,attachment,some_cpt. Default value is post,attachment.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator content-diff-migrate-live-content',
			self::get_command_closure('cmd_migrate_live_content' ),
			[
				'shortdesc' => 'Migrates content from Live site tables to local site tables.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'import-dir',
						'description' => 'Folder containing the file with list of IDs to migrate.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'live-table-prefix',
						'description' => 'Live site table prefix.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'custom-taxonomies-csv',
						'description' => 'CSV of all the taxonomies to import, no extra spaces. NOTE, if you are modifying this list, make sure to include category and post_tag or else these will not be migrated. E.g. --custom-taxonomies-csv=post_tag,category,brand,custom_taxonomy.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator content-diff-fix-image-ids-in-post-content',
			self::get_command_closure('cmd_fix_image_ids_in_post_content' ),
			[
				'shortdesc' => 'Standalone command which fixes attachment IDs in Block content. It does so by loading all the posts, goes through post_content and gets all the WP Blocks which use attachments IDs (see \NewspackCustomContentMigrator\Logic\ContentDiffMigrator::update_blocks_ids), then it takes every single attachment file and checks if its attachment ID has changed, and if it has it updates the IDs.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'post-id-from',
						'description' => 'Optional. Post ID range minimum.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-id-to',
						'description' => 'Optional. Post ID range maximum.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'local-hostname-aliases-csv',
						'description' => "Optional. CSV of image URL hostnames to be used as local hostname aliases when searching for image attachment files. If, for example, the site uses S3, and some images' src hostnames use newspack-pubname.s3.amazonaws.com in URL hostnames, we should add this AWS hostname to the list here, to treat these URLs as local hostnames when searching for the files' attachment IDs in local DB -- in other words, the search for attachment ID will substitute these aliases for actual local hostname e.g. 'host.com' and search by a local URL instead.",
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator display-collations-comparison',
			self::get_command_closure('cmd_compare_collations_of_live_and_core_wp_tables' ),
			[
				'shortdesc' => 'Display a table comparing collations of Live and Core WP tables.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'live-table-prefix',
						'description' => 'Live site table prefix.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'skip-tables',
						'description' => 'CSV of tables to skip checking for collation.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'different-collations-only',
						'description' => 'This flag determines to only display tables with differing collations.',
						'optional'    => true,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator correct-collations-for-live-wp-tables',
			self::get_command_closure('cmd_correct_collations_for_live_wp_tables' ),
			[
				'shortdesc' => 'This command will handle the necessary operations to match collations across Live and Core WP tables',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'live-table-prefix',
						'description' => 'Live site table prefix.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'mode',
						'description' => 'Determines how large the SQL insert transactions are and the latency between them.',
						'optional'    => true,
						'default'     => 'generous',
						'options'     => [
							'aggressive',
							'generous',
							'cautious',
							'calm',
						],
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'skip-tables',
						'description' => 'Skip checking a particular set of tables from the collation checks.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'backup-table-prefix',
						'description' => 'Prefix to use when backing up the Live tables.',
						'optional'    => true,
						'default'     => 'bak_',
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator content-diff-update-featured-images-ids',
			self::get_command_closure('cmd_update_feat_images_ids' ),
			[
				'shortdesc' => 'A helper/fixer command which can be run on any site to pick up and update leftover featured image IDs. Fix to a previous bug that ignored some _thumbnail_ids. It automatically picks up "old_attachment_ids"=>"new_attachment_ids" from DB and updates those (unless provided with an optional --attachment-ids-json-file).',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'export-dir',
						'description' => 'Path to where log will be written.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'attachment-ids-json-file',
						'description' => 'Optional. Path to a JSON encoded array where keys are old attachment IDs and values are new attachment IDs. If provided, will only update these _thumbnail_ids, and only on those posts which were imported by the Content Diff.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Optional. Will not make changes to DB. And instead of writing to log file will just output changes to console.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Callable for `newspack-content-migrator content-diff-update-featured-images-ids`.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_update_feat_images_ids( $pos_args, $assoc_args ) {

		// Get optional JSON list of "old_attachment_ids"=>"new_attachment_ids"mapping. If not provided, will load from DB, which is recommended.
		$attachment_ids_map = null;
		if ( isset( $assoc_args['attachment-ids-json-file'] ) && file_exists( $assoc_args['attachment-ids-json-file'] ) ) {
			$attachment_ids_map = json_decode( file_get_contents( $assoc_args['attachment-ids-json-file'] ), true );
			if ( empty( $attachment_ids_map ) ) {
				WP_CLI::error( 'No attachment IDs found in the JSON file.' );
			}
		}

		// Get export dir param. Will save a detailed log there.
		$export_dir = $assoc_args['export-dir'];
		if ( ! file_exists( $export_dir ) ) {
			$made = mkdir( $export_dir, 0777, true ); // phpcs:ignore -- We allow creating this directory for logs.
			if ( false == $made ) {
				WP_CLI::error( "Could not create export directory $export_dir ." );
			}
		}

		// Get dry-run param.
		$dry_run = isset( $assoc_args['dry-run'] ) ? true : false;

		// If no attachment IDs map was passed, get it from the DB.
		if ( is_null( $attachment_ids_map ) ) {
			// Get all attachment old and new IDs from DB.
			$attachment_ids_map = self::$logic->get_imported_attachment_id_mapping_from_db();

			if ( ! $attachment_ids_map ) {
				WP_CLI::warning( 'No attachment IDs found in the DB. No changes made.' );
				exit;
			}
		}

		// Timestamp the log.
		$ts       = gmdate( 'Y-m-d h:i:s a', time() );
		$log      = 'content-diff__updated-feat-imgs-helper.log';
		$log_path = $export_dir . '/' . $log;
		$this->log( $log_path, sprintf( 'Starting %s.', $ts ) );

		// Get local Post IDs which were imported using Content Diff (these posts will have the ContentDiffMigratorLogic::SAVED_META_LIVE_POST_ID postmeta).
		$imported_post_ids_mapping = self::$logic->get_imported_post_id_mapping_from_db();
		$imported_post_ids         = array_values( $imported_post_ids_mapping );

		// Update attachment IDs.
		self::$logic->update_featured_images( $imported_post_ids, $attachment_ids_map, $log_path, $dry_run );

		wp_cache_flush();
		WP_CLI::success( sprintf( 'Done. Log saved to %s', $log_path ) );
	}

	/**
	 * Callable for `newspack-content-migrator content-diff-search-new-content-on-live`.
	 *
	 * @param array $args       CLI args.
	 * @param array $assoc_args CLI assoc args.
	 */
	public function cmd_search_new_content_on_live( $args, $assoc_args ) {
		$export_dir        = $assoc_args['export-dir'] ?? false;
		$live_table_prefix = $assoc_args['live-table-prefix'] ?? false;
		$post_types        = isset( $assoc_args['post-types-csv'] ) ? explode( ',', $assoc_args['post-types-csv'] ) : [ 'post', 'attachment' ];
		// Disable CAP's "guest-author" CPT.
		if ( in_array( 'guest-author', $post_types ) ) {
			WP_CLI::error( "CAP's 'guest-author' CPT is not supported at this point as CAP data requires a dedicated migrator for its complexity and special cases. Please remove 'guest-author' from the list of CPTs to migrate and re-run the command." );
		}

		global $wpdb;
		try {
			$this->validate_db_tables( $live_table_prefix, [ 'options' ] );
		} catch ( \RuntimeException $e ) {
			WP_CLI::warning( $e->getMessage() );
			WP_CLI::line( "Now running command `newspack-content-migrator correct-collations-for-live-wp-tables --live-table-prefix={$live_table_prefix} --mode=generous --skip-tables=options` ..." );
			$this->cmd_correct_collations_for_live_wp_tables(
				[],
				[
					'live-table-prefix' => $live_table_prefix,
					'mode'              => 'generous',
					'skip-tables'       => 'options',
				]
			);
		}

		// Search distinct Post types in live DB.
		$live_table_prefix_escaped = esc_sql( $live_table_prefix );
		// phpcs:ignore -- table prefix string value was escaped.
		$cpts_live = $wpdb->get_col( "SELECT DISTINCT( post_type ) FROM {$live_table_prefix_escaped}posts ;" );
		WP_CLI::log( sprintf( 'These unique Post types exist in live DB:%s', "\n- " . implode( "\n- ", $cpts_live ) ) );

		// Validate selected post types.
		array_walk(
			$post_types,
			function ( &$v, $k ) use ( $cpts_live ) {
				if ( ! in_array( $v, $cpts_live ) ) {
					WP_CLI::error( sprintf( 'Post type %s not found in live DB.', $v ) );
				}
			}
		);

		// Get list of post types except attachments.
		$post_types_non_attachments = $post_types;
		$key                        = array_search( 'attachment', $post_types_non_attachments );
		if ( false !== $key ) {
			unset( $post_types_non_attachments[ $key ] );
			$post_types_non_attachments = array_values( $post_types_non_attachments );
		}

		WP_CLI::log( sprintf( 'Now searching live DB for new Post types %s ...', implode( ', ', $post_types ) ) );
		try {
			WP_CLI::log( sprintf( 'Querying %s types...', implode( ',', $post_types_non_attachments ) ) );
			$results_live_posts  = self::$logic->get_posts_rows_for_content_diff( $live_table_prefix . 'posts', $post_types_non_attachments, [ 'publish', 'future', 'draft', 'pending', 'private' ] );
			$results_local_posts = self::$logic->get_posts_rows_for_content_diff( $wpdb->prefix . 'posts', $post_types_non_attachments, [ 'publish', 'future', 'draft', 'pending', 'private' ] );

			WP_CLI::log( sprintf( 'Fetched %s total from live site. Searching new ones...', count( $results_live_posts ) ) );
			$new_live_ids = self::$logic->filter_new_live_ids( $results_live_posts, $results_local_posts );
			WP_CLI::success( sprintf( '%d new IDs found.', count( $new_live_ids ) ) );

			WP_CLI::log( 'Searching for records more recently modified on live...' );
			$modified_live_ids = self::$logic->filter_modified_live_ids( $results_live_posts, $results_local_posts );
			WP_CLI::success( sprintf( '%d modified IDs found.', count( $modified_live_ids ) ) );

			WP_CLI::log( 'Querying attachments...' );
			$results_live_attachments  = self::$logic->get_posts_rows_for_content_diff( $live_table_prefix . 'posts', [ 'attachment' ], [ 'inherit' ] );
			$results_local_attachments = self::$logic->get_posts_rows_for_content_diff( $wpdb->prefix . 'posts', [ 'attachment' ], [ 'inherit' ] );

			WP_CLI::log( sprintf( 'Fetched %s total from live site. Searching new ones...', count( $results_live_attachments ) ) );
			$new_live_attachment_ids = self::$logic->filter_new_live_ids( $results_live_attachments, $results_local_attachments );
			$new_live_ids            = array_merge( $new_live_ids, $new_live_attachment_ids );
			WP_CLI::success( sprintf( '%d new IDs found.', count( $new_live_attachment_ids ) ) );

		} catch ( \Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		// Save logs and output results.
		if ( count( $new_live_ids ) > 0 ) {
			$file = $export_dir . '/' . self::LOG_IDS_CSV;
			file_put_contents( $file, implode( ',', $new_live_ids ) );
			WP_CLI::success( sprintf( 'New IDs exported to %s', $file ) );
		}
		if ( count( $modified_live_ids ) > 0 ) {
			$file_modified = $export_dir . '/' . self::LOG_IDS_MODIFIED;
			if ( file_exists( $file_modified ) ) {
				unlink( $file_modified );
			}
			foreach ( $modified_live_ids as $modified_live_id_pair ) {
				$this->log(
					$file_modified,
					json_encode(
						[
							'live_id'  => $modified_live_id_pair['live_id'],
							'local_id' => $modified_live_id_pair['local_id'],
						]
					)
				);
			}
			WP_CLI::success( sprintf( 'Modified IDs exported to %s', $file_modified ) );
		}
	}

	/**
	 * Callable for `newspack-content-migrator content-diff-migrate-live-content`.
	 *
	 * @param array $args       CLI args.
	 * @param array $assoc_args CLI assoc args.
	 */
	public function cmd_migrate_live_content( $args, $assoc_args ) {
		global $wpdb;

		$import_dir            = $assoc_args['import-dir'] ?? false;
		$live_table_prefix     = $assoc_args['live-table-prefix'] ?? false;
		$taxonomies_to_migrate = isset( $assoc_args['custom-taxonomies-csv'] ) ? explode( ',', $assoc_args['custom-taxonomies-csv'] ) : [ 'category', 'post_tag' ];

		// Validate all params.
		$file_ids_csv      = $import_dir . '/' . self::LOG_IDS_CSV;
		$file_ids_modified = $import_dir . '/' . self::LOG_IDS_MODIFIED;
		if ( ! file_exists( $file_ids_csv ) ) {
			WP_CLI::error( sprintf( 'File %s not found.', $file_ids_csv ) );
		}
		$all_live_posts_ids           = explode( ',', trim( file_get_contents( $file_ids_csv ) ) );
		$all_live_modified_posts_data = file_exists( $file_ids_modified ) ? $this->get_data_from_log( $file_ids_modified, [ 'live_id', 'local_id' ] ) : [];
		if ( empty( $all_live_posts_ids ) ) {
			WP_CLI::error( sprintf( 'File %s does not contain valid CSV IDs.', $file_ids_csv ) );
		}
		// Disable CAP's "author" taxonomy.
		if ( in_array( 'author', $taxonomies_to_migrate ) ) {
			WP_CLI::error( "CAP's 'author' taxonomy is not supported at this point as CAP data requires a dedicated migrator for its complexity and special cases. Please remove 'author' from the list of taxonomies to migrate and re-run the command." );
		}

		// In case some custom taxonomies were provided, but category or post_tag were not among those, warn the user that they won't be migrated and ask for confirmation to continue.
		if ( ! empty( $assoc_args['custom-taxonomies-csv'] ) ) {
			if ( ! in_array( 'category', $taxonomies_to_migrate ) ) {
				WP_CLI::confirm( 'Warning, category was not given in --custom-taxonomies-csv argument and so categories will not be migrated. Continue?' );
			}
			if ( ! in_array( 'post_tag', $taxonomies_to_migrate ) ) {
				WP_CLI::confirm( 'Warning, post_tag was not given in --custom-taxonomies-csv argument and so tags will not be migrated. Continue?' );
			}
		}

		// Validate DBs.
		try {
			$this->validate_db_tables( $live_table_prefix, [ 'options' ] );
		} catch ( \RuntimeException $e ) {
			WP_CLI::warning( $e->getMessage() );
			WP_CLI::line( "Now running command `newspack-content-migrator correct-collations-for-live-wp-tables --live-table-prefix={$live_table_prefix} --mode=generous --skip-tables=options` ..." );
			$this->cmd_correct_collations_for_live_wp_tables(
				[],
				[
					'live-table-prefix' => $live_table_prefix,
					'mode'              => 'generous',
					'skip-tables'       => 'options',
				]
			);
		}

		// Set constants.
		$this->live_table_prefix                     = $live_table_prefix;
		$this->log_error                             = $import_dir . '/' . self::LOG_ERROR;
		$this->log_recreated_hierarchical_taxonomies = $import_dir . '/' . self::LOG_RECREATED_HIERARCHICAL_TAXONOMIES;
		$this->log_inserted_wp_users                 = $import_dir . '/' . self::LOG_INSERTED_WP_USERS;
		$this->log_imported_post_ids                 = $import_dir . '/' . self::LOG_IMPORTED_POST_IDS;
		$this->log_updated_posts_parent_ids          = $import_dir . '/' . self::LOG_UPDATED_PARENT_IDS;
		$this->log_deleted_modified_ids              = $import_dir . '/' . self::LOG_DELETED_MODIFIED_IDS;
		$this->log_updated_featured_imgs_ids         = $import_dir . '/' . self::LOG_UPDATED_FEATURED_IMAGES_IDS;
		$this->log_updated_blocks_ids                = $import_dir . '/' . self::LOG_UPDATED_BLOCKS_IDS;

		// Timestamp the logs.
		$ts = gmdate( 'Y-m-d h:i:s a', time() );
		$this->log( $this->log_error, sprintf( 'Starting %s.', $ts ) );
		$this->log( $this->log_recreated_hierarchical_taxonomies, sprintf( 'Starting %s.', $ts ) );
		$this->log( $this->log_imported_post_ids, sprintf( 'Starting %s.', $ts ) );
		$this->log( $this->log_updated_posts_parent_ids, sprintf( 'Starting %s.', $ts ) );
		$this->log( $this->log_deleted_modified_ids, sprintf( 'Starting %s.', $ts ) );
		$this->log( $this->log_updated_featured_imgs_ids, sprintf( 'Starting %s.', $ts ) );
		$this->log( $this->log_updated_blocks_ids, sprintf( 'Starting %s.', $ts ) );

		// List all the custom taxonomies which exist in Live DB for user's overview.
		// phpcs:ignore -- table prefix string value was escaped.
		$live_table_prefix_escaped = esc_sql( $live_table_prefix );
		$live_taxonomies = $wpdb->get_col( "SELECT DISTINCT( taxonomy ) FROM {$live_table_prefix_escaped}term_taxonomy ;" ); // phpcs:ignore -- table prefix string value was escaped.
		WP_CLI::log( sprintf( 'Here is a list of all the taxonomies which exist in the live DB:%s', "\n- " . implode( "\n- ", $live_taxonomies ) ) );

		// Before we create hierarchical taxonomies, let's make sure all hierarchical taxonomies have valid parents. If they don't they should be fixed first.
		WP_CLI::log( sprintf( 'Validating all the taxonomies which will be migrated: %s', "\n- " . implode( "\n- ", $taxonomies_to_migrate ) ) );
		$this->validate_hierarchical_taxonomies( $taxonomies_to_migrate, $live_taxonomies );

		// Recreate taxonomies but leave out (unused) tags.
		$taxonomies_to_recreate = array_diff( $taxonomies_to_migrate, [ 'post_tag' ] );
		WP_CLI::log( sprintf( 'Recreating taxonomies %s ...', "\n- " . implode( "\n- ", $taxonomies_to_recreate ) ) );
		$hierarchical_taxonomy_term_id_updates = $this->recreate_hierarchical_taxonomies( $taxonomies_to_recreate );

		// Migrate all WP_Users (for WooComm data).
		WP_CLI::log( 'Migrating all WP_Users...' );
		$this->migrate_all_users( $live_table_prefix );

		if ( ! empty( $all_live_modified_posts_data ) ) {
			WP_CLI::log( sprintf( 'Deleting %s modified posts before they are reimported...', count( $all_live_modified_posts_data ) ) );
		}

		/**
		 * Map of modified Post IDs.
		 *
		 * @var array $modified_ids_map Keys are old Live IDs, values are new local IDs.
		 */
		$modified_ids_map   = $this->get_ids_from_modified_posts_log( $all_live_modified_posts_data );
		$modified_live_ids  = array_keys( $modified_ids_map );
		$modified_local_ids = array_values( $modified_ids_map );
		/**
		 * Importing modified IDS. Different kind of data could have been updated for a post (content, author, featured image, etc.),
		 * so the easiest way to refresh them is to:
		 * 1. delete the existing post,
		 * 2. reimport it
		 */
		// Delete outdated local Posts.
		$this->delete_local_posts( $modified_local_ids );
		$this->log( $this->log_deleted_modified_ids, implode( ',', $modified_local_ids ) );
		// Merge modified posts IDs with $all_live_posts_ids for reimport.
		$all_live_posts_ids = array_merge( $all_live_posts_ids, $modified_live_ids );

		WP_CLI::log( sprintf( 'Importing %d objects, hold tight...', count( $all_live_posts_ids ) ) );
		$imported_posts_data = $this->import_posts( $all_live_posts_ids, $hierarchical_taxonomy_term_id_updates );

		WP_CLI::log( 'Updating Post parent IDs...' );
		$this->update_post_parent_ids( $all_live_posts_ids, $imported_posts_data );

		WP_CLI::log( 'Updating Featured images IDs...' );
		$this->update_featured_image_ids( $imported_posts_data );

		WP_CLI::log( 'Updating attachment IDs in block content...' );
		$this->update_attachment_ids_in_blocks( $imported_posts_data );

		WP_CLI::success( 'All done migrating content! 🙌 ' );

		// Output info about all available logs.
		$cli_output_logs_report = [];
		if ( file_exists( $this->log_error ) ) {
			$cli_output_logs_report[] = sprintf( '%s - errors', $this->log_error );
		}
		if ( file_exists( $this->log_deleted_modified_ids ) ) {
			$cli_output_logs_report[] = sprintf( '%s - all modified post IDs', $this->log_deleted_modified_ids );
		}
		if ( file_exists( $this->log_imported_post_ids ) ) {
			$cli_output_logs_report[] = sprintf( '%s - all imported IDs', $this->log_imported_post_ids );
		}
		if ( file_exists( $this->log_recreated_hierarchical_taxonomies ) ) {
			$cli_output_logs_report[] = sprintf( '%s - created taxonomies', $this->log_recreated_hierarchical_taxonomies );
		}
		if ( file_exists( $this->log_inserted_wp_users ) ) {
			$cli_output_logs_report[] = sprintf( '%s - created WP_Users', $this->log_inserted_wp_users );
		}
		if ( file_exists( $this->log_updated_blocks_ids ) ) {
			$cli_output_logs_report[] = sprintf( '%s - detailed blocks IDs post content replacements', $this->log_updated_blocks_ids );
		}
		if ( file_exists( $this->log_updated_posts_parent_ids ) ) {
			$cli_output_logs_report[] = sprintf( '%s - post_parent IDs updates', $this->log_updated_posts_parent_ids );
		}
		if ( file_exists( $this->log_updated_featured_imgs_ids ) ) {
			$cli_output_logs_report[] = sprintf( '%s - featured image IDs updates', $this->log_updated_featured_imgs_ids );
		}
		if ( ! empty( $cli_output_logs_report ) ) {
			WP_CLI::success( 'Check the logs for more details:' );
			WP_CLI::log( '- ' . implode( "\n- ", $cli_output_logs_report ) );
		}

		wp_cache_flush();
	}

	/**
	 * Validates local DB and live DB taxonomies. Checks if the taxonomies's parent term_ids are correct in the live DB, and sets those to zero if they are not correct.
	 *
	 * @param array $taxonomies_to_check Hierarchical taxonomies to validate.
	 * @param array $live_taxonomies     List of all taxonomies found in the Live DB.
	 *
	 * @return void
	 */
	public function validate_hierarchical_taxonomies( $taxonomies_to_check, $live_taxonomies ): void {
		global $wpdb;

		// Check if any of the taxonomies does not exist in the live DB.
		foreach ( $taxonomies_to_check as $taxonomy_to_check ) {
			if ( ! in_array( $taxonomy_to_check, $live_taxonomies ) ) {
				WP_CLI::error( sprintf( 'Taxonomy %s not found in live DB.', $taxonomy_to_check ) );
			}
		}

		// Check if any of the local taxonomies have nonexistent wp_term_taxonomy.parent, and fix those before continuing.
		$hierarchical_taxonomies = self::$logic->get_taxonomies_with_nonexistent_parents( $wpdb->prefix, $taxonomies_to_check );
		if ( ! empty( $hierarchical_taxonomies ) ) {
			$list              = '';
			$term_taxonomy_ids = [];
			foreach ( $hierarchical_taxonomies as $hierarchical_taxonomy ) {
				$list               .= ( empty( $list ) ? '' : "\n" ) . '  ' . wp_json_encode( $hierarchical_taxonomy );
				$term_taxonomy_ids[] = $hierarchical_taxonomy['term_taxonomy_id'];
			}

			WP_CLI::warning( 'The following local DB hierarchical taxonomies have invalid parent IDs which will be fixed first (their parents set to 0).' );
			WP_CLI::log( $list );

			WP_CLI::confirm( "OK to fix and set all these hierarchical taxonomies' parents to 0? in local site's DB tables" );
			self::$logic->reset_hierarchical_taxonomies_parents( $wpdb->prefix, $term_taxonomy_ids );
		}

		// Check the same for Live DB's hierarchical taxonomies, and fix those before continuing.
		$hierarchical_taxonomies = self::$logic->get_taxonomies_with_nonexistent_parents( $this->live_table_prefix, $taxonomies_to_check );
		if ( ! empty( $hierarchical_taxonomies ) ) {
			$list              = '';
			$term_taxonomy_ids = [];
			foreach ( $hierarchical_taxonomies as $hierarchical_taxonomy ) {
				$list               .= ( empty( $list ) ? '' : "\n" ) . '  ' . json_encode( $hierarchical_taxonomy );
				$term_taxonomy_ids[] = $hierarchical_taxonomy['term_taxonomy_id'];
			}

			WP_CLI::warning( 'The following live DB hierarchical taxonomies have invalid parent IDs which must be fixed first (their parents set to 0 in live tables).' );
			WP_CLI::log( $list );

			WP_CLI::confirm( "OK to fix and set all these hierarchical taxonomies' parents to 0 in live DB tables?" );
			self::$logic->reset_hierarchical_taxonomies_parents( $this->live_table_prefix, $term_taxonomy_ids );
		}
	}

	/**
	 * Fixes attachment IDs in Block content.
	 *
	 * @param array $positional_args Positional arguments.
	 * @param array $assoc_args      Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_fix_image_ids_in_post_content( $positional_args, $assoc_args ) {
		// Params.
		global $wpdb;
		$post_id_from                = $assoc_args['post-id-from'] ?? null;
		$post_id_to                  = $assoc_args['post-id-to'] ?? null;
		$local_hostnames_aliases_csv = $assoc_args['local-hostname-aliases-csv'] ?? null;
		$log_file_path               = 'contentdiff_update_blocks_ids.log';
		if ( ( ! is_null( $post_id_from ) && is_null( $post_id_to ) ) || ( is_null( $post_id_from ) && ! is_null( $post_id_to ) ) ) {
			WP_CLI::error( 'Both --post-id-from and --post-id-to must be provided' );
		}

		// Deactivate the S3-Uploads plugin because it changes how \attachment_url_to_postid() behaves.
		WP_CLI::log( '' );
		WP_CLI::confirm( 'In order to correctly update attachment IDs in Block content, S3-Uploads plugin will be deactivated. Continue' );
		foreach ( wp_get_active_and_valid_plugins() as $plugin ) {
			if ( false !== strrpos( strtolower( $plugin ), 's3-uploads.php' ) ) {
				deactivate_plugins( $plugin );
				WP_CLI::success( sprintf( 'Deactivated %s', $plugin ) );
			}
		}

		// Either use --local-hostname-aliases-csv, or search all content for used image hostnames, then display those hostnames and prompt which to use as local hostname aliases.
		if ( ! is_null( $local_hostnames_aliases_csv ) ) {
			$local_hostname_aliases = explode( ',', $local_hostnames_aliases_csv );
		} else {
			// Scan all content for used images hostnames by using NewspackPostImageDownloader.
			WP_CLI::log( 'Now searching all posts for used image URL hostnames...' );
			$downloader             = new \NewspackPostImageDownloader\Downloader();
			$posts                  = $downloader->get_posts_ids_and_contents();
			$all_hostnames_with_ids = $downloader->get_all_image_hostnames_from_posts( $posts );
			// Remove relative URLs, leave just ones with hostnames.
			unset( $all_hostnames_with_ids['relative URL paths'] );
			$all_hostnames = array_keys( $all_hostnames_with_ids );

			// Display all found hostnames and prompt which local aliases to use.
			WP_CLI::log( sprintf( "Found following image hosts: \n- %s\n", implode( "\n- ", $all_hostnames ) ) );
			WP_CLI::log( "If any of these hostnames should be looked up as local attachments, add them next (e.g. if S3 hostname 'newspack-pubname.s3.amazonaws.com' is used in <img> srcs in post_content, it should be added as a local hostname alias)." );
			$local_hostnames_aliases_csv = PHPUtil::readline( "Enter additional image hostnames to be treated as local, or leave blank for none (CSVs, don't use any extra spaces): " );
			$local_hostname_aliases      = explode( ',', $local_hostnames_aliases_csv );
		}

		// Either use --post-id-to and --post-id-from, or get all post IDs.
		if ( is_null( $post_id_to ) || is_null( $post_id_from ) ) {
			WP_CLI::log( 'Getting a list of all the post IDs...' );
			$post_ids = $this->posts_logic->get_all_posts_ids();
		} else {
			$post_ids = $wpdb->get_col(
				$wpdb->prepare(
					"select ID
					from $wpdb->posts
					where post_type = 'post'
					and post_status in ( 'publish', 'draft' )
					and ID >= %d
					and ID <= %d
					order by ID asc",
					$post_id_from,
					$post_id_to
				)
			);
		}

		// Run the command on a single $post_id at a time to control interruptions more easily.
		$known_attachment_ids_updates = [];
		foreach ( $post_ids as $key_post_id => $post_id ) {
			WP_CLI::log( sprintf( '(%d)/(%d) %d', $key_post_id + 1, count( $post_ids ), $post_id ) );
			self::$logic->update_blocks_ids( [ $post_id ], $known_attachment_ids_updates, $local_hostname_aliases, $log_file_path );
		}

		wp_cache_flush();
		WP_CLI::success( sprintf( 'Done. Check %s.', $log_file_path ) );
	}

	/**
	 * Recreates all hierarchical taxonomies from Live to local.
	 *
	 * If hierarchical cats are used, their whole structure should be in place when they get assigned to posts.
	 *
	 * @param array $taxonomies_to_migrate Hierarchical taxonomies to migrate.
	 *
	 * @return array Map of taxonomy term_id udpdates. Keys are hierarchical taxonomies' term_ids on Live and values are corresponding
	 *               hierarchical taxonomies' term_ids on local (staging).
	 */
	public function recreate_hierarchical_taxonomies( $taxonomies_to_migrate ) {
		$hierarchical_taxonomy_term_id_updates = self::$logic->recreate_hierarchical_taxonomies( $this->live_table_prefix, $taxonomies_to_migrate );
		
		// Log taxonomy term_id updates.
		$this->log(
			$this->log_recreated_hierarchical_taxonomies,
			wp_json_encode( [ 'hierarchical_taxonomy_term_id_updates' => $hierarchical_taxonomy_term_id_updates ] )
		);

		return $hierarchical_taxonomy_term_id_updates;
	}

	/**
	 * Migrates all WP_Users from Live to local.
	 * 
	 * @param string $live_table_prefix Live table prefix.
	 * @return array Map of newly inserted WP_Users, keys are old Live IDs and values are new local IDs.
	 */
	public function migrate_all_users( $live_table_prefix ) {
		$inserted_wp_users_updates = self::$logic->migrate_all_users( $live_table_prefix );

		// Log taxonomy term_id updates.
		$this->log(
			$this->log_inserted_wp_users,
			wp_json_encode( [ 'inserted_wp_users_updates' => $inserted_wp_users_updates ] )
		);

		return $inserted_wp_users_updates;
	}

	/**
	 * Permanently deletes local posts.
	 *
	 * @param array $ids Post IDs.
	 *
	 * @return void
	 */
	public function delete_local_posts( array $ids ): void {
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
	}

	/**
	 * Creates and imports posts and all related post data. Skips previously imported IDs found in $this->log_imported_post_ids.
	 *
	 * @param array $all_live_posts_ids       Live IDs to be imported to local.
	 * @param array $hierarchical_taxonomy_term_id_updates Map of updated hierarchical taxonomy term_ids. Keys are Taxonomies' term_ids on live, and values
	 *                                        are corresponding Taxonomies' term_ids on local (staging).
	 *
	 * @return array $imported_posts_data {
	 *     Array with subarray records for all the imported post objects.
	 *
	 *     @type array $record {
	 *         @type string $post_type Imported post_object.
	 *         @type string $id_old    Original ID on live.
	 *         @type string $id_new    New ID of imported post.
	 *     }
	 * }
	 */
	public function import_posts( $all_live_posts_ids, $hierarchical_taxonomy_term_id_updates ) {

		$post_ids_for_import = $all_live_posts_ids;

		// Skip previously imported posts.
		$imported_posts_data = $this->get_data_from_log( $this->log_imported_post_ids, [ 'post_type', 'id_old', 'id_new' ] ) ?? [];
		foreach ( $imported_posts_data as $imported_post_data ) {
			$id_old     = $imported_post_data['id_old'] ?? null;
			$key_id_old = array_search( $id_old, $post_ids_for_import );
			if ( ! is_null( $id_old ) && false !== $key_id_old ) {
				unset( $post_ids_for_import[ $key_id_old ] );
			}
		}
		if ( empty( $post_ids_for_import ) ) {
			WP_CLI::log( 'All posts were already imported, moving on.' );
			return $imported_posts_data;
		}
		if ( $post_ids_for_import !== $all_live_posts_ids ) {
			$post_ids_for_import = array_values( $post_ids_for_import );
			WP_CLI::log( sprintf( '%s of total %d IDs were already imported, continuing from there. Hold tight..', count( $all_live_posts_ids ) - count( $post_ids_for_import ), count( $all_live_posts_ids ) ) );
		}

		// Import Posts.
		$percent_progress = null;
		foreach ( $post_ids_for_import as $key_post_id => $post_id_live ) {

			// Get and output progress meter by 10%.
			$last_percent_progress = $percent_progress;
			self::$logic->get_progress_percentage( count( $post_ids_for_import ), $key_post_id + 1, 10, $percent_progress );
			if ( $last_percent_progress !== $percent_progress ) {
				PHPUtil::echo_stdout( $percent_progress . '%' . ( ( $percent_progress < 100 ) ? '... ' : ".\n" ) );
			}

			// Get all Post data from DB.
			$post_data = self::$logic->get_post_data( (int) $post_id_live, $this->live_table_prefix );
			$post_type = $post_data[ self::$logic::DATAKEY_POST ]['post_type'];

			// First just insert a new blank `wp_posts` record to get the new ID.
			try {
				$post_id_new           = self::$logic->insert_post( $post_data[ self::$logic::DATAKEY_POST ] );
				$imported_posts_data[] = [
					'post_type' => $post_type,
					'id_old'    => (int) $post_id_live,
					'id_new'    => (int) $post_id_new,
				];
			} catch ( \Exception $e ) {
				$this->log( $this->log_error, sprintf( 'import_posts error while inserting post_type %s id_old=%d : %s', $post_type, $post_id_live, $e->getMessage() ) );
				WP_CLI::warning( sprintf( 'Error inserting %s Live ID %d (details in log file)', $post_type, $post_id_live ) );

				// Error is logged. Continue importing other posts.
				continue;
			}

			// Now import all related Post data.
			$import_errors = self::$logic->import_post_data( $post_id_new, $post_data, $hierarchical_taxonomy_term_id_updates );
			if ( ! empty( $import_errors ) ) {
				$msg = sprintf( 'Errors during import post_type=%s, id_old=%d, id_new=%d :', $post_type, $post_id_live, $post_id_new );
				foreach ( $import_errors as $import_error ) {
					$msg .= PHP_EOL . '- ' . $import_error;
				}
				$this->log( $this->log_error, $msg );
				WP_CLI::warning( $msg );
			}

			// Log imported post.
			$this->log(
				$this->log_imported_post_ids,
				json_encode(
					[
						'post_type' => $post_type,
						'id_old'    => (int) $post_id_live,
						'id_new'    => (int) $post_id_new,
					]
				)
			);

			// Save some metas.
			update_post_meta( $post_id_new, ContentDiffMigratorLogic::SAVED_META_LIVE_POST_ID, $post_id_live );
		}

		// Flush the cache for `$wpdb::update`s to sink in.
		wp_cache_flush();

		return $imported_posts_data;
	}

	/**
	 * Updates all Posts' post_parent IDs.
	 *
	 * @param array $all_live_posts_ids Old (Live) IDs to have their post_parent updated.
	 * @param array $imported_posts_data {
	 *     Return result from import_posts method, a map of all the imported post objects.
	 *
	 *     @type array $record {
	 *         @type string $post_type Imported post_object.
	 *         @type string $id_old    Original ID on live.
	 *         @type string $id_new    New ID of imported post.
	 *     }
	 * }
	 */
	public function update_post_parent_ids( $all_live_posts_ids, $imported_posts_data ) {

		$parent_ids_for_update = $all_live_posts_ids;

		// Skip previously updated IDs.
		$previously_updated_parent_ids_data = $this->get_data_from_log( $this->log_updated_posts_parent_ids, [ 'id_old' ] );
		foreach ( $previously_updated_parent_ids_data as $entry ) {
			$id_old     = $entry['id_old'] ?? null;
			$key_id_old = array_search( $id_old, $parent_ids_for_update );
			if ( ! is_null( $id_old ) && false !== $key_id_old ) {
				unset( $parent_ids_for_update[ $key_id_old ] );
			}
		}
		if ( empty( $parent_ids_for_update ) ) {
			WP_CLI::log( 'All posts already had their post_parent updated, moving on.' );
			return;
		}
		if ( $parent_ids_for_update !== $all_live_posts_ids ) {
			$parent_ids_for_update = array_values( $parent_ids_for_update );
			WP_CLI::log( sprintf( '%s post_parent IDs of total %d were already updated, continuing from there..', count( $all_live_posts_ids ) - count( $parent_ids_for_update ), count( $all_live_posts_ids ) ) );
		}

		/**
		 * Map of all imported post types other than Attachments (Posts, Pages, etc).
		 *
		 * @var array $imported_post_ids_map Keys are old Live IDs, values are new local IDs.
		 */
		$imported_post_ids_map = $this->get_non_attachments_from_imported_posts_log( $imported_posts_data );

		/**
		 * Map of imported Attachments.
		 *
		 * @var array $imported_attachment_ids_map Keys are old Live IDs, values are new local IDs.
		 */
		$imported_attachment_ids_map = $this->get_attachments_from_imported_posts_log( $imported_posts_data );

		// Try and free some memory.
		$all_live_posts_ids  = null;
		$imported_posts_data = null;
		usleep( 100000 );

		// Update parent IDs.
		global $wpdb;
		$percent_progress = null;
		foreach ( $parent_ids_for_update as $key_id_old => $id_old ) {

			// Get and output progress meter by 10%.
			$last_percent_progress = $percent_progress;
			self::$logic->get_progress_percentage( count( $parent_ids_for_update ), $key_id_old + 1, 10, $percent_progress );
			if ( $last_percent_progress !== $percent_progress ) {
				PHPUtil::echo_stdout( $percent_progress . '%' . ( ( $percent_progress < 100 ) ? '... ' : ".\n" ) );
			}

			// Get new local Post ID.
			$id_new = $imported_post_ids_map[ $id_old ] ?? null;
			$id_new = is_null( $id_new ) ? $imported_attachment_ids_map[ $id_old ] : $id_new;

			// Get Post's post_parent which uses the live DB ID.
			$parent_id_old = $wpdb->get_var( $wpdb->prepare( "SELECT post_parent FROM $wpdb->posts WHERE ID = %d;", $id_new ) );

			// No update to do.
			if ( ( '0' == $parent_id_old ) || empty( $parent_id_old ) ) {
				continue;
			}

			// Get new post_parent.
			$parent_id_new = $imported_post_ids_map[ $parent_id_old ] ?? null;
			// Check if it's perhaps an attachment.
			$parent_id_new = is_null( $parent_id_new ) && array_key_exists( $parent_id_old, $imported_attachment_ids_map ) ? $imported_attachment_ids_map[ $parent_id_old ] : $parent_id_new;

			// It's possible that this $post's post_parent already existed in local DB before the Content Diff import was run, so
			// it won't be present in the list of the posts we imported. Let's try and search for the new ID directly in DB.
			// First try searching by postmeta ContentDiffMigratorLogic::SAVED_META_LIVE_POST_ID -- in case a previous content diff imported it.
			if ( is_null( $parent_id_new ) ) {
				$parent_id_new = self::$logic->get_current_post_id_by_custom_meta( $parent_id_old, ContentDiffMigratorLogic::SAVED_META_LIVE_POST_ID );
			}
			// Next try searching for the new parent_id by joining local and live DB tables.
			if ( is_null( $parent_id_new ) ) {
				$parent_id_new = self::$logic->get_current_post_id_by_comparing_with_live_db( $parent_id_old, $this->live_table_prefix );
			}

			// Warn if this post_parent object was not found/imported. It might be legit, like the parent object being a
			// post_type different than the supported post type, or an error like the post_parent object missing in Live DB.
			if ( is_null( $parent_id_new ) ) {
				// If all attempts failed (possibly this parent does not exist in the live DB, or if this parent is of a post_type which was not imported), set that post_parent to 0.
				$parent_id_new = 0;

				$this->log( $this->log_error, sprintf( 'update_post_parent_ids error, $id_old=%s, $id_new=%s, $parent_id_old=%s, $parent_id_new is 0.', $id_old, $id_new, $parent_id_old ) );
			}

			// Update.
			if ( $parent_id_old != $parent_id_new ) {
				self::$logic->update_post_parent( $id_new, $parent_id_new );
			}

			// Log IDs of the Post.
			$log_entry = [
				'id_old' => $id_old,
				'id_new' => $id_new,
			];
			if ( 0 != $parent_id_old && ! is_null( $parent_id_new ) ) {
				// Log, add IDs of post_parent.
				$log_entry = array_merge(
					$log_entry,
					[
						'parent_id_old' => $parent_id_old,
						'parent_id_new' => $parent_id_new,
					]
				);
			}
			$this->log( $this->log_updated_posts_parent_ids, json_encode( $log_entry ) );
		}
	}

	/**
	 * Updates all Featured Images IDs.
	 *
	 * @param array $imported_posts_data {
	 *     Return result from import_posts method, a map of all the imported post objects.
	 *
	 *     @type array $record {
	 *         @type string $post_type Imported post_object.
	 *         @type string $id_old    Original ID on live.
	 *         @type string $id_new    New ID of imported post.
	 *     }
	 * }
	 */
	public function update_featured_image_ids( $imported_posts_data ) {

		/**
		 * Map of all imported post types other than Attachments (Posts, Pages, etc).
		 *
		 * @var array $imported_post_ids_map Keys are old Live IDs, values are new local IDs.
		 */
		$imported_post_ids_map = $this->get_non_attachments_from_imported_posts_log( $imported_posts_data );

		/**
		 * Map of imported Attachments.
		 *
		 * @var array $imported_attachment_ids_map Keys are old Live IDs, values are new local IDs.
		 */
		$imported_attachment_ids_map = self::$logic->get_imported_attachment_id_mapping_from_db();

		// Get new Post IDs from DB.
		$new_post_ids = array_values( $imported_post_ids_map );

		self::$logic->update_featured_images( $new_post_ids, $imported_attachment_ids_map, $this->log_updated_featured_imgs_ids );
	}

	/**
	 * Updates Attachment IDs in Post contents.
	 *
	 * Some Gutenberg Blocks contain `id` or `ids` of Attachments attributes in their headers, and image elements contain those
	 * IDs too.
	 *
	 * @param array $imported_posts_data {
	 *     Return result from import_posts method, a map of all the imported post objects.
	 *
	 *     @type array $record {
	 *         @type string $post_type Imported post_object.
	 *         @type string $id_old    Original ID on live.
	 *         @type string $id_new    New ID of imported post.
	 *     }
	 * }
	 */
	public function update_attachment_ids_in_blocks( $imported_posts_data ) {

		/**
		 * Map of all imported post types other than Attachments (Posts, Pages, etc).
		 *
		 * @var array $imported_post_ids_map Keys are old Live IDs, values are new local IDs.
		 */
		$imported_post_ids_map = $this->get_non_attachments_from_imported_posts_log( $imported_posts_data );

		/**
		 * Map of imported Attachments.
		 *
		 * @var array $imported_attachment_ids_map Keys are old Live IDs, values are new local IDs.
		 */
		$imported_attachment_ids_map = $this->get_attachments_from_imported_posts_log( $imported_posts_data );

		// Skip previously updated Posts.
		$updated_post_ids               = $this->get_data_from_log( $this->log_updated_blocks_ids, [ 'id_new' ] ) ?? [];
		$new_post_ids_for_blocks_update = array_values( $imported_post_ids_map );
		foreach ( $updated_post_ids as $entry ) {
			$id_new     = $entry['id_new'] ?? null;
			$key_id_new = array_search( $id_new, $new_post_ids_for_blocks_update );
			if ( ! is_null( $id_new ) && false !== $key_id_new ) {
				unset( $new_post_ids_for_blocks_update[ $key_id_new ] );
			}
		}
		if ( empty( $new_post_ids_for_blocks_update ) ) {
			WP_CLI::log( 'All posts already had their blocks\' att. IDs updated, moving on.' );
			return;
		}
		if ( array_values( $imported_post_ids_map ) !== $new_post_ids_for_blocks_update ) {
			$new_post_ids_for_blocks_update = array_values( $new_post_ids_for_blocks_update );
			WP_CLI::log( sprintf( '%s of total %d posts already had their blocks\' IDs updated, continuing from there..', count( $imported_post_ids_map ) - count( $new_post_ids_for_blocks_update ), count( $imported_post_ids_map ) ) );
		}

		self::$logic->update_blocks_ids( $new_post_ids_for_blocks_update, $imported_attachment_ids_map, [], $this->log_updated_blocks_ids );
	}

	/**
	 * This function will display a table comparing the collations of Live and Core WP tables.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Optional arguments.
	 */
	public function cmd_compare_collations_of_live_and_core_wp_tables( $args, $assoc_args ) {
		$live_table_prefix     = $assoc_args['live-table-prefix'];
		$skip_tables           = [];
		$different_tables_only = $assoc_args['different-collations-only'] ?? false;

		if ( ! empty( $assoc_args['skip-tables'] ) ) {
			$skip_tables = explode( ',', $assoc_args['skip-tables'] );
		}

		$tables = [];

		if ( $different_tables_only ) {
			$tables = self::$logic->filter_for_different_collated_tables( $live_table_prefix, $skip_tables );
		} else {
			$tables = self::$logic->get_collation_comparison_of_live_and_core_wp_tables( $live_table_prefix, $skip_tables );
		}

		if ( ! empty( $tables ) ) {
			WP_CLI\Utils\format_items( 'table', $tables, array_keys( $tables[0] ) );
		} else {
			WP_CLI::success( 'Live and Core WP DB table collations match!' );
		}
	}

	/**
	 * This function will execute the necessary steps to get Live WP
	 * tables to match the collation of Core WP tables.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Optional arguments.
	 */
	public function cmd_correct_collations_for_live_wp_tables( $args, $assoc_args ) {
		$live_table_prefix = $assoc_args['live-table-prefix'];
		$mode              = $assoc_args['mode'];
		$backup_prefix     = isset( $assoc_args['backup-table-prefix'] ) ? $assoc_args['backup-table-prefix'] : 'collationbak_';
		$skip_tables       = isset( $assoc_args['skip-tables'] ) ? explode( ',', $assoc_args['skip-tables'] ) : [];

		$tables_with_differing_collations = self::$logic->filter_for_different_collated_tables( $live_table_prefix, $skip_tables );

		if ( ! empty( $tables_with_differing_collations ) ) {
			WP_CLI\Utils\format_items( 'table', $tables_with_differing_collations, array_keys( $tables_with_differing_collations[0] ) );
		}

		switch ( $mode ) {
			case 'aggressive':
				$records_per_transaction = 15000;
				$sleep_in_seconds        = 1;
				break;
			case 'generous':
				$records_per_transaction = 10000;
				$sleep_in_seconds        = 2;
				break;
			case 'calm':
				$records_per_transaction = 1000;
				$sleep_in_seconds        = 3;
				break;
			default: // Cautious.
				$records_per_transaction = 5000;
				$sleep_in_seconds        = 2;
				break;
		}

		WP_CLI::log( "Now fixing $live_table_prefix tables collations..." );
		foreach ( $tables_with_differing_collations as $result ) {
			WP_CLI::log( 'Addressing ' . $result['table'] . ' table...' );
			self::$logic->copy_table_data_using_proper_collation( $live_table_prefix, $result['table'], $records_per_transaction, $sleep_in_seconds, $backup_prefix );
		}
	}

	/**
	 * Filters the log data array by where conditions.
	 *
	 * @param array  $imported_posts_log_data Log data array, consists of subarrays with one or more multiple key=>values.
	 * @param string $where_key               Search key.
	 * @param array  $where_values            Search value.
	 * @param string $where_operand           Search operand, can be '==' or '!='.
	 * @param bool   $return_first            If true, return just the first matched entry, otherwise returns all matched entries.
	 *
	 * @throws \RuntimeException In case an unsupported $where_operand was given.
	 *
	 * @return array Found results. Mind that if $return_first is true, it will return a one-dimensional array,
	 *               and if $return_first is false, it will return two-dimensional array with all matched elements as subarrays.
	 */
	private function filter_imported_posts_log( array $imported_posts_log_data, string $where_key, array $where_values, string $where_operand, bool $return_first = true ): array {
		$return                   = [];
		$supported_where_operands = [ '==', '!=' ];

		// Validate $where_operand.
		if ( ! in_array( $where_operand, $supported_where_operands ) ) {
			throw new \RuntimeException( sprintf( 'Where operand %s is not supported.', esc_textarea( $where_operand ) ) );
		}

		foreach ( $imported_posts_log_data as $entry ) {

			// Check $where conditions.
			foreach ( $where_values as $where_value ) {

				$matched = false;
				if ( '==' === $where_operand ) {
					$matched = isset( $entry[ $where_key ] ) && $where_value == $entry[ $where_key ];
				} elseif ( '!=' === $where_operand ) {
					$matched = isset( $entry[ $where_key ] ) && $where_value != $entry[ $where_key ];
				}

				if ( true === $matched ) {
					$return[] = $entry;

					// Return the very first element matching $where.
					if ( true === $return_first ) {
						return $entry;
					}
				}
			}
		}

		return $return;
	}

	/**
	 * Gets IDs from the log for Posts, Pages and other post types which are not Attachments.
	 *
	 * @param array $imported_posts_data Imported posts log data.
	 *
	 * @return array IDs.
	 */
	private function get_non_attachments_from_imported_posts_log( array $imported_posts_data ): array {
		$imported_post_ids_map    = [];
		$imported_posts_data_post = $this->filter_imported_posts_log( $imported_posts_data, 'post_type', [ 'attachment' ], '!=', false );
		foreach ( $imported_posts_data_post as $entry ) {
			$imported_post_ids_map[ $entry['id_old'] ] = $entry['id_new'];
		}

		return $imported_post_ids_map;
	}

	/**
	 * Gets IDs from the log for Attachments.
	 *
	 * @param array $imported_posts_data Imported posts log data.
	 *
	 * @return array IDs, keys are old/live IDs, values are new/local IDs.
	 */
	private function get_attachments_from_imported_posts_log( array $imported_posts_data ): array {
		$imported_attachment_ids_map   = [];
		$imported_post_data_attachment = $this->filter_imported_posts_log( $imported_posts_data, 'post_type', [ 'attachment' ], '==', false );
		foreach ( $imported_post_data_attachment as $entry ) {
			$imported_attachment_ids_map[ $entry['id_old'] ] = $entry['id_new'];
		}

		return $imported_attachment_ids_map;
	}

	/**
	 * Gets a map of live=>local IDs from the modified IDs log.
	 *
	 * @param array $modified_posts_log_data Modified post IDs log data.
	 *
	 * @return array IDs, keys are live IDs, values are local IDs.
	 */
	private function get_ids_from_modified_posts_log( array $modified_posts_log_data ): array {
		$ids = [];
		foreach ( $modified_posts_log_data as $entry ) {
			$ids[ $entry['live_id'] ] = $entry['local_id'];
		}

		return $ids;
	}

	/**
	 * Gets data from logs which contain JSON encoded arrays per line.
	 *
	 * @param string $log       Path to log.
	 * @param array  $json_keys Keys to fetch from log lines.
	 *
	 * @return array|null Array with subarray elements with $json_keys keys and values pulled from the log, or null if file can't be found.
	 */
	private function get_data_from_log( $log, $json_keys ) {
		$data = [];

		// Read line by line.
		$handle = fopen( $log, 'r' );
		if ( $handle ) {
			while ( ( $line = fgets( $handle ) ) !== false ) {
				// Skip if not JSON data on line.
				$line_decoded = json_decode( $line, true );
				if ( ! is_array( $line_decoded ) ) {
					continue;
				}

				// Get data if line contains these JSON keys.
				$data_key          = count( $data );
				$data[ $data_key ] = [];
				foreach ( $json_keys as $json_key ) {
					if ( isset( $line_decoded[ $json_key ] ) ) {
						$data[ $data_key ] = array_merge( $data[ $data_key ], [ $json_key => $line_decoded[ $json_key ] ] );
					}
				}
			}

			fclose( $handle );
		} else {
			return null;
		}

		return $data;
	}

	/**
	 * Validates DB tables.
	 *
	 * @param string $live_table_prefix Live table prefix.
	 * @param array  $skip_tables       Core WP DB tables to skip (without prefix).
	 *
	 * @throws \RuntimeException In case that table collations do not match.
	 *
	 * @return void
	 */
	public function validate_db_tables( string $live_table_prefix, array $skip_tables ): void {
		self::$logic->validate_core_wp_db_tables_exist_in_db( $live_table_prefix, $skip_tables );
		if ( ! self::$logic->are_table_collations_matching( $live_table_prefix, $skip_tables ) ) {
			throw new \RuntimeException( 'Table collations do not match for some (or all) WP tables.' );
		}
	}

	/**
	 * Logs error message to file.
	 *
	 * @param string $file Full file path.
	 * @param string $msg  Error message.
	 */
	public function log( $file, $msg ) {
		file_put_contents( $file, $msg . "\n", FILE_APPEND );
	}
}
