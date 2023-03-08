<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \WP_CLI;

/**
 * Custom migration scripts for Retro Report.
 */
class NewsroomNZMigrator implements InterfaceCommand {

	/**
	 * Instance of RetroReportMigrator
	 *
	 * @var null|InterfaceCommand
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
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {

		\WP_CLI::add_command(
			'newspack-content-migrator newsroom-nz-import-articles',
			[ $this, 'cmd_import_articles' ],
			[
				'shortdesc' => 'Import articles from a Newsroom NZ article XML export.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'xml',
						'optional'    => false,
						'description' => 'The XML export file location'
					]
				]
			]
		);

	}

	public function cmd_import_articles( $args, $assoc_args ) {
		\WP_CLI::line( 'Importing Newsroom NZ articles...' );
	}

}