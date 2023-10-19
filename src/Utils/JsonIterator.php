<?php

namespace NewspackCustomContentMigrator\Utils;

use Exception;
use JsonMachine\Items;

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
	 * A low-tech way to do a "file_exists" on a url.
	 *
	 * @param string $url The url to check.
	 *
	 * @return bool True if the url responds with a 200 OK.
	 */
	private function url_responds( string $url ): bool {
		$ch = curl_init( $url );

		curl_setopt( $ch, CURLOPT_NOBODY, true ); // Exclude the body (don't download).
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true ); // Follow redirects
		curl_setopt( $ch, CURLOPT_HEADER, true ); // Include the headers in the output
		curl_setopt( $ch, CURLOPT_TIMEOUT, 5 ); // Set a timeout

		curl_exec( $ch );

		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

		curl_close( $ch );

		return $http_code === 200;
	}

	/**
	 * Will read a JSON file in chunks and return an iterable of objects from the JSON.
	 *
	 * If you want only parts of the json, or the structure is not straightforward,
	 * you can pass JSON pointers in the $options array. See this for more info:
	 * https://github.com/halaxa/json-machine#json-pointer
	 *
	 * @param string $json_file Path to the JSON file – can be a URL too.
	 * @param array $options Options to pass to JsonMachine.
	 *
	 * @return iterable
	 */
	public function items( string $json_file, array $options = [] ): iterable {
		$file_exists = str_starts_with( $json_file, 'http' ) ? $this->url_responds( $json_file ) : file_exists( $json_file );

		if ( ! $file_exists ) {
			$this->logger->log( self::LOG_NAME, "Doesn't exist: {$json_file}", Logger::ERROR );

			return new \EmptyIterator();
		}

		try {
			return Items::fromFile( $json_file, $options );
		} catch ( Exception $o_0 ) {
			$this->logger->log( self::LOG_NAME, "Could not read the JSON from {$json_file}", Logger::ERROR );
		}

		return new \EmptyIterator();
	}

	/**
	 * Will count number of entries in a JSON file where the root is an array.
	 *
	 * Handy for getting a "total" number for progress bars and such.
	 *
	 * @param string $json_file_path Path to the JSON file.
	 *
	 * @return int Number of entries in the array in the JSON file.
	 *
	 * @throws Exception if the jq command fails or the JSON file does not exist.
	 */
	public function count_json_array_entries( string $json_file_path ): int {
		if ( file_exists( $json_file_path ) ) {
			exec( 'cat ' . escapeshellarg( $json_file_path ) . " | jq 'length'", $count );
			if ( ! empty( $count[0] ) ) {
				return (int) $count[0];
			}
		}

		throw new Exception( "Could not count entries in JSON file: {$json_file_path}" );
	}

}
