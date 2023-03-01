<?php
/**
 * Concrete CSV File Implementation.
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Utils;

use Exception;
use Iterator;
use NewspackCustomContentMigrator\Utils\Contracts\CSVFile as CSVFileInterface;
use NewspackCustomContentMigrator\Utils\Contracts\IterableFile;

/**
 * Short description.
 */
class CSVFile extends AbstractIterableFile implements CSVFileInterface {

	/**
	 * CSV start row.
	 *
	 * @var int $start
	 */
	protected int $start = 1;

	/**
	 * The separator character to be used for CSV parsing.
	 *
	 * @var string $separator
	 */
	protected string $separator = ',';

	/**
	 * The enclosure character to be used for CSV parsing.
	 *
	 * @var string $enclosure
	 */
	protected string $enclosure = '"';

	/**
	 * The escape character to be used for CSV parsing.
	 *
	 * @var string $escape
	 */
	protected string $escape = '\\';

	/**
	 * Defines the encoding to be used for decoding the CSV file.
	 *
	 * @var string|bool $encoding
	 */
	protected $encoding = false;

	/**
	 * Constructor.
	 *
	 * @param string $path Full path to CSV File.
	 * @param string $separator CSV Separator to use.
	 * @param string $enclosure CSV Enclosure Char to use.
	 * @param string $escape CSV Escape Char to use.
	 *
	 * @throws Exception Throws Exception if file is not found.
	 */
	public function __construct( string $path, string $separator = ',', string $enclosure = '"', string $escape = '\\' ) {
		parent::__construct( $path );
		$this->set_separator( $separator );
		$this->set_enclosure( $enclosure );
		$this->set_escape( $escape );

		$this->encoding = function_exists( 'mb_detect_encoding' ) ?
			mb_detect_encoding( $path, 'UTF-8, ISO-8859-1', true ) :
			false;
	}

	/**
	 * Returns the very first row in a CSV, the header.
	 *
	 * @inheritDoc
	 * @throws Exception Exception thrown if file does not exist on system.
	 */
	public function get_header(): array {
		return $this->get_row( rewind( $this->get_handle() ) );
	}

	/**
	 * The iterator that provides the CSV rows.
	 *
	 * @return Iterator
	 * @throws Exception Exception thrown if file does not exist on system.
	 */
	public function getIterator(): Iterator {
		$handle = $this->get_handle();
		$header = $this->get_row( $handle );
		$header = array_map( fn( $column) => trim( $column ), $header );
		$header_count = count( $header );

		$row_count = 1;
		while ( $row_count < $this->get_start() ) {
			$this->get_row( $handle ); // Move the file handle to the desired starting position.

			if ( feof( $handle ) ) {
				yield [];
				return;
			}

			$row_count++;
		}

		while ( $row_count <= $this->get_end() && ! feof( $handle ) ) {
			$raw_row = $this->get_row( $handle );

			if ( $header_count !== count( $raw_row ) ) {
				throw new Exception( 'CSV row does not have the same number of columns as the header. Likely an issue with the CSV File.' );
			}

			$row = array_combine( $header, $raw_row );

			array_map(
				function( $value ) {
					if ( 'UTF-8' == $this->get_encoding() ) {
						return function_exists( 'utf8_encode' ) ? utf8_encode( $value ) : $value;
					}

					return $value;
				},
				$row
			);

			yield $row;
			$row_count++;
		}
	}


	/**
	 * Overriding the default behavior of set_start to ensure we always skip the header CSV row.
	 *
	 * @param int $start Starting row position.
	 *
	 * @return IterableFile
	 * @throws Exception Will throw exception if $start is greater than $max.
	 */
	public function set_start( int $start = 1 ): IterableFile {
		if ( 1 > $start ) {
			$start = 1;
		}
		return parent::set_start( $start );
	}

	/**
	 * Set the separator character to be used for CSV parsing.
	 *
	 * @param string $separator Separator character to be used for CSV parsing.
	 *
	 * @return CSVFileInterface
	 */
	public function set_separator( string $separator ): CSVFileInterface {
		$this->separator = $separator;

		return $this;
	}

	/**
	 * Get the separator used for CSV parsing.
	 *
	 * @return string
	 */
	public function get_separator(): string {
		return $this->separator;
	}

	/**
	 * Set the enclosure character to be used for CSV parsing.
	 *
	 * @param string $enclosure The enclosure character used for CSV parsing.
	 *
	 * @return CSVFileInterface
	 */
	public function set_enclosure( string $enclosure ): CSVFileInterface {
		$this->enclosure = $enclosure;

		return $this;
	}

	/**
	 * Returns the enclosure character used for CSV parsing.
	 *
	 * @return string
	 */
	public function get_enclosure(): string {
		return $this->enclosure;
	}

	/**
	 * Set the escape character to be used for CSV Parsing.
	 *
	 * @param string $escape The escape character used for CSV parsing.
	 *
	 * @return CSVFileInterface
	 */
	public function set_escape( string $escape ): CSVFileInterface {
		$this->escape = $escape;

		return $this;
	}

	/**
	 * Returns the escape character to be used for CSV parsing.
	 *
	 * @return string
	 */
	public function get_escape(): string {
		return $this->escape;
	}

	/**
	 * Convenience function to facilitate obtaining a CSV row.
	 *
	 * @param resource $handle The open file handle for the CSV file.
	 * @throws Exception Exception thrown if $handle is not a resource.
	 */
	protected function get_row( $handle ): array {
		if ( ! is_resource( $handle ) ) {
			throw new Exception( '$handle is not a resource.' );
		}

		@ini_set( 'auto_detect_line_endings', true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return fgetcsv(
			$handle,
			0,
			$this->get_separator(),
			$this->get_enclosure(),
			$this->get_escape()
		);
	}

	/**
	 * Returns the CSV File encoding. False if unable to detect the encoding.
	 *
	 * @return bool|string
	 */
	private function get_encoding() {
		return $this->encoding;
	}
}
