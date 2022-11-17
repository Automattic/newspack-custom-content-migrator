<?php
/**
 * Migrator for Aavlwatchdog.
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;

/**
 * Custom migration scripts for Aavlwatchdog.
 */
class AavlwatchdogMigrator implements InterfaceMigrator {

	/**
	 * Instance of AavlwatchdogMigrator
	 *
	 * @var null|InterfaceMigrator
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceMigrator|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator avlwatchdog-tsv-to-csv-convert-old-data-format',
			[ $this, 'cmd_convert_tsv_old_data_to_csv' ],
			[
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'old-datafile-path',
						'description' => 'Full path to the old data file.',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * @param $pos_args
	 * @param $assoc_args
	 *
	 * @return void
	 */
	public function cmd_convert_tsv_old_data_to_csv( $pos_args, $assoc_args ) {
		$old_data_file_path = $assoc_args['old-datafile-path'];


		// Transform tsv to array.
		$lines = explode( "\n", file_get_contents( $old_data_file_path ) );
		$old_data = [];
		foreach ( $lines as $key_line => $line_tsv ) {
			if ( 0 === $key_line ) {
				$columns = explode( '	', $line_tsv );
				continue;
			}

			$line = explode( '	', $line_tsv );
			$line_formatted = [];
			foreach ( $columns as $key_column => $column ) {
				$line_formatted[$column] = $line[$key_column];
			}

			$old_data[] = $line_formatted;
		}


		// Create new data array.
		$new_data = [
			// Header line.
			[
				'customer_email',
				'start_date',
				'next_payment_date',
				'billing_period',
				'billing_interval',
				'order_items',
				'order_total',
				'order_notes',
				'payment_method',
				'payment_method_title',
				'payment_method_post_meta',
				'',
				'subscription_status',
			]
		];


		foreach ( $old_data as $donation ) {

			// Get next payment date.
			//      - Monthly product (ID 20053).
			//      - Yearly product (ID 20054)
			$created_datetime = \DateTime::createFromFormat( 'Y-m-d H:i', $donation['created_at'] , new \DateTimeZone( 'UTC' ) );
			$next_payment_date = null;
			if ( 20053 == $donation['product_id'] ) {
				$created_datetime->add( new \DateInterval( 'P1M' ) );
				$next_payment_date = $created_datetime->format( 'Y-m-d H:i:s' );
			} elseif ( 20054 == $donation['product_id'] ) {
				$created_datetime->add( new \DateInterval( 'P1Y' ) );
				$next_payment_date = $created_datetime->format( 'Y-m-d H:i:s' );
			} else {
				WP_CLI::log( sprintf( "%s productID is single donation, skipping.", $donation['product_id'] ) );
				continue;
			}

			$new_donation = [
				// customer_email
				$donation['billing_email'],
				// start_date
				$donation['created_at'] . ':00',
				// next_payment_date
				$next_payment_date,
				// billing_period
				$donation['Subscription Billing Period'],
				// billing_interval (always 1, either 1 month, or 1 year)
				'1',
				// order_items
				$donation['product_id'],
				// order_total
				$donation['total'],
				// order_notes
				'',
				// payment_method
				$donation['payment_method'],
				// payment_method_title
				ucfirst( $donation['payment_method'] ),
				// payment_method_post_meta
				'',
				// --blank
				'',
				// subscription_status
				'wc-active',
			];

			$new_data[] = $new_donation;
		}


		// Convert to CSV
		$fp = fopen('new_data.csv', 'w');
		foreach ( $new_data as $fields ) {
			fputcsv( $fp, $fields );
		}
		fclose( $fp );

		$d=1;
	}
}
