<?php
/**
 * Migration tasks for The Fifth Estate.
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use Exception;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Utils\Logger;
use WP_CLI;

/**
 * Custom migration scripts for The Fifth Estate.
 */
class TheFifthEstateMigrator implements InterfaceCommand {

	/**
	 * Logger.
	 *
	 * @var Logger $logger Logger instance.
	 */
	private $logger;

	/**
	 * Private constructor.
	 */
	private function __construct() {
		$this->logger = new Logger();
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
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Do a dry run.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Cleanup Orders and their related Customers from 22 Dec 2022.
	 */
	public function cmd_cleanup_orders_from_22_dec_2022( array $args, array $assoc_args ): void {
		$log = 'tfe-cleanup-orders-from-22-dec-2022.log';

		$dry_run = isset( $assoc_args['dry-run'] ) ? true : false;

		$start_time = microtime( true );

		$this->logger->log( $log, sprintf( 'Starting Orders and Customers Deletion %s', date( 'Y-m-d H:I:s' ) ) );

		foreach ( $this->get_wc_orders() as $order ) {
			$this->logger->log( $log, sprintf( '[Memory: %s » Time: %s] Deleting Customer %s', size_format( memory_get_usage( true ) ), human_time_diff( $start_time, microtime( true ) ), $order->get_customer_id() ) );

			if ( ! $dry_run ) {
				if ( wp_delete_user( $order->get_customer_id() ) ) {
					$this->logger->log( $log, sprintf( '✅ Successfully deleted Customer with ID %s', $order->get_customer_id() ), Logger::SUCCESS );
				} else {
					$this->logger->log( $log, sprintf( '❌ Could not delete Customer with ID %s', $order->get_customer_id() ), Logger::ERROR );
				}
			}

			$this->logger->log( $log, sprintf( '[Memory: %s » Time: %s] Deleting Order %s', size_format( memory_get_usage( true ) ), human_time_diff( $start_time, microtime( true ) ), $order->get_id() ) );

			if ( ! $dry_run ) {
				if ( wp_delete_post( $order->get_id() ) ) {
					$this->logger->log( $log, sprintf( '✅ Successfully deleted Order with ID %s', $order->get_id() ), Logger::SUCCESS );
				} else {
					$this->logger->log( $log, sprintf( '❌ Could not delete Order with ID %s', $order->get_id() ), Logger::ERROR );
				}
			}
		}

		if ( $dry_run ) {
			$this->logger->log( $log, '⚠️ Dry Run: No deletions have been made.', Logger::SUCCESS );
		} else {
			$this->logger->log( $log, '✅ All Orders and their Customers have been successfully deleted!', Logger::SUCCESS );
		}
	}

	/**
	 * Get WooCommerce orders
	 */
	private function get_wc_orders(): iterable {
		$ids = wc_get_orders(
			[
				'date_created' => '2022-12-22',
				'status'       => 'wc-failed',
				'return'       => 'ids',
				'limit'        => -1,
			] 
		);

		foreach ( $ids as $id ) {
			yield wc_get_order( $id );
		}

		return $ids;
	}
}
