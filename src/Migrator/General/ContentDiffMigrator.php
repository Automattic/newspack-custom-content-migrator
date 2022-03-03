<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use NewspackCustomContentMigrator\MigrationLogic\ContentDiffMigrator as ContentDiffMigratorLogic;
use \WP_CLI;

class ContentDiffMigrator implements InterfaceMigrator {

	const LIVE_DIFF_CONTENT_IDS_CSV = 'content-diff__new-ids-csv.log';
	const LIVE_DIFF_CONTENT_LOG_IMPORTED_IDS = 'content-diff__imported-ids.log';
	const LIVE_DIFF_CONTENT_LOG_UPDATED_IDS_REFERENCES = 'content-diff__updated-id-refs.log';
	const LIVE_DIFF_CONTENT_ERR_LOG = 'content-diff__err.log';
	const LIVE_DIFF_CONTENT_BLOCKS_IDS_LOG = 'content-diff__wp-blocks-ids-updates.log';

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * @var null|ContentDiffMigratorLogic Logic.
	 */
	private static $logic = null;

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

			self::$logic = new ContentDiffMigratorLogic( $wpdb );
			self::$instance = new $class;
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
	}

	/**
	 * Callable for `newspack-content-migrator content-diff-search-new-content-on-live`.
	 *
	 * @param array $args       CLI args.
	 * @param array $assoc_args CLI assoc args.
	 */
	public function cmd_search_new_content_on_live( $args, $assoc_args ) {
		$export_dir = $assoc_args[ 'export-dir' ] ?? false;
		$live_table_prefix = $assoc_args[ 'live-table-prefix' ] ?? false;

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

		$file = $export_dir . '/' . self::LIVE_DIFF_CONTENT_IDS_CSV;
		file_put_contents( $export_dir . '/' . self::LIVE_DIFF_CONTENT_IDS_CSV, implode( ',', $ids ) );

		WP_CLI::success( sprintf( '%d new IDs found, and a list of these IDs exported to %s', count( $ids ), $file ) );
	}

	/**
	 * Callable for `newspack-content-migrator content-diff-migrate-live-content`.
	 *
	 * @param array $args       CLI args.
	 * @param array $assoc_args CLI assoc args.
	 */
	public function cmd_migrate_live_content( $args, $assoc_args ) {
		$import_dir = $assoc_args[ 'import-dir' ] ?? false;
		$live_table_prefix = $assoc_args[ 'live-table-prefix' ] ?? false;

		// Time stamp log files.
		$log_file_imported_ids = $import_dir . '/' . self::LIVE_DIFF_CONTENT_LOG_IMPORTED_IDS;
		$log_file_updated_ids_refs = $import_dir . '/' . self::LIVE_DIFF_CONTENT_LOG_UPDATED_IDS_REFERENCES;
		$log_file_err = $import_dir . '/' . self::LIVE_DIFF_CONTENT_ERR_LOG;
		$log_file_blocks_ids_updates = $import_dir . '/' . self::LIVE_DIFF_CONTENT_BLOCKS_IDS_LOG;
		$ts = date( 'Y-m-d h:i:s a', time() );
		$this->log( $log_file_imported_ids, sprintf( 'Starting Content Diff %s.', $ts ) );
		$this->log( $log_file_updated_ids_refs, sprintf( 'Starting Content Diff %s.', $ts ) );
		$this->log( $log_file_blocks_ids_updates, sprintf( 'Starting Content Diff %s.', $ts ) );

		// Validate params.
		$file_ids_csv = $import_dir . '/' . self::LIVE_DIFF_CONTENT_IDS_CSV;
		if ( ! file_exists( $file_ids_csv ) ) {
			WP_CLI::error( sprintf( 'File %s not found.', $file_ids_csv ) );
		}
		$post_ids = explode( ',', file_get_contents( $file_ids_csv ) );
		if ( empty( $post_ids ) ) {
			WP_CLI::error( sprint( 'File %s does not contain valid CSV IDs.', $file_ids_csv ) );
		}
		try {
			self::$logic->validate_core_wp_db_tables( $live_table_prefix, [ 'options' ] );
		} catch ( \Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		echo 'Recreating categories..';
		$created_terms_in_taxonomies = self::$logic->recreate_categories( $live_table_prefix );
		if ( isset( $created_terms_in_taxonomies[ 'errors' ] ) && ! empty( $created_terms_in_taxonomies[ 'errors' ] ) ) {
			foreach ( $created_terms_in_taxonomies[ 'errors' ] as $error ) {
				WP_CLI::warning( $error );
				$this->log( $log_file_err, $error );
			}
		}
		echo "\n";
		WP_CLI::success( 'Done' );

		$time_start = microtime( true );
		$imported_post_ids = [];
		$imported_attachment_ids = [];
		echo sprintf( 'Importing %d post objects, hold tight..', count( $post_ids ) );

		// $already_imported_post_ids = $this->match_old_and_new_ids_from_log( $log_file_imported_ids, '|Imported\s[^\s]+\slive\sID\s(\d+)\sto\sID\s(\d+)|xims' );
		$already_imported_post_ids = $this->get_imported_ids_from_log( $log_file_imported_ids );
		foreach ( $post_ids as $key_post_id => $post_id ) {

			// Skip if Post IDs was already imported.
			if ( in_array( $post_id, $already_imported_post_ids ) ) {
				continue;
			}

			// Output a '.' every 2000 objects to prevent process getting killed.
			if (  0 == $key_post_id % 2000 ) {
				echo '.';
			}

			$data = self::$logic->get_data( (int) $post_id, $live_table_prefix );
			$post_type = $data[ self::$logic::DATAKEY_POST ][ 'post_type' ];
			try {
				// Import and create just the `posts` table record, and get the new ID.
				$imported_post_id = self::$logic->insert_post( $data[ self::$logic::DATAKEY_POST ] );
				if ( 'attachment' == $post_type ) {
					$imported_attachment_ids[ $post_id ] = $imported_post_id;
				} else {
					$imported_post_ids[ $post_id ] = $imported_post_id;
				}
			} catch ( \Exception $e ) {
				$this->log( $log_file_err, sprintf( 'Error inserting %s Live ID %d : %s', $post_type, $post_id, $e->getMessage() ) );
				echo "\n";
				WP_CLI::warning( sprintf( 'Error inserting %s Live ID %d (details in log file)', $post_type, $post_id) );
				continue;
			}

			// Import all the related Post data.
			$import_errors = self::$logic->import_post_data( $imported_post_id, $data );
			if ( ! empty( $import_errors ) ) {
				$this->log( $log_file_err, sprintf( 'Some errors while importing %s, Live ID %d, imported ID %d : %s', $post_type, $post_id, $imported_post_id, implode( '; ', $import_errors ) ) );
				echo "\n";
				WP_CLI::warning( sprintf( 'Some errors while importing %s, Live ID %d, imported ID %d (details in log file).', $post_type, $post_id, $imported_post_id ) );
			}

			$this->log( $log_file_imported_ids, json_encode( [ 'post_type' => $post_type, 'old_id' => $post_id, 'new_id' => $imported_post_id, ] ) );
		}
		echo "\n";
		WP_CLI::success( sprintf( 'Done importing %d posts and %d attachments.', count( $imported_post_ids ), count( $imported_attachment_ids ) ) );

		// Flush the cache in order for the `$wpdb->update()`s to sink in.
		wp_cache_flush();

		echo 'Updating Post ID references..';
		// '|Imported\s[^\s]+\slive\sID\s(\d+)\sto\sID\s\d+|xims'
		$already_updated_old_post_ids_refs = array_merge( $this->match_old_and_new_ids_from_log( $log_file_updated_ids_refs, '|Updated\sold\spost\sID\sreferences\s(%d+)\sto\snew\spost\sID\s%d+|xims' ) );
		$old_post_ids_which_need_updating = array_merge( $already_imported_post_ids, array_keys( $imported_post_ids ) );
		$i = 0;
		foreach ( $old_post_ids_which_need_updating as $post_id_old => $post_id_new ) {

			// Skip if Post IDs was already updated.
			if ( in_array( $post_id_old, $already_updated_old_post_ids_refs ) ) {
				continue;
			}

			self::$logic->update_post_parent( $post_id_new, $imported_post_ids );
			$this->log( $log_file_updated_ids_refs, sprintf( 'Updated old post ID references %d to new post ID %d', $post_id_old, $post_id_new ) );
			// Output a '.' every 2000 objects to prevent process getting killed.
			if ( 0 == $i % 2000 ) {
				echo '.';
			}
			$i++;
		}
		echo "\n";
		if ( ! empty( $imported_attachment_ids ) ) {
			echo 'Updating Featured images IDs..';
			self::$logic->update_featured_images( $imported_attachment_ids );
			echo "\n";
			echo 'Updating attachment IDs in block content..';
			self::$logic->update_blocks_ids( $imported_post_ids, $imported_attachment_ids, $log_file_blocks_ids_updates );
			echo "\n";
		}
		WP_CLI::success( 'Done updating IDs.' );

		WP_CLI::log( sprintf( 'All done migrating content! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );

		// List all the logs which contain some info.
		$cli_output_logs_report = [];
		if ( file_exists( $log_file_err ) ) {
			$cli_output_logs_report[] = sprintf( '%s - some errors occurred', $log_file_err );
		}
		if ( file_exists( $log_file_imported_ids ) ) {
			$cli_output_logs_report[] = sprintf( '%s - list of imported IDs', $log_file_imported_ids );
		}
		if ( file_exists( $log_file_blocks_ids_updates ) ) {
			$cli_output_logs_report[] = sprintf( '%s - detailed log of blocks IDs updates', $log_file_blocks_ids_updates );
		}
		if ( ! empty( $cli_output_logs_report ) ) {
			WP_CLI::log( "Check the logs for more details:" );
			WP_CLI::log( "- " . implode( "\n- ", $cli_output_logs_report ) );
		}

		wp_cache_flush();
	}

	/**
	 * Gets old_id and new_id pairs from the formatted log of imported IDs.
	 *
	 * @param $log_file_imported_ids Path to log.
	 *
	 * @return array Keys are old (original live) IDs, values are new (imported) IDs.
	 */
	private function get_imported_ids_from_log( $log_file_imported_ids ) {
		$old_new_ids = [];
		$log = file_get_contents( $log_file_imported_ids );
		$lines = explode( "\n", $log );
		foreach ( $lines as $line ) {
			$decoded = json_decode( $line, true );
			if ( empty ( $decoded ) ) {
				continue;
			}
			$old_new_ids[ $decoded[ 'old_id' ] ] = $decoded[ 'new_id' ];
		}

		return $old_new_ids;
	}

	/**
	 * Reads old and new Post IDs from a formatted log file.
	 *
	 * @param string $log_file Formatted log file containing Post IDs.
	 * @param array  $pattern  Regex pattern which matches the Post IDs from the formatted log.
	 *
	 * @return array Matched Post IDs.
	 */
	private function match_old_and_new_ids_from_log( $log_file, $pattern ) {
		$log = file_get_contents( $log_file );
		preg_match_all( $pattern, $log, $matches );
		if ( ! isset( $matches[1] ) || empty( $matches[1] ) ) {
			return [];
		}

		$already_imported_post_ids = array_values( $matches[1] );

		return $already_imported_post_ids;
	}

	/**
	 * Logs error message to file.
	 *
	 * @param string $msg Error message.
	 */
	public function log( $file, $msg ) {
		file_put_contents( $file, $msg . "\n", FILE_APPEND );
	}
}
