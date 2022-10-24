<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \Newspack\Stripe_Connection;
use \WP_CLI;

/**
 * Custom migration scripts for CalMatters.
 */
class LongreadsMigrator implements InterfaceMigrator {

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

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
			'newspack-content-migrator longreads-subscriptions-stripe',
			array( $this, 'longreads_subscriptions_stripe' ),
			array(
				'shortdesc' => 'Import Stripe subscriptions.',
				'synopsis'  => array(),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator longreads-subscriptions-misc',
			array( $this, 'longreads_subscriptions_misc' ),
			array(
				'shortdesc' => 'Import misc. non-Stripe subscriptions.',
				'synopsis'  => array(),
			)
		);
	}

	public function longreads_subscriptions_stripe( $args, $assoc_args ) {
		if ( empty( $assoc_args['input_json'] ) ) {
			WP_CLI::error( 'Need to specify --input_json file' );
		}

		$is_dry_run     = ! empty( $assoc_args['dry-run'] );
		$force_override = ! empty( $assoc_args['force'] );
		$batch_size     = ! empty( $assoc_args['batch-size'] ) ? intval( $assoc_args['batch-size'] ) : 10;

		$input_json = $assoc_args['input_json'];
		$wpcom_data = json_decode( file_get_contents( $input_json ), true );
		if ( empty( $wpcom_data ) ) {
			WP_CLI::error( 'Failed to open input JSON: ' . $input_json );
		}

		$num_successfully_processed = 0;
		foreach ( $wpcom_data as $subscriber ) {
			$emails_to_skip = [
				'rnteacher47@yahoo.com',
				'drrachelwalker@gmail.com',
				'vjtuey@gmail.com',
				'josepablogzac@gmail.com',
				'danfernandez597@gmail.com',
				'peter.s.rubin@gmail.com',
			];
			$email = $subscriber['user']['user_email'];
			if ( in_array( $email, $emails_to_skip ) ) {
				continue;
			}
			$plan_interval = $subscriber['plan']['renew_interval'];
			if ( 'one-time' === $plan_interval ) {
				//WP_CLI::log( '  - One-Time Donation. Skipping.' );
				continue;
			}

			if ( false !== stripos( $email, 'purged-account' ) ) {
				//WP_CLI::log( '  - Churned. Account deleted. Skipping.' );
				continue;
			}

			$plan_amount  = $subscriber['plan']['renewal_price'];
			$next_renewal = $subscriber['end_date'];
			$status       = $subscriber['status'];
			$user         = $subscriber['user']['ID'];

			if ( strtotime( $next_renewal ) < time() ) {
				//WP_CLI::log( '  - Churned: ' . $next_renewal . '. Skipping.' );
				continue;
			}

			WP_CLI::log( 'Processing: ' . $email );
			WP_CLI::log( '  - Interval: ' . $plan_interval );
			WP_CLI::log( '  - Amount: ' . $plan_amount );
			WP_CLI::log( '  - Next renewal: ' . $next_renewal );
			WP_CLI::log( '  - Status: ' . $status );
			WP_CLI::log( '  - User ID: ' . $user );

			$stripe_data = get_option( 'newspack_stripe_data' );
			$stripe_key = $stripe_data['secretKey'];
			$stripe     = new \Stripe\StripeClient( [
				"api_key"        => $stripe_key,
				"stripe_version" => '2022-08-01',
			] );

			try {
				// Get the PaymentMethod for the customer.
				$payments = $stripe->charges->search( [
					'query' => 'metadata[\'user_id\']:\'' . $user . '\'',
				] );
				if ( empty( $payments ) || empty( $payments['data'] ) ) {
					WP_CLI::warning( "Failed to find associated Stripe data" );
					continue;
				}
				$payment_method_id = $payments['data'][0]['payment_method'];

				$customer_id = false;

				// Find the customer if exists.
				$payment_method = $stripe->paymentMethods->retrieve(
					$payment_method_id,
					[]
				);
				if ( $payment_method['customer'] ) {
					$customer_id = $payment_method['customer'];
				} else {
					// Create the official customer.
					$customer_data = [
						'description'    => 'Migrated to Newspack from WPCOM',
						'email'          => $email,
						'name'           => $subscriber['user']['name'],
						'payment_method' => $payment_method_id,
					];
					$stripe_customer = $stripe->customers->create( $customer_data );
					$customer_id = $stripe_customer['id'];
				}

				// Check customer's subscriptions to see if they're aleady migrated.
				$existing_subscriptions = $stripe->subscriptions->all( [ 'customer' => $customer_id ] );
				if ( ! empty( $existing_subscriptions ) && ! empty( $existing_subscriptions['data'] ) ) {
					WP_CLI::log( '  - ' . $email . ' already migrated: ' . $customer_id );
					continue;
				}

				$stripe_prices    = Stripe_Connection::get_donation_prices();
				$stripe_frequency = '1 month' === $plan_interval ? 'month' : 'year';
				$stripe_price = $stripe_prices[ $stripe_frequency ];

				$stripe_subscription_items = [
					[
						'price'    => $stripe_price,
						'quantity' => $plan_amount * 100,
					],
				];

				$stripe_subscription = $stripe->subscriptions->create(
					[
						'customer'             => $customer_id,
						'items'                => $stripe_subscription_items,
						'payment_behavior'     => 'allow_incomplete',
						'billing_cycle_anchor' => strtotime( $next_renewal ),
						'trial_end'            => strtotime( $next_renewal ),
						'metadata'             => [
							'subscription_migrated_to_newspack' => gmdate( 'c' ),
						],
					]
				);

				++$num_successfully_processed;
				WP_CLI::log( '  - Migrated subscription: ' . $stripe_subscription->id );
			} catch ( \Stripe\Exception\ApiErrorException $e ) {
				WP_CLI::warning( sprintf( 'Failed processing: %s', $e->getMessage() ) );
			}
		}
		WP_CLI::success( 'Processed ' . $num_successfully_processed . ' subscriptions' );
	}

	public function longreads_subscriptions_misc( $args, $assoc_args ) {

	}
}
