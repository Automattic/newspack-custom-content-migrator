<?php

namespace NewspackCustomContentMigrator\Command\General;

use Newspack\MigrationTools\Command\WpCliCommandTrait;
use NewspackCustomContentMigrator\Command\RegisterCommandInterface;
use NewspackCustomContentMigrator\Logic\TablePress as TablePressLogic;
use WP_CLI;

/**
 * TablePress Plugin Migrator.
 */
class TablePressMigrator implements RegisterCommandInterface {

			use WpCliCommandTrait;

	/**
	 * @var TablePressLogic $table_press_logic
	 */
	private $table_press_logic;

	/**
	 * TablePressMigrator constructor.
	 */
	private function __construct() {
		$this->table_press_logic = new TablePressLogic();
	}

	/**
	 * {@inheritDoc}
	 */
	public static function register_commands(): void {
		WP_CLI::add_command(
			'newspack-content-migrator import-table-press-tables',
			self::get_command_closure( 'cmd_import_table_press' ),
			array(
				'shortdesc' => 'Import CSV or JSON files to TablePress plugin.',
				'synopsis'  => array(
array(
						'type'        => 'assoc',
						'name'        => 'input-dir',
						'description' => 'Input directory full path (no ending slash).',
						'optional'    => false,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'input-type',
						'description' => 'Input file type (csv, json). Defaults to CSV.',
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
	 * Callable for `newspack-content-migrator import-table-press-tables`.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function cmd_import_table_press( $args, $assoc_args ) {
		if ( is_null( $this->table_press_logic->tablepress_import ) ) {
			WP_CLI::error( 'TablePress plugin is a dependency, and will have to be installed before this command can be used.' );
		}

		$table_files  = array_filter( glob( $assoc_args['input-dir'] . '/*.' . $assoc_args['input-type'] ), 'is_file' );
		$total_tables               = count( $table_files );

		foreach ( $table_files as $key_table_file => $table_file ) {
			WP_CLI::line(sprintf( 'Importing table %d/%d.', $key_table_file + 1, $total_tables ) );

			$data     = file_get_contents( $table_file ); //phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$table_id       = $this->table_press_logic->import_table( $assoc_args['input-type'], $data, $table_file, 'Imported table.' );
			if ( \is_wp_error( $table_id ) ) {
				WP_CLI::warning( sprintf( 'An error occured while importing the table %s', $table_file ) );
			}
		}

		WP_CLI::line( 'Import is done!' );
	}
}
