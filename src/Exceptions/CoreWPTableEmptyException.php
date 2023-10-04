<?php
/**
 * CoreWPTableEmptyException class.
 *
 * @package NewspackCustomContentMigrator\Exceptions
 */

namespace NewspackCustomContentMigrator\Exceptions;

/**
 * Class CoreWPTableEmptyException.
 *
 * This class facilitates the handling of instances where core WP tables are empty. It might be necessary
 * to ignore certain tables even if they are empty. This class will allow us to obtain that list of
 * empty core tables and allow us to make a decision on a case-by-case basis how to proceed.
 */
class CoreWPTableEmptyException extends \Exception {

	/**
	 * Array of table names that are empty.
	 *
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
