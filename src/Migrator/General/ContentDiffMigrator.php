<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use NewspackCustomContentMigrator\MigrationLogic\ContentDiffMigrator as ContentDiffMigratorLogic;
use \WP_CLI;

class ContentDiffMigrator implements InterfaceMigrator {

	const LIVE_DIFF_CONTENT_IDS_CSV = 'newspack-live-content-diff-ids-csv.txt';
	const LIVE_DIFF_CONTENT_LOG = 'newspack-live-content-diff.log';
	const LIVE_DIFF_CONTENT_ERR_LOG = 'newspack-live-content-diff-err.log';

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
			self::$logic->validate_core_wp_db_tables( $live_table_prefix );
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
	 * Callable for `newspack-content-migrator content-diff-import-new-live-content`.
	 *
	 * @param array $args       CLI args.
	 * @param array $assoc_args CLI assoc args.
	 */
	public function cmd_migrate_live_content( $args, $assoc_args ) {
		$import_dir = $assoc_args[ 'import-dir' ] ?? false;
		$live_table_prefix = $assoc_args[ 'live-table-prefix' ] ?? false;
		$log_file = $import_dir . '/' . self::LIVE_DIFF_CONTENT_LOG;
		$err_log_file = $import_dir . '/' . self::LIVE_DIFF_CONTENT_ERR_LOG;

		$file = $import_dir . '/' . self::LIVE_DIFF_CONTENT_IDS_CSV;
		if ( ! file_exists( $file ) ) {
			WP_CLI::error( sprintf( 'File %s not found.', $file ) );
		}
		$post_ids = explode( ',', file_get_contents( $file ) );
		if ( empty( $post_ids ) ) {
			WP_CLI::error( sprint( 'File %s does not contain valid CSV IDs.', $file ) );
		}
		try {
			self::$logic->validate_core_wp_db_tables( $live_table_prefix );
		} catch ( \Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		$time_start = microtime( true );
		$imported_post_ids = [];

		foreach ( $post_ids as $key_post_id => $post_id ) {
			WP_CLI::log( sprintf( '(%d/%d) migrating ID %d', $key_post_id + 1, count( $post_ids ), $post_id ) );
			$data = self::$logic->get_data( (int) $post_id, $live_table_prefix );
			try {
				$imported_post_id = self::$logic->insert_post( $data[ self::$logic::DATAKEY_POST ] );
			} catch ( \Exception $e ) {
				WP_CLI::error( sprintf( 'Error inserting post, ID %d', $post_id) );
				$this->log( $err_log_file, $e->getMessage() );
				continue;
			}

			$import_errors = self::$logic->import_post_data( $imported_post_id, $data );
			if ( ! empty( $import_errors ) ) {
				$msg_short = sprintf( 'Errors occurred while importing Live ID %d, imported ID %d.', $post_id, $imported_post_id );
				WP_CLI::warning( $msg_short );
				$msg_long = sprintf( 'Errors occurred while importing Live ID %d, imported ID %d : %s', $post_id, $imported_post_id, implode( '; ', $import_errors ) );
				$this->log( $err_log_file, $msg_long );
			}
			WP_CLI::success( sprintf( 'created Post ID %d (from Live Post ID %d)', $imported_post_id, $post_id ) );

			$imported_post_ids[ $post_id ] = $imported_post_id;
			$this->log( $log_file, sprintf( 'Imported live ID %d to ID %d', $post_id, $imported_post_id ) );
		}

		// Flush the cache in order for the `$wpdb->update()`s to sink in.
		wp_cache_flush();

		WP_CLI::log( 'Updating parent IDs...' );
		foreach ( $imported_post_ids as $post_id_old => $post_id_new ) {
			self::$logic->update_post_parent( $post_id_new, $imported_post_ids );
		}

		if ( file_exists( $err_log_file ) ) {
			WP_CLI::warning( sprintf( 'Some errors occurred! See %s on launch site for more details.', $err_log_file ) );
		}

		wp_cache_flush();

		WP_CLI::line( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
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
