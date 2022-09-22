<?php

namespace NewspackCustomContentMigrator\Utils;

/**
 * PHP utilities class.
 */
class PHP {

	/**
	 * Taken from class-wp-cli.php function confirm() and simplified.
	 *
	 * @param string $question   Question to display before the prompt.
	 * @param array  $assoc_args Skips prompt if 'yes' is provided.
	 *
	 * @return string CLI user input.
	 */
	public static function readline( $question, $assoc_args = [] ) {
		fwrite( STDOUT, $question );
		$answer = strtolower( trim( fgets( STDIN ) ) );

		return $answer;
	}
}
