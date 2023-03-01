<?php

namespace NewspackCustomContentMigrator\Utils;

use Exception;
use IteratorAggregate;
use NewspackCustomContentMigrator\Utils\Contracts\IterableFile;

class FileImportFactory {
	/**
	 * Given a file path, return an IterableFile object.
	 *
	 * @param string $file_path Full path to file.
	 *
	 * @throws Exception If file does not exist.
	 */
	public function get_file( string $file_path ): IterableFile {
		if ( ! file_exists( $file_path ) ) {
			throw new Exception( 'File does not exist!' );
		}

		if ( str_ends_with( $file_path, 'csv' ) ) {
			return $this->make_csv( $file_path );
		}

		if ( str_ends_with( $file_path, 'json' ) ) {
			return $this->make_json( $file_path );
		}

		throw new Exception( 'Unsupported File Type.' );
	}

	/**
	 * Given a file path, return a CSVFile object.
	 *
	 * @param string $file_path Full path to file.
	 *
	 * @return CSVFile
	 * @throws Exception If file does not exist.
	 */
	protected function make_csv( string $file_path ): CSVFile {
		return new CSVFile( $file_path );
	}

	/**
	 * Given a file path, return a JSONFile object.
	 *
	 * @param string $file_path Full path to file.
	 *
	 * @return IterableFile
	 * @throws Exception If file does not exist.
	 */
	protected function make_json( string $file_path ): IterableFile {
		return new Json_File( $file_path );
	}
}