<?php

namespace NewspackCustomContentMigrator;

use Exception;

class JetPackBusinessHoursGutenbergBlock extends AbstractGutenbergDataBlock {

	const MONDAY_ABBR = 'Mon';
	const TUESDAY_ABBR = 'Tue';
	const WEDNESDAY_ABBR = 'Wed';
	const THURSDAY_ABBR = 'Thu';
	const FRIDAY_ABBR = 'Fri';
	const SATURDAY_ABBR = 'Sat';
	const SUNDAY_ABBR = 'Sun';

	const MONDAY = 'Monday';
	const TUESDAY = 'Tuesday';
	const WEDNESDAY = 'Wednesday';
	const THURSDAY = 'Thursday';
	const FRIDAY = 'Friday';
	const SATURDAY = 'Saturday';
	const SUNDAY = 'Sunday';

	const OPEN = 'open';
	const CLOSE = 'close';

	const OPENING = 'opening';
	const CLOSING = 'closing';

	const NAME = 'name';
	const HOURS = 'hours';

	private array $days_of_week_in_order = [
		self::SUNDAY,
		self::MONDAY,
		self::TUESDAY,
		self::WEDNESDAY,
		self::THURSDAY,
		self::FRIDAY,
		self::SATURDAY,
	];

	private array $days_of_week_to_abbr = [
		self::SUNDAY => self::SUNDAY_ABBR,
		self::MONDAY => self::MONDAY_ABBR,
		self::TUESDAY => self::TUESDAY_ABBR,
		self::WEDNESDAY => self::WEDNESDAY_ABBR,
		self::THURSDAY => self::THURSDAY_ABBR,
		self::FRIDAY => self::FRIDAY_ABBR,
		self::SATURDAY => self::SATURDAY_ABBR,
	];

	private array $hours = [
		self::SUNDAY => [],
		self::MONDAY => [],
		self::TUESDAY => [],
		self::WEDNESDAY => [],
		self::THURSDAY => [],
		self::FRIDAY => [],
		self::SATURDAY => [],
	];

	public function set_day_hours( string $day_of_week, string $open, string $close ) {
		if ( ! array_key_exists( $day_of_week, $this->hours ) ) {
			throw new Exception( "$day_of_week is not a valid day." );
		}

		if ( ! empty( $open ) ) {
			$this->hours[ $day_of_week ][ self::OPEN ] = trim( $open );
		} else {
			unset( $this->hours[ $day_of_week ][ self::OPEN ] );
		}

		if ( ! empty( $close ) ) {
			$this->hours[ $day_of_week ][ self::CLOSE ] = trim( $close );
		} else {
			unset( $this->hours[ $day_of_week ][ self::CLOSE ] );
		}
	}

	public function set_hours( string $hours ) {
		for ( $i = 0; $i <= 6; $i++) {
			$day_of_week = $this->days_of_week_in_order[ $i ];
			$position_of_day_in_string = stripos( $hours, $day_of_week );

			if ( false === $position_of_day_in_string ) {
				continue;
			}

			if ( 6 === $i ) {
				// Saturday

				// Use whatever is until end of string
				$day_of_week_hours = substr( $hours, $position_of_day_in_string + strlen( $day_of_week ) );

				//Check if Sunday is available and after Saturday
				$sunday_position_in_string = stripos( $hours, self::SUNDAY );
				if ( false !== $sunday_position_in_string && $sunday_position_in_string > $position_of_day_in_string ) {
					$offset = $position_of_day_in_string + strlen( $day_of_week );
					$day_of_week_hours = substr( $hours, $offset, $sunday_position_in_string - $offset );
				}

				$open_position = stripos( $day_of_week_hours, self::OPEN );
				$close_position = stripos( $day_of_week_hours, self::CLOSE );
				$open_hour = '';
				$close_hour = '';

				if ( false !== $open_position ) {
					$offset = $open_position + strlen( self::OPEN );
					$open_hour = substr( $day_of_week_hours, $offset );

					if ( false !== $close_position && $close_position > $open_position ) {
						$open_hour = substr( $day_of_week_hours, $offset, $close_position - $offset );
					}

					$open_hour = trim( $open_hour );
				}

				if ( false !== $close_position ) {
					$offset = $close_position + strlen( self::CLOSE );
					$close_hour = substr( $day_of_week_hours, $offset );

					if ( $close_position < $open_position ) {
						$close_hour = substr( $day_of_week_hours, $offset, $open_position - $offset );
					}

					$close_hour = trim( $close_hour );
				}

				$this->set_day_hours( $day_of_week, $open_hour, $close_hour );
				continue;
			}

			$position_of_following_day_in_string = stripos( $hours, $this->days_of_week_in_order[ $i + 1 ] );

			if ( false === $position_of_following_day_in_string || ( self::SUNDAY === $day_of_week && $position_of_following_day_in_string < $position_of_day_in_string ) ) {
				$position_of_following_day_in_string = strlen( $hours );
			}

			if ( $position_of_following_day_in_string < $position_of_day_in_string ) {
				throw new Exception('Hours should be formatted in ascending order, starting from either Sunday or Monday' );
			}

			$offset = $position_of_day_in_string + strlen( $day_of_week );
			$day_of_week_hours = substr( $hours, $offset, $position_of_following_day_in_string - $offset );
			$open_position = stripos( $day_of_week_hours, self::OPEN );
			$close_position = stripos( $day_of_week_hours, self::CLOSE );
			$open_hour = '';
			$close_hour = '';

			if ( false !== $open_position ) {
				$offset = $open_position + strlen( self::OPEN );
				$open_hour = substr( $day_of_week_hours, $offset );

				if ( false !== $close_position && $close_position > $open_position ) {
					$open_hour = substr( $day_of_week_hours, $offset, $close_position - $offset );
				}

				$open_hour = trim( $open_hour );
			}

			if ( false !== $close_position ) {
				$offset = $close_position + strlen( self::CLOSE );
				$close_hour = substr( $day_of_week_hours, $offset );

				if ( $close_position < $open_position ) {
					$close_hour = substr( $day_of_week_hours, $offset, $open_position - $offset );
				}

				$close_hour = trim( $close_hour );
			}

			$this->set_day_hours( $day_of_week, $open_hour, $close_hour );
		}
	}


	/**
	 * @inheritDoc
	 */
	public function jsonSerialize() {
		$hours = [];

		foreach ( $this->hours as $day_of_week => $hours_of_operation ) {
			$data = [
				'name' => $this->days_of_week_to_abbr[ $day_of_week ],
				'hours' => [],
			];

			if ( ! empty( $hours_of_operation ) ) {
				$final_hours_of_operations = [
					self::OPENING => $hours_of_operation[ self::OPEN ],
					self::CLOSING => $hours_of_operation[ self::CLOSE ],
				];
				$data['hours'] = [ $final_hours_of_operations ];
			}

			$hours[] = $data;
		}

		return [ 'days' => $hours ];
	}

	/**
	 * @inheritDoc
	 */
	public function __toString(): string {
		return '<!-- wp:jetpack/business-hours ' . $this->get_json() . ' /-->';
	}
}