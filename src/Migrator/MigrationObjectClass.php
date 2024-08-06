<?php

namespace NewspackCustomContentMigrator\Migrator;

use NewspackCustomContentMigrator\Migrator\AbstractMigrationObject;

class MigrationObjectClass extends AbstractMigrationObject {

	public function __construct( MigrationRunKey $run_key, object|array $data, string $pointer_to_identifier = 'id' ) {
		parent::__construct( $run_key );
		$this->set( $data, $pointer_to_identifier );
	}
}