<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use \WP_CLI;

/**
 * Custom migration scripts for Moco360.
 */
class Moco360Migrator implements InterfaceCommand {

	/**
	 * @var null|CLASS Instance.
	 */
	private static $instance = null;

	/**
	 * @var CoAuthorPlus $coauthorsplus_logic
	 */
	private $coauthorsplus_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthorsplus_logic = new CoAuthorPlus();
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return PostsMigrator|null
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
		WP_CLI::add_command(
			'newspack-content-migrator moco360-bethesda-get-gas-and-their-posts',
			[ $this, 'cmd_bethesda_get_gas_and_their_posts' ]
		);
		WP_CLI::add_command(
			'newspack-content-migrator moco360-recreate-gas',
			[ $this, 'cmd_moco360_recreate_gas' ]
		);
	}

	/**
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_bethesda_get_gas_and_their_posts( $args, $assoc_args ) {
		// Get all GAs.
		// Info like name, bio, ...
		// Is linked to WP User account? Which.

		// Save GA authors info file.

		// Get all Posts' GAs and WP User authors.
	}

	/**
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_moco360_recreate_gas( $args, $assoc_args ) {
		// Load GAs.
		// Load posts GAs and WP User authors.

		// Recreate GAs.

		// Find posts by original ID and assign GAs.

	}
}
