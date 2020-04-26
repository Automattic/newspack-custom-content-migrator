<?php

namespace NewspackCustomContentMigrator\Migrator;

interface InterfaceMigrator {

	/**
	 * Ensures that the commands will be registered by calling this method.
	 */
	public function register_commands();

}
