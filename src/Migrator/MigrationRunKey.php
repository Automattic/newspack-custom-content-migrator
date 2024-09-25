<?php

namespace NewspackCustomContentMigrator\Migrator;

interface MigrationRunKey {

	/**
	 * Returns the migration run key.
	 *
	 * @return string
	 */
	public function get(): string;
}