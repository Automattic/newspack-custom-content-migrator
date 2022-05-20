<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use WP_CLI;

class PaidMembershipsPro2WooCommMigrator implements InterfaceMigrator {

	/**
	 * @var null|InterfaceMigrator Instance.
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
		WP_CLI::add_command( 'newspack-content-migrator pmp-2-woocomm-debug-importer-csv', [ $this, 'cmd_debug_importer_csv' ], [
			'shortdesc' => 'Exports Newspack Campaigns.',
		] );
		WP_CLI::add_command( 'newspack-content-migrator pmp-2-woocomm-import', [ $this, 'cmd_import' ], [
			'shortdesc' => 'Exports Newspack Campaigns.',
			// 'synopsis'  => [
			// 	[
			// 		'type'        => 'assoc',
			// 		'name'        => 'wc-csv-file',
			// 		'description' => 'Full path to WooComm\'s ______________________ CSV file.',
			// 		'optional'    => false,
			// 		'repeating'   => false,
			// 	],
			// ],
		] );
	}

	public function cmd_debug_importer_csv( $args, $assoc_args ) {
		$file_csv = isset( $assoc_args[ 'woocomm-csv' ] ) ? $assoc_args[ 'woocomm-csv' ] : null;
	}

	/**
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_import( $args, $assoc_args ) {
		// $output_dir = isset( $assoc_args[ 'output-dir' ] ) ? $assoc_args[ 'output-dir' ] : null;
		// if ( is_null( $output_dir ) || ! is_dir( $output_dir ) ) {
		// 	WP_CLI::error( 'Invalid output dir.' );
		// }


		$pmpro_orders_csv_file = '/var/www/afro2.test/public/wp-content/plugins/woocommerce-subscriptions-importer-exporter/pmpro-orders.csv';
		$pmpro_members_csv_file = '/var/www/afro2.test/public/wp-content/plugins/woocommerce-subscriptions-importer-exporter/pmpro-members_list.csv';

		// Get associative arrays from CSV data files.
		$pmpro_orders = $this->get_array_from_csv( $pmpro_orders_csv_file );
		$pmpro_members = $this->get_array_from_csv( $pmpro_members_csv_file );

		$woocomm_importer_data = $this->create_woocomm_importer_subscriptions_data( $pmpro_members, $pmpro_orders );

		$woocomm_importer_csv;


	}

	/**
	 * Converts CSV data file to an associative array.
	 *
	 * @param $csv_file
	 *
	 * @return array
	 */
	public function get_array_from_csv( $csv_file ) {
		$data = [];

		$lines = explode( "\n", file_get_contents( $csv_file ) );
		foreach ( $lines as $key_line => $line ) {
			if ( 0 === $key_line ) {
				$columns = explode( ',', $key_line );
				continue;
			}

			$line_values = explode( ',', $key_line );

			// Number of columns should correspond to number of values.
			if ( count( $columns ) !== count( $line_values ) ) {
				throw new \RuntimeException( sprintf(
					"Number of columns %d in CSV file %s is different than the number of values found %d on line number %d.",
					count( $columns ),
					$csv_file,
					count( $line_values ),
					$key_line
				) );
			}

		}

		return $data;
	}

	/**
	 * Creeates WooComm Subscriptions Importer data array.
	 *
	 * @param $pmpro_members
	 * @param $pmpro_orders
	 *
	 * @return array
	 */
	public function create_woocomm_importer_subscriptions_data( $pmpro_members, $pmpro_orders ) {

		/**
		 * WooComm CSV structure overview -- https://github.com/woocommerce/woocommerce-subscriptions-importer-exporter
		 *
		 *  customer User
		 *  Billing info
		 *  Shipping info
		 *  Subscription
		 *      status
		 *      start date
		 *      trial end date
		 *      next payment date
		 *      end date
		 *      billing frequency
		 *  Order items             --> Importing Order Items
		 *  Coupon items            --> Importing Subscriptions with Coupons
		 *  Fee items               --> Importing Subscriptions with Fee Line Items
		 *  Tax items               --> Importing Subscriptions with Tax Line Items
		 *  Discount info
		 *  Order info
		 *      shipping amount, tax
		 *      money info
		 *  Shipping method
		 *  Download permissions
		 *  Notes
		 *  Payment
		 *      method
		 *      title
		 *      post and user meta
		 *  Extra notes
		 *  Extra metas
		 *
		 *  --> Importing Payment Gateway Meta Data
		 *
		 *      ! payment gateway extension for the payment gateways must be active !
		 *
		 *      - for processing recurring payments automatically
		 *      - like customer or credit card tokens, in CSV
		 *      - Each payment method requires different meta data to process automatic payments
		 *      PayPal Reference Transactions
		 *          - _paypal_subscription_id must be mapped to payment_method_post_meta column
		 *            This value needs to be the customers billing agreement (will start with I-**************)
		 *      Stripe:
		 *          - _stripe_customer_id mapped to payment_method_post_meta column
		 *            and optionally, _stripe_source_id also mapped to payment_method_post_meta column if you want to charge
		 *            recurring payments against a specific payment method on the customer's account.
		 *            Only values beginning with cus_ and card_ will be considered valid tokens.
		 *
		 *  --> Pending cancellation dates
		 *      Importing a subscription with pending cancellation status will require an "end date" is set in the future
		 *      and no "next payment date" is set. Otherwise "next payment date" will be used as the susbcriptions end date.
		 *
		 *      If subscription is pending cancelled, CSV requires:
		 *          - if exists "next payment date" column will be used,
		 *          - if not, it will use "the end date", if that exists,
		 *          - if not, will throw error
		 *
		 *  --> Importing Order Items
		 *      Orders can have a number of different line items, including:
		 *          - product line items
		 *		    --> Importing Product Line Items
		 *			        The order_items column can be either:
		 *			            - a Single Product ID for the product you want the set as the product line item on the subscription; or
		 *                          => to import a variation of a variable prodcut, you must use the variation's ID, not the parent variable product's ID
		 *			            - an array of Line Item Data, including line item totals and tax amounts.
		 *                          => To add tax or other custom information to your product line items you need to follow strict formatting
		 *                          - table with allowed columns
		 *                      - Multiple Product Line Items
		 *          - shipping line items
		 *          - fee line items
		 *
		 *  --> Importing Subscriptions with Coupons
		 *      - coupon_items
		 *
		 *  --> Importing Subscriptions with Fee Line Items
		 *      - fee_items
		 *
		 *  --> Importing Subscriptions with Tax Line Items
		 *      - tax_items
		 */

		$woocomm_subscriptions_importer_data = [];

		foreach ( $pmpro_orders as $key_pmpro_order => $pmpro_order ) {

			$subscription = [];

			$pmp_order_member = $this->get_pmp_member_by_username( $pmpro_order[ 'username' ], $pmpro_members );

			// --- Customer User data.
			// Takes either WP user ID, or username and email. We'll work with User IDs, so that we can also store other customer info.
			$subscription = array_merge(
				$subscription,
				[
					'customer_id' => $pmpro_order[ 'user_id' ],
				]
			);

			// TODO - there are some blank 'user_id's/emails in PMP's Orders CSV. How to handle those? Create new WP Users wo/ email?

			// Start TEMP User creation.
			// 	// Validate email.
			// 	if ( ! isset( $pmpro_order[ 'email' ] ) || empty( $pmpro_order[ 'email' ] ) ) {
			// 		throw new \RuntimeException( sprintf(
			// 			"User email not found for Order ID %d, CSV file line number %d.",
			// 			$pmpro_order[ 'id' ],
			// 			$key_pmpro_order + 1
			// 		) );
			// 	}
			//
			// 	// Get or create WP User.
			// 	$user = get_user_by( 'email', $pmpro_order[ 'email' ] );
			// 	$user_id = null;
			// 	if ( ! $user ) {
			// 		$user_id = $user->ID;
			// 	} else {
			// 		$user_id = wp_insert_user( [
			// 			'user_login' => $pmpro_order[ 'username' ],
			// 			'user_email' => $pmpro_order[ 'email' ],
			// 			'first_name' => $pmpro_order[ 'firstname' ],
			// 			'last_name' => $pmpro_order[ 'lastname' ],
			// 		] );
			// 	}
			//
			// 	if ( ! $user_id ) {
			// 		throw new \RuntimeException( sprintf(
			// 			"Could not get or create WP User with email %s for Order ID %d, CSV file line number %d.",
			// 			$pmpro_order[ 'email' ],
			// 			$pmpro_order[ 'id' ],
			// 			$key_pmpro_order + 1
			// 		) );
			// 	}
			// End TEMP User creation.


			// --- Subscription status.

			// TODO
			// PMP statuses:
			//     cancelled
			//     review
			//     success
			//     token
			// WooComm Subscription statuses:
			//     wc-active
			//     wc-on-hold
			//     wc-cancelled
			$subscription_status = 'wc-active';

			$subscription = array_merge(
				$subscription,
				[
					'subscription_status' => $subscription_status,
				]
			);


			// --- Subscription start and end dates, trial end date, last and next payment dates.

			// TODO - confirm that last_payment_date is same as PMP Order timestamp?
			// TODO - confirm that 'next_payment_date' and 'end_date' is equal to Order timestamp + "one unit of frequency"?

			$order_date = \DateTime::createFromFormat( 'F j, Y g:i A', $pmpro_order[ 'timestamp' ] );
			$woocom_order_date = $order_date->format( 'Y-m-d H:i:s' );

			$frequency_interval = \DateInterval::createFromDateString( sprintf( "1 %s", strtolower( $pmp_order_member[ 'term' ] ) ) );
			$end_date = $order_date->add( $frequency_interval );
			$end_date_format = $end_date->format( 'Y-m-d H:i:s' );

			return;


			// TODO
			// PMP Member info
			//      initial payment
			//          10
			//      fee
			//          10
			//      term
			//          'Month'
			//          'Year'
			//          ''
			//          TODO - Member's 'term' (frequency) can be blank, too. What then?
			//          TODO - validate other values
			//      discount_code_id
			//          TODO ...
			//      discount_code
			//          TODO ...
			//      joined
			//          2022-03-06
			//      expires
			//          Never

			$subscription = array_merge(
				$subscription,
				[
					'start_date' => $woocom_order_date,
					// 2016-04-29 00:44:44
					'trial_end_date' => 0,
					// 0
					'next_payment_date' => $end_date_format,
					// 2016-05-29 00:44:44
					'last_payment_date' => $woocom_date_format,
					// 2016-04-29 00:44:46
					'end_date' => $end_date_format,
					// 2018-04-29 00:44:44
				]
			);


			// --- Subscription frequency.
			$subscription = array_merge(
				$subscription,
				[
					'billing_period',
					// month
					// week
					'billing_interval',
					// 1
					// 2
				]
			);


			// --- Shipping amount, tax, order tax, discount amount, discount tax, order total and order currency.

			// TODO
			// PMP's 'discount_code_id's
			//      1
			// 	    11
			// 	    4
			// 	    5
			// 	    9
			// PMP's 'discount_code's:
			// 	    AFROHOLIDAY
			// 	    GRANDFATHER
			// 	    SEEYOURSELF
			// 	    SIMMONE
			// 	    TEST
			$subscription = array_merge(
				$subscription,
				[
					'order_shipping',
					// 4.44
					'order_shipping_tax',
					// 0.444
					'order_tax',
					// 4.3
					'cart_discount',
					// 22
					'cart_discount_tax',
					// 2.2
					'order_total',
					// 46.68
					'order_currency',
					// USD
				]
			);


			// TODO - $pmpro_order[ 'gateway_environment' ] can be 'live' or 'sandbox'. Should we import 'sandbox' ones too?


			// --- Payment method.

			// TODO - check if other gateways other than Stripe can/need to be imported. Log which of these had non-stripe.
			// PMP's 'gateway' column can be:
			//     check
			//     free
			//     payflowpro
			//     paypal
			//     paypalexpress
			//     paypalstandard
			//     stripe

			// Will be 'manual' or 'stripe. Seems like it's not possible to migrate PayPal Standard (https://github.com/woocommerce/woocommerce-subscriptions-importer-exporter).
			$payment_method = 'manual';
			$payment_method_title = 'Manual';
			if ( 'stripe' == $pmpro_order[ 'gateway_environment' ] ) {
				$payment_method = 'stripe';
				$payment_method_title = 'Credit card (Stripe)';
			}

			$subscription = array_merge(
				$subscription,
				[
					'payment_method' => $payment_method,
					// manual
					// or:
					// stripe
					'payment_method_title' => $payment_method_title,
					// Manual
					// or:
					// Credit card (Stripe)
					'payment_method_post_meta',
					'payment_method_user_meta',
				]
			);


			// --- Shipping method.
			$subscription = array_merge(
				$subscription,
				[
					'shipping_method',
					// method_id:flat_rate|
					// method_title:Flat Rate|
					// total:4.44
					// --- or:
					// method_id:free_shipping|
					// method_title:Free Shipping|
					// total:0.00
				]
			);


			// --- Billing info.

			// PMP has a single billing_name, while WooComm needs separate first and last.
			$name = $pmpro_order[ 'name' ];
			$name_exploded = explode( ' ', $name );
			$name_last = $name_exploded[ count( $name ) - 1 ];
			unset( $name_exploded[ count( $name ) - 1 ] );
			$names_before_last = implode( ' ', $name_exploded );

			$subscription = array_merge(
				$subscription,
				[
					'billing_first_name' => $names_before_last,
					// George
					'billing_last_name' => $name_last,
					// Washington
					'billing_email' => '',
					// george@example.com
					'billing_phone' => $pmpro_order[ 'billing_phone' ],
					// (555) 555-5555
					'billing_address_1' => $pmpro_order[ 'billing_street' ],
					// 969 Market
					'billing_address_2' => '',
					'billing_postcode' => $pmpro_order[ 'billing_zip' ],
					// 94103
					'billing_city' => $pmpro_order[ 'billing_city' ],
					// San Francisco
					'billing_state' => $pmpro_order[ 'billing_state' ],
					// CA
					'billing_country' => $pmpro_order[ 'billing_country' ],
					// US
					'billing_company' => '',
					// Prospress Inc.
				]
			);


			// --- Shipping info.
			$subscription = array_merge(
				$subscription,
				[
					'shipping_first_name',
					// George
					'shipping_last_name',
					// Washington
					'shipping_address_1',
					// 969 Market
					'shipping_address_2',
					'shipping_postcode',
					// 94103
					'shipping_city',
					// San Francisco
					'shipping_state',
					// CA
					'shipping_country',
					// US
					'shipping_company',
				]
			);


			// --- Customer note placed on the order/subscription by the customer at checkout and displayed to the store owner via the Edit Subscription and Edit Order administration screens..
			$subscription = array_merge(
				$subscription,
				[
					'customer_note',
				]
			);


			// --- Order items.
			$product_id = $pmp_woocomm_product_map[ $pmpro_order[ 'membership_id' ] ] ?? null;
			if ( is_null( $product_id ) ) {
				throw new \RuntimeException( sprintf(
					"Product mapping missing, WooComm product ID not found for PMP's membership_id %d.",
					$pmpro_order[ 'membership_id' ]
				) );
			}
			$product_name = $pmpro_order[ 'level_name' ];
			// TODO - can quantity be > 1?
			$product_quantity = 1;
			$product_total = $pmpro_order[ 'subtotal' ];
			$product_meta = $pmpro_order[ '' ];
			$product_tax = $pmpro_order[ 'tax' ];
			// TODO - check if PMP Order's $pmpro_order[ 'total' ] corresponds to what WooComm importer calculated and saved.

			$subscription = array_merge(
				$subscription,
				[
					'order_items' => sprintf(
						"product_id:%d|name:%s|quantity:%d|total:%f|meta:%s|tax:%f",
						$product_id,
						$product_name,
						$product_quantity,
						$product_total,
						$product_meta,
						$product_tax
					),
					// 229945 - corporate
					// 229884 - digital, variants:
					//      229941 - monthly
					//      229942 - annually
					// 29880 - print & digital, variants:
					//      229948 - monthly
					//      229949 - annually
					// product_id:229884|
					// name:Imported Subscription with Custom Line Item Name|
					// quantity:4|
					// total:38.00|
					// meta:|
					// tax:3.80
					'order_notes',
					// This is a note to the customer added by the store owner via Edit Subscription admin screen.;
					// This is a private order note added by the store owner via Edit Subscription admin screen.;
					// Payment received.;
					// Status changed from Pending to Active.
				]
			);


			// --- Cupon items.
			$subscription = array_merge(
				$subscription,
				[
					'coupon_items',
				]
			);


			// --- Fee items.
			$subscription = array_merge(
				$subscription,
				[
					'fee_items',
					// name:Custom Fee|
					// total:5.00|
					// tax:0.50
				]
			);


			// --- Tax items.
			$subscription = array_merge(
				$subscription,
				[
					'tax_items',
					// id:4|
					// code:Sales Tax|
					// total:4.74
					// --- or:
					// id:4|
					// code:Sales Tax|
					// total:0.00
				]
			);


			// --- Download permissions grants download permission for product (requires files to bet set on product). Can be 0 or 1.
			$subscription = array_merge(
				$subscription,
				[
					'download_permissions',
				]
			);

			$woocomm_subscriptions_importer_data[] = $subscription;
		}

		return $woocomm_subscriptions_importer_data;
	}

	/**
	 * Finds PMP Member by username/user_login.
	 *
	 * @param $pmpro_order_username
	 * @param $pmpro_members
	 *
	 * @return null
	 */
	public function get_pmp_member_by_username( $pmpro_order_username, $pmpro_members ) {

		foreach ( $pmpro_members as $key_pmpro_member => $pmpro_member ) {
			if ( $pmpro_order_username == $pmpro_member[ 'user_login' ] ) {
				return $pmpro_member;
			}
		}

		return null;
	}
}
