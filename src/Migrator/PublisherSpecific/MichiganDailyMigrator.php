<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;

/**
 * Custom migration scripts for Michigan Daily.
 */
class MichiganDailyMigrator implements InterfaceMigrator {

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
			'newspack-content-migrator michigan-daily-fix-drupal-content-after-conversion',
			[ $this, 'cmd_fix_drupal_content_after_conversion' ],
			[
				'shortdesc' => 'Fills in the gaps left by the Drupal importer, by getting and patching data right from the original Drupal DB tables.',
			]
		);
	}

	/**
	 * Callable for the `newspack-content-migrator michigan-daily-fix-drupal-content-after-conversion`.
	 */
	public function cmd_fix_drupal_content_after_conversion( $args, $assoc_args ) {
		$d=1;
	}
}
