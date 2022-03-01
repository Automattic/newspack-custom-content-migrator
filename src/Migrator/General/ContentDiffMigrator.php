<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use NewspackCustomContentMigrator\MigrationLogic\ContentDiffMigrator as ContentDiffMigratorLogic;
use \WP_CLI;

class ContentDiffMigrator implements InterfaceMigrator {

	const LIVE_DIFF_CONTENT_IDS_CSV = 'newspack-live-content-diff-ids-csv.txt';
	const LIVE_DIFF_CONTENT_LOG = 'newspack-live-content-diff.log';
	const LIVE_DIFF_CONTENT_ERR_LOG = 'newspack-live-content-diff-err.log';
	const LIVE_DIFF_CONTENT_BLOCKS_IDS_LOG = 'newspack-live-content-diff-blocks-ids.log';

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
	 * Callable for `newspack-content-migrator content-diff-import-new-live-content`.
	 *
	 * @param array $args       CLI args.
	 * @param array $assoc_args CLI assoc args.
	 */
	public function cmd_migrate_live_content( $args, $assoc_args ) {

$html = <<<HTML
<!-- wp:jetpack/slideshow {"ids":[111,222,333],"sizeSlug":"large"} -->
something
<!-- /wp:jetpack/slideshow -->

<!-- wp:image {"id":111,"sizeSlug":"large","linkDestination":"none"} -->
something else
<!-- /wp:image -->

<!-- wp:jetpack/tiled-gallery {"columnWidths":[["71.51704","28.48296"],["45.84734","54.15266"]],"ids":[222,111,333]} -->
always something :)
<!-- /wp:jetpack/tiled-gallery -->
HTML;

$imported_attachment_ids = [
	111 => 112,
	333 => 332,
];




		// Pattern for matching Gutenberg block's multiple CSV IDs attribute value.
		$pattern_csv_ids = '|
			(
				\<\!--       # beginning of the block element
				\s           # followed by a space
				wp\:[^\s]+   # element name/designation
				\s           # followed by a space
				{            # opening brace
				[^}]*        # zero or more characters except closing brace
				"ids"\:      # ids attribute
				\[           # opening square bracket containing CSV IDs
			)
			(
				 [\d,]+      # coma separated IDs
			)
			(
				\]           # closing square bracket containing CSV IDs
				[^\d\>]+     # any following char except numeric and comment closing angle bracket
			)
		|xims';
		preg_match_all( $pattern_csv_ids, $html, $matches );
		// Loop through all CSV IDs matches, and prepare replacements.
		$ids_csv_replacements = [];
		if ( isset( $matches[2] ) && ! empty( $matches[2] ) ) {
			foreach ( $matches[2] as $ids_csv ) {
				$ids = explode( ',', $ids_csv );
				$ids_updated = [];
				foreach ( $ids as $key_id => $id ) {
					if ( isset( $imported_attachment_ids[ $id ] ) ) {
						$ids_updated[ $key_id ] = $imported_attachment_ids[ $id ];
					} else {
						$ids_updated[ $key_id ] = $id;
					}
				}
				// Store "before CSV IDs" and "after CSV IDs" in $ids_csv_replacements.
				if ( $ids_updated != $ids ) {
					$ids_csv_replacements[ implode( ',', $ids ) ] = implode( ',', $ids_updated );
				}
			}
		}
		// Add to patterns and replacements for every CSV IDs string.
		foreach ( $ids_csv_replacements as $ids_csv_before => $ids_csv_after ) {
			$patterns[] = $pattern_csv_ids;
			$replacements[] = '${1}' . $ids_csv_after . '${3}';
		}

// $content_updated = preg_replace( $patterns, $replacements, $html );



		// This method uses preg_replace which takes an array of $patterns and $replacements.
		$patterns = [];
		$replacements = [];


		// 1. Update Gutenberg blocks which contain single IDs.
		// Pattern for matching any Gutenberg block's "id" attribute value ; uses %d as placeholder for sprintf.
		$pattern_id = '|
			(\<\!--      # beginning of the block element
			\s           # followed by a space
			wp\:[^\s]+   # element name/designation
			\s           # followed by a space
			{            # opening brace
			[^}]*        # zero or more characters except closing brace
			"id"\:)      # id attribute
			(%d)         # id value
			([^\d\>]+)   # any following char except numeric and comment closing angle bracket
		|xims';
		// Pattern for matching <img> element's class value which contains the att.ID ; uses %d as placeholder for sprintf.
		$pattern_img_class = '|
			(\<img
			[^\>]*                   # zero or more characters except closing angle bracket
			class="wp-image-)(%d)("  # class image with id
			[^\d"]*                  # zero or more characters except numeric and double quote
			/\>)                     # closing angle bracket
		|xims';
		// Compose patterns and replacements (for use by preg_replace) for every attachment ID.
		foreach ( $imported_attachment_ids as $att_id_old => $att_id_new ) {
			$patterns[] = sprintf( $pattern_id, $att_id_old );
			$replacements[] = '${1}' . $att_id_new . '${3}';
			$patterns[] = sprintf( $pattern_img_class, $att_id_old );
			$replacements[] = '${1}' . $att_id_new . '${3}';
		}






exit;

		$import_dir = $assoc_args[ 'import-dir' ] ?? false;
		$live_table_prefix = $assoc_args[ 'live-table-prefix' ] ?? false;

		// Clean up log files.
		$log_file = $import_dir . '/' . self::LIVE_DIFF_CONTENT_LOG;
		$log_file_err = $import_dir . '/' . self::LIVE_DIFF_CONTENT_ERR_LOG;
		$log_file_blocks_ids_updates = $import_dir . '/' . self::LIVE_DIFF_CONTENT_BLOCKS_IDS_LOG;
		if ( file_exists( $log_file ) ) {
			unlink( $log_file );
		}
		if ( file_exists( $log_file_err ) ) {
			unlink( $log_file_err );
		}
		if ( file_exists( $log_file_blocks_ids_updates ) ) {
			unlink( $log_file_blocks_ids_updates );
		}

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

		// Recreate all hierarchical taxonomies, such as categories and other custom taxonomies.
		WP_CLI::log( 'Recreating taxonomies...' );
		$created_terms_in_taxonomies = self::$logic->recreate_taxonomies( $live_table_prefix );
		if ( isset( $created_terms_in_taxonomies[ 'errors' ] ) && ! empty( $created_terms_in_taxonomies[ 'errors' ] ) ) {
			foreach ( $created_terms_in_taxonomies[ 'errors' ] as $error ) {
				WP_CLI::warning( $error );
				$this->log( $log_file_err, $error );
			}
		}
		WP_CLI::success( 'Done' );

		$time_start = microtime( true );
		$imported_post_ids = [];
		$imported_attachment_ids = [];
		WP_CLI::log( sprintf( 'Importing %d post objects, hold tight...', count( $post_ids ) ) );
		foreach ( $post_ids as $key_post_id => $post_id ) {
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
				WP_CLI::error( sprintf( 'Error inserting %s Live ID %d', $post_type, $post_id) );
				$this->log( $log_file_err, $e->getMessage() );
				continue;
			}

			// Import all the related Post data.
			$import_errors = self::$logic->import_post_data( $imported_post_id, $data );
			if ( ! empty( $import_errors ) ) {
				$msg_short = sprintf( 'Errors while importing %s, Live ID %d, imported ID %d.', $post_type, $post_id, $imported_post_id );
				WP_CLI::warning( $msg_short );
				$msg_long = sprintf( 'Errors while importing %s, Live ID %d, imported ID %d : %s', $post_type, $post_id, $imported_post_id, implode( '; ', $import_errors ) );
				$this->log( $log_file_err, $msg_long );
			}

			$this->log( $log_file, sprintf( 'Imported %s live ID %d to ID %d', $post_type, $post_id, $imported_post_id ) );
		}
		WP_CLI::success( sprintf( 'Done importing %d posts and %d attachments.', count( $imported_post_ids ), count( $imported_attachment_ids ) ) );

		// Flush the cache in order for the `$wpdb->update()`s to sink in.
		wp_cache_flush();

		WP_CLI::log( 'Updating Post ID references...' );
		foreach ( $imported_post_ids as $post_id_old => $post_id_new ) {
			self::$logic->update_post_parent( $post_id_new, $imported_post_ids );
		}
		if ( ! empty( $imported_attachment_ids ) ) {
			WP_CLI::log( 'Updating Featured images IDs...' );
			self::$logic->update_featured_images( $imported_attachment_ids );
			WP_CLI::log( 'Updating attachment IDs in block content...' );
			self::$logic->update_blocks_ids( $imported_post_ids, $imported_attachment_ids, $log_file_blocks_ids_updates );
		}
		WP_CLI::success( 'Done updating IDs.' );

		WP_CLI::log( sprintf( 'All done migrating content! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );

		// List all the logs which contain some info.
		$cli_output_logs_report = [];
		if ( file_exists( $log_file_err ) ) {
			$cli_output_logs_report[] = sprintf( '%s - some errors occurred', $log_file_err );
		}
		if ( file_exists( $log_file ) ) {
			$cli_output_logs_report[] = sprintf( '%s - list of imported IDs', $log_file );
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
	 * Logs error message to file.
	 *
	 * @param string $msg Error message.
	 */
	public function log( $file, $msg ) {
		file_put_contents( $file, $msg . "\n", FILE_APPEND );
	}
}
