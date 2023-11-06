<?php
/**
 * Logic class to help with dates and timezone conversions.
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Logic;

use DateTime;
use DateTimeZone;
use Exception;

/**
 * Dates class.
 */
class DateTimeConverter {
	const MYSQL_DATE_FORMAT = 'Y-m-d H:i:s';

	/**
	 * Some common date formats. Used for parsing dates. Add more as needed.
	 *
	 * @var array|string[] $common_formats
	 */
	protected array $common_formats = [
		self::MYSQL_DATE_FORMAT,
		'Y-m-d H:i',
		'Y-m-d',
		'm/d/Y H:i:s',
		'm/d/Y H:i',
		'm/d/Y',
		'd/m/Y H:i:s',
		'd/m/Y H:i',
		'd/m/Y',
		'Y-m-d\TH:i:s',
		'Y-m-d\TH:i',
	];

	/**
	 * The baseline timezone to use for date conversions.
	 *
	 * @var DateTimeZone|null $base_timezone Timezone to use for date conversions.
	 */
	protected ?DateTimeZone $base_timezone;

	/**
	 * The target timezone to use for date conversions.
	 *
	 * @var DateTimeZone|null $target_timezone Timezone to use for date conversions.
	 */
	protected ?DateTimeZone $target_timezone = null;

	/**
	 * Constructor.
	 *
	 * @param string $base_timezone  The baseline timezone to use for date conversions. Defaults to UTC.
	 * @param string $target_timezone The target timezone to use for date conversions.
	 * @param array  $common_date_formats User defined common date formats.
	 *
	 * @throws Exception Throws exception if invalid timezone is passed.
	 */
	public function __construct( string $base_timezone = 'UTC', string $target_timezone = '', array $common_date_formats = [] ) {
		$this->set_base_timezone( $base_timezone );

		if ( ! empty( $target_timezone ) ) {
			$this->set_target_timezone( $target_timezone );
		}
		if ( ! empty( $common_date_formats ) ) {
			$this->set_common_date_formats( $common_date_formats );
		}
	}

	/**
	 * Override the common date formats in this class with your own.
	 *
	 * @param string[] $common_date_formats Common date formats.
	 * @return void
	 */
	public function set_common_date_formats( array $common_date_formats ) {
		$this->common_formats = $common_date_formats;
	}

	/**
	 * Adds a common date format to the list of common date formats.
	 *
	 * @param string $format Additional common date format.
	 * @return void
	 */
	public function add_common_date_format( string $format ) {
		$this->common_formats[] = $format;
	}

	/**
	 * Remove a particular date format from the list of common date formats, by index or by value.
	 * Returns true if the format was found and removed, false otherwise.
	 * Useful if you know the entire universe of formats you'll be dealing with.
	 *
	 * @param string|int $format Format to remove, or index of format to remove.
	 * @return bool
	 */
	public function remove_common_date_format( $format ): bool {
		if ( is_numeric( $format ) ) {
			unset( $this->common_formats[ $format ] );
			return true;
		} else {
			$index = array_search( $format, $this->common_formats, true );
			if ( false !== $index ) {
				unset( $this->common_formats[ $index ] );
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns list of common date formats.
	 *
	 * @return array|string[]
	 */
	public function get_common_date_formats(): array {
		return $this->common_formats;
	}

	/**
	 * Sets the base timezone to use for date conversions.
	 *
	 * @param DateTimeZone|string $timezone Timezone to use for date conversions.
	 *
	 * @return void
	 * @throws Exception Throws exception if invalid timezone is passed.
	 */
	public function set_base_timezone( $timezone ) {
		if ( $timezone instanceof DateTimeZone ) {
			$this->base_timezone = $timezone;
		} else {
			$this->base_timezone = new DateTimeZone( $timezone );
		}
	}

	/**
	 * Sets the target timezone to use for date conversions.
	 *
	 * @param DateTimeZone|string $timezone Timezone to use for date conversions.
	 *
	 * @return void
	 * @throws Exception Throws exception if invalid timezone is passed.
	 */
	public function set_target_timezone( $timezone ) {
		if ( $timezone instanceof DateTimeZone ) {
			$this->target_timezone = $timezone;
		} else {
			$this->target_timezone = new DateTimeZone( $timezone );
		}
	}

	/**
	 * Get the target DateTimeZone object.
	 *
	 * @return DateTimeZone|null
	 */
	public function get_target_timezone(): ?DateTimeZone {
		return $this->target_timezone;
	}

	/**
	 * Get a DateTime object for a given date string.
	 *
	 * @param string $date String representation of date.
	 * @return DateTime|null
	 */
	public function get_date_time_object( string $date ): ?DateTime {
		foreach ( $this->get_common_date_formats() as $format ) {
			$datetime = DateTime::createFromFormat( $format, $date, $this->base_timezone );
			if ( false !== $datetime ) {
				return $datetime;
			}
		}

		return null;
	}

	/**
	 * Convert a date from one timezone to another.
	 *
	 * @param DateTime|string     $date Date to convert.
	 * @param DateTimeZone|string $target_timezone Timezone to convert to.
	 * @param string              $format Format to return date in.
	 * @return string|null
	 * @throws Exception Throws exception if date cannot be parsed, or if no target timezone is set, or if invalid target timezone is passed.
	 */
	public function convert( $date, $target_timezone = '', string $format = self::MYSQL_DATE_FORMAT ): ?string {
		$datetime = null;
		if ( $date instanceof DateTime ) {
			$date->setTimezone( $this->base_timezone );
			$datetime = new DateTime( $date->format( self::MYSQL_DATE_FORMAT ), $this->base_timezone );
		} elseif ( is_string( $date ) ) {
			$datetime = $this->get_date_time_object( $date );
		}

		if ( null === $datetime ) {
			$exception_message_escaped = wp_kses_post( sprintf( 'Could not parse date: %s', $date ) );
			throw new Exception( $exception_message_escaped ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		if ( empty( $target_timezone ) ) {
			if ( null === $this->get_target_timezone() ) {
				$exception_message_escaped = wp_kses_post( sprintf( 'No target timezone set. Cannot convert date: %s', $date ) );
				throw new Exception( $exception_message_escaped ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			} else {
				$target_timezone = $this->get_target_timezone();
			}
		} elseif ( is_string( $target_timezone ) ) {
				$target_timezone = new DateTimeZone( $target_timezone );
		} elseif ( ! ( $target_timezone instanceof DateTimeZone ) ) {
			throw new Exception( wp_kses_post( 'Invalid target timezone' ) );
		}

		$datetime->setTimezone( $target_timezone );

		return $datetime->format( $format );
	}
}
