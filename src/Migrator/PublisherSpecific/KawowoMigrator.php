<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;

/**
 * Custom migration scripts for Kawowo.
 */
class KawowoMigrator implements InterfaceMigrator {

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
			'newspack-content-migrator kawowo-update-collations',
			[ $this, 'cmd_update_collations' ],
			[
				'shortdesc' => 'Updates live Kawowo tables imported during Launch and sets collations from from utf8mb4_unicode_520_ci to Atomic default utf8mb4_unicode_ci',
				'synopsis'  => [],
			]
		);
	}

	/**
	 * Updates live Kawowo tables imported during Launch and sets collations from from utf8mb4_unicode_520_ci to Atomic default
	 * utf8mb4_unicode_ci.
	 */
	public function cmd_update_collations() {
		global $wpdb;

		$wpdb->get_results( "ALTER TABLE kaw_posts CONVERT TO CHARACTER SET utf8mb4 COLLATE 'utf8mb4_unicode_ci'; " );
		$wpdb->get_results( "ALTER TABLE kaw_postmeta CONVERT TO CHARACTER SET utf8mb4 COLLATE 'utf8mb4_unicode_ci'; " );
		$wpdb->get_results( "ALTER TABLE kaw_commentmeta CONVERT TO CHARACTER SET utf8mb4 COLLATE 'utf8mb4_unicode_ci'; " );
		$wpdb->get_results( "ALTER TABLE kaw_comments CONVERT TO CHARACTER SET utf8mb4 COLLATE 'utf8mb4_unicode_ci'; " );
		$wpdb->get_results( "ALTER TABLE kaw_links CONVERT TO CHARACTER SET utf8mb4 COLLATE 'utf8mb4_unicode_ci'; " );
		$wpdb->get_results( "ALTER TABLE kaw_term_relationships CONVERT TO CHARACTER SET utf8mb4 COLLATE 'utf8mb4_unicode_ci'; " );
		$wpdb->get_results( "ALTER TABLE kaw_term_taxonomy CONVERT TO CHARACTER SET utf8mb4 COLLATE 'utf8mb4_unicode_ci'; " );
		$wpdb->get_results( "ALTER TABLE kaw_termmeta CONVERT TO CHARACTER SET utf8mb4 COLLATE 'utf8mb4_unicode_ci'; " );
		$wpdb->get_results( "ALTER TABLE kaw_terms CONVERT TO CHARACTER SET utf8mb4 COLLATE 'utf8mb4_unicode_ci'; " );
		$wpdb->get_results( "ALTER TABLE kaw_usermeta CONVERT TO CHARACTER SET utf8mb4 COLLATE 'utf8mb4_unicode_ci'; " );
		$wpdb->get_results( "ALTER TABLE kaw_users CONVERT TO CHARACTER SET utf8mb4 COLLATE 'utf8mb4_unicode_ci'; " );
	}
}
