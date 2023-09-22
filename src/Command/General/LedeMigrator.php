<?php

namespace NewspackCustomContentMigrator\Command\General;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\Lede;
use \NewspackCustomContentMigrator\Logic\Posts;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use \WP_CLI;

/**
 * Lede migration commands.
 */
class LedeMigrator implements InterfaceCommand {

	/**
	 * Instance.
	 *
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Lede instance.
	 *
	 * @var Lede Lede instance.
	 */
	private $lede;

	/**
	 * Posts instance.
	 *
	 * @var Posts Posts instance.
	 */
	private $posts;

	/**
	 * CoAuthorPlus instance.
	 *
	 * @var CoAuthorPlus CoAuthorPlus instance.
	 */
	private $cap;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->lede  = new Lede();
		$this->posts = new Posts();
		$this->cap   = new CoAuthorPlus();
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
			'newspack-content-migrator lede-migrate-authors-to-gas',
			[ $this, 'cmd_migrate_authors_to_gas' ],
			[
				'shortdesc' => 'Migrates that custom Lede Authors plugin data to GA authors.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'live-table-prefix',
						'description' => "Live posts and postmeta tables need to be available for this scipt to access original IDs of author Post objects, terms and WP_Users -- because these IDs might get changed when imported on top of an existing site. As a future improvement, this could be replaced with CDiff's original-post-id metas which we already have in the DB, but need to check whether we store them for WP_Users.",
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Callable for 'newspack-content-migrator lede-migrate-authors-to-gas'.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_migrate_authors_to_gas( array $pos_args, array $assoc_args ) {

		$live_table_prefix = $assoc_args['live-table-prefix'];

		WP_CLI::line( 'Converting Lede Authors profiles to GAs and assigning them to all posts...' );
		// $post_ids = $this->posts->get_all_posts_ids();
		$post_ids = [ 640615 ];
		foreach ( $post_ids as $key_post_id => $post_id ) {

			WP_CLI::log( sprintf( '(%d)/(%d) %d', $key_post_id + 1, count( $post_ids ), $post_id ) );

			$ga_ids = $this->lede->convert_lede_authors_to_gas_for_post( $live_table_prefix, $post_id );
			// Output results.
			if ( $ga_ids ) {
				foreach ( $ga_ids as $ga_id ) {
					$ga = $this->cap->get_guest_author_by_id( $ga_id );
					WP_CLI::line( sprintf( "- GA ID %d '%s'", $ga_id, $ga->display_name ) );
				}
			}
		}

		wp_cache_flush();
		WP_CLI::line( 'Done.' );
	}
}
