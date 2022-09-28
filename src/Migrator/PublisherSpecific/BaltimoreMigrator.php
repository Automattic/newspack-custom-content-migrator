<?php
/**
 * Migrator for BaltimoreFishBowl
 * 
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use NewspackCustomContentMigrator\MigrationLogic\Attachments;
use NewspackCustomContentMigrator\MigrationLogic\SimpleLocalAvatars;
use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;

/**
 * Custom migration scripts for BaltimoreFishBowl.
 */
class BaltimoreMigrator implements InterfaceMigrator {

	/**
	 * Instance of BaltimoreMigrator
	 * 
	 * @var null|InterfaceMigrator
	 */
	private static $instance = null;

	/**
	 * Instance of \MigrationLogic\SimpleLocalAvatars.
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
			'newspack-content-migrator baltimore-migrate-starbox-avatars',
			[ $this, 'cmd_baltimore_migrate_starbox_avatars' ],
			[
				'shortdesc' => 'Migrates the authors\' avatars from Starbox to Simple Local Avatars.',
				'synopsis'  => [],
			]
		);
	}

	/**
	 * Extract the old avatars from Starbox options and migrate them to Simple Local Avatars.
	 */
	public function cmd_baltimore_migrate_starbox_avatars( $pos_args, $assoc_args ) {
		if ( ! $this->sla_logic->is_sla_plugin_active() ) {
			WP_CLI::warning( 'Simple Local Avatars not found. Install and activate it before using this command.' );
			return;
		}

		$dry_run = isset( $assoc_args['dry-run'] ) ? true : false;

		$attachments_logic = new Attachments();
		$site_upload_dir   = wp_upload_dir();

		// Starbox stores the uploaded avatars in wp-content/uploads/gravatar/.
		$starbox_avatars_dir = $site_upload_dir['basedir'] . '/gravatar/';

		// Starbox store all the data in one row in the wp_options table as JSON.
		$starbox_options = json_decode( get_option( 'abh_options', array() ), true );

		$imported_avatars_count = 0;

		if ( ! $starbox_options ) {
			WP_CLI::warning( 'Starbox data not found in the database. Aborting...' );
			exit;
		}

		foreach ( $starbox_options as $option_key => $author_settings ) {
			if ( strpos( $option_key, 'abh_author' ) === false ) {
				continue;
			}

			if ( ! isset( $author_settings['abh_gravatar'] ) || empty( $author_settings['abh_gravatar'] ) ) {
				continue;
			}

			$author_id = str_replace( 'abh_author', '', $option_key );

			$author_gravatar_filename = $author_settings['abh_gravatar'];

			$author_gravatar_path = $starbox_avatars_dir . $author_gravatar_filename;

			WP_CLI::log( sprintf( 'Migrating the avatar for user #%s', $author_id ) );

			if ( $dry_run ) {
				continue;
			}

			$new_avatar_id = $attachments_logic->import_external_file(
				$author_gravatar_path, 
				sprintf( 'User #%s Avatar', $author_id ),
			);
			
			if ( is_wp_error( $new_avatar_id ) ) {
				WP_CLI::warning( sprintf( 'There was an error importing the avatar attachment for user #%s', $author_id ) );
				continue;
			}

			$sla_migrated = $this->sla_logic->import_avatar( $author_id, $new_avatar_id );

			if ( ! $sla_migrated ) {
				WP_CLI::warning( sprintf( 'There was an error migrating the avatar image to SLA for user #%s', $author_id ) );
				continue;
			}

			$imported_avatars_count++;
		}

		if ( $imported_avatars_count > 0 ) {
			WP_CLI::success( sprintf( 'Done! %d avatars were migrated from Starbox to SLA.', $imported_avatars_count ) );
		}
	}
}
