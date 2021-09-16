<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;

class WooCommOrdersMigrator implements InterfaceMigrator {

	const GENERAL_LOG = 'wc_orders_subscriptions_migration.log';

	const EXCEPTION_CODE_SKIP_IMPORTING = 700;

	const WOOCOMM_ORDER_CPT = 'shop_order';
	const WOOCOMM_SUBSCRIPTION_CPT = 'shop_subscription';

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
				'shortdesc' => 'Migrates WC Orders (with belonging Subscriptions and Customers) from one site to another one. Expects to have source WoComm DB tables (with one prefix) along side destination/local DB tables (with a different prefix).',
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
						'description' => "Source Orders\' IDs in CSV to import into destination.",
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'source-subscription-ids-csv',
						'description' => "Source Subscriptions\' IDs in CSV to import into destination.",
						'optional'    => true,
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
		$source_subscription_ids = $assoc_args[ 'source-subscription-ids-csv' ] ?? null;
		if ( $source_subscription_ids ) {
			$source_subscription_ids = explode( ',', $source_subscription_ids );
		}
		if ( empty( $source_order_ids ) && empty( $source_subscription_ids ) ) {
			WP_CLI::error( 'Must provide either Order IDs and/or Subscription IDs.' );
		}

		WP_CLI::warning( "Please make sure Order Products have the same IDs, or improve this script to import Products, too." );

		foreach ( $source_order_ids as $key_source_order_ids => $source_order_id ) {
			$msg = sprintf( '(%d/%d) Getting order ID %d', $key_source_order_ids + 1, count($source_order_ids), $source_order_id );
			WP_CLI::success( $msg );
			$this->log( self::GENERAL_LOG, $msg );

			try {
				$this->migrate_order_or_subscription( $source_order_id, null, $table_prefix_source, $table_prefix_destination );
			} catch ( \Exception $e ) {
				if ( self::EXCEPTION_CODE_SKIP_IMPORTING == $e->getCode() ) {
					// Continue with the next ID.
					continue;
				}
			}
		}

		foreach ( $source_subscription_ids as $key_source_subscription_ids => $source_subscription_id ) {
			$msg = sprintf( '(%d/%d) Getting subscription ID %d', $key_source_subscription_ids + 1, count( $source_subscription_ids ), $source_subscription_id );
			WP_CLI::success( $msg );
			$this->log( self::GENERAL_LOG, $msg );

			try {
				$this->migrate_order_or_subscription( null, $source_subscription_id, $table_prefix_source, $table_prefix_destination );
			} catch ( \Exception $e ) {
				if ( self::EXCEPTION_CODE_SKIP_IMPORTING == $e->getCode() ) {
					// Continue with the next ID.
					continue;
				}
			}
		}

	}

	/**
	 * Migrates either a WC Order or a WC Subscription from one set of DB tables to another.
	 *
	 * The code is far from great; it was first done for Order migration, then reused to handle Subscriptions, because these two
	 * objects use the same data structure.
	 *
	 * @param int|null $source_order_id
	 * @param int|null $source_subscription_id
	 * @param string   $table_prefix_source
	 * @param string   $table_prefix_destination
	 */
	private function migrate_order_or_subscription( $source_order_id = null, $source_subscription_id = null, $table_prefix_source, $table_prefix_destination ) {
		global $wpdb;

		if ( is_null( $source_order_id ) && is_null( $source_subscription_id ) ) {
			throw new \RuntimeException( 'Must provide either Order ID or Subscription ID.' );
		}

		// Sanitize table prefixes.
		$table_prefix_source = sanitize_key( $table_prefix_source );
		$table_prefix_destination = sanitize_key( $table_prefix_destination );

		// Is this an Order or a Subscription.
		$cpt = ! is_null( $source_order_id ) ? self::WOOCOMM_ORDER_CPT : self::WOOCOMM_SUBSCRIPTION_CPT;
		$object_name = self::WOOCOMM_ORDER_CPT == $cpt ? 'Order' : 'Subscription';
		$source_object_id = ! is_null( $source_order_id ) ? $source_order_id : $source_subscription_id;

		$order_row = $wpdb->get_row(
			$wpdb->prepare(
				"select wp.* from {$table_prefix_source}posts wp
					left join {$table_prefix_source}postmeta wpm on wpm.post_id = wp.ID
					where wp.post_type = %s
					and wp.ID = %d;",
				$cpt,
				$source_object_id
			),
			ARRAY_A
		);
		if ( empty( $order_row ) ) {
			$msg = sprintf( "ERROR: could not find order ID %d. Skipping.", $source_object_id );
			WP_CLI::warning( $msg );
			$this->log( self::GENERAL_LOG, $msg );
			throw new \RuntimeException( $msg, self::EXCEPTION_CODE_SKIP_IMPORTING );
		} else {
			$msg = sprintf( 'Found Order ID %s', $source_object_id );
			WP_CLI::success( $msg );
			$this->log( self::GENERAL_LOG, $msg );
		}

		$order_meta_rows = $wpdb->get_results(
			$wpdb->prepare(
				"select wpm.* from {$table_prefix_source}postmeta wpm
					join {$table_prefix_source}posts wp on wpm.post_id = wp.ID and wp.ID = %d;",
				$source_object_id
			),
			ARRAY_A
		);
		if ( empty( $order_meta_rows ) ) {
			$msg = sprintf( "ERROR: could not find meta for order ID %d. Skipping.", $source_object_id );
			WP_CLI::warning( $msg );
			$this->log( self::GENERAL_LOG, $msg );
			throw new \RuntimeException( $msg, self::EXCEPTION_CODE_SKIP_IMPORTING );
		} else {
			$msg = sprintf( 'Found order meta', $source_object_id );
			WP_CLI::success( $msg );
			$this->log( self::GENERAL_LOG, $msg );
		}

		$order_comments_rows = $wpdb->get_results(
			$wpdb->prepare(
				"select * from {$table_prefix_source}comments
					where comment_post_ID = %d;",
				$source_object_id
			),
			ARRAY_A
		);
		if ( empty( $order_comments_rows ) ) {
			$msg = sprintf( "No Order comments found" );
			WP_CLI::success( $msg );
			$this->log( self::GENERAL_LOG, $msg );
		} else {
			$msg = sprintf( 'Found Order Comments' );
			WP_CLI::success( $msg );
			$this->log( self::GENERAL_LOG, $msg );
		}

		$order_stats_rows = $wpdb->get_results(
			$wpdb->prepare(
				"select wos.* from {$table_prefix_source}wc_order_stats wos
					where wos.order_id = %d;",
				$source_object_id
			),
			ARRAY_A
		);
		if ( empty( $order_stats_rows ) ) {
			$msg = sprintf( "WARNING: could not find order stats" );
			WP_CLI::success( $msg );
			$this->log( self::GENERAL_LOG, $msg );
		} else {
			$msg = sprintf( 'Found Order order stats' );
			WP_CLI::success( $msg );
			$this->log( self::GENERAL_LOG, $msg );
		}

		$order_items_rows = $wpdb->get_results(
			$wpdb->prepare(
				"select * from {$table_prefix_source}woocommerce_order_items
					where order_id = %d;",
				$source_object_id
			),
			ARRAY_A
		);
		if ( empty( $order_items_rows ) ) {
			$msg = sprintf( "WARNING: could not find order items.", $source_object_id );
			WP_CLI::success( $msg );
			$this->log( self::GENERAL_LOG, $msg );

			$order_items_metas = [];
		} else {
			$msg = 'Found Order items';
			WP_CLI::success( $msg );
			$this->log( self::GENERAL_LOG, $msg );

			$order_items_metas = [];
			foreach ( $order_items_rows as $order_items_row ) {

				// Key is order_item_id, and values are its meta.
				$order_items_metas[ $order_items_row[ 'order_item_id' ] ] = $wpdb->get_results(
					$wpdb->prepare(
						"select * from {$table_prefix_source}woocommerce_order_itemmeta
							where order_item_id = %d;",
						$order_items_row[ 'order_item_id' ]
					),
					ARRAY_A
				);

			}
			$msg = 'Found Order items meta';
			WP_CLI::success( $msg );
			$this->log( self::GENERAL_LOG, $msg );
		}

		$order_product_lookup_rows = $wpdb->get_results(
			$wpdb->prepare(
				"select * from {$table_prefix_source}wc_order_product_lookup
					where order_id = %d;",
				$source_object_id
			),
			ARRAY_A
		);
		if ( empty( $order_product_lookup_rows ) ) {
			$msg = sprintf( "WARNING: could not find order product lookup records.", $source_object_id );
			WP_CLI::success( $msg );
			$this->log( self::GENERAL_LOG, $msg );
		} else {
			$msg = 'Found Order Product Lookup records';
			WP_CLI::success( $msg );
			$this->log( self::GENERAL_LOG, $msg );
		}

		$order_user = $wpdb->get_row(
			$wpdb->prepare(
				"select wu.* from {$table_prefix_source}users wu
					join {$table_prefix_source}postmeta wpm on wpm.meta_value = wu.ID
					where wpm.meta_key = '_customer_user'
					and wpm.post_id = %d;",
				$source_object_id
			),
			ARRAY_A
		);
		$order_user_meta = [];
		$order_customer_lookup_row = [];
		if ( empty( $order_user ) ) {
			$msg = sprintf( "WARNING: could not find WP User for order ID %d.", $source_object_id );
			WP_CLI::success( $msg );
			$this->log( self::GENERAL_LOG, $msg );
		} else {
			$msg = sprintf( 'Found Order WP User ID %s', $order_user[ 'ID' ] );
			WP_CLI::success( $msg );
			$this->log( self::GENERAL_LOG, $msg );

			$order_user_meta = $wpdb->get_results(
				$wpdb->prepare(
					"select wum.* from {$table_prefix_source}usermeta wum
					where wum.user_id = %d;",
					$order_user[ 'ID' ]
				),
				ARRAY_A
			);
			if ( empty( $order_user_meta ) ) {
				$msg = sprintf( "WARNING: could not find WP User Meta for order ID %d.", $source_object_id );
				WP_CLI::success( $msg );
				$this->log( self::GENERAL_LOG, $msg );
			} else {
				$msg = sprintf( 'Found Order WP User meta' );
				WP_CLI::success( $msg );
				$this->log( self::GENERAL_LOG, $msg );
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
				$msg = sprintf( "WARNING: could not find customer lookup records for order ID %d. Skipping.", $source_object_id );
				WP_CLI::success( $msg );
				$this->log( self::GENERAL_LOG, $msg );
			}
			$msg = sprintf( 'Found Order WC Customer lookup ID %s', $order_customer_lookup_row[ 'customer_id' ] );
			WP_CLI::success( $msg );
			$this->log( self::GENERAL_LOG, $msg );
		}


		// Import WP User.
		if ( ! empty( $order_user ) ) {
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
				$msg = sprintf( 'Importing WP User, existing user found, ID %d', $user_id );
				$this->log( self::GENERAL_LOG, $msg );
				WP_CLI::success( $msg );
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
				if ( 1 != $res ) {
					$msg = sprintf( 'ERROR: WP User insert error, user_login %s', $order_user[ 'user_login' ] );
					$this->log( self::GENERAL_LOG, $msg );
					WP_CLI::warning( $msg );
				}
				$user_id = $wpdb->insert_id;
				$msg = sprintf( 'Imported WP User ID %d', $user_id );
				$this->log( self::GENERAL_LOG, $msg );
				WP_CLI::success( $msg );

				foreach ( $order_user_meta as $order_user_meta_row ) {
					$query = $wpdb->prepare(
						"insert into {$table_prefix_destination}usermeta
						(user_id,meta_key,meta_value)
						values (%s,%s,%s); ",
						$user_id,
						$order_user_meta_row[ 'meta_key' ],
						$order_user_meta_row[ 'meta_value' ],
					);
					$res = $wpdb->query( $query );
					if ( 1 != $res ) {
						$msg = sprintf( 'ERROR: WP User meta insert error, source meta ID %d', $order_user_meta_row[ 'umeta_id' ] );
						$this->log( self::GENERAL_LOG, $msg );
						WP_CLI::warning( $msg );
					}
					$last_inserted_id = $wpdb->insert_id;
				}
				$msg = 'Imported WP User Meta.';
				$this->log( self::GENERAL_LOG, $msg );
				WP_CLI::success( $msg );
			}
		} else {
			$msg = 'Importing Order user is skipped.';
			$this->log( self::GENERAL_LOG, $msg );
			WP_CLI::success( $msg );
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
				$user_id,
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
			if ( 1 != $res ) {
				$msg = sprintf( 'ERROR: customer lookup insert error, source customer_id %d', $order_customer_lookup_row[ 'customer_id' ] );
				$this->log( self::GENERAL_LOG, $msg );
				WP_CLI::warning( $msg );
			}
			$customer_id = $wpdb->insert_id;

			$msg = sprintf( 'Imported Customer lookup ID %d', $customer_id );
			$this->log( self::GENERAL_LOG, $msg );
			WP_CLI::success( $msg );
		} else {
			$customer_id = $customer_lookup_existing_record[ 'customer_id' ];

			$msg = sprintf( 'Importing Customer lookup, existing record found, customer_id %d', $customer_id );
			$this->log( self::GENERAL_LOG, $msg );
			WP_CLI::success( $msg );
		}

		// Import Order.
		$query = $wpdb->prepare(
			"insert into {$table_prefix_destination}posts
				(post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_password,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,post_parent,guid,menu_order,post_type,post_mime_type,comment_count)
				values (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s); ",
			$order_row[ 'post_author' ],
			$order_row[ 'post_date' ],
			$order_row[ 'post_date_gmt' ],
			$order_row[ 'post_content' ],
			$order_row[ 'post_title' ],
			$order_row[ 'post_excerpt' ],
			$order_row[ 'post_status' ],
			$order_row[ 'comment_status' ],
			$order_row[ 'ping_status' ],
			$order_row[ 'post_password' ],
			$order_row[ 'post_name' ],
			$order_row[ 'to_ping' ],
			$order_row[ 'pinged' ],
			$order_row[ 'post_modified' ],
			$order_row[ 'post_modified_gmt' ],
			$order_row[ 'post_content_filtered' ],
			$order_row[ 'post_parent' ],
			$order_row[ 'guid' ],
			$order_row[ 'menu_order' ],
			$order_row[ 'post_type' ],
			$order_row[ 'post_mime_type' ],
			$order_row[ 'comment_count' ],
		);
		$res = $wpdb->query( $query );
		$order_id = $wpdb->insert_id;
		if ( 1 != $res ) {
			$msg = sprintf( 'ERROR: order insert error, source order ID %d', $source_object_id );
			$this->log( self::GENERAL_LOG, $msg );
			WP_CLI::warning( $msg );
			throw new \RuntimeException( $msg, self::EXCEPTION_CODE_SKIPP_IMPORTING );
		}
		$msg = sprintf( 'Importing Order ID %d', $order_id );
		$this->log( self::GENERAL_LOG, $msg );
		WP_CLI::success( $msg );

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
			if ( 1 != $res ) {
				$msg = sprintf( 'ERROR: order post meta insert error, source meta ID %d', $order_meta_row[ 'meta_id' ] );
				$this->log( self::GENERAL_LOG, $msg );
				WP_CLI::warning( $msg );
			}
			$last_inserted_id = $wpdb->insert_id;
		}
		$msg = 'Imporing Order Post Meta done.';
		$this->log( self::GENERAL_LOG, $msg );
		WP_CLI::success( $msg );

		// Import Order Comments.
		foreach ( $order_comments_rows as $order_comments_row ) {
			$query = $wpdb->prepare(
				"insert into {$table_prefix_destination}comments
					(comment_post_ID,comment_author,comment_author_email,comment_author_url,comment_author_IP,comment_date,comment_date_gmt,comment_content,comment_karma,comment_approved,comment_agent,comment_type,comment_parent,user_id)
					values (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s); ",
				$order_id,
				$order_comments_row[ 'comment_author' ],
				$order_comments_row[ 'comment_author_email' ],
				$order_comments_row[ 'comment_author_url' ],
				$order_comments_row[ 'comment_author_IP' ],
				$order_comments_row[ 'comment_date' ],
				$order_comments_row[ 'comment_date_gmt' ],
				$order_comments_row[ 'comment_content' ],
				$order_comments_row[ 'comment_karma' ],
				$order_comments_row[ 'comment_approved' ],
				$order_comments_row[ 'comment_agent' ],
				$order_comments_row[ 'comment_type' ],
				$order_comments_row[ 'comment_parent' ],
				$order_comments_row[ 'user_id' ],
			);
			$res = $wpdb->query( $query );
			if ( 1 != $res ) {
				$msg = sprintf( 'ERROR: order comment insert error, source comment_ID %d', $order_comments_row[ 'comment_ID' ] );
				$this->log( self::GENERAL_LOG, $msg );
				WP_CLI::warning( $msg );
			}
			$last_inserted_id = $wpdb->insert_id;
		}
		$msg = 'Importing Order Comments done.';
		$this->log( self::GENERAL_LOG, $msg );
		WP_CLI::success( $msg );

		// Import Order Stats.
		foreach ( $order_stats_rows as $order_stats_row ) {
			$query = $wpdb->prepare(
				"insert into {$table_prefix_destination}wc_order_stats
    				(order_id,parent_id,date_created,date_created_gmt,num_items_sold,total_sales,tax_total,shipping_total,net_total,returning_customer,status,customer_id)
					values (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s); ",
				$order_id,
				$order_stats_row[ 'parent_id' ],
				$order_stats_row[ 'date_created' ],
				$order_stats_row[ 'date_created_gmt' ],
				$order_stats_row[ 'num_items_sold' ],
				$order_stats_row[ 'total_sales' ],
				$order_stats_row[ 'tax_total' ],
				$order_stats_row[ 'shipping_total' ],
				$order_stats_row[ 'net_total' ],
				$order_stats_row[ 'returning_customer' ],
				$order_stats_row[ 'status' ],
				$customer_id,
			);
			$res = $wpdb->query( $query );
			if ( 1 != $res ) {
				$msg = sprintf( 'ERROR: order stats insert error, source order_id ID %d', $order_id );
				$this->log( self::GENERAL_LOG, $msg );
				WP_CLI::warning( $msg );
			}
			$last_inserted_id = $wpdb->insert_id;
		}
		$msg = 'Importing Order Stats done.';
		$this->log( self::GENERAL_LOG, $msg );
		WP_CLI::success( $msg );

		// Import Order items.
		foreach ( $order_items_rows as $order_items_row ) {
			$query = $wpdb->prepare(
				"insert into {$table_prefix_destination}woocommerce_order_items
					(order_item_name,order_item_type,order_id)
					values (%s,%s,%s); ",
				$order_items_row[ 'order_item_name' ],
				$order_items_row[ 'order_item_type' ],
				$order_id,
			);
			$res = $wpdb->query( $query );
			if ( 1 != $res ) {
				$msg = sprintf( 'ERROR: order item insert error, source order_item_id ID %d', $order_items_row[ 'order_item_id' ] );
				$this->log( self::GENERAL_LOG, $msg );
				WP_CLI::warning( $msg );
				continue;
			} else {
				$order_item_id = $wpdb->insert_id;

				// Insert order item meta.
				$source_order_item_id = $order_items_row[ 'order_item_id' ];
				if ( ! empty ( $order_items_metas[ $source_order_item_id ] ) ) {
					foreach ( $order_items_metas[ $source_order_item_id ] as $order_items_meta ) {
						$query = $wpdb->prepare(
							"insert into {$table_prefix_destination}woocommerce_order_itemmeta
								(order_item_id,meta_key,meta_value)
								values (%s,%s,%s); ",
							$order_item_id,
							$order_items_meta[ 'meta_key' ],
							$order_items_meta[ 'meta_value' ],
						);
						$res = $wpdb->query( $query );
						if ( 1 != $res ) {
							$msg = sprintf( 'ERROR: order item meta insert error, source order_item_id ID %d', $source_order_item_id );
							$this->log( self::GENERAL_LOG, $msg );
							WP_CLI::warning( $msg );
						}
					}
				}
			}
		}
		$msg = 'Importing Order Items and order item meta done.';
		$this->log( self::GENERAL_LOG, $msg );
		WP_CLI::success( $msg );

		// Import Order Product Lookup records.
		foreach ( $order_product_lookup_rows as $order_product_lookup_row ) {
			$query = $wpdb->prepare(
				"insert into {$table_prefix_destination}wc_order_product_lookup
					(order_item_id,order_id,product_id,variation_id,customer_id,date_created,product_qty,product_net_revenue,product_gross_revenue,coupon_amount,tax_amount,shipping_amount,shipping_tax_amount)
					values (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s); ",
				$order_item_id,
				$order_id,
				$order_product_lookup_row[ 'product_id' ],
				$order_product_lookup_row[ 'variation_id' ],
				$customer_id,
				$order_product_lookup_row[ 'date_created' ],
				$order_product_lookup_row[ 'product_qty' ],
				$order_product_lookup_row[ 'product_net_revenue' ],
				$order_product_lookup_row[ 'product_gross_revenue' ],
				$order_product_lookup_row[ 'coupon_amount' ],
				$order_product_lookup_row[ 'tax_amount' ],
				$order_product_lookup_row[ 'shipping_amount' ],
				$order_product_lookup_row[ 'shipping_tax_amount' ],
			);
			$res = $wpdb->query( $query );
			if ( 1 != $res ) {
				$msg = sprintf( 'ERROR: order product lookup insert error, source order_item_id ID %d', $order_product_lookup_row[ 'order_item_id' ] );
				$this->log( self::GENERAL_LOG, $msg );
				WP_CLI::warning( $msg );
			}
			$last_inserted_id = $wpdb->insert_id;
		}
		$msg = 'Order Product Lookup import done.';
		$this->log( self::GENERAL_LOG, $msg );
		WP_CLI::success( $msg );
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
