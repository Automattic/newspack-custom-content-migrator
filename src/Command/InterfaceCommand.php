<?php

namespace NewspackCustomContentMigrator\Command;

interface InterfaceCommand {

	/**
	 * Ensures that the commands will be registered by calling this method.
	 */
	public function register_commands();

}
