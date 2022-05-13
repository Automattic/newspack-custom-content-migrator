<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use WP_CLI;
use NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use NewspackCustomContentMigrator\MigrationLogic\Posts as PostsLogic;

/**
 * Custom migration scripts for Afro.
 */
class AfroMigrator implements InterfaceMigrator {

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * @var PostsLogic
	 */
	private $posts_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts_logic = new PostsLogic();
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
			'newspack-content-migrator afro-migrate',
			[ $this, 'cmd_migrate' ],
		);
	}

	/**
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_migrate( $args, $assoc_args ) {
		$ids = $this->posts_logic->get_all_posts_ids( 'attachment' );
		foreach ( $ids as $key_id => $id ) {
			WP_CLI::line( sprintf( "(%d)/(%d) %d", $key_id + 1, count( $ids ), $id ) );
			wp_delete_post( $id, true );
		}

	}

	/**
	 * Simple file logging.
	 *
	 * @param string $file    File name or path.
	 * @param string $message Log message.
	 */
	public function log( $file, $message ) {
		$message .= "\n";
		file_put_contents( $file, $message, FILE_APPEND );
	}
}
