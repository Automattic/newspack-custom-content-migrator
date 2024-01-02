<?php
/**
 * Class to output various tables to the console.
 *
 * @package NewspackCustomContentMigrator;
 */

namespace NewspackCustomContentMigrator\Utils;

use cli\Table;

/**
 * Class to output various tables to the console.
 */
class ConsoleTable {

	/**
	 * Constructor.
	 */
	public function __construct() {
	}

	/**
	 * This function outputs a comparison table of the given arrays.
	 *
	 * @param array $keys Specific keys to compare. If empty, all keys will be compared.
	 * @param array ...$arrays The arrays to be compared.
	 *
	 * @return void
	 */
	public function output_comparison( array $keys, array ...$arrays ) {
		$array_bag = array(
			...$arrays,
		);

		if ( empty( $keys ) ) {
			$keys = array_keys( array_merge( ...$arrays ) );
		}

		$table = new Table();
		$table->setHeaders(
			array(
				'',
				...array_keys( $array_bag ),
			)
		);

		foreach ( $keys as $key ) {
			$row = array(
				$key,
			);

			foreach ( $array_bag as $array ) {
				$row[] = $array[ $key ] ?? '';
			}

			$table->addRow( $row );
		}

		$table->display();
	}

	/**
	 * This function will take two arrays and compare their values key-by-key.
	 *
	 * @param array  $keys Specific keys to compare. If empty, all keys will be compared.
	 * @param array  $left_set First array to compare.
	 * @param array  $right_set Second array to compare.
	 * @param bool   $strict Whether to use strict comparison or not.
	 * @param string $left The name of the first/left array.
	 * @param string $right The name of the second/right array.
	 *
	 * @return array[]
	 */
	public function output_value_comparison( array $keys, array $left_set, array $right_set, bool $strict = true, string $left = 'LEFT', string $right = 'RIGHT' ) {
		if ( empty( $keys ) ) {
			$keys = array_keys( array_merge( $left_set, $right_set ) );
		}

		$table = new Table();
		$table->setHeaders(
			array(
				'',
				'Match?',
				$left,
				$right,
			)
		);

		$matching_rows     = array();
		$different_rows    = array();
		$undetermined_rows = array();

		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $left_set ) ) {
				if ( is_bool( $left_set[ $key ] ) ) {
					$left_set[ $key ] = $left_set[ $key ] ? 'true' : 'false';
				}
			}

			if ( array_key_exists( $key, $right_set ) ) {
				if ( is_bool( $right_set[ $key ] ) ) {
					$right_set[ $key ] = $right_set[ $key ] ? 'true' : 'false';
				}
			}

			if ( array_key_exists( $key, $left_set ) && array_key_exists( $key, $right_set ) ) {
				if ( empty( $left_set[ $key ] ) && empty( $right_set[ $key ] ) ) {
					$match = '-';
				} else {
					// phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
					$match = $strict ? ( $left_set[ $key ] === $right_set[ $key ] ? '✅' : '❌' ) : ( $left_set[ $key ] == $right_set[ $key ] ? '✅' : '❌' );
				}
			} elseif ( empty( $left_set[ $key ] ) && empty( $right_set[ $key ] ) ) {
				$match = '-';
			} else {
				$match = '❌';
			}

			$values = array(
				$left  => $left_set[ $key ] ?? '',
				$right => $right_set[ $key ] ?? '',
			);

			$row = array(
				$key,
				$match,
				...array_values( $values ),
			);

			if ( '✅' === $match ) {
				$matching_rows[ $key ] = $values;
			} elseif ( '❌' === $match ) {
				$different_rows[ $key ] = $values;
			} else {
				$undetermined_rows[ $key ] = $values;
			}

			$table->addRow( $row );
		}

		$table->display();

		return array(
			'matching'     => $matching_rows,
			'different'    => $different_rows,
			'undetermined' => $undetermined_rows,
		);
	}
}
