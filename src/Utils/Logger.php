<?php
/**
 * Logger class for handling commands' logging
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Utils;

use \WP_CLI;

/**
 * Class for handling commands' logging
 */
class Logger {

	const LE_LOG_DIRECTORY = 'newspack_le_logs';

	/**
	 * Determine the writeable directory used for storing logs created by migration commands.
	 *
	 * @return string Directory path.
	 */
	public function get_le_log_path( $filename ) {

		$log_dir = get_temp_dir() . self::LE_LOG_DIRECTORY;
		if ( ! file_exists( $log_dir ) ) {
			mkdir( $log_dir );
		}

		return $log_dir . DIRECTORY_SEPARATOR . $filename . '.log';

	}

	/**
	 * Simple file logging.
	 *
	 * @param string  $file File name or path.
	 * @param string  $message Log message.
	 * @param boolean $to_cli Whether to output the message to the CLI. Default to false.
	 */
	public function log( $file, $message, $to_cli = true ) {

		// Print the message to the console, if required.
		if ( $to_cli ) {
			WP_CLI::line( $message );
		}

		// Log to the file, ensureing we use a good filename and path.
		$file     = $this->get_le_log_path( $file );
		$message .= "\n";
		file_put_contents( $file, $message, FILE_APPEND );
	}

}
