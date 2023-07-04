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
			'newspack-content-migrator delete-all-users',
			array( $this, 'cmd_delete_all_users' ),
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
	}

	/**
	 * Callable for `newspack-content-migrator delete-all-users`.
	 *
	 * @param array $positional_args Positional arguments.
	 * @param array $assoc_args      Associative arguments.
	 * @return void
	 */
	public function cmd_delete_all_users( $positional_args, $assoc_args ) {
		$log_file        = 'delete_users.log';
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
}
