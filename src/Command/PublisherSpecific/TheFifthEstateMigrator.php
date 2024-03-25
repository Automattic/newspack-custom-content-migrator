<?php
/**
 * Migration tasks for The Fifth Estate.
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use Exception;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use WP_CLI;

/**
 * Custom migration scripts for The Fifth Estate.
 */
class TheFifthEstateMigrator implements InterfaceCommand {

	/**
	 * Private constructor.
	 */
	private function __construct() {
		// Do nothing
	}

	/**
	 * Singleton.
	 *
	 * @return TheFifthEstateMigrator
	 */
	public static function get_instance(): TheFifthEstateMigrator {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new self();
		}

		return $instance;
	}

	/**
	 * @throws Exception
	 */
	public function register_commands(): void {
		WP_CLI::add_command(
			'newspack-content-migrator tfe-cleanup-orders-from-22dec2022',
			[ $this, 'cmd_cleanup_orders_from_22_dec_2022' ],
			[
				'shortdesc' => 'Cleanup Orders and their related Customers from 22 Dec 2022',
			]
		);
	}

	/**
     * Cleanup Orders and their related Customers from 22 Dec 2022.
     */
    public function cmd_cleanup_orders_from_22_dec_2022( array $args, array $assoc_args ): void {
        $start_time = microtime( true );

        WP_CLI::line( 'Starting Orders and Customers Deletion' );

        foreach ( $this->get_wc_orders( -1 ) as $order ) {
            WP_CLI::line( sprintf( '[Memory: %s » Time: %s] Deleting Customer %s', size_format( memory_get_usage( true ) ), human_time_diff( $start_time, microtime( true ) ), $order->get_customer_id() ) );
            wp_delete_user( $order->get_customer_id() );

            WP_CLI::line( sprintf( '[Memory: %s » Time: %s] Deleting order %s', size_format( memory_get_usage( true ) ), human_time_diff( $start_time, microtime( true ) ), $order->get_id() ) );
            wp_delete_post( $order->get_id() );
        }

        WP_CLI::success( 'All Orders and their Customers have been successfully deleted!' );
    }

    /**
     * Get WooCommerce orders
     */
    private function get_wc_orders( $limit = 100 ): iterable {
        $ids = wc_get_orders( [
            'date_created' => '2022-12-22',
            'status' => 'wc-failed',
            'return' => 'ids',
            'limit' => $limit,
        ] );

        foreach ( $ids as $id ) {
            yield wc_get_order( $id );
        }

        return $ids;
    }
}