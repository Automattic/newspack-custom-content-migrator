<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \WP_CLI;

/**
 * Custom migration scripts for Latin Finance.
 */
class LatinFinanceMigrator implements InterfaceCommand {

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceCommand|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator latinfinance-import-from-mssql',
			[ $this, 'cmd_import_from_mssql' ],
			[
				'shortdesc' => 'Imports content from MS SQL DB as posts.',
				'synopsis'  => [],
			]
		);
	}

	/**
	 * Callable for 'newspack-content-migrator latinfinance-import-from-mssql'.
	 */
	public function cmd_import_from_mssql() {
		echo 123;
	}
}
