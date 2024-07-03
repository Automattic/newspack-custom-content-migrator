<?php
/**
 * Logger class for handling commands' logging
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Utils;

use WP_CLI;

/**
 * Class for handling commands' logging
 */
class Logger {

	const LE_LOG_DIRECTORY = 'newspack_le_logs';

	const WARNING = 'warning';
	const LINE    = 'line';
	const SUCCESS = 'success';
	const ERROR   = 'error';
	const INFO    = 'info';

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
	 * @param string         $file File name or path.
	 * @param string         $message Log message.
	 * @param string|boolean $level Whether to output the message to the CLI. Default to `line` CLI level.
	 * @param bool           $exit_on_error Whether to exit on error.
	 */
	public function log( $file, $message, $level = 'line', bool $exit_on_error = false ) {
		$log_line_prefix = '';
		if ( in_array( $level, [ self::SUCCESS, self::WARNING, self::ERROR ], true ) ) {
			// Prepend the level to the message for easier grepping in the log file.
			$log_line_prefix .= strtoupper( $level ) . ': ';
		}

		file_put_contents( $file, $log_line_prefix . $message . "\n", FILE_APPEND ); //phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		if ( $level ) {
			switch ( $level ) {
				case ( self::SUCCESS ):
					WP_CLI::success( $message );
					break;
				case ( self::WARNING ):
					WP_CLI::warning( $message );
					break;
				case ( self::ERROR ):
					WP_CLI::error( $message, $exit_on_error );
					break;
				case ( self::INFO ):
					$label = 'Info';
					if ( class_exists( 'cli\Colors' ) ) {
						$color = '%B';
						$label = \cli\Colors::colorize( "$color$label:%n", true );
					} else {
						$label = "$label:";
					}
					WP_CLI::line( "$label $message" );
					break;
				case ( self::LINE ):
				default:
					WP_CLI::line( $message );
					break;
			}
		}
	}
}
