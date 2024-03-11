<?php
/**
 * Class to output various tables to the console.
 *
 * @package NewspackCustomContentMigrator;
 */

namespace NewspackCustomContentMigrator\Utils;

use cli\Table;
use WP_CLI;

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
	public static function output_comparison( array $keys, array ...$arrays ) {
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
				if ( array_key_exists( $key, $array ) ) {
					if ( is_bool( $array[ $key ] ) ) {
						$row[] = $array[ $key ] ? 'true' : 'false';
					} elseif ( is_array( $array[ $key ] ) ) {
						$sub_key   = array_key_first( $array[ $key ] );
						$sub_value = reset( $array[ $key ] );
						$row[]     = ConsoleColor::black_with_cyan_background( $sub_key )->get() .
									 ConsoleColor::white( ":{$sub_value}" )->get();

						$table->addRow( $row );

						unset( $array[ $key ][ $sub_key ] );
						foreach ( $array[ $key ] as $sub_key => $sub_value ) {
							$table->addRow(
								[
									'',
									ConsoleColor::black_with_cyan_background( $sub_key )->get() .
									ConsoleColor::white( ":{$sub_value}" )->get(),
								]
							);
						}

						continue 2;
					} elseif ( empty( $array[ $key ] ) ) {
						$row[] = '-';
					} else {
						$row[] = $array[ $key ] ?? '';
					}
				} else {
					$row[] = '-';
				}
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
	public static function output_value_comparison( array $keys, array $left_set, array $right_set, bool $strict = true, string $left = 'LEFT', string $right = 'RIGHT' ) {
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

	/**
	 * Simple function to output a table of data, with an optional title.
	 *
	 * @param array[] $array_of_arrays An array of arrays that hold the data to be output.
	 * @param array   $header An array of strings that will be used as the table header.
	 * @param string  $title The title of the table.
	 *
	 * @return void
	 */
	public static function output_data( array $array_of_arrays, array $header = [], string $title = '' ) {
		$array_of_arrays = array_map(
			function ( $member ) {
				if ( ! is_array( $member ) ) {
					return (array) $member;
				}

				return $member;
			},
			$array_of_arrays
		);

		if ( empty( $header ) && isset( $array_of_arrays[0] ) ) {
			$header = array_keys( $array_of_arrays[0] );
		}

		if ( ! empty( $title ) ) {
			if ( ConsoleColor::has_color( $title ) ) {
				echo esc_html( $title ) . PHP_EOL;
			} else {
				ConsoleColor::title_output( $title );
			}
		}

		WP_CLI\Utils\format_items( 'table', $array_of_arrays, $header );
	}
}
