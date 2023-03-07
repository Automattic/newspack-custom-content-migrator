<?php
/**
 * Contract to define Iterable_File.
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Utils\CommonDataFileIterator\Contracts;

use IteratorAggregate;
use NewspackCustomContentMigrator\Utils\Contracts\File as FileInterface;

interface IterableFile extends FileInterface, IteratorAggregate {
	/**
	 * Set the row at which iteration should begin.
	 *
	 * @param int $start Integer value representing the row at which iteration should begin.
	 *
	 * @return IterableFile|void
	 */
	public function set_start( int $start = 0 );

	/**
	 * Get the row at which iteration should begin.
	 *
	 * @return int
	 */
	public function get_start(): int;

	/**
	 * Set the row at which the iteration should end.
	 *
	 * @param int $end Integer value representing the row at which the iteration should end.
	 *
	 * @return IterableFile|void
	 */
	public function set_end( int $end = PHP_INT_MAX );

	/**
	 * Get the row at which iteration should end.
	 *
	 * @return int
	 */
	public function get_end(): int;
}
