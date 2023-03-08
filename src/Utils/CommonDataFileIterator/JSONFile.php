<?php
/**
 * Concrete implementation of Abstract_Iterable_File for JSON files.
 *
 * @package NewspackCustomContentMigrator.
 */

namespace NewspackCustomContentMigrator\Utils\CommonDataFileIterator;

use \Iterator;

/**
 * Iterable File implementation for JSON file types.
 */
class JSONFile extends AbstractIterableFile {

	/**
	 * Get the Iterator for JSON files.
	 *
	 * @return Iterator
	 */
	public function getIterator(): Iterator {
		$json = json_decode( file_get_contents( $this->get_path() ), true );

		$row_count = 0;
		while ( $row_count < $this->get_start() ) {
			array_shift( $json );

			if ( empty( $json ) ) {
				yield [];
				return;
			}

			$row_count++;
		}

		while ( $row_count <= $this->get_end() && ! empty( $json ) ) {
			yield array_shift( $json );
			$row_count++;
		}
	}
}
