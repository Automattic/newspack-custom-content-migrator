<?php

namespace NewspackCustomContentMigrator\Command\General;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\Lede;
use \NewspackCustomContentMigrator\Logic\Posts;
use Newspack\MigrationTools\Logic\CoAuthorsPlusHelper;
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
	 * CoAuthorsPlusHelper instance.
	 *
	 * @var CoAuthorsPlusHelper instance.
	 */
	private $cap;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->lede  = new Lede();
		$this->posts = new Posts();
		$this->cap   = new CoAuthorsPlusHelper();
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
		WP_CLI::add_command(
			'newspack-content-migrator lede-migrate-dek-postmeta-to-subtitle',
			[ $this, 'cmd_migrate_dek_postmeta_to_subtitle' ],
			[
				'shortdesc' => 'Migrates postmeta dek to Newspack Subtitle.',
			]
		);
	}

	/**
	 * Migrates postmeta dek to Newspack Subtitle.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_migrate_dek_postmeta_to_subtitle( array $pos_args, array $assoc_args ) {
		global $wpdb;

		$post_ids = $this->posts->get_all_posts_ids( 'post', [ 'publish', 'future', 'draft', 'pending', 'private' ] );
		foreach ( $post_ids as $key_post_id => $post_id ) {
			// Get postmeta with meta_key 'dek'.
			$dek_subtitle = $wpdb->get_var( $wpdb->prepare( "select meta_value from {$wpdb->postmeta} where meta_key = 'dek' and post_id = %d ;", $post_id ) );
			if ( $dek_subtitle ) {
				update_post_meta( $post_id, 'newspack_post_subtitle', $dek_subtitle );
				WP_CLI::success( 'Updated ' . $post_id );
			} else {
				$d = 1;
			}
		}
	}

	/**
	 * Callable for 'newspack-content-migrator lede-migrate-authors-to-gas'.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @throws \RuntimeException If required live tables don't exist.
	 */
	public function cmd_migrate_authors_to_gas( array $pos_args, array $assoc_args ) {

		// Args.
		$live_table_prefix = $assoc_args['live-table-prefix'];

		global $wpdb;

		// Validate that required live tables exist. Needed to access original IDs of author Post objects, terms and WP_Users -- because these IDs might get changed when imported on top of an existing site. Future improvement -- switch to fetching original ID from DB, however we need to implement saving imported WP_User's original ID in DB first.
		$this->validate_tables_exist( [ $live_table_prefix . 'posts', $live_table_prefix . 'postmeta', $live_table_prefix . 'users', $live_table_prefix . 'usermeta' ] );

		WP_CLI::line( 'Converting Lede Authors profiles to GAs and assigning them to all posts...' );
		$post_ids = $this->posts->get_all_posts_ids();
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

	/**
	 * Checks that local tables exist.
	 *
	 * @param array $tables Table names.
	 *
	 * @throws \RuntimeException If required live tables don't exist.
	 *
	 * @return void
	 */
	public function validate_tables_exist( array $tables ) {
		global $wpdb;
		foreach ( $tables as $table ) {
			$table = esc_sql( $table );
			// phpcs:ignore -- Table name properly escaped.
			$test_var = $wpdb->get_var( "select 1 from $table;" );
			if ( ! $test_var ) {
				throw new \RuntimeException( sprintf( "Table `%s` not found and it's needed to access original Lede Author data.", $table ) );
			}
		}
	}
}
