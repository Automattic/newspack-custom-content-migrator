<?php

namespace NewspackCustomContentMigrator\Exceptions;

class CoreWPTableEmptyException extends \Exception {

	/**
	 * @var string[] $tables Names of empty tables.
	 */
	protected array $tables = [];

	/**
	 * Constructor.
	 *
	 * @param string[] $tables Table names.
	 */
	public function __construct( array $tables ) {
		$this->tables = $tables;
		parent::__construct( sprintf( 'The following core WP Tables are empty: %s', implode( $this->tables ) ) );
	}

	/**
	 * Returns the names of the empty tables.
	 *
	 * @return string[]
	 */
	public function get_tables(): array {
		return $this->tables;
	}
}
