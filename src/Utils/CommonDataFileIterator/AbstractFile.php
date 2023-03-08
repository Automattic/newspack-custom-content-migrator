<?php
/**
 * Abstract class definition of File.
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Utils\CommonDataFileIterator;

use \Exception;

/**
 * Abstract File class, handles low level loading of file and basic functions.
 */
abstract class AbstractFile implements Contracts\File {

	/**
	 * File's full path.
	 *
	 * @var string $path
	 */
	protected string $path;

	/**
	 * The file resource handle.
	 *
	 * @var resource $handle
	 */
	protected $handle = null;

	/**
	 * File's name, including file extension.
	 *
	 * @var string $name
	 */
	protected string $name;

	/**
	 * File's size in MB.
	 *
	 * @var int $size
	 */
	protected int $size;

	/**
	 * Constructor. For now this class expects the file to exist on the system.
	 *
	 * @param string $path Full file path.
	 * @throws Exception If file does not exist.
	 */
	public function __construct( string $path ) {
		if ( ! file_exists( $path ) ) {
			throw new Exception( 'File does not exist!' );
		}

		$this->path = $path;
		$parts = explode( '/', $path );
		$this->name = array_pop( $parts );
		$this->size = filesize( $this->path ) / 1024;
	}

	/**
	 * Returns the file's full path.
	 *
	 * @inheritDoc
	 */
	public function get_path(): string {
		return $this->path;
	}


	/**
	 * Returns a resource stream to the open file.
	 *
	 * @return resource
	 */
	public function get_handle() {
		if ( is_null( $this->handle ) ) {
			$this->handle = fopen( $this->get_path(), 'r' );
		}

		return $this->handle;
	}

	/**
	 * Returns the file's name, with file extension.
	 *
	 * @inheritDoc
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Returns the file's size, in MB.
	 *
	 * @inheritDoc
	 */
	public function get_size(): int {
		return $this->size;
	}

	/**
	 * Destructor.
	 */
	public function __destruct() {
		if ( is_resource( $this->handle ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
			fclose( $this->handle );
		}
	}
}