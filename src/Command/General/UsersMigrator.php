<?php

namespace NewspackCustomContentMigrator\Command\General;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Utils\Logger;
use \WP_CLI;

class UsersMigrator implements InterfaceCommand {

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * @var Logger.
	 */
	private $logger;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->logger = new Logger();
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceCommand|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator delete-all-users-with-role',
			array( $this, 'cmd_delete_all_users_with_role' ),
			array(
				'shortdesc' => 'Deletes all users.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'role',
						'description' => 'Role to delete.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'reassign',
						'description' => 'Reassign posts to this user ID.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'batch',
						'description' => 'Bath to start from.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'users-per-batch',
						'description' => 'users to import per batch',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator users-fix-unsafe-nicenames',
			[ $this, 'users_fix_unsafe_nicenames' ],
			[
				'shortdesc' => 'Updates user nicenames if they are created from emails.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'batch',
						'description' => 'Bath to start from.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'users-per-batch',
						'description' => 'users to process per batch',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Callable for `newspack-content-migrator delete-all-users-with-role`.
	 *
	 * @param array $positional_args Positional arguments.
	 * @param array $assoc_args      Associative arguments.
	 * @return void
	 */
	public function cmd_delete_all_users_with_role( $positional_args, $assoc_args ) {
		$log_file        = 'delete_users_with_role.log';
		$users_per_batch = isset( $assoc_args['users-per-batch'] ) ? intval( $assoc_args['users-per-batch'] ) : 10000;
		$batch           = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : 1;
		$reassign        = intval( $assoc_args['reassign'] );
		$role            = $assoc_args['role'];

		$reassign_user = get_user_by( 'id', $reassign );

		if ( ! $reassign_user ) {
			$this->logger->log( $log_file, 'User ' . $reassign . ' not found.', Logger::ERROR );
			return;
		}

		$users = get_users(
			[
				'role'   => $role,
				'number' => $users_per_batch,
				'offset' => ( $batch - 1 ) * $users_per_batch,
			]
		);

		foreach ( $users as $user ) {
			$this->logger->log( $log_file, 'Deleting user ' . $user->ID . ' ' . $user->user_email, Logger::SUCCESS );
			wp_delete_user( $user->ID, $reassign );
		}

		wp_cache_flush();
	}

	/**
	 * Update the user nice name (slug) – please note that this can mess with co-author relations, so test and make sure!
	 *
	 * It simply takes the display name of the user and makes that the nicename. If there is no display name, then
	 * a random string is used.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function users_fix_unsafe_nicenames( array $args, array $assoc_args ): void {
		$log_file        = __FUNCTION__ . '.log';
		$users_per_batch = isset( $assoc_args['users-per-batch'] ) ? intval( $assoc_args['users-per-batch'] ) : 10000;
		$batch           = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : 1;
		$query_args      = [
			'number' => $users_per_batch,
			'offset' => ( $batch - 1 ) * $users_per_batch,
		];

		/** @var \WP_User $user */
		foreach ( get_users( $query_args ) as $user ) {
			$old_nicename = $user->user_nicename;
			if ( empty( $user->display_name ) ) {
				$new_nicename = wp_generate_password( 12, false, false );
			} else {
				$new_nicename = sanitize_title( mb_substr( $user->display_name, 0, 50 ) );
			}
			$res = wp_update_user(
				[
					'ID'            => $user->ID,
					'user_nicename' => $new_nicename,
				]
			);
			if ( ! is_wp_error( $res ) ) {
				$this->logger->log( $log_file, sprintf( 'Changed nicename on user %d from %s to %s', $user->ID, $old_nicename, $new_nicename ), Logger::SUCCESS );
			} else {
				$this->logger->log( $log_file, sprintf( 'Problem updating user %d nicename from %s to %s', $user->ID, $old_nicename, $new_nicename, ), Logger::ERROR );
			}
		}

		wp_cache_flush();
	}

}
