<?php
/**
 * Content Diff migrator exports and imports the content differential from one site to the local site.
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Migrator\General;

use NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use NewspackCustomContentMigrator\MigrationLogic\ContentDiffMigrator as ContentDiffMigratorLogic;
use NewspackCustomContentMigrator\Utils\PHP as PHPUtil;
use WP_CLI;

/**
 * Content Diff Migrator CLI commands class.
 *
 * @package NewspackCustomContentMigrator\Migrator\General
 */
class ContentDiffMigrator implements InterfaceMigrator {

	const LOG_IDS_CSV                     = 'content-diff__new-ids-csv.log';
	const LOG_IMPORTED_POST_IDS           = 'content-diff__imported-post-ids.log';
	const LOG_UPDATED_PARENT_IDS          = 'content-diff__updated-parent-ids.log';
	const LOG_UPDATED_FEATURED_IMAGES_IDS = 'content-diff__updated-feat-imgs-ids.log';
	const LOG_UPDATED_BLOCKS_IDS          = 'content-diff__wp-blocks-ids-updates.log';
	const LOG_ERROR                       = 'content-diff__err.log';

	const SAVED_META_LIVE_POST_ID         = 'newspackcontentdiff_live_id';

	/**
	 * Instance.
	 *
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * Content Diff logic class.
	 *
	 * @var null|ContentDiffMigratorLogic Logic.
	 */
	private static $logic = null;

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
			global $wpdb;

			self::$logic    = new ContentDiffMigratorLogic( $wpdb );
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator content-diff-search-new-content-on-live',
			[ $this, 'cmd_search_new_content_on_live' ],
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
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator content-diff-migrate-live-content',
			[ $this, 'cmd_migrate_live_content' ],
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
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator content-diff-fix-image-ids-in-post-content',
			[ $this, 'cmd_fix_image_ids_in_post_content' ],
			[
				'shortdesc' => 'Standalone command which fixes attachment IDs in Block content. It does so by loading all the posts, goes through post_content and gets all the WP Blocks which use attachments IDs (see \NewspackCustomContentMigrator\MigrationLogic\ContentDiffMigrator::update_blocks_ids), then it takes every single attachment file and checks if its attachment ID has changed, and if it has it updates the IDs.',
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
			[ $this, 'cmd_compare_collations_of_live_and_core_wp_tables' ],
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
						'description' => 'Skip checking a particular set of tables from the collation checks.',
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
			[ $this, 'cmd_correct_collations_for_live_wp_tables' ],
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

		try {
			self::$logic->validate_core_wp_db_tables( $live_table_prefix, [ 'options' ] );
		} catch ( \Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::log( 'Searching for new content on Live Site...' );
		try {
			$ids = self::$logic->get_live_diff_content_ids( $live_table_prefix );
		} catch ( \Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		$file = $export_dir . '/' . self::LOG_IDS_CSV;
		file_put_contents( $export_dir . '/' . self::LOG_IDS_CSV, implode( ',', $ids ) );

		WP_CLI::success( sprintf( '%d new IDs found, and a list of these IDs exported to %s', count( $ids ), $file ) );
	}

	/**
	 * Callable for `newspack-content-migrator content-diff-migrate-live-content`.
	 *
	 * @param array $args       CLI args.
	 * @param array $assoc_args CLI assoc args.
	 */
	public function cmd_migrate_live_content( $args, $assoc_args ) {
		$import_dir        = $assoc_args['import-dir'] ?? false;
		$live_table_prefix = $assoc_args['live-table-prefix'] ?? false;

		// Validate all params.
		$file_ids_csv = $import_dir . '/' . self::LOG_IDS_CSV;
		if ( ! file_exists( $file_ids_csv ) ) {
			WP_CLI::error( sprintf( 'File %s not found.', $file_ids_csv ) );
		}
		$all_live_posts_ids = explode( ',', file_get_contents( $file_ids_csv ) );
		if ( empty( $all_live_posts_ids ) ) {
			WP_CLI::error( sprint( 'File %s does not contain valid CSV IDs.', $file_ids_csv ) );
		}
		try {
			self::$logic->validate_core_wp_db_tables( $live_table_prefix, [ 'options' ] );
		} catch ( \Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		// Set constants.
		$this->live_table_prefix             = $live_table_prefix;
		$this->log_error                     = $import_dir . '/' . self::LOG_ERROR;
		$this->log_imported_post_ids         = $import_dir . '/' . self::LOG_IMPORTED_POST_IDS;
		$this->log_updated_posts_parent_ids  = $import_dir . '/' . self::LOG_UPDATED_PARENT_IDS;
		$this->log_updated_featured_imgs_ids = $import_dir . '/' . self::LOG_UPDATED_FEATURED_IMAGES_IDS;
		$this->log_updated_blocks_ids        = $import_dir . '/' . self::LOG_UPDATED_BLOCKS_IDS;

		// Timestamp the logs.
		$ts = gmdate( 'Y-m-d h:i:s a', time() );
		$this->log( $this->log_error, sprintf( 'Starting %s.', $ts ) );
		$this->log( $this->log_imported_post_ids, sprintf( 'Starting %s.', $ts ) );
		$this->log( $this->log_updated_posts_parent_ids, sprintf( 'Starting %s.', $ts ) );
		$this->log( $this->log_updated_featured_imgs_ids, sprintf( 'Starting %s.', $ts ) );
		$this->log( $this->log_updated_blocks_ids, sprintf( 'Starting %s.', $ts ) );

		echo 'Recreating categories..';
		$this->recreate_categories();
		echo "\nDone!\n";

		echo sprintf( 'Importing %d objects, hold tight..', count( $all_live_posts_ids ) );
		$imported_posts_data = $this->import_posts( $all_live_posts_ids );
		echo "\nDone!\n";

		echo 'Updating Post parent IDs..';
		$this->update_post_parent_ids( $all_live_posts_ids, $imported_posts_data );
		echo "\nDone!\n";

		echo 'Updating Featured images IDs..';
		$this->update_featured_image_ids( $imported_posts_data );
		echo "\nDone!\n";

		echo 'Updating attachment IDs in block content..';
		$this->update_attachment_ids_in_blocks( $imported_posts_data );
		echo "\nDone!\n";

		echo "\n";
		WP_CLI::success( 'All done migrating content! 🙌 ' );

		// Output info about all available logs.
		$cli_output_logs_report = [];
		if ( file_exists( $this->log_error ) ) {
			$cli_output_logs_report[] = sprintf( '%s - errors', $this->log_error );
		}
		if ( file_exists( $this->log_imported_post_ids ) ) {
			$cli_output_logs_report[] = sprintf( '%s - all imported IDs', $this->log_imported_post_ids );
		}
		if ( file_exists( $this->log_updated_blocks_ids ) ) {
			$cli_output_logs_report[] = sprintf( '%s - detailed blocks IDs post content replacements', $this->log_updated_blocks_ids );
		}
		if ( ! empty( $cli_output_logs_report ) ) {
			WP_CLI::log( 'Check the logs for more details:' );
			WP_CLI::log( '- ' . implode( "\n- ", $cli_output_logs_report ) );
		}

		wp_cache_flush();
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
	 * Recreates all categories from Live to local.
	 * If hierarchical cats are used, their whole structure should be in place when they get assigned to posts.
	 */
	public function recreate_categories() {
		$created_terms_in_taxonomies = self::$logic->recreate_categories( $this->live_table_prefix );

		// Output and log errors.
		if ( isset( $created_terms_in_taxonomies['errors'] ) && ! empty( $created_terms_in_taxonomies['errors'] ) ) {
			foreach ( $created_terms_in_taxonomies['errors'] as $error ) {
				WP_CLI::warning( $error );
				$this->log( $this->log_error, 'recreate_categories error: ' . $error );
			}
		}
	}

	/**
	 * Creates and imports posts and all related post data. Skips previously imported IDs found in $this->log_imported_post_ids.
	 *
	 * @param array $all_live_posts_ids Live IDs to be imported to local.
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
	public function import_posts( $all_live_posts_ids ) {

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
			echo "\n" . 'All posts were already imported, moving on.';
			return $imported_posts_data;
		}
		if ( $post_ids_for_import !== $all_live_posts_ids ) {
			$post_ids_for_import = array_values( $post_ids_for_import );
			echo "\n" . sprintf( '%s of total %d IDs were already imported, continuing from there. Hold tight..', count( $all_live_posts_ids ) - count( $post_ids_for_import ), count( $all_live_posts_ids ) );
		}

		// Import Posts.
		foreach ( $post_ids_for_import as $key_post_id => $post_id_live ) {
			// Output a '.' every 2000 objects to let CLI know it's running.
			if ( 0 == $key_post_id % 2000 ) {
				echo '.';
			}

			// Get all Post data from DB.
			$post_data = self::$logic->get_post_data( (int) $post_id_live, $this->live_table_prefix );
			$post_type = $post_data[ self::$logic::DATAKEY_POST ]['post_type'];

			// Extra check, shouldn't happen, but better safe than sorry.
			if ( ! in_array( $post_type, [ 'post', 'page', 'attachment' ] ) ) {
				$this->log( $this->log_error, sprintf( 'import_posts error, unexpected post_type %s for id_old=%s', $post_type, $post_id_live ) );
				echo "\n";
				WP_CLI::error( sprintf( 'Unexpected post_type %s for Live Post ID %s.', $post_type, $post_id_live ) );
			}

			// First just insert a new `wp_posts` record to get the new ID.
			try {
				$post_id_new           = self::$logic->insert_post( $post_data[ self::$logic::DATAKEY_POST ] );
				$imported_posts_data[] = [
					'post_type' => $post_type,
					'id_old'    => (int) $post_id_live,
					'id_new'    => (int) $post_id_new,
				];
			} catch ( \Exception $e ) {
				$this->log( $this->log_error, sprintf( 'import_posts error while inserting post_type %s id_old=%d : %s', $post_type, $post_id_live, $e->getMessage() ) );
				echo "\n";
				WP_CLI::warning( sprintf( 'Error inserting %s Live ID %d (details in log file)', $post_type, $post_id_live ) );

				// Error is logged. Continue importing other posts.
				continue;
			}

			// Now import all related Post data.
			$import_errors = self::$logic->import_post_data( $post_id_new, $post_data );
			if ( ! empty( $import_errors ) ) {
				$this->log( $this->log_error, sprintf( 'Following errors happened in import_posts() for post_type=%s, id_old=%d, id_new=%d :', $post_type, $post_id_live, $post_id_new ) );
				foreach ( $import_errors as $import_error ) {
					$this->log( $this->log_error, sprintf( '- %s', $import_error ) );
				}
				echo "\n";
				WP_CLI::warning( sprintf( 'Some errors while importing %s id_old=%d id_new=%d (details in log file).', $post_type, $post_id_live, $post_id_new ) );
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
			update_post_meta( $post_id_new, self::SAVED_META_LIVE_POST_ID, $post_id_live );
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
			echo "\n" . 'All posts already had their post_parent updated, moving on.';
			return;
		}
		if ( $parent_ids_for_update !== $all_live_posts_ids ) {
			$parent_ids_for_update = array_values( $parent_ids_for_update );
			echo "\n" . sprintf( '%s post_parent IDs of total %d were already updated, continuing from there..', count( $all_live_posts_ids ) - count( $parent_ids_for_update ), count( $all_live_posts_ids ) );
		}

		/**
		 * Helper map of imported Posts and Pages.
		 *
		 * @var array $imported_post_ids_map Keys are old Live IDs, values are new local IDs.
		 */
		$imported_post_ids_map    = [];
		$imported_posts_data_post = $this->filter_log_data_array( $imported_posts_data, [ 'post_type' => [ 'post', 'page' ] ], false );
		foreach ( $imported_posts_data_post as $entry ) {
			$imported_post_ids_map[ $entry['id_old'] ] = $entry['id_new'];
		}

		/**
		 * Helper map of imported Attachments.
		 *
		 * @var array $imported_attachment_ids_map Keys are old Live IDs, values are new local IDs.
		 */
		$imported_attachment_ids_map   = [];
		$imported_post_data_attachment = $this->filter_log_data_array( $imported_posts_data, [ 'post_type' => 'attachment' ], false );
		foreach ( $imported_post_data_attachment as $entry ) {
			$imported_attachment_ids_map[ $entry['id_old'] ] = $entry['id_new'];
		}

		// Update parent IDs.
		$i = 0;
		foreach ( $parent_ids_for_update as $key_id_old => $id_old ) {
			// Output a '.' every 2000 objects to let CLI know it's running.
			if ( 0 == $i % 2000 ) {
				echo '.';
			}
			$i++;

			// Get Post ID and its new parent_id.
			$id_new = $imported_post_ids_map[ $id_old ] ?? null;
			$id_new = is_null( $id_new ) ? $imported_attachment_ids_map[ $id_old ] : $id_new;
			$post   = get_post( $id_new );
			if ( is_null( $post ) ) {
				$this->log( $this->log_error, sprintf( 'update_post_parent_ids error, post not found in $imported_post_ids_map, $id_old=%s, $id_new=%s.', $id_old, $id_new ) );
				echo "\n";
				WP_CLI::warning( sprintf( 'Error updating post_parent, post not found in $imported_post_ids_map $id_old=%s, $id_new=%s.', $id_old, $id_new ) );
				continue;
			}

			// No update to do.
			if ( 0 === $post->post_parent ) {
				continue;
			}

			$parent_id_old = $post->post_parent;
			$parent_id_new = $imported_post_ids_map[ $post->post_parent ] ?? null;
			$parent_id_new = is_null( $parent_id_new ) & array_key_exists( $post->post_parent, $imported_attachment_ids_map ) ? $imported_attachment_ids_map[ $post->post_parent ] : $parent_id_new;

			// It's possible that this $post's post_parent already existed in local DB before the Content Diff import was run, so
			// it won't be present in the list of the posts we imported, $all_live_posts_ids. So let's try and search for this
			// post_parent directly in DB, and find it's new ID.
			if ( is_null( $parent_id_new ) ) {
				$parent_id_new = $this->get_existing_post_by_live_id( $parent_id_old );
			}

			// Check and warn if this post_parent object was not found/imported. It might be legit, like the parent object being a
			// post_type different than 'post','page' or 'attachment', or an error like the post_parent object missing in Live DB.
			if ( ( 0 !== $post->post_parent ) && is_null( $parent_id_new ) ) {
				$this->log( $this->log_error, sprintf( 'update_post_parent_ids error, $id_old=%s, $id_new=%s, $parent_id_old=%s, $parent_id_new is null.', $id_old, $id_new, $parent_id_old ) );
				echo "\n";
				WP_CLI::warning( sprintf( 'Error updating post_parent, $id_old=%s, $id_new=%s, $parent_id_old=%s, $parent_id_new is null.', $id_old, $id_new, $parent_id_old ) );
				continue;
			}

			// Update.
			self::$logic->update_post_parent( $post, $parent_id_new );

			// Log IDs of the Post.
			$log_entry = [
				'id_old' => $id_old,
				'id_new' => $post->ID,
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
		 * Helper map of imported Posts and Pages.
		 *
		 * @var array $imported_post_ids_map Keys are old Live IDs, values are new local IDs.
		 */
		$imported_post_ids_map    = [];
		$imported_posts_data_post = $this->filter_log_data_array( $imported_posts_data, [ 'post_type' => [ 'post', 'page' ] ], false );
		foreach ( $imported_posts_data_post as $entry ) {
			$imported_post_ids_map[ $entry['id_old'] ] = $entry['id_new'];
		}

		/**
		 * Helper map of imported Attachments.
		 *
		 * @var array $imported_attachment_ids_map Keys are old Live IDs, values are new local IDs.
		 */
		$imported_attachment_ids_map   = [];
		$imported_post_data_attachment = $this->filter_log_data_array( $imported_posts_data, [ 'post_type' => 'attachment' ], false );
		foreach ( $imported_post_data_attachment as $entry ) {
			$imported_attachment_ids_map[ $entry['id_old'] ] = $entry['id_new'];
		}

		// We need the old Live attachment IDs; we'll first search for those then update them with new IDs.
		$attachment_ids_for_featured_image_update = array_keys( $imported_attachment_ids_map );

		// Skip previously updated Attachment IDs.
		$updated_featured_images_data = $this->get_data_from_log( $this->log_updated_featured_imgs_ids, [ 'id_old', 'id_new' ] ) ?? [];
		foreach ( $updated_featured_images_data as $entry ) {
			$id_old     = $entry['id_old'] ?? null;
			$key_id_old = array_search( $id_old, $attachment_ids_for_featured_image_update );
			if ( ! is_null( $id_old ) && false !== $key_id_old ) {
				unset( $attachment_ids_for_featured_image_update[ $key_id_old ] );
			}
		}
		if ( empty( $attachment_ids_for_featured_image_update ) ) {
			echo "\n" . 'All posts already had their featured image IDs updated, moving on.';
			return;
		}
		if ( array_keys( $imported_attachment_ids_map ) !== $attachment_ids_for_featured_image_update ) {
			$attachment_ids_for_featured_image_update = array_values( $attachment_ids_for_featured_image_update );
			echo "\n" . sprintf( '%s of total %d attachments IDs already had their featured images imported, continuing from there..', count( $imported_attachment_ids_map ) - count( $attachment_ids_for_featured_image_update ), count( $imported_attachment_ids_map ) );
		}
		self::$logic->update_featured_images( $imported_post_ids_map, $attachment_ids_for_featured_image_update, $imported_attachment_ids_map, $this->log_updated_featured_imgs_ids );
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
		 * Helper map of imported Posts and Pages.
		 *
		 * @var array $imported_post_ids_map Keys are old Live IDs, values are new local IDs.
		 */
		$imported_post_ids_map    = [];
		$imported_posts_data_post = $this->filter_log_data_array( $imported_posts_data, [ 'post_type' => [ 'post', 'page' ] ], false );
		foreach ( $imported_posts_data_post as $entry ) {
			$imported_post_ids_map[ $entry['id_old'] ] = $entry['id_new'];
		}

		/**
		 * Helper map of imported Attachments.
		 *
		 * @var array $imported_attachment_ids_map Keys are old Live IDs, values are new local IDs.
		 */
		$imported_attachment_ids_map   = [];
		$imported_post_data_attachment = $this->filter_log_data_array( $imported_posts_data, [ 'post_type' => 'attachment' ], false );
		foreach ( $imported_post_data_attachment as $entry ) {
			$imported_attachment_ids_map[ $entry['id_old'] ] = $entry['id_new'];
		}

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
			echo "\n" . 'All posts already had their blocks\' att. IDs updated, moving on.';
			return;
		}
		if ( array_values( $imported_post_ids_map ) !== $new_post_ids_for_blocks_update ) {
			$new_post_ids_for_blocks_update = array_values( $new_post_ids_for_blocks_update );
			echo "\n" . sprintf( '%s of total %d posts already had their blocks\' IDs updated, continuing from there..', count( $imported_post_ids_map ) - count( $new_post_ids_for_blocks_update ), count( $imported_post_ids_map ) );
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
		$backup_prefix     = $assoc_args['backup-table-prefix'];
		$skip_tables       = [];

		if ( ! empty( $assoc_args['skip-tables'] ) ) {
			$skip_tables = explode( ',', $assoc_args['skip-tables'] );
		}

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

		foreach ( $tables_with_differing_collations as $result ) {
			echo 'Addressing ' . $result['table'] . "\n";
			self::$logic->copy_table_data_using_proper_collation( $live_table_prefix, $result['table'], $records_per_transaction, $sleep_in_seconds, $backup_prefix );
		}
	}

	/**
	 * Filters the log data array by where clause and returns found element(s).
	 *
	 * @param array $imported_posts_data Log data array, consists of subarrays with one or more multiple key=>values.
	 * @param array $where               Search conditions, match key and value(s) in $imported_posts_data. Value can either be
	 *                                   a scalar, or an array of multiple possible "or" values.
	 * @param bool  $return_first        If true, return just the first found entry, otherwise return all which match the conditions.
	 *
	 * @return array Found results. Mind that if $return_first is true, it will return a one-dimensional array,
	 *               and if $return_first is false, it will return two-dimensional array with all matched elements as subarrays.
	 */
	private function filter_log_data_array( $imported_posts_data, $where, $return_first = true ) {
		$return = [];
		foreach ( $imported_posts_data as $entry ) {
			// Check $where conditions.
			foreach ( $where as $key => $value ) {
				// If value is an array, it contains multiple OR options.
				$multiple_values = is_array( $value ) ? $value : [ $value ];
				foreach ( $multiple_values as $specific_value ) {
					if ( isset( $entry[ $key ] ) && $specific_value == $entry[ $key ] ) {
						if ( true === $return_first ) {
							// Return first element matching $where.
							return $entry;
						} else {
							// Return all the elements.
							$return[] = $entry;
						}
					}
				}
			}
		}

		return $return;
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
	 * Takes the old Live site's ID, and finds this post in the local site.
	 *
	 * @param int $id_live Old live site's post object ID.
	 *
	 * @return int|null Found local ID.
	 */
	private function get_existing_post_by_live_id( $id_live ) {
		global $wpdb;

		$live_posts_table = $this->live_table_prefix . 'posts';
		$posts_table      = $wpdb->posts;

		// phpcs:disable -- wpdb::prepare is used correctly.
		$parent_id_new = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT wp.ID
			FROM {$live_posts_table} lwp
			LEFT JOIN {$posts_table} wp
				ON wp.post_name = lwp.post_name
				AND wp.post_title = lwp.post_title
				AND wp.post_status = lwp.post_status
				AND wp.post_date = lwp.post_date
				AND wp.post_type = lwp.post_type
			WHERE lwp.ID = %d ;",
				$id_live
			)
		);
		// phpcs:enable

		return $parent_id_new;
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
