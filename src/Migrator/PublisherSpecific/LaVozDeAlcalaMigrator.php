<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;

/**
 * Custom migration scripts for La Voz De Alcala.
 */
class LaVozDeAlcalaMigrator implements InterfaceMigrator {

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {}

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
			'newspack-content-migrator lavozdealcala-members',
			[ $this, 'cmd_lavozdealcala_members' ],
			[
				'shortdesc' => 'Converts Private Content Users to WC Members and assigns the proper Membership to them.',
				'synopsis'  => [],
			]
		);
	}

	/**
	 * Callable for `newspack-content-migrator lavozdealcala-members`.
	 */
	public function cmd_lavozdealcala_members() {

		// Get the WC Membership.
		$membership_title = 'Socio';
		$plan_posts = get_posts( [
			'post_type'  => 'wc_membership_plan',
			'title' => $membership_title,
		] );
		if ( 1 !== count( $plan_posts ) ) {
			WP_CLI::error( sprintf( 'Not found WC Membership %s.', $membership_title ) );
		}
		$membership_plan_id = $plan_posts[ 0 ]->ID;

		// Get active PrivateContent Users, convert them to WP Users, and assign the WC Membership Plan to them.
		$users = $this->get_pc_active_users();
		foreach ( $users as $k => $user ) {
			$user_id = wc_create_new_customer( $user[ 'email' ], $user[ 'username' ], wp_generate_password(), [
				'first_name' => $user[ 'name' ],
				'last_name' => $user[ 'surname' ],
				'user_registered' => $user[ 'insert_date' ],
			] );

			// User exists.
			if ( is_wp_error( $user_id ) && isset( $user_id->errors[ 'registration-error-email-exists' ] ) ) {
				$wp_user = get_user_by( 'email', $user[ 'email' ] );
				$user_id = $wp_user->ID;
			}

			// Unknown error.
			if ( is_wp_error( $user_id ) ) {
				WP_CLI::error( sprintf( 'Error creating user %s: %s', $user[ 'username' ], $user_id->get_error_message() ) );
			}

			// Save some conversion meta.
			update_user_meta( $user_id, 'newspack_pc_original_id', $user[ 'id' ] );
			update_user_meta( $user_id, 'newspack_pc_phone', $user[ 'tel' ] );

			// Assign "Socio" Membership to the User.
			wc_memberships_create_user_membership( [
				'plan_id' => $membership_plan_id,
				'user_id' => $user_id,
			] );

			WP_CLI::line( sprintf( '%d/%d %s', $k + 1, count( $users ), $user[ 'username' ] ) );
		}

		WP_CLI::success( 'Done.' );
	}

	/**
	 * Gets PrivateContent Users data.
	 *
	 * @return array|object|null
	 */
	public function get_pc_active_users() {
		global $wpdb;

		// `status`=2 means user is disabled.
		$sql_get_pc_users = <<<SQL
			SELECT id, name, surname, username, email, tel, insert_date
			FROM {$wpdb->prefix}pc_users
			WHERE status = 1
			;
SQL;

		return $wpdb->get_results( $sql_get_pc_users, ARRAY_A );
	}
}
