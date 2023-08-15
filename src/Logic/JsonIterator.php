<?php

namespace NewspackCustomContentMigrator\Logic;

use JsonMachine\Items;
use NewspackCustomContentMigrator\Utils\Logger;

/**
 * Class JsonIterator.
 *
 * Helpers to iterate over JSON files using less memory.
 */
class JsonIterator {

	/**
	 * @var Logger.
	 */
	private Logger $logger;

	/**
	 * @var string Log name.
	 */
	const LOG_NAME = 'json_iterator';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->logger = new Logger();
	}

	/**
	 * Will read a JSON file in chunks and return an iterable of objects from the JSON.
	 *
	 * If you want only parts of the json, or the structure is not straightforward,
	 * you can pass JSON pointers in the $options array. See this for more info:
	 * https://github.com/halaxa/json-machine#json-pointer
	 *
	 * @param string $filename Path to the JSON file – can be a URL too.
	 * @param array $options Options to pass to JsonMachine.
	 *
	 * @return iterable
	 */
	public function items( string $filename, array $options = [] ): iterable {

		// Suppress errors with '@' because we want to specifically errors below.
		$stream = @fopen( $filename, 'r' );
		if ( ! $stream ) {
			$this->logger->log( self::LOG_NAME, "The JSON file with url {$filename} doesn't exist." );

			return new \EmptyIterator();
		}

		try {
			return Items::fromStream( $stream, $options );
		} catch ( \Exception $o_0 ) {
			$this->logger->log( self::LOG_NAME, "Could not read the JSON from {$filename}" );
		}

		return new \EmptyIterator();
	}

}
