<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \WP_CLI;

/**
 * Custom migration scripts for The Emancipator.
 */
class TheEmancipator implements InterfaceCommand {

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
			'newspack-content-migrator emancipator-authors',
			[ $this, 'cmd_authors' ],
			[
				'shortdesc' => 'This is an initial version of the command, it will probably migrate authors from end of post_content into actual authors.',
				'synopsis'  => [],
			]
		);


	}

	/**
	 * Create tags where needed from the 'topics' taxonomy and assign posts to them.
	 */
	public function cmd_authors() {
		echo 'hi';
	}
}
