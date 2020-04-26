<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;

class ContentConverterPluginMigrator implements InterfaceMigrator {

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
	 * Singleton get_instance().
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
		WP_CLI::add_command( 'newspack-content-migrator back-up-converter-plugin-staging-table', array( $this, 'cmd_back_up_converter_plugin_staging_table' ), [
			'shortdesc' => "Creates a backup copy of the Newspack Content Converter plugin's Staging site table.",
		] );

		WP_CLI::add_command( 'newspack-content-migrator import-blocks-content-from-staging-site', array( $this, 'cmd_import_blocks_content_from_staging_site' ), [
			'shortdesc' => "Imports previously backed up Newspack Content Converter plugin's Staging site table contents.",
			'synopsis'  => [
				[
					'type'        => 'assoc',
					'name'        => 'table-prefix',
					'description' => 'WP DB table prefix.',
					'optional'    => false,
					'repeating'   => false,
				],
			],
		] );
	}

	/**
	 * Callable for the back-up-converter-plugin-staging-table command.
	 * Exits with code 0 for success or 1 otherwise.
	 *
	 * @param $args
	 * @param $assoc_args
	 * @return 1|0 exit code.
	 */
	public function cmd_back_up_converter_plugin_staging_table( $args, $assoc_args ) {
		global $wpdb;

		$table_count = $wpdb->get_var(
			$wpdb->prepare (
				"SELECT COUNT(table_name) as table_count FROM information_schema.tables WHERE table_schema='%s' AND table_name='ncc_wp_posts';",
				$wpdb->dbname
			)
		);
		// If table doesn't exist, return exit code 1.
		if ( 1 != $table_count ) {
			exit(1);
		}

		WP_CLI::line( 'Creating a backup of the Newspack Content Converter Plugin table...' );

		// Create `staging_ncc_wp_posts_backup`.
		$wpdb->get_results( "DROP TABLE IF EXISTS staging_ncc_wp_posts_backup;" );
		$wpdb->get_results( "CREATE TABLE staging_ncc_wp_posts_backup LIKE ncc_wp_posts;" );
		$wpdb->get_results( "INSERT INTO staging_ncc_wp_posts_backup SELECT * FROM ncc_wp_posts;" );

		WP_CLI::success( 'Done.' );
	}

	/**
	 * Callable for the back-up-converter-plugin-staging-table command.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_import_blocks_content_from_staging_site( $args, $assoc_args ) {
		global $wpdb;

		$table_prefix = isset( $assoc_args[ 'table-prefix' ] ) ? $assoc_args[ 'table-prefix' ] : null;
		if ( is_null( $table_prefix ) ) {
			WP_CLI::error( 'Invalid table prefix.' );
		}

		WP_CLI::line( 'Importing content from the Staging site which was previously converted to blocks...' );

		// An older version of the NCC Plugin didn't use this column, must add it first.
		$results1 = $wpdb->get_results( "ALTER TABLE staging_ncc_wp_posts_backup
     		ADD COLUMN IF NOT EXISTS `retry_conversion` tinyint(1) DEFAULT NULL; "
		);

		// Update ncc_wp_posts with converted content from staging_ncc_wp_posts_backup.
		$results2 = $wpdb->get_results( "UPDATE ncc_wp_posts ncc
			JOIN staging_ncc_wp_posts_backup sncc
				ON sncc.ID = ncc.ID AND sncc.post_title = ncc.post_title
			SET ncc.post_content_gutenberg_converted = sncc.post_content_gutenberg_converted
			WHERE sncc.post_content_gutenberg_converted <> ''
			AND ncc.post_content NOT LIKE '<!-- wp:%'; "
		);

		// Update wp_posts with converted content from staging_ncc_wp_posts_backup.
		$table_name = $wpdb->dbh->real_escape_string( $table_prefix . 'posts' );
		$results3 = $wpdb->get_results(
			"UPDATE $table_name wp
			JOIN staging_ncc_wp_posts_backup sncc
				ON wp.ID = sncc.ID AND wp.post_title = sncc.post_title
			SET wp.post_content = sncc.post_content_gutenberg_converted
			WHERE sncc.post_content_gutenberg_converted <> ''; "
		);

		WP_CLI::success( 'Done.' );
	}
}
