<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Migrator\AbstractMigrationRun;
use NewspackCustomContentMigrator\Migrator\JSONMigrationObjectsClass;
use NewspackCustomContentMigrator\Migrator\MigrationObjects;

class StandardMigrationTestMigrator implements InterfaceCommand {

	private static $instance = null;

	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * @inheritDoc
	 */
	public function register_commands() {
		\WP_CLI::add_command(
			'newspack-content-migrator execute-user-migration',
			[ $this, 'execute_user_migration' ],
			[
				'shortdesc' => 'Executes the user migration.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'path-to-json',
						'description' => 'The JSON to migrate',
						'optional'    => false,
					],
				],
			]
		);
	}

	public function execute_user_migration( $args, $assoc_args ) {
		( new class( $args, $assoc_args ) extends AbstractMigrationRun {

			public function __construct( $args, $assoc_args ) {
				$this->args       = $args;
				$this->assoc_args = $assoc_args;

				$this->set_name( 'User Migration' );
			}

			/**
			 * Returns the migration objects.
			 *
			 * @return MigrationObjects
			 */
			public function get_migration_objects(): MigrationObjects {
				return new JSONMigrationObjectsClass( $this->get_run_key(), $this->assoc_args['path-to-json'] );
			}

			/**
			 * This function houses the logic for the command.
			 *
			 * @param MigrationObjects $migration_objects The objects to perform the migration on.
			 *
			 * @return bool|\WP_Error
			 * @throws \Exception If an error occurs.
			 */
			public function command( MigrationObjects $migration_objects ): bool|\WP_Error {
				foreach ( $migration_objects->get_unprocessed() as $migration_object ) {
					$user_data = [
						'user_pass' => wp_generate_password( 12 ),
					];

					if ( is_email( $migration_object->get()['user_login'] ) ) {
						$user_data['user_login'] = substr( $migration_object->get()['user_login'], 0, strpos( $migration_object->get()['user_login'], '@' ) );
					} else {
						$user_data['user_login'] = $migration_object->get()['user_login'];
					}

					$user_data['user_email'] = $migration_object->get()['user_email'];

					$user_data['display_name'] = $migration_object->get()['user_name'] . ' ' . $migration_object->get()['user_lastname'];

					$user_data['user_nicename'] = sanitize_title( $user_data['display_name'] );

					$user_id = wp_insert_user( $user_data );

					if ( is_wp_error( $user_id ) ) {
						\WP_CLI::error( 'Failed to insert user: ' . $user_id->get_error_message() );
						return false;
					} else {
						\WP_CLI::success( 'Inserted user: ' . $user_data['user_login'] );
						$migration_object->record_source( 'wp_users', 'user_login', $user_id, 'user_login' );
						$migration_object->record_source( 'wp_users', 'user_email', $user_id, 'user_email' );
						$migration_object->record_source( 'wp_users', 'display_name', $user_id, 'user_name' );
						$migration_object->record_source( 'wp_users', 'display_name', $user_id, 'user_lastname' );
						$migration_object->record_source( 'wp_users', 'user_nicename', $user_id, 'wp_users.display_name' );
						$migration_object->store_processed_marker();
					}
				}

				return true;
			}
		} )->start();
	}
}
