<?php
/**
 * GiveWPMigrator.
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Migrator\General;

use NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use NewspackCustomContentMigrator\Utils\Logger;
use \WP_CLI;

/**
 * GiveWPMigrator migrator
 */
class GiveWPMigrator implements InterfaceMigrator {

	/**
	 * Instance of the class
	 *
	 * @var null|InterfaceMigrator
	 */
	private static $instance = null;

	/**
	 * Instance of Logger
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->logger = new Logger();
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceMigrator|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator givewp-export-csv-donations',
			[ $this, 'cmd_export_csv_donations' ],
		);
	}

	/**
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative Arguments.
	 *
	 * @return void
	 */
	public function cmd_export_csv_donations( $pos_args, $assoc_args ) {

		global $wpdb;

		// Get all payment products/line items. Products are stored in wp_posts.
		$rows_products = $wpdb->get_results(
			"select * from {$wpdb->prefix}posts where post_type = 'product';",
			ARRAY_A
		);
		$products = [];
		array_walk(
			$rows_products,
			function ( $row, $i ) use ( &$products ) {
				$products[ $row['meta_key'] ] = $row['meta_value'];
			}
		);


		// Get all payment_ids.
		$rows_payment_ids = $wpdb->get_results(
			"select payment_id from {$wpdb->prefix}give_sequential_ordering order by id",
			ARRAY_A
		);
		$payment_ids = [];
		array_walk(
			$rows_payment_ids,
			function ( $row, $i ) use ( &$payment_ids ) {
				$payment_ids[] = $row['payment_id'];
			}
		);


		// Loop through each of the payment_ids and create $orders.
		$orders = [];
		$invalid_payment_ids = [];
 		foreach ( $payment_ids as $key_payment_id => $payment_id ) {
			\WP_CLI::log( sprintf( "(%d)/(%d) %d", $key_payment_id + 1, count( $payment_ids ), $payment_id ) );
			$payment = $wpdb->get_row(
				$wpdb->prepare(
					"select * from {$wpdb->posts}
					where ID = %s
				    and post_type = 'give_payment' ",
					$payment_id
				),
				ARRAY_A
			);
			if ( is_null( $payment ) ) {
				\WP_CLI::warning( sprintf( "payment_id %d %s record doesn not exist.", $payment_id, $wpdb->posts ) );
				$invalid_payment_ids[] = $payment_id;
				continue;
			}


			// donation_id is same as payment_id.
		    // Get donation meta.
		    $donation_id = $payment_id;
		    $rows_donationmeta = $wpdb->get_results(
			    $wpdb->prepare(
				    "select * from {$wpdb->prefix}give_donationmeta where donation_id = %s ",
				    $donation_id
			    ),
			    ARRAY_A
		    );
		    $donationmeta = [];
		    array_walk(
			    $rows_donationmeta,
			    function ( $row, $i ) use ( &$donationmeta ) {
				    $donationmeta[ $row['meta_key'] ] = $row['meta_value'];
			    }
		    );


		    // Order nubmer is post_title, also used in donationmeta, but that's the only place; not really that useful.
		    $donationmeta['order_number'];


		    // Get donor data.
		    $donor = $wpdb->get_row(
			    $wpdb->prepare(
				    "select * from {$wpdb->prefix}give_donors where id = %s ",
				    $donationmeta['_give_payment_donor_id']
			    ),
			    ARRAY_A
		    );
		    $rows_donormeta = $wpdb->get_results(
			    $wpdb->prepare(
				    "select * from {$wpdb->prefix}give_donormeta where donor_id = %s ",
				    $donationmeta['_give_payment_donor_id']
			    ),
			    ARRAY_A
		    );
		    $donormeta = [];
		    array_walk(
			    $rows_donormeta,
			    function ( $row, $i ) use ( &$donormeta ) {
				    $donormeta[ $row['meta_key'] ] = $row['meta_value'];
			    }
		    );


			// All donor's payments are also stored in a CSV, though we're already looping through all payment_ids:
		    //      select * from `local`.wp_xpM1VX_give_donors where purchase_count > 1;
		    // $donors_payment_ids = explode( ',', $donor['payment_ids'] );
		    

			// Statuses seem to be working like this.
			if (
				'give_subscription' === $payment['post_status']
				|| 'publish' === $payment['post_status']
			) {
				$status = 'completed';
			} else {
				$status = $payment['post_status'];
			}
			// Double-check the resulting status. If new statuses are found, extend this block.
			$known_statuses = [ 'completed', 'abandoned', 'failed', 'processing', 'refunded' ];
			if ( ! in_array( $status, $known_statuses ) ) {
				throw new \RuntimeException( sprintf( "donation_id/payment_id ID %d has unknown status %s. Known statuses are %s. Edit the Newspack's GiveWPMigrator to support this status.", $donation_id, $status, implode( ',', $known_statuses ) ) );
			}


		    // Fees.
		    if ( $donationmeta['_give_fee_amount'] ) {
			    $donation_fee = round( $donationmeta['_give_fee_amount'], 2 );
			    $total_shipping = round( $donationmeta['_give_fee_amount'], 2 );
		    } else {
			    $donation_fee = 0;
			    $total_shipping = 0;
		    }


		    // Subscriptions are linked to customers. But not all recurring donations seem to be associated to subscriptions.
		    // $subscription = null;
			// if ( isset( $donationmeta['subscription_id'] ) ) {
			//     $subscription = $wpdb->get_row(
			// 	    $wpdb->prepare(
			// 		    "select * from {$wpdb->prefix}give_subscriptions where customer_id = %s order by id desc limit 1;",
			// 		    $donationmeta['_give_payment_donor_id']
			// 	    ),
			// 	    ARRAY_A
			//     );
			// }

		    // Type of payment.
		    // Recurring donations are an internal addon to the GiveWP plugin, and they were additionally implemented,
		    // which is why the data structure is not so clear.
		    // Work in progress -- reading from method \Give\Donations\DataTransferObjects\DonationQueryData::fromObject
		    // we can try and set the $donation_type from donation_meta:
		    if ( $donationmeta['_give_subscription_payment'] ) {
				$donation_type = 'subscription';
		    } elseif (
				isset( $donationmeta['subscription_id'] )
				&& ( 0 != $donationmeta['subscription_id'] )
		    ) {
				$donation_type = 'renewal';
		    } else {
				$donation_type = 'single';
		    }


			// TODO, set line items depending on type of payment.
		    $line_items = 'product_id:20055|total:1|quantity:1';
			$product_id = '20055';
			$payment_type = 'one time';
			$subscription_billing_period = '';
			$subscription_billing_times = '';
			$subscription_billing_frequency = '';

			// e.g. these WooComm products were created on one of our Publisher's site, so we'd next proceed to set the above
		    // variables to one of these, depending on the type of payment:
			//
			// 	- Recurring monthly donations
			// 		ID 20053
			// 		Donate: Monthly product
			// 	- Recurring yearly donations
			// 		ID 20054
			// 		Donate: Yearly product
			//  - One-time, non-recurring donations
			// 		ID 20055
			// 		Donate: One-time product


			$order = [
				// Basic donation info.
				'id' =>                     $donation_id,
				'order_number' =>           $payment['post_title'],
				'total' =>                  round( $donationmeta['_give_payment_total'], 2 ),
				'currency' =>               $donationmeta['USD'],
				'status' =>                 $status,
				'created_at' =>             $payment['post_date'],
				'Date' =>                   date( "Y-m-d H:i", strtotime( $payment['post_date'] ) ),
				'Donation Date' =>          date( "M j, y", strtotime( $payment['post_date'] ) ),
				'Donation Time' =>          date( "H:i:s", strtotime( $payment['post_date'] ) ),
				'payment_method' =>         $donationmeta['_give_payment_gateway'],

				// Products/items.
				'line_items' =>             $line_items,
				'product_id' =>             $product_id,

				// Subscription.
				'Payment Type' =>           $payment_type,
				'Subscription Billing Period' => $subscription_billing_period,
				'Subscription Billing Times' => $subscription_billing_times,
				'Subscription Billing Frequency' => $subscription_billing_frequency,

				// Billing/shipping.
				'billing_first_name' =>     $donationmeta['_give_donor_billing_first_name'],
				'billing_last_name' =>      $donationmeta['_give_donor_billing_last_name'],
				'billing_email' =>          $donationmeta['_give_payment_donor_email'],
				'billing_company' =>        $donationmeta['_give_donation_company'],
				'billing_address_1' =>      $donationmeta['_give_donor_billing_address1'],
				'billing_address_2' =>      $donationmeta['_give_donor_billing_address2'],
				'billing_city' =>           $donationmeta['_give_donor_billing_city'],
				'billing_state' =>          $donationmeta['_give_donor_billing_state'],
				'billing_postcode' =>       $donationmeta['_give_donor_billing_zip'],
				'billing_country' =>        $donationmeta['_give_donor_billing_country'],

				// Using billing info for shipping too, since not finding it as separate in DB:
				'shipping_first_name' =>    $donationmeta['_give_donor_billing_first_name'],
				'shipping_last_name' =>     $donationmeta['_give_donor_billing_last_name'],
				'shipping_company' =>       $donationmeta['_give_donation_company'],
				'shipping_address_1' =>     $donationmeta['_give_donor_billing_address1'],
				'shipping_address_2' =>     $donationmeta['_give_donor_billing_address2'],
				'shipping_city' =>          $donationmeta['_give_donor_billing_city'],
				'shipping_state' =>         $donationmeta['_give_donor_billing_state'],
				'shipping_postcode' =>      $donationmeta['_give_donor_billing_zip'],
				'shipping_country' =>       $donationmeta['_give_donor_billing_country'],

				// Final bits.
				'customer_id' =>            '0',
				'Donation Fee' =>           $donation_fee,
				'total_shipping' =>         $total_shipping,
			];
		    $orders[] = $order;
		}


		// Output statuses.
		\WP_CLI::warning( sprintf( "Invalid payment_ids: %s", implode( ',', $invalid_payment_ids ) ) );


		// Create CSV file.
		$lines     = [];
		$separator = ',';
		$separator = '	';
		// Header line.
		if ( isset( $orders[0] ) && ! empty( $orders[0] ) ) {
			$lines[] = implode( $separator, array_keys( $orders[0] ) );
		}
		// Order lines.
		foreach ( $orders as $order ) {
			$lines[] = implode( $separator, array_values( $order ) );
		}
		// Save file.
		$filename = 'orders.csv';
		\file_put_contents( $filename, implode( "\n", $lines ) );
		\WP_CLI::success( sprintf( "Created %s", $filename ) );
	}
}
