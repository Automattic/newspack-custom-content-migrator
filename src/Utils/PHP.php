<?php

namespace NewspackCustomContentMigrator\Utils;

class PHP {

	/**
	 * Defines \readline() function if it doesn't exist locally.
	 */
	public function register_readline() {

		// Function \readline() has gone missing from Atomic, so here's it is back.
		if ( ! function_exists( 'readline' ) ) {

			/**
			 * Taken from class-wp-cli.php function confirm() and simplified.
			 *
			 * @param string $question   Question to display before the prompt.
			 * @param array  $assoc_args Skips prompt if 'yes' is provided.
			 *
			 * @return string CLI user input.
			 */
			function readline( $question, $assoc_args = [] ) {
				fwrite( STDOUT, $question );
				$answer = strtolower( trim( fgets( STDIN ) ) );

				return $answer;
			}

		}
	}
}
