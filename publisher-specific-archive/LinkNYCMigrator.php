<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \WP_CLI;

/**
 * Custom migration scripts for LinkNYC.
 */
class LinkNYCMigrator implements InterfaceCommand {

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
			'newspack-content-migrator linknyc-molongui-to-cap',
			[ $this, 'cmd_molongui_to_cap' ],
			[
				'shortdesc' => 'Migrates Molongui plugin autorship to CAP.',
				'synopsis'  => [],
			]
		);
	}

	/**
	 * Create tags where needed from the 'topics' taxonomy and assign posts to them.
	 */
	public function cmd_molongui_to_cap() {
		echo 123;

		wp_cache_flush();
	}
}
