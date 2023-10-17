<?php
/**
 * Helper to consistently handle start and end for commands.
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Utils;

use WP_CLI;
use WP_CLI\ExitException;

/**
 * BatchLogic helper to consistently handle start and end for commands.
 */
class BatchLogic {
	/**
	 * Args to use in a command.
	 *
	 * @var array
	 */
	private static array $batch_args = [
		[
			'type'        => 'assoc',
			'name'        => 'start',
			'description' => 'Start row (default: 0)',
			'optional'    => true,
			'repeating'   => false,
		],
		[
			'type'        => 'assoc',
			'name'        => 'end',
			'description' => 'End row (default: PHP_INT_MAX)',
			'optional'    => true,
			'repeating'   => false,
		],
		[
			'type'        => 'assoc',
			'name'        => 'num-items',
			'description' => 'Number of items to process. Will be ignored if end is provided.',
			'optional'    => true,
			'repeating'   => false,
		],
	];

	/**
	 * Get batch args.
	 *
	 * To use in a command, spread the returned value into the command's synopsis property.
	 *
	 * @return array[]
	 */
	public static function get_batch_args(): array {
		return self::$batch_args;
	}

	/**
	 * Validate assoc args for batch and return start, end, and total.
	 *
	 * @param array $assoc_args Assoc args from a command run.
	 *
	 * @return array Array keyed with: start, end, total.
	 * @throws ExitException If the args were not acceptable.
	 */
	public static function validate_and_get_batch_args( array $assoc_args ): array {
		$start     = $assoc_args[ self::$batch_args[0]['name'] ] ?? 0;
		$end       = $assoc_args[ self::$batch_args[1]['name'] ] ?? PHP_INT_MAX;
		$num_items = $assoc_args[ self::$batch_args[2]['name'] ] ?? false;

		if ( $num_items && PHP_INT_MAX === $end ) {
			$end = $start + $num_items;
		}

		if ( ! is_numeric( $start ) || ! is_numeric( $end ) ) {
			WP_CLI::error( 'Start and end args must be numeric.' );
		}
		if ( $end < $start ) {
			WP_CLI::error( 'End arg must be greater than start arg.' );
		}

		return [
			'start' => $start,
			'end'   => $end,
			'total' => $end - $start,
		];
	}

}
