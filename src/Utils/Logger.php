<?php
/**
 * Logger class for handling commands' logging
 * 
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Utils;

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
	public function get_le_log_path() {
		return get_temp_dir() . self::LE_LOG_DIRECTORY;
	}

	/**
	 * Simple file logging.
	 *
	 * @param string  $file File name or path.
	 * @param string  $message Log message.
	 * @param boolean $to_cli Whether to output the message to the CLI. Default to false.
	 */
	private function log( $file, $message, $to_cli = true ) {
		$message .= "\n";
		if ( $to_cli ) {
			WP_CLI::line( $message );
		}
		file_put_contents( $file, $message, FILE_APPEND );
	}

}
