<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\Migrator\General\PostsMigrator;
use \WP_CLI;

class WooCommMigrator implements InterfaceMigrator {

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
		WP_CLI::add_command( 'newspack-content-migrator woocomm-update-pages', array( $this, 'cmd_update_pages' ), [
			'shortdesc' => 'Exports settings for default Site Pages.',
		] );
	}

	/**
	 * Callable for woocomm-update-pages command.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_update_pages( $args, $assoc_args ) {
		$this->update_woocomm_pages_ids_after_import();
		WP_CLI::success( 'Done.' );
	}

	/**
	 * Updates WoComm's pages' IDs after they've been imported with new IDs.
	 */
	private function update_woocomm_pages_ids_after_import() {
		$option_names = array(
			'woocommerce_shop_page_id',
			'woocommerce_cart_page_id',
			'woocommerce_checkout_page_id',
			'woocommerce_myaccount_page_id',
			'woocommerce_terms_page_id',
		);

		$posts_migrator = PostsMigrator::get_instance();
		foreach ( $option_names as $option_name ) {
			$old_id = get_option( $option_name );
			if ( empty( $old_id ) ) {
				continue;
			}

			$current_id = $posts_migrator->get_current_post_id_from_original_post_id( $old_id );
			if ( null !== $current_id ) {
				update_option( $option_name, $current_id );
			}
		}
	}
}
