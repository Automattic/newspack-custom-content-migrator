<?php

namespace NewspackCustomContentMigrator\Command;

use Exception;

/**
 * Interface for registering commands.
 * 
 * Used in the PluginSetup class to register commands with WP CLI.
 */
interface RegisterCommandInterface {

	/**
	 * Register commands with WP CLI.
	 *
	 * @throws Exception If the command registration fails.
	 */
	public static function register_commands(): void;

	/**
	 * Get the instance of the class.
	 *
	 * @return self
	 */
	public static function get_instance(): self;

}
