<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;

class WCOrdersMigrator implements InterfaceMigrator {

	const GENERAL_LOG = 'ordersmigrator.log';

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
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator wc-orders-migrate',
			[ $this, 'cmd_wc_order_migrate' ],
			[
				'shortdesc' => 'Migrates WC Orders (with belonging Subscriptions and Customers) from source DB tables (with one prefix) to destination DB tables (with a different prefix).',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'source-table-prefix',
						'description' => "Source DB tables prefix.",
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'destination-table-prefix',
						'description' => "Destination DB tables prefix.",
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'source-order-ids-csv',
						'description' => "Source orders\' IDs in CSV to import into destination.",
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Callable for newspack-content-migrator wc-orders-migrate command.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_wc_order_migrate( $args, $assoc_args ) {
		$table_prefix_source = $assoc_args[ 'source-table-prefix' ] ?? null;
		$table_prefix_destination = $assoc_args[ 'destination-table-prefix' ] ?? null;
		$source_order_ids = $assoc_args[ 'source-order-ids-csv' ] ?? null;
		if ( $source_order_ids ) {
			$source_order_ids = explode( ',', $source_order_ids );
		}
		if ( empty( $source_order_ids ) ) {
			WP_CLI::error( 'Order IDs for export not provided.' );
		}

		// Sanitize table prefixes.
		$table_prefix_source = sanitize_key( $table_prefix_source );
		$table_prefix_destination = sanitize_key( $table_prefix_destination );

		global $wpdb;
		// 	Get shop orders.
		foreach ( $source_order_ids as $source_order_id ) {

			$msg = sprintf( 'Getting order ID %d' );
			WP_CLI::line( $msg );
			$this->log( self::GENERAL_LOG, $msg );

			// Export Order, Subscription, WP User and WC Customer Lookup records.
			$order_row = $wpdb->get_row(
				$wpdb->prepare(
					"select wp.* from {$table_prefix_source}posts wp
					left join {$table_prefix_source}postmeta wpm on wpm.post_id = wp.ID
					where wp.post_type='shop_order'
					and wp.ID = %d;",
					$source_order_id
				),
				ARRAY_A
			);
			if ( empty( $order_row ) ) {
				$msg = sprintf( "ERROR: could not find order ID %d. Skipping.", $source_order_id );
				WP_CLI::warning( $msg );
				$this->log( self::GENERAL_LOG, $msg );
				continue;
			}

			$order_meta_rows = $wpdb->get_results(
				$wpdb->prepare(
					"select wpm.* from {$table_prefix_source}postmeta wpm
					join {$table_prefix_source}posts wp on wpm.post_id = wp.ID and wp.ID = %d;",
					$source_order_id
				),
				ARRAY_A
			);
			if ( empty( $order_meta_rows ) ) {
				$msg = sprintf( "ERROR: could not find meta for order ID %d. Skipping.", $source_order_id );
				WP_CLI::warning( $msg );
				$this->log( self::GENERAL_LOG, $msg );
				continue;
			}

			$order_user = $wpdb->get_row(
				$wpdb->prepare(
					"select wu.* from {$table_prefix_source}users wu
					join {$table_prefix_source}postmeta wpm on wpm.meta_value = wu.ID
					where wpm.meta_key = '_customer_user'
					and wpm.post_id = %d;",
					$source_order_id
				),
				ARRAY_A
			);
			if ( empty( $order_user ) ) {
				$msg = sprintf( "ERROR: could not find order WP User for order ID %d. Skipping.", $source_order_id );
				WP_CLI::warning( $msg );
				$this->log( self::GENERAL_LOG, $msg );
				continue;
			}

			$order_customer_lookup_row = $wpdb->get_row(
				$wpdb->prepare(
					"select wccl.* from {$table_prefix_source}wc_customer_lookup wccl
					where wccl.user_id = %d;",
					$order_user[ 'ID' ]
				),
				ARRAY_A
			);
			if ( empty( $order_customer_lookup_row ) ) {
				$msg = sprintf( "ERROR: could not find customer lookup records for order ID %d. Skipping.", $source_order_id );
				WP_CLI::warning( $msg );
				$this->log( self::GENERAL_LOG, $msg );
				continue;
			}


			// Import User.
			$order_existing_user = $wpdb->get_row(
				$wpdb->prepare(
					"select wu.* from {$table_prefix_destination}users wu
					where wu.user_login = %s;",
					$order_user[ 'user_login' ]
				),
				ARRAY_A
			);
			$user_already_exists = ! empty( $order_existing_user );
			if ( $user_already_exists ) {
				$user_id = $order_existing_user[ 'ID' ];
			} else {
				$query = $wpdb->prepare(
					"insert into {$table_prefix_destination}users
					(user_login,user_pass,user_nicename,user_email,user_url,user_registered,user_activation_key,user_status,display_name)
					values (%s,%s,%s,%s,%s,%s,%s,%s,%s); ",
					$order_user[ 'user_login' ],
					$order_user[ 'user_pass' ],
					$order_user[ 'user_nicename' ],
					$order_user[ 'user_email' ],
					$order_user[ 'user_url' ],
					$order_user[ 'user_registered' ],
					$order_user[ 'user_activation_key' ],
					$order_user[ 'user_status' ],
					$order_user[ 'display_name' ],
				);
				$res = $wpdb->query( $query );
				$user_id = $wpdb->insert_id;
			}

			// Import Customer Lookup.
			$customer_lookup_existing_record = $wpdb->get_row(
				$wpdb->prepare(
					"select wcl.* from {$table_prefix_destination}wc_customer_lookup wcl
					where wcl.user_id = %d;",
					$user_id
				),
				ARRAY_A
			);
			$customer_lookup_already_exists = ! empty( $customer_lookup_existing_record );
			if ( ! $customer_lookup_already_exists ) {
				$query = $wpdb->prepare(
					"insert into {$table_prefix_destination}wc_customer_lookup
					(user_id,username,first_name,last_name,email,date_last_active,date_registered,country,postcode,city,state)
					values (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s); ",
					$order_customer_lookup_row[ 'user_id' ],
					$order_customer_lookup_row[ 'username' ],
					$order_customer_lookup_row[ 'first_name' ],
					$order_customer_lookup_row[ 'last_name' ],
					$order_customer_lookup_row[ 'email' ],
					$order_customer_lookup_row[ 'date_last_active' ],
					$order_customer_lookup_row[ 'date_registered' ],
					$order_customer_lookup_row[ 'country' ],
					$order_customer_lookup_row[ 'postcode' ],
					$order_customer_lookup_row[ 'city' ],
					$order_customer_lookup_row[ 'state' ],
				);
				$res = $wpdb->query( $query );
				$last_inserted_id = $wpdb->insert_id;
			}

			// Import Order.
			// $query = $wpdb->prepare(
			// 	"insert into {$table_prefix_destination}posts
			// 	(post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_password,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,post_parent,guid,menu_order,post_type,post_mime_type,comment_count)
			// 	values (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s); ",
			// 	$order_row[ 'post_author' ],
			// 	$order_row[ 'post_date' ],
			// 	$order_row[ 'post_date_gmt' ],
			// 	$order_row[ 'post_content' ],
			// 	$order_row[ 'post_title' ],
			// 	$order_row[ 'post_excerpt' ],
			// 	$order_row[ 'post_status' ],
			// 	$order_row[ 'comment_status' ],
			// 	$order_row[ 'ping_status' ],
			// 	$order_row[ 'post_password' ],
			// 	$order_row[ 'post_name' ],
			// 	$order_row[ 'to_ping' ],
			// 	$order_row[ 'pinged' ],
			// 	$order_row[ 'post_modified' ],
			// 	$order_row[ 'post_modified_gmt' ],
			// 	$order_row[ 'post_content_filtered' ],
			// 	$order_row[ 'post_parent' ],
			// 	$order_row[ 'guid' ],
			// 	$order_row[ 'menu_order' ],
			// 	$order_row[ 'post_type' ],
			// 	$order_row[ 'post_mime_type' ],
			// 	$order_row[ 'comment_count' ],
			// );
			// $res = $wpdb->query( $query );
			// $order_id = $wpdb->insert_id;
$order_id = 23995;
			if ( 1 != $res ) {
				// TODO
			}

			// Import Meta.
			foreach ( $order_meta_rows as $order_meta_row ) {
				$meta_value = $order_meta_row[ 'meta_value' ];
				if ( '_customer_user' == $order_meta_row[ 'meta_key' ] ) {
					$meta_value = $user_id;
				}
				$query = $wpdb->prepare(
					"insert into {$table_prefix_destination}postmeta
					(post_id,meta_key,meta_value)
					values (%s,%s,%s); ",
					$order_id,
					$order_meta_row[ 'meta_key' ],
					$meta_value
				);
				$res = $wpdb->query( $query );
				$last_inserted_id = $wpdb->insert_id;
			}


		}
	}

	/**
	 * Simple file logging.
	 *
	 * @param string $file    File name or path.
	 * @param string $message Log message.
	 */
	private function log( $file, $message ) {
		$message .= "\n";
		file_put_contents( $file, $message, FILE_APPEND );
	}
}
