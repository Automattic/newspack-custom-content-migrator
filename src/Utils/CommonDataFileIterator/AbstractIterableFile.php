<?php
/**
 * Abstract implementation of \NewspackCustomContentMigrator\Utils\Contracts\IterableFile.
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Utils\CommonDataFileIterator;

use \Exception;
use NewspackCustomContentMigrator\Utils\CommonDataFileIterator\Contracts\IterableFile;

/**
 * Abstract implementation of Iterable File contract.
 */
abstract class AbstractIterableFile extends AbstractFile implements Contracts\IterableFile {

	/**
	 * The row/line the iteration should begin at.
	 *
	 * @var int $start Default to 0 for first row/line.
	 */
	protected int $start = 0;

	/**
	 * The row/line at which the iteration should terminate.
	 *
	 * @var int $end Default value max integer.
	 */
	protected int $end = PHP_INT_MAX;

	/**
	 * Setter for $start.
	 *
	 * @param int $start Row/line to start iteration at.
	 * @returns IterableFile
	 * @throws Exception Thrown if $start is greater than $end.
	 */
	public function set_start( int $start = 0 ): IterableFile {
		if ( $start > $this->end ) {
			throw new Exception( 'Start cannot be greater than End.' );
		}

		$this->start = $start;

		return $this;
	}

	/**
	 * Returns the integer value representing the row/line at which iteration should begin.
	 *
	 * @returns int
	 */
	public function get_start(): int {
		return $this->start;
	}

	/**
	 * Setter for $end.
	 *
	 * @param int $end Row/line to end iteration at.
	 * @returns IterableFile
	 * @throws Exception Thrown if $end is less than $start.
	 */
	public function set_end( int $end = PHP_INT_MAX ): IterableFile {
		if ( $end < $this->start ) {
			throw new Exception( 'End cannot be less than Start.' );
		}

		$this->end = $end;

		return $this;
	}

	/**
	 * Returns the integer value representing the row/line at which iteration should end.
	 *
	 * @returns integer
	 */
	public function get_end(): int {
		return $this->end;
	}
}
