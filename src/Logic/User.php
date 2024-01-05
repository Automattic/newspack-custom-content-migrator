<?php
/**
 * Class to encapsulate Listing Type values.
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Logic;

use NewspackCustomContentMigrator\Utils\ConsoleColor;
use NewspackCustomContentMigrator\Utils\ConsoleTable;
use WP_User;

/**
 * Class to handle user related logic.
 */
class User {

	/**
	 * Constructor.
	 */
	public function __construct() {
	}

	/**
	 * Outputs a table of user's from a given array of user ID's or WP_User objects.
	 *
	 * @param WP_User[]|int[] $users Array of user ID's or WP_User objects.
	 * @param string          $title The title of the table.
	 *
	 * @return void|null
	 */
	public function output_users_table( array $users, string $title = "User's Table" ) {
		$users = array_map(
			function ( $user ) {
				if ( $user instanceof WP_User ) {
					return $user->to_array();
				}

				if ( is_numeric( $user ) ) {
					$user = get_user_by( 'id', $user );

					if ( $user ) {
						return $user->to_array();
					}
				}

				return null;
			},
			$users
		);

		$users = array_filter( $users );

		if ( empty( $users ) ) {
			ConsoleColor::bright_yellow( 'No users found.' )->output();

			return null;
		}

		ConsoleTable::output_data(
			$users,
			[
				'ID',
				'user_login',
				'user_nicename',
				'user_email',
				'display_name',
			],
			$title
		);
	}
}
