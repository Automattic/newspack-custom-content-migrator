<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;

/**
 * Custom migration scripts for LkldNow.
 */
class TestMigrator implements InterfaceMigrator {

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
		WP_CLI::add_command(
			'newspack-content-migrator check-broken-images',
			[ $this, 'cmd_check_broken_images' ],
			[
				'shortdesc' => 'Check all broken images.',
				'synopsis'  => [
                    [
						'type'        => 'flag',
						'name'        => 'dry-run',
						'optional'    => true,
						'repeating'   => false,
					],
                ],
			]
		);
        WP_CLI::add_command(
			'newspack-content-migrator fix-missing-taxonomies',
			[ $this, 'cmd_fix_missing_taxonomies' ],
			[
				'shortdesc' => 'Check all broken images.',
				'synopsis'  => [
                    [
						'type'        => 'flag',
						'name'        => 'dry-run',
						'optional'    => true,
						'repeating'   => false,
					],
                ],
			]
		);
        WP_CLI::add_command(
			'newspack-content-migrator migrate-avatars',
			[ $this, 'cmd_migrate_avatars' ],
			[
				'shortdesc' => 'Check all broken images.',
				'synopsis'  => [
                    [
						'type'        => 'flag',
						'name'        => 'dry-run',
						'optional'    => true,
						'repeating'   => false,
					],
                ],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator migrate-avatars',
			[ $this, 'cmd_migrate_co_authors' ],
			[
				'shortdesc' => 'Check all broken images.',
				'synopsis'  => [
                    [
						'type'        => 'flag',
						'name'        => 'dry-run',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'path',
						'optional'    => false,
						'repeating'   => false,
					],
                ],
			]
		);
	}

	public function cmd_check_broken_images( $pos_args, $assoc_args ) {
		var_dump( $pos_args );
		var_dump( $assoc_args );
        $dry_run           = isset( $assoc_args['dry-run'] ) ? true : false;
        if ( $dry_run ) {
            WP_CLI::log('Dry running...');
        }
        sleep(2);
        WP_CLI::success('3000 broken images were fixed.');
	}

    public function cmd_fix_missing_taxonomies( $pos_args, $assoc_args ) {
		var_dump( $pos_args );
		var_dump( $assoc_args );
        $dry_run           = isset( $assoc_args['dry-run'] ) ? true : false;
        if ( $dry_run ) {
            WP_CLI::log('Dry running...');
        }
        sleep(2);
        WP_CLI::success('3467 missing taxonomies were fixed.');
	}

    public function cmd_migrate_avatars( $pos_args, $assoc_args ) {
		var_dump( $pos_args );
		var_dump( $assoc_args );
        $dry_run           = isset( $assoc_args['dry-run'] ) ? true : false;
        if ( $dry_run ) {
            WP_CLI::log('Dry running...');
        }
        sleep(2);
        WP_CLI::error('hadchi khsr.', defined( 'PRELAUNCH_QA' ) ? false : true );
	}

	public function cmd_migrate_co_authors( $pos_args, $assoc_args ) {
		var_dump( $pos_args );
		var_dump( $assoc_args );
        $dry_run           = isset( $assoc_args['dry-run'] ) ? true : false;
		
        if ( $dry_run ) {
            WP_CLI::log('Dry running...');
        }
        sleep(2);
        WP_CLI::error('hadchi khsr.');
	}
}
