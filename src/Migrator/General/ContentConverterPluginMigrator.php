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
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_import_blocks_content_from_staging_site( $args, $assoc_args ) {
		$table_prefix = isset( $assoc_args[ 'table-prefix' ] ) ? $assoc_args[ 'table-prefix' ] : null;
		if ( is_null( $table_prefix ) ) {
			WP_CLI::error( 'Invalid table prefix.' );
		}

		global $wpdb;

		$staging_posts_table = $wpdb->dbh->real_escape_string( 'staging_' . $table_prefix . 'posts' );
		$posts_table = $wpdb->dbh->real_escape_string( $table_prefix . 'posts' );

		// Check if the backed up posts table from staging exists.
		$table_count = $wpdb->get_var(
			$wpdb->prepare (
				"SELECT COUNT(table_name) as table_count FROM information_schema.tables WHERE table_schema='%s' AND table_name='%s';",
				$wpdb->dbname,
				$staging_posts_table
			)
		);
		if ( 1 != $table_count ) {
			WP_CLI::error( sprintf( 'Table %s not found in DB, skipping importing block contents.', $staging_posts_table ) );
		}

		WP_CLI::line( 'Importing content previously converted to blocks from the Staging posts table...' );

		// Update wp_posts with converted content from the Staging wp_posts backup.
		$wpdb->get_results(
			"UPDATE $posts_table wp
			JOIN $staging_posts_table swp
				ON swp.ID = wp.ID
				AND swp.post_title = wp.post_title
				AND swp.post_content <> wp.post_content
			SET wp.post_content = swp.post_content
			WHERE swp.post_content LIKE '<!-- wp:%'; "
		);

		WP_CLI::success( 'Done.' );
	}
}
