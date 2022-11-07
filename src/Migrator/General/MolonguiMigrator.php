<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use NewspackCustomContentMigrator\MigrationLogic\SimpleLocalAvatars;
use NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;

/**
 * Class for migrating Molongui content
 */
class MolonguiMigrator implements InterfaceMigrator {
	/**
	 * InterfaceMigrator instance
	 * 
	 * @var null|InterfaceMigrator
	 */
	private static $instance = null;

	/**
	 * Instance of \MigrationLogic\SimpleLocalAvatars
	 * 
	 * @var null|SimpleLocalAvatars
	 */
	private $sla_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->sla_logic = new SimpleLocalAvatars();
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
		WP_CLI::add_command(
			'newspack-content-migrator migrate-molongui-avatars-to-sla',
			array( $this, 'cmd_migrate_molongui_avatars_to_sla' ),
			array(
				'shortdesc' => 'Migrate users avatars from Molongui to Simple Local Avatars.',
			),
		);
	}


	/**
	 * Callable for wp newspack-content-migrator migrate-molongui-avatars-to-sla.
	 *
	 * @param array $pos_args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_migrate_molongui_avatars_to_sla( $pos_args, $assoc_args ) {
		if ( ! $this->sla_logic->is_sla_plugin_active() ) {
			WP_CLI::error( 'Simple Local Avatars not found. Install and activate it before using this command.' );
		}

		$molongui_avatar_id_meta_key = 'molongui_author_image_id';

		$users = get_users(
			array(
				'meta_key'     => $molongui_avatar_id_meta_key,
				'meta_compare' => 'EXISTS',
			)
		);

		foreach ( $users as $user ) {
			WP_CLI::log( sprintf( 'Migrating avatar for user %d...', $user->ID ) );
			$attachment_id = get_user_meta( $user->ID, $molongui_avatar_id_meta_key, true );

			if ( ! $attachment_id ) {
				WP_CLI::log( sprintf( 'No avatar data found for user %d...', $user->ID ) );
				continue;
			}

			if ( $this->sla_logic->user_has_sla_avatar( $user->ID ) ) {
				WP_CLI::log( sprintf( 'User %d already has SLA avatar. Skipping...', $user->ID ) );
				continue;
			}

			if ( $this->sla_logic->import_avatar( $user->ID, $attachment_id ) ) {
				WP_CLI::log( sprintf( 'Successfully migrated the avatar for user %d.', $user->ID ) );
			} else {
				WP_CLI::warning( sprintf( 'Could not migrate the avatar for user %d.', $user->ID ) );
			}
		}

		WP_CLI::success( 'Done!' );
	}
}