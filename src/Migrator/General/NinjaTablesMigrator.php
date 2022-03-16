<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use \WP_CLI;
use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\MigrationLogic\NinjaTables as NinjaTablesLogic;

/**
 * NinjaTables Plugin Migrator.
 */
class NinjaTablesMigrator implements InterfaceMigrator {
	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * @var NinjaTablesLogic $ninja_tables_logic
	 */
	private $ninja_tables_logic;

	/**
	 * NinjaTablesMigrator constructor.
	 */
	private function __construct() {
		$this->ninja_tables_logic = new NinjaTablesLogic();
	}

	/**
	 * Sets up NinjaTables plugin dependencies.
	 *
	 * @return InterfaceMigrator|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator export-ninja-tables',
			array( $this, 'cmd_export_ninja_tables' ),
			array(
				'shortdesc' => 'Export Ninja tables to a CSV or JSON file.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'output-dir',
						'description' => 'Output directory full path (no ending slash).',
						'optional'    => false,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'output-type',
						'description' => 'Output file type (csv, json). Defaults to CSV.',
						'optional'    => true,
						'repeating'   => false,
						'default'     => 'csv',
						'options'     => array( 'csv', 'json' ),
					),
				),
			)
		);
	}

	/**
	 * Callable for `newspack-content-migrator export-ninja-tables`.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function cmd_export_ninja_tables( $args, $assoc_args ) {
		// Get all Ninja tables.
		$reflector = new \ReflectionObject( $this->ninja_tables_logic->ninja_tables_admin );
		$method    = $reflector->getMethod( 'getAllTablesForMce' );
		$method->setAccessible( true );
		$all_tables = $method->invoke( $this->ninja_tables_logic->ninja_tables_admin );

		// Export tables.
		foreach ( $all_tables as $table ) {
			$table_id   = $table['value'];
			$table_name = $table['text'];
			if ( $table_id ) {
				$_REQUEST['table_id'] = $table_id;
				$this->ninja_tables_logic->export_data( $table_id, path_join( $assoc_args['output-dir'], "$table_name." . $assoc_args['output-type'] ), $assoc_args['output-type'] );
				WP_CLI::line( sprintf( 'Table exported successfully: %d', $table_id ) );
			}
		}
		WP_CLI::line( 'Export is done!' );
	}
}
