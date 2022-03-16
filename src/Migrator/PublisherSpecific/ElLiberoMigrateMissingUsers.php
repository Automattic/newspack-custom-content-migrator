<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use WP_CLI;

class ElLiberoMigrateMissingUsers implements InterfaceMigrator {
	/**
	 * ElLiberoMigrateMissingUsers Singleton.
	 *
	 * @var ElLiberoMigrateMissingUsers|null $instance
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
	}

	/**
	 * Get singleton instance.
	 *
	 * @return ElLiberoMigrateMissingUsers
	 */
	public static function get_instance(): ElLiberoMigrateMissingUsers {
		$class = get_called_class();

		if ( null === self::$instance ) {
			self::$instance = new $class();
		}

		return self::$instance;
	}

	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator el-libero-migrate-missing-users',
			[ $this, 'driver' ],
			[
				'shortdesc' => 'Will handle migrating over missing users for El Libero',
				'synopsis'
			]
		);
	}

	public function driver() {
		global $wpdb;

		$live_table_users_sql = "SELECT * FROM live_{$wpdb->users} WHERE user_email != ''";
		$results = $wpdb->get_results( $live_table_users_sql );

		foreach ( $results as $user_row ) {
			$exists_in_main_users_table_sql = "SELECT * FROM $wpdb->users WHERE user_email = '$user_row->user_email'";
			$result = $wpdb->get_results( $exists_in_main_users_table_sql );
			$exists_in_main_users_table = ! empty( $result );

			if ( ! $exists_in_main_users_table ) {
				$user_array = (array) $user_row;
				unset( $user_array['ID'] );
				$new_user_id = $wpdb->insert( $wpdb->users, $user_array );
				$old_meta = $wpdb->get_results( "SELECT meta_key, meta_value FROM live_{$wpdb->usermeta} WHERE user_id = $user_row->ID" );

				foreach ( $old_meta as $meta ) {
					$meta->user_id = $new_user_id;
					$wpdb->insert( $wpdb->usermeta, (array) $meta );
				}
			}
		}
	}
}